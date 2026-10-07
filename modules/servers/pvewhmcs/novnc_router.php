<?php

/*
 * Secure noVNC bootstrap for Proxmox VE for WHMCS.
 *
 * The public URL carries only an opaque one-time session nonce. PVE and VNC
 * tickets are generated server-side after validating the authenticated WHMCS
 * client and service ownership.
 */

$rootDir = dirname(__DIR__, 3);
require_once $rootDir . '/init.php';
require_once $rootDir . '/modules/addons/pvewhmcs/proxmox.php';
require_once $rootDir . '/modules/addons/pvewhmcs/security.php';

use Illuminate\Database\Capsule\Manager as Capsule;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");

function pvewhmcs_console_fail($message, $status = 403)
{
    http_response_code((int) $status);
    echo htmlspecialchars((string) $message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    pvewhmcs_console_fail('Secure console session is unavailable.', 500);
}

$nonce = isset($_GET['session']) ? (string) $_GET['session'] : '';
if (!preg_match('/^[a-f0-9]{64}$/', $nonce)) {
    pvewhmcs_console_fail('Invalid console session.');
}

$entry = isset($_SESSION['pvewhmcs_console'][$nonce])
    ? $_SESSION['pvewhmcs_console'][$nonce]
    : null;

// One-time use: consume before any privileged work.
unset($_SESSION['pvewhmcs_console'][$nonce]);

if (
    !is_array($entry)
    || empty($entry['serviceid'])
    || empty($entry['userid'])
    || empty($entry['expires'])
    || (int) $entry['expires'] < time()
) {
    pvewhmcs_console_fail('Console session expired. Open the console again from your service page.');
}

$sessionClientId = 0;
if (!empty($_SESSION['uid'])) {
    $sessionClientId = (int) $_SESSION['uid'];
} elseif (!empty($_SESSION['cid'])) {
    $sessionClientId = (int) $_SESSION['cid'];
}

if ($sessionClientId <= 0 || $sessionClientId !== (int) $entry['userid']) {
    pvewhmcs_console_fail('Client authentication does not match this console session.');
}

$serviceId = (int) $entry['serviceid'];
$clientId = (int) $entry['userid'];

$service = Capsule::table('tblhosting')
    ->where('id', '=', $serviceId)
    ->where('userid', '=', $clientId)
    ->first();

if (!$service || (string) $service->domainstatus !== 'Active') {
    pvewhmcs_console_fail('This service is not eligible for console access.');
}

$guest = Capsule::table('mod_pvewhmcs_vms')
    ->where('id', '=', $serviceId)
    ->where('user_id', '=', $clientId)
    ->first();

if (!$guest || !in_array((string) $guest->vtype, array('qemu', 'lxc'), true)) {
    pvewhmcs_console_fail('Unable to resolve the virtual guest for this service.');
}

$server = Capsule::table('tblservers')
    ->where('id', '=', (int) $service->server)
    ->where('type', '=', 'pvewhmcs')
    ->where('disabled', '=', 0)
    ->first();

if (!$server) {
    pvewhmcs_console_fail('The assigned Proxmox server is unavailable.');
}

$apiHost = !empty($server->hostname) ? (string) $server->hostname : (string) $server->ipaddress;
$apiPort = !empty($server->port) ? (int) $server->port : 8006;

if ($apiHost === '' || filter_var($apiHost, FILTER_VALIDATE_IP)) {
    pvewhmcs_console_fail(
        'Secure console requires the WHMCS server entry to use a DNS hostname with a valid TLS certificate.'
    );
}

$decrypted = localAPI('DecryptPassword', array(
    'password2' => $server->password,
));

if (
    !is_array($decrypted)
    || !isset($decrypted['result'], $decrypted['password'])
    || $decrypted['result'] !== 'success'
    || $decrypted['password'] === ''
) {
    pvewhmcs_console_fail('Unable to access the Proxmox API credential.', 500);
}

try {
    $proxmox = new PVE2_API(
        $apiHost,
        (string) $server->username,
        'pam',
        (string) $decrypted['password'],
        $apiPort
    );

    if (!$proxmox->login()) {
        throw new RuntimeException('PVE API authentication failed.');
    }

    // Resolve the node from trusted PVE cluster data, never from the browser.
    $resources = $proxmox->get('/cluster/resources?type=vm');
    $guestNode = null;

    if (is_array($resources)) {
        foreach ($resources as $resource) {
            if (
                isset($resource['vmid'], $resource['type'], $resource['node'])
                && (int) $resource['vmid'] === (int) $guest->vmid
                && (string) $resource['type'] === (string) $guest->vtype
            ) {
                $guestNode = (string) $resource['node'];
                break;
            }
        }
    }

    if ($guestNode === null || $guestNode === '') {
        throw new RuntimeException('Unable to locate the virtual guest in the PVE cluster.');
    }

    $vncSecret = pvewhmcs_get_vnc_secret();
    if (strlen($vncSecret) < 15) {
        throw new RuntimeException('Console credential is not configured securely.');
    }

    // Dedicated console-only identity. This intentionally remains separate
    // from the provisioning API token/user.
    $consoleApi = new PVE2_API(
        $apiHost,
        'vnc',
        'pve',
        $vncSecret,
        $apiPort
    );

    if (!$consoleApi->login()) {
        throw new RuntimeException('Restricted console authentication failed.');
    }

    $proxy = $consoleApi->post(
        '/nodes/' . rawurlencode($guestNode)
        . '/' . rawurlencode((string) $guest->vtype)
        . '/' . (int) $guest->vmid
        . '/vncproxy',
        array('websocket' => '1')
    );

    if (
        !is_array($proxy)
        || empty($proxy['ticket'])
        || empty($proxy['port'])
    ) {
        throw new RuntimeException('PVE did not return a valid console proxy ticket.');
    }

    $pveTicket = $consoleApi->getTicket();
    if (!$pveTicket) {
        throw new RuntimeException('Unable to obtain the restricted PVE console ticket.');
    }

    global $CONFIG;
    $whmcsHost = parse_url((string) $CONFIG['SystemURL'], PHP_URL_HOST);
    $cookieDomain = pvewhmcs_console_cookie_domain($whmcsHost, $apiHost);

    if ($cookieDomain === null) {
        throw new RuntimeException(
            'WHMCS and PVE must use trusted HTTPS hostnames under a common registrable domain for direct noVNC.'
        );
    }

    // Short-lived, HttpOnly and path-scoped. JavaScript cannot read the PVE
    // ticket and unrelated WHMCS paths will not receive the cookie.
    setrawcookie('PVEAuthCookie', '', array(
        'expires' => time() - 3600,
        'path' => '/api2/json/',
        'domain' => $cookieDomain,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ));

    setrawcookie('PVEAuthCookie', $pveTicket, array(
        'expires' => time() + 120,
        'path' => '/api2/json/',
        'domain' => $cookieDomain,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ));

    $runtimeNonce = bin2hex(random_bytes(32));

    if (!isset($_SESSION['pvewhmcs_console_runtime'])) {
        $_SESSION['pvewhmcs_console_runtime'] = array();
    }

    $_SESSION['pvewhmcs_console_runtime'][$runtimeNonce] = array(
        'userid' => $clientId,
        'serviceid' => $serviceId,
        'host' => $apiHost,
        'port' => $apiPort,
        'node' => $guestNode,
        'vtype' => (string) $guest->vtype,
        'vmid' => (int) $guest->vmid,
        'proxy_port' => (int) $proxy['port'],
        'vnc_ticket' => (string) $proxy['ticket'],
        'expires' => time() + 60,
    );

    header(
        'Location: novnc_client.php?session=' . rawurlencode($runtimeNonce),
        true,
        303
    );
    exit;
} catch (Throwable $e) {
    if (function_exists('logActivity')) {
        logActivity(
            'PVEWHMCS secure console bootstrap failed for Service #'
            . $serviceId
            . ': '
            . substr($e->getMessage(), 0, 300)
        );
    }

    pvewhmcs_console_fail('Unable to start the secure console. Please try again or contact support.', 502);
}
