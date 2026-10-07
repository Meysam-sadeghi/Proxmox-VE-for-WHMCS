<?php

/*
 * Shared security helpers for Proxmox VE for WHMCS.
 *
 * This file requires the WHMCS bootstrap to be loaded before use.
 */

use Illuminate\Database\Capsule\Manager as Capsule;


/**
 * Validate a release-version string received from an external update source.
 *
 * Returns a normalized semantic-looking version string or null. External
 * update responses are never trusted as executable/configuration content.
 */
function pvewhmcs_validate_release_version($value)
{
    $value = trim((string) $value);

    if (
        $value === ''
        || strlen($value) > 64
        || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9._-]+)?$/', $value)
    ) {
        return null;
    }

    return $value;
}


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
 * Decrypt an encrypted module secret.
 *
 * Legacy plaintext values are accepted temporarily so existing installations
 * can migrate without losing console access. Callers may persist the encrypted
 * replacement returned by pvewhmcs_get_vnc_secret().
 */
function pvewhmcs_decrypt_secret($stored)
{
    $stored = (string) $stored;

    if ($stored === '') {
        return '';
    }

    if (strpos($stored, 'enc:') !== 0) {
        return $stored;
    }

    $ciphertext = substr($stored, 4);
    $result = localAPI('DecryptPassword', array(
        'password2' => $ciphertext,
    ));

    if (
        !is_array($result)
        || !isset($result['result'], $result['password'])
        || $result['result'] !== 'success'
    ) {
        throw new RuntimeException('WHMCS failed to decrypt the module secret.');
    }

    return (string) $result['password'];
}

/**
 * Return the VNC secret, automatically migrating legacy plaintext storage to
 * WHMCS-encrypted storage on first successful read.
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

    $plaintext = pvewhmcs_decrypt_secret($stored);

    if (strpos($stored, 'enc:') !== 0 && $plaintext !== '') {
        $encrypted = pvewhmcs_encrypt_secret($plaintext);

        Capsule::connection()->transaction(function ($connection) use ($encrypted) {
            $connection->table('mod_pvewhmcs')
                ->where('id', '=', 1)
                ->update(array('vnc_secret' => $encrypted));

            $persisted = (string) $connection->table('mod_pvewhmcs')
                ->where('id', '=', 1)
                ->value('vnc_secret');

            if (!hash_equals($encrypted, $persisted)) {
                throw new RuntimeException('Encrypted VNC secret failed persistence integrity verification.');
            }
        });
    }

    return $plaintext;
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
