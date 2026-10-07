<?php

/*
 * Minimal noVNC client page for a previously authorized console session.
 *
 * PVE/VNC credentials never appear in the browser address bar or WHMCS access
 * log query strings. The VNC proxy ticket is held in server-side session state
 * until this page is rendered once, then immediately consumed.
 */

$rootDir = dirname(__DIR__, 3);
require_once $rootDir . '/init.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

function pvewhmcs_novnc_client_fail($message, $status = 403)
{
    http_response_code((int) $status);
    echo htmlspecialchars((string) $message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    pvewhmcs_novnc_client_fail('Secure console session is unavailable.', 500);
}

$nonce = isset($_GET['session']) ? (string) $_GET['session'] : '';
if (!preg_match('/^[a-f0-9]{64}$/', $nonce)) {
    pvewhmcs_novnc_client_fail('Invalid console session.');
}

$entry = isset($_SESSION['pvewhmcs_console_runtime'][$nonce])
    ? $_SESSION['pvewhmcs_console_runtime'][$nonce]
    : null;

// One-time use. Reloading this page requires starting a new console session.
unset($_SESSION['pvewhmcs_console_runtime'][$nonce]);

if (
    !is_array($entry)
    || empty($entry['userid'])
    || empty($entry['expires'])
    || (int) $entry['expires'] < time()
) {
    pvewhmcs_novnc_client_fail('Console session expired. Open the console again from your service page.');
}

$sessionClientId = 0;
if (!empty($_SESSION['uid'])) {
    $sessionClientId = (int) $_SESSION['uid'];
} elseif (!empty($_SESSION['cid'])) {
    $sessionClientId = (int) $_SESSION['cid'];
}

if ($sessionClientId <= 0 || $sessionClientId !== (int) $entry['userid']) {
    pvewhmcs_novnc_client_fail('Client authentication does not match this console session.');
}

$host = (string) $entry['host'];
$port = (int) $entry['port'];
$node = (string) $entry['node'];
$vtype = (string) $entry['vtype'];
$vmid = (int) $entry['vmid'];
$proxyPort = (int) $entry['proxy_port'];
$vncTicket = (string) $entry['vnc_ticket'];

if (
    $host === ''
    || $port < 1
    || $port > 65535
    || !in_array($vtype, array('qemu', 'lxc'), true)
    || $vmid < 100
    || $proxyPort < 1
) {
    pvewhmcs_novnc_client_fail('Invalid console runtime state.');
}

$wsPath = '/api2/json/nodes/' . rawurlencode($node)
    . '/' . rawurlencode($vtype)
    . '/' . $vmid
    . '/vncwebsocket?port=' . $proxyPort
    . '&vncticket=' . rawurlencode($vncTicket);

$wsUrl = 'wss://' . $host . ':' . $port . $wsPath;

$cspConnect = 'wss://' . $host . ':' . $port;
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
