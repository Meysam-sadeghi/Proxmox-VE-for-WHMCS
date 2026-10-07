<?php

/*
 * Secure noVNC bootstrap for Proxmox VE for WHMCS.
 *
 * The browser submits a short-lived, one-time console nonce in the POST body.
 * No PVE/VNC ticket, destination data, VMID, node, or console nonce appears in
 * the public URL. Authorization and the final noVNC page are handled in this
 * single request to minimize trust handoffs.
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
header('X-Frame-Options: SAMEORIGIN');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-site');

function pvewhmcs_console_fail($message, $status = 403)
{
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
    http_response_code((int) $status);
    echo htmlspecialchars((string) $message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    pvewhmcs_console_fail('Secure console session is unavailable.', 500);
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    pvewhmcs_console_fail('Console bootstrap accepts POST requests only.', 405);
}

global $CONFIG;
$systemUrl = (string) ($CONFIG['SystemURL'] ?? '');
$systemScheme = strtolower((string) parse_url($systemUrl, PHP_URL_SCHEME));
$systemHost = strtolower((string) parse_url($systemUrl, PHP_URL_HOST));
$systemPort = parse_url($systemUrl, PHP_URL_PORT);

if (
    $systemScheme !== 'https'
    || $systemHost === ''
    || !filter_var($systemHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
) {
    pvewhmcs_console_fail('Secure console requires WHMCS SystemURL to use a valid HTTPS hostname.', 500);
}

// Same-origin form posts are expected. The random session nonce is already a
// CSRF secret; when Origin is supplied by the browser, also require it to match
// the configured WHMCS origin exactly.
$origin = isset($_SERVER['HTTP_ORIGIN']) ? rtrim(strtolower((string) $_SERVER['HTTP_ORIGIN']), '/') : '';
if ($origin !== '') {
    $expectedOrigin = $systemScheme . '://' . $systemHost;
    if ($systemPort !== null && (int) $systemPort !== 443) {
        $expectedOrigin .= ':' . (int) $systemPort;
    }

    if (!hash_equals($expectedOrigin, $origin)) {
        pvewhmcs_console_fail('Console request origin validation failed.');
    }
}

$nonce = isset($_POST['session']) ? (string) $_POST['session'] : '';
if (!preg_match('/^[a-f0-9]{64}$/', $nonce)) {
    pvewhmcs_console_fail('Invalid console session.');
}

$entry = isset($_SESSION['pvewhmcs_console'][$nonce])
    ? $_SESSION['pvewhmcs_console'][$nonce]
    : null;

// One-time use: consume before any privileged work or validation response.
unset($_SESSION['pvewhmcs_console'][$nonce]);

$now = time();
if (
    !is_array($entry)
    || empty($entry['serviceid'])
    || empty($entry['userid'])
    || empty($entry['created'])
    || empty($entry['expires'])
    || (int) $entry['created'] > $now
    || (int) $entry['expires'] < $now
    || ((int) $entry['expires'] - (int) $entry['created']) > 60
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

$serverId = (int) $service->server;
$guestVmid = (int) $guest->vmid;
$guestType = (string) $guest->vtype;

$server = Capsule::table('tblservers')
    ->where('id', '=', $serverId)
    ->where('type', '=', 'pvewhmcs')
    ->where('disabled', '=', 0)
    ->first();

if (!$server) {
    pvewhmcs_console_fail('The assigned Proxmox server is unavailable.');
}

$apiHost = !empty($server->hostname) ? strtolower((string) $server->hostname) : '';
$apiPort = !empty($server->port) ? (int) $server->port : 8006;

if (
    $apiHost === ''
    || filter_var($apiHost, FILTER_VALIDATE_IP)
    || !filter_var($apiHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
    || $apiPort < 1
    || $apiPort > 65535
) {
    pvewhmcs_console_fail(
        'Secure console requires a valid DNS hostname covered by a trusted TLS certificate.'
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
        $apiPort,
        true,
        true
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
                && (int) $resource['vmid'] === $guestVmid
                && (string) $resource['type'] === $guestType
            ) {
                $guestNode = (string) $resource['node'];
                break;
            }
        }
    }

    if (
        $guestNode === null
        || $guestNode === ''
        || !preg_match('/^[A-Za-z0-9._-]+$/', $guestNode)
    ) {
        throw new RuntimeException('Unable to locate a valid virtual guest node in the PVE cluster.');
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
        $apiPort,
        true,
        false
    );

    if (!$consoleApi->login()) {
        throw new RuntimeException('Restricted console authentication failed.');
    }

    $proxy = $consoleApi->post(
        '/nodes/' . rawurlencode($guestNode)
        . '/' . rawurlencode($guestType)
        . '/' . $guestVmid
        . '/vncproxy',
        array('websocket' => '1')
    );

    $proxyPort = isset($proxy['port']) ? (int) $proxy['port'] : 0;
    $vncTicket = isset($proxy['ticket']) ? (string) $proxy['ticket'] : '';

    if (
        !is_array($proxy)
        || $vncTicket === ''
        || $proxyPort < 1
        || $proxyPort > 65535
    ) {
        throw new RuntimeException('PVE did not return a valid console proxy ticket.');
    }

    $pveTicket = $consoleApi->getTicket();
    if (!$pveTicket) {
        throw new RuntimeException('Unable to obtain the restricted PVE console ticket.');
    }

    // Revalidate all authorization-sensitive mappings immediately before
    // exposing the console page. This closes the small race window while PVE
    // tickets were being created.
    $currentService = Capsule::table('tblhosting')
        ->where('id', '=', $serviceId)
        ->where('userid', '=', $clientId)
        ->first();

    $currentGuest = Capsule::table('mod_pvewhmcs_vms')
        ->where('id', '=', $serviceId)
        ->where('user_id', '=', $clientId)
        ->first();

    $currentServer = $currentService
        ? Capsule::table('tblservers')
            ->where('id', '=', (int) $currentService->server)
            ->where('type', '=', 'pvewhmcs')
            ->where('disabled', '=', 0)
            ->first()
        : null;

    if (
        !$currentService
        || (string) $currentService->domainstatus !== 'Active'
        || !$currentGuest
        || (int) $currentGuest->vmid !== $guestVmid
        || (string) $currentGuest->vtype !== $guestType
        || !$currentServer
        || (int) $currentServer->id !== $serverId
    ) {
        throw new RuntimeException('Console authorization state changed during bootstrap.');
    }

    $currentHost = !empty($currentServer->hostname)
        ? strtolower((string) $currentServer->hostname)
        : '';
    $currentPort = !empty($currentServer->port) ? (int) $currentServer->port : 8006;

    if (!hash_equals($apiHost, $currentHost) || $apiPort !== $currentPort) {
        throw new RuntimeException('Console server assignment changed during bootstrap.');
    }

    $whmcsHost = $systemHost;
    $cookieDomain = pvewhmcs_console_cookie_domain($whmcsHost, $apiHost);

    if ($cookieDomain === null) {
        throw new RuntimeException(
            'WHMCS and PVE must use trusted HTTPS hostnames under a common registrable domain for direct noVNC.'
        );
    }

    // Short-lived, HttpOnly and path-scoped. JavaScript cannot read the PVE
    // authentication ticket and unrelated application paths will not receive it.
    setrawcookie('PVEAuthCookie', '', array(
        'expires' => time() - 3600,
        'path' => '/api2/json/',
        'domain' => $cookieDomain,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ));

    setrawcookie('PVEAuthCookie', (string) $pveTicket, array(
        'expires' => time() + 120,
        'path' => '/api2/json/',
        'domain' => $cookieDomain,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ));

    $wsPath = '/api2/json/nodes/' . rawurlencode($guestNode)
        . '/' . rawurlencode($guestType)
        . '/' . $guestVmid
        . '/vncwebsocket?port=' . $proxyPort
        . '&vncticket=' . rawurlencode($vncTicket);

    $wsUrl = 'wss://' . $apiHost . ':' . $apiPort . $wsPath;
    $cspConnect = 'wss://' . $apiHost . ':' . $apiPort;
    $cspNonce = base64_encode(random_bytes(18));
    $cspNonceAttr = htmlspecialchars($cspNonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    header(
        "Content-Security-Policy: default-src 'none'; "
        . "script-src 'self' 'nonce-" . $cspNonce . "'; "
        . "style-src 'nonce-" . $cspNonce . "'; "
        . "img-src 'self' data:; "
        . "connect-src " . $cspConnect . "; "
        . "frame-ancestors 'self'; base-uri 'none'; form-action 'none'"
    );

    $jsWsUrl = json_encode(
        $wsUrl,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
    );
    $jsPassword = json_encode(
        $vncTicket,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
    );
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="referrer" content="no-referrer">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Secure noVNC Console</title>
    <style nonce="<?php echo $cspNonceAttr; ?>">
        html, body, #screen { width: 100%; height: 100%; margin: 0; background: #111; overflow: hidden; }
        #status { position: fixed; z-index: 10; left: 10px; top: 10px; padding: 7px 10px; color: #fff; background: rgba(0,0,0,.65); border-radius: 4px; font: 13px sans-serif; }
    </style>
</head>
<body>
<div id="status">Connecting…</div>
<div id="screen"></div>
<script type="module" nonce="<?php echo $cspNonceAttr; ?>">
    import RFB from './novnc/core/rfb.js';

    const screen = document.getElementById('screen');
    const status = document.getElementById('status');
    const rfb = new RFB(screen, <?php echo $jsWsUrl; ?>, {
        credentials: { password: <?php echo $jsPassword; ?> }
    });

    rfb.scaleViewport = true;
    rfb.resizeSession = true;

    rfb.addEventListener('connect', () => {
        status.textContent = 'Connected';
        window.setTimeout(() => status.remove(), 1500);
    });

    rfb.addEventListener('disconnect', (event) => {
        status.textContent = event.detail.clean ? 'Disconnected' : 'Connection lost';
    });

    rfb.addEventListener('securityfailure', () => {
        status.textContent = 'Console authentication failed';
    });
</script>
</body>
</html>
<?php
    exit;
} catch (Throwable $e) {
    if (function_exists('logActivity')) {
        $safeError = pvewhmcs_redact_log_value($e->getMessage());
        logActivity(
            'PVEWHMCS secure console bootstrap failed for Service #'
            . $serviceId
            . ': '
            . substr((string) $safeError, 0, 300)
        );
    }

    pvewhmcs_console_fail('Unable to start the secure console. Please try again or contact support.', 502);
}
