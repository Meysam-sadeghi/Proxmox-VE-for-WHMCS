<?php

/*
 * Shared security helpers for Proxmox VE for WHMCS.
 *
 * This file requires the WHMCS bootstrap to be loaded before use.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Encrypt a module secret using WHMCS's supported EncryptPassword Local API.
 */
function pvewhmcs_encrypt_secret($plaintext)
{
    $plaintext = (string) $plaintext;

    if ($plaintext === '') {
        return '';
    }

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

    return 'enc:' . $result['password'];
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
    $stored = (string) Capsule::table('mod_pvewhmcs')
        ->where('id', '=', 1)
        ->value('vnc_secret');

    if ($stored === '') {
        return '';
    }

    $plaintext = pvewhmcs_decrypt_secret($stored);

    if (strpos($stored, 'enc:') !== 0 && $plaintext !== '') {
        $encrypted = pvewhmcs_encrypt_secret($plaintext);
        Capsule::table('mod_pvewhmcs')
            ->where('id', '=', 1)
            ->update(array('vnc_secret' => $encrypted));
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
