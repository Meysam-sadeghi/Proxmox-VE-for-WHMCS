<?php

/*
 * Shared security helpers for Proxmox VE for WHMCS.
 *
 * This file requires the WHMCS bootstrap to be loaded before use.
 */

use Illuminate\Database\Capsule\Manager as Capsule;


/**
 * Recursively redact secrets before any value is written to WHMCS/PHP logs.
 *
 * This is defense-in-depth: callers should still avoid logging raw request
 * bodies, provisioning params, PVE responses, or credentials in the first
 * place.
 */
function pvewhmcs_redact_log_value($value)
{
    $sensitiveKeyPattern = '/(?:password|passwd|passphrase|secret|token|ticket|cookie|authorization|csrf)/i';

    if (is_array($value)) {
        $safe = array();

        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match($sensitiveKeyPattern, $key)) {
                $safe[$key] = '[REDACTED]';
            } else {
                $safe[$key] = pvewhmcs_redact_log_value($item);
            }
        }

        return $safe;
    }

    if (is_object($value)) {
        return pvewhmcs_redact_log_value(get_object_vars($value));
    }

    if (!is_string($value)) {
        return $value;
    }

    $patterns = array(
        '/(PVEAPIToken=)[^=\\s]+=[^\\s,;&]+/i',
        '/(PVEAuthCookie=)[^\\s;]+/i',
        '/((?:"|\\\')?(?:password|cipassword|pass|secret|token|ticket|vncticket|csrfpreventiontoken|authorization)(?:"|\\\')?\\s*[:=]\\s*)(?:"[^"]*"|\\\'[^\\\']*\\\'|[^,}\\s;&]+)/i',
    );

    return preg_replace($patterns, '$1[REDACTED]', $value);
}

/**
 * Mandatory wrapper for WHMCS module logging.
 *
 * All first-party module logging must pass through this function so secrets are
 * recursively redacted even if a future caller accidentally supplies a raw
 * request/response structure. Direct logModuleCall() use is blocked by CI.
 */
function pvewhmcs_secure_log_module_call(
    $module,
    $action,
    $request,
    $response = '',
    $processedData = '',
    array $replaceVars = array()
) {
    if (!function_exists('logModuleCall')) {
        return;
    }

    $safeModule = is_string($module) ? $module : 'pvewhmcs';
    $safeAction = is_string($action) ? $action : 'unknown';
    $safeRequest = pvewhmcs_redact_log_value($request);
    $safeResponse = pvewhmcs_redact_log_value($response);
    $safeProcessedData = pvewhmcs_redact_log_value($processedData);

    // Do not forward caller-supplied replacement values at all. They are often
    // secrets by definition. The wrapper has already redacted request/response
    // content recursively, so raw replacement material is unnecessary.
    $safeReplaceVars = array();

    logModuleCall(
        $safeModule,
        $safeAction,
        $safeRequest,
        $safeResponse,
        $safeProcessedData,
        $safeReplaceVars
    );
}

/**
 * Produce a deliberately small, non-secret summary of a PVE/API response.
 */
function pvewhmcs_safe_log_result($value)
{
    if (is_string($value)) {
        if (strpos($value, 'UPID:') === 0) {
            return 'PVE asynchronous task accepted';
        }

        return pvewhmcs_redact_log_value(substr($value, 0, 256));
    }

    if (is_array($value)) {
        return array(
            'type' => 'array',
            'keys' => array_slice(array_map('strval', array_keys($value)), 0, 25),
            'count' => count($value),
        );
    }

    if (is_object($value)) {
        return array(
            'type' => 'object',
            'class' => get_class($value),
        );
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if ($value === null) {
        return 'null';
    }

    return gettype($value);
}

/**
 * Ensure the database column can safely hold opaque WHMCS ciphertext.
 *
 * Older module installs used VARCHAR(255). Modern/future WHMCS ciphertext is
 * treated as opaque and must never be truncated, so migrate the singleton
 * secret column to TEXT before reads/writes.
 */
function pvewhmcs_ensure_secret_storage()
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $rows = Capsule::select(
        "SELECT DATA_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'mod_pvewhmcs'
           AND COLUMN_NAME = 'vnc_secret'
         LIMIT 1"
    );

    if (empty($rows) || empty($rows[0]->DATA_TYPE)) {
        throw new RuntimeException('Unable to verify VNC secret storage schema.');
    }

    $type = strtolower((string) $rows[0]->DATA_TYPE);
    if (!in_array($type, array('text', 'mediumtext', 'longtext'), true)) {
        Capsule::statement(
            "ALTER TABLE mod_pvewhmcs
             MODIFY vnc_secret TEXT NULL"
        );

        $verifyRows = Capsule::select(
            "SELECT DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'mod_pvewhmcs'
               AND COLUMN_NAME = 'vnc_secret'
             LIMIT 1"
        );

        $verifiedType = !empty($verifyRows) && !empty($verifyRows[0]->DATA_TYPE)
            ? strtolower((string) $verifyRows[0]->DATA_TYPE)
            : '';

        if (!in_array($verifiedType, array('text', 'mediumtext', 'longtext'), true)) {
            throw new RuntimeException('VNC secret storage schema migration did not complete safely.');
        }
    }

    $checked = true;
}

/**
 * Encrypt a module secret using WHMCS's supported EncryptPassword Local API.
 */
function pvewhmcs_encrypt_secret($plaintext)
{
    $plaintext = (string) $plaintext;

    if ($plaintext === '') {
        return '';
    }

    pvewhmcs_ensure_secret_storage();

    $result = localAPI('EncryptPassword', array(
        'password2' => $plaintext,
    ));

    if (
        !is_array($result)
        || !isset($result['result'], $result['password'])
        || $result['result'] !== 'success'
        || $result['password'] === ''
    ) {
        throw new RuntimeException('WHMCS failed to encrypt the module secret.');
    }

    $ciphertext = (string) $result['password'];

    $verify = localAPI('DecryptPassword', array(
        'password2' => $ciphertext,
    ));

    if (
        !is_array($verify)
        || !isset($verify['result'], $verify['password'])
        || $verify['result'] !== 'success'
        || !hash_equals($plaintext, (string) $verify['password'])
    ) {
        throw new RuntimeException('WHMCS secret encryption round-trip verification failed.');
    }

    return 'enc:' . $ciphertext;
}

/**
 * Decrypt a module secret only from the explicit WHMCS-encrypted storage
 * format. Plaintext is never accepted by the normal decryption path.
 */
function pvewhmcs_decrypt_secret($stored)
{
    $stored = (string) $stored;

    if ($stored === '') {
        return '';
    }

    if (strpos($stored, 'enc:') !== 0) {
        throw new RuntimeException('Refusing to use an unencrypted module secret.');
    }

    $ciphertext = substr($stored, 4);
    if ($ciphertext === '') {
        throw new RuntimeException('Encrypted module secret payload is empty.');
    }

    $result = localAPI('DecryptPassword', array(
        'password2' => $ciphertext,
    ));

    if (
        !is_array($result)
        || !isset($result['result'], $result['password'])
        || $result['result'] !== 'success'
        || $result['password'] === ''
    ) {
        throw new RuntimeException('WHMCS failed to decrypt the module secret.');
    }

    return (string) $result['password'];
}

/**
 * Return the VNC secret.
 *
 * Legacy plaintext is accepted only as migration input. It must first be
 * encrypted, transactionally persisted and read back exactly before the
 * operational secret is obtained by decrypting the persisted ciphertext.
 */
function pvewhmcs_get_vnc_secret()
{
    pvewhmcs_ensure_secret_storage();

    $stored = (string) Capsule::table('mod_pvewhmcs')
        ->where('id', '=', 1)
        ->value('vnc_secret');

    if ($stored === '') {
        return '';
    }

    if (strpos($stored, 'enc:') !== 0) {
        $legacyPlaintext = $stored;
        if ($legacyPlaintext === '') {
            return '';
        }

        $encrypted = pvewhmcs_encrypt_secret($legacyPlaintext);

        Capsule::connection()->transaction(function ($connection) use ($encrypted) {
            $connection->table('mod_pvewhmcs')
                ->where('id', '=', 1)
                ->update(array('vnc_secret' => $encrypted));

            $persisted = (string) $connection->table('mod_pvewhmcs')
                ->where('id', '=', 1)
                ->value('vnc_secret');

            if (
                strpos($persisted, 'enc:') !== 0
                || !hash_equals($encrypted, $persisted)
            ) {
                throw new RuntimeException('Encrypted VNC secret failed persistence integrity verification.');
            }
        });

        // Do not return the legacy plaintext directly. From this point onward
        // the operational path is identical to a normal encrypted read.
        $stored = (string) Capsule::table('mod_pvewhmcs')
            ->where('id', '=', 1)
            ->value('vnc_secret');

        if (strpos($stored, 'enc:') !== 0) {
            throw new RuntimeException('VNC secret migration did not produce encrypted storage.');
        }
    }

    return pvewhmcs_decrypt_secret($stored);
}

/**
 * Return whether an encrypted VNC secret is configured without exposing it.
 */
function pvewhmcs_has_vnc_secret()
{
    $stored = (string) Capsule::table('mod_pvewhmcs')
        ->where('id', '=', 1)
        ->value('vnc_secret');

    return $stored !== '';
}

/**
 * Return the address count for an IPv4 CIDR only when it is within the
 * configured safe import limit.
 *
 * The host-bit limit is checked before any bit shift, preventing oversized
 * prefixes such as /0 from reaching allocation/iterator code.
 */
function pvewhmcs_bounded_ipv4_cidr_size($prefix, $maxAddresses = 4096)
{
    if (is_string($prefix) && ctype_digit($prefix)) {
        $prefix = (int) $prefix;
    }

    if (!is_int($prefix) || $prefix < 0 || $prefix > 32) {
        throw new InvalidArgumentException('Invalid IPv4 CIDR prefix.');
    }

    $maxAddresses = (int) $maxAddresses;
    if ($maxAddresses < 1 || $maxAddresses > 4096) {
        throw new InvalidArgumentException('Invalid IPv4 import limit.');
    }

    $hostBits = 32 - $prefix;

    // 2^12 = 4096. Reject before shifting or constructing a subnet.
    if ($hostBits > 12) {
        throw new InvalidArgumentException(
            'IPv4 import is limited to 4096 addresses. Use /20 or a smaller range.'
        );
    }

    $addressCount = 1 << $hostBits;

    if ($addressCount < 1 || $addressCount > $maxAddresses) {
        throw new InvalidArgumentException('IPv4 CIDR size is outside the safe import limit.');
    }

    return $addressCount;
}

/**
 * Compute the narrowest common DNS suffix that both the WHMCS host and PVE
 * host are allowed to share for the short-lived PVEAuthCookie.
 *
 * Returns a leading-dot cookie domain, e.g. ".example.com". IP literals are
 * rejected because secure cross-subdomain console routing requires DNS names
 * covered by valid TLS certificates.
 */
function pvewhmcs_console_cookie_domain($whmcsHost, $pveHost)
{
    $whmcsHost = strtolower(trim((string) $whmcsHost, ". \t\n\r\0\x0B"));
    $pveHost = strtolower(trim((string) $pveHost, ". \t\n\r\0\x0B"));

    if (
        $whmcsHost === ''
        || $pveHost === ''
        || filter_var($whmcsHost, FILTER_VALIDATE_IP)
        || filter_var($pveHost, FILTER_VALIDATE_IP)
    ) {
        return null;
    }

    $a = array_reverse(explode('.', $whmcsHost));
    $b = array_reverse(explode('.', $pveHost));
    $common = array();
    $limit = min(count($a), count($b));

    for ($i = 0; $i < $limit; $i++) {
        if ($a[$i] !== $b[$i]) {
            break;
        }
        $common[] = $a[$i];
    }

    // Require at least a registrable-looking suffix plus one additional label
    // when possible. Comparing both actual hostnames avoids the old "last two
    // labels" heuristic that broke on domains such as example.co.uk.
    if (count($common) < 2) {
        return null;
    }

    $domain = implode('.', array_reverse($common));

    // Refuse obvious public-suffix-only results.
    $knownTwoPartPublicSuffixes = array(
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk',
        'com.au', 'net.au', 'org.au',
        'co.nz', 'com.br', 'com.cn', 'co.jp',
    );

    if (in_array($domain, $knownTwoPartPublicSuffixes, true)) {
        return null;
    }

    return '.' . $domain;
}
