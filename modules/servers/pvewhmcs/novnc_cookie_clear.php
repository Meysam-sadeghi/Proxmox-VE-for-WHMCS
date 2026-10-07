<?php

/*
 * One-time endpoint that expires the short-lived parent-domain PVEAuthCookie
 * immediately after noVNC reports a successful WebSocket connection.
 */

$rootDir = dirname(__DIR__, 3);
require_once $rootDir . '/init.php';
require_once $rootDir . '/modules/addons/pvewhmcs/security.php';

use Illuminate\Database\Capsule\Manager as Capsule;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");

function pvewhmcs_console_clear_fail($status = 403)
{
    http_response_code((int) $status);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    pvewhmcs_console_clear_fail(500);
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    pvewhmcs_console_clear_fail(405);
}

$nonce = isset($_POST['session']) ? (string) $_POST['session'] : '';
if (!preg_match('/^[a-f0-9]{64}$/', $nonce)) {
    pvewhmcs_console_clear_fail();
}

$entry = isset($_SESSION['pvewhmcs_console_clear'][$nonce])
    ? $_SESSION['pvewhmcs_console_clear'][$nonce]
    : null;

// One-time use regardless of success/failure.
unset($_SESSION['pvewhmcs_console_clear'][$nonce]);

$now = time();
if (
    !is_array($entry)
    || empty($entry['userid'])
    || empty($entry['serviceid'])
    || empty($entry['cookie_domain'])
    || empty($entry['created'])
    || empty($entry['expires'])
    || (int) $entry['created'] > $now
    || (int) $entry['expires'] < $now
    || ((int) $entry['expires'] - (int) $entry['created']) > 60
) {
    pvewhmcs_console_clear_fail();
}

$sessionClientId = 0;
if (!empty($_SESSION['uid'])) {
    $sessionClientId = (int) $_SESSION['uid'];
} elseif (!empty($_SESSION['cid'])) {
    $sessionClientId = (int) $_SESSION['cid'];
}

if ($sessionClientId <= 0 || $sessionClientId !== (int) $entry['userid']) {
    pvewhmcs_console_clear_fail();
}

$serviceId = (int) $entry['serviceid'];
$service = Capsule::table('tblhosting')
    ->where('id', '=', $serviceId)
    ->where('userid', '=', $sessionClientId)
    ->first();

if (!$service) {
    pvewhmcs_console_clear_fail();
}

$server = Capsule::table('tblservers')
    ->where('id', '=', (int) $service->server)
    ->where('type', '=', 'pvewhmcs')
    ->where('disabled', '=', 0)
    ->first();

if (!$server || empty($server->hostname)) {
    pvewhmcs_console_clear_fail();
}

global $CONFIG;
$whmcsHost = strtolower((string) parse_url((string) $CONFIG['SystemURL'], PHP_URL_HOST));
$pveHost = strtolower((string) $server->hostname);
$cookieDomain = pvewhmcs_console_cookie_domain($whmcsHost, $pveHost);

if (
    $cookieDomain === null
    || !hash_equals((string) $entry['cookie_domain'], $cookieDomain)
) {
    pvewhmcs_console_clear_fail();
}

setrawcookie('PVEAuthCookie', '', array(
    'expires' => time() - 3600,
    'path' => '/api2/json/',
    'domain' => $cookieDomain,
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
));

http_response_code(204);
exit;
