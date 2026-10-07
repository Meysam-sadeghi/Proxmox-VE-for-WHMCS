<?php

/*
 * Proxmox VE for WHMCS - Client Reinstall/Rebuild support for Proxmox VE 9+
 *
 * This file intentionally keeps destructive reinstall logic isolated from the
 * main provisioning module. Proxmox VE 9 still exposes its REST API through
 * /api2/json; compatibility is verified at runtime using /version.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Escape HTML output.
 */
function pvewhmcs_reinstall_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Return product custom-field select options as value => label.
 *
 * WHMCS select fields store options in a comma-separated fieldoptions string,
 * and support the "value|Display Label" convention used by this module.
 */
function pvewhmcs_reinstall_product_field_options($pid, $fieldName)
{
    $options = array();

    try {
        $fields = Capsule::table('tblcustomfields')
            ->where('type', '=', 'product')
            ->where('relid', '=', (int) $pid)
            ->get();

        foreach ($fields as $field) {
            $rawName = trim((string) $field->fieldname);
            $baseName = trim(explode('|', $rawName, 2)[0]);

            if (strcasecmp($baseName, $fieldName) !== 0) {
                continue;
            }

            $rawOptions = trim((string) $field->fieldoptions);
            if ($rawOptions === '') {
                continue;
            }

            foreach (explode(',', $rawOptions) as $rawOption) {
                $rawOption = trim($rawOption);
                if ($rawOption === '') {
                    continue;
                }

                $parts = explode('|', $rawOption, 2);
                $value = trim($parts[0]);
                $label = isset($parts[1]) && trim($parts[1]) !== ''
                    ? trim($parts[1])
                    : $value;

                if ($value !== '') {
                    $options[$value] = $label;
                }
            }
        }
    } catch (\Throwable $e) {
        // The caller can still fall back to the currently selected field value
        // or addon template table. Do not expose DB internals to the client.
    }

    return $options;
}

/**
 * Validate a reinstall image identifier before it can enter the allowlist.
 */
function pvewhmcs_reinstall_valid_image_value($guestType, $value)
{
    $value = trim((string) $value);

    if ($guestType === 'qemu') {
        if (!ctype_digit($value)) {
            return false;
        }

        $id = (int) $value;
        return $id >= 100 && $id <= 999999999;
    }

    if ($guestType === 'lxc') {
        // Proxmox container-template volume IDs use STORAGE:vztmpl/FILENAME.
        return (bool) preg_match(
            '#^[A-Za-z0-9_.-]+:vztmpl/[A-Za-z0-9_.+@-]+\.(?:tar(?:\.(?:gz|xz|zst))?|tgz)$#',
            $value
        );
    }

    return false;
}

/**
 * Build the customer-visible reinstall allowlist.
 *
 * Priority:
 *   1. The product's existing KVMTemplate/Template WHMCS custom field options.
 *   2. The service's currently selected template value.
 *   3. The addon template catalog, when populated.
 */
function pvewhmcs_reinstall_allowed_images(array $params, $guest)
{
    $guestType = (string) $guest->vtype;
    $fieldName = $guestType === 'qemu' ? 'KVMTemplate' : 'Template';
    $options = pvewhmcs_reinstall_product_field_options((int) $params['pid'], $fieldName);

    $current = '';
    if (isset($params['customfields'][$fieldName])) {
        $current = trim((string) $params['customfields'][$fieldName]);
    }

    if (
        $current !== ''
        && pvewhmcs_reinstall_valid_image_value($guestType, $current)
        && !isset($options[$current])
    ) {
        $options[$current] = 'Current OS / current template';
    }

    // Reuse the addon's template catalog when the deployment has populated it.
    try {
        $rows = Capsule::table('mod_pvewhmcs_templates')->get();

        foreach ($rows as $row) {
            $rowGuest = strtolower(trim((string) $row->guest));

            if ($guestType === 'qemu' && in_array($rowGuest, array('vm', 'qemu'), true)) {
                $value = trim((string) $row->tpl_id);
                if (
                    pvewhmcs_reinstall_valid_image_value('qemu', $value)
                    && !isset($options[$value])
                ) {
                    $options[$value] = trim((string) $row->title) !== ''
                        ? (string) $row->title
                        : 'QEMU Template ' . $value;
                }
            }

            if ($guestType === 'lxc' && in_array($rowGuest, array('ct', 'lxc'), true)) {
                $value = trim((string) $row->template);
                if (
                    pvewhmcs_reinstall_valid_image_value('lxc', $value)
                    && !isset($options[$value])
                ) {
                    $options[$value] = trim((string) $row->title) !== ''
                        ? (string) $row->title
                        : $value;
                }
            }
        }
    } catch (\Throwable $e) {
        // Optional fallback only.
    }

    // Filter again so a malformed DB/custom-field value can never become an API target.
    foreach ($options as $value => $label) {
        if (!pvewhmcs_reinstall_valid_image_value($guestType, $value)) {
            unset($options[$value]);
        }
    }

    return $options;
}

/**
 * Find the WHMCS custom field ID used to store the selected OS/template.
 */
function pvewhmcs_reinstall_product_field_id($pid, $fieldName)
{
    try {
        $fields = Capsule::table('tblcustomfields')
            ->where('type', '=', 'product')
            ->where('relid', '=', (int) $pid)
            ->get();

        foreach ($fields as $field) {
            $rawName = trim((string) $field->fieldname);
            $baseName = trim(explode('|', $rawName, 2)[0]);

            if (strcasecmp($baseName, $fieldName) === 0) {
                return (int) $field->id;
            }
        }
    } catch (\Throwable $e) {
        return null;
    }

    return null;
}

/**
 * Update the service's selected template custom-field value after success.
 */
function pvewhmcs_reinstall_store_selected_image(array $params, $guestType, $selectedImage)
{
    $fieldName = $guestType === 'qemu' ? 'KVMTemplate' : 'Template';
    $fieldId = pvewhmcs_reinstall_product_field_id((int) $params['pid'], $fieldName);

    if (!$fieldId) {
        return;
    }

    $existing = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', '=', $fieldId)
        ->where('relid', '=', (int) $params['serviceid'])
        ->first();

    if ($existing) {
        Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', '=', $fieldId)
            ->where('relid', '=', (int) $params['serviceid'])
            ->update(array('value' => (string) $selectedImage));
    } else {
        Capsule::table('tblcustomfieldsvalues')->insert(array(
            'fieldid' => $fieldId,
            'relid' => (int) $params['serviceid'],
            'value' => (string) $selectedImage,
        ));
    }
}

/**
 * Create a one-time, per-service CSRF token for the destructive action.
 */
function pvewhmcs_reinstall_issue_nonce($serviceId)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new Exception('Unable to initialize secure reinstall session.');
    }

    if (!isset($_SESSION['pvewhmcs_reinstall_nonce'])) {
        $_SESSION['pvewhmcs_reinstall_nonce'] = array();
    }

    $nonce = bin2hex(random_bytes(32));
    $_SESSION['pvewhmcs_reinstall_nonce'][(int) $serviceId] = $nonce;

    return $nonce;
}

/**
 * Consume and validate a one-time reinstall CSRF token.
 */
function pvewhmcs_reinstall_consume_nonce($serviceId, $provided)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $serviceId = (int) $serviceId;
    $expected = isset($_SESSION['pvewhmcs_reinstall_nonce'][$serviceId])
        ? (string) $_SESSION['pvewhmcs_reinstall_nonce'][$serviceId]
        : '';

    unset($_SESSION['pvewhmcs_reinstall_nonce'][$serviceId]);

    return $expected !== ''
        && is_string($provided)
        && hash_equals($expected, $provided);
}

/**
 * Render the reinstall confirmation UI.
 */
function pvewhmcs_reinstall_render_form(array $params, $guest, array $options)
{
    if (empty($options)) {
        return '<div class="alert alert-warning">'
            . '<strong>Reinstall is not configured for this service.</strong><br>'
            . 'For QEMU, configure allowed <code>KVMTemplate</code> options. '
            . 'For LXC, configure allowed <code>Template</code> options. '
            . 'Only allowlisted templates are accepted.'
            . '</div>';
    }

    $nonce = pvewhmcs_reinstall_issue_nonce((int) $params['serviceid']);
    $html = '';

    $html .= '<div class="panel panel-danger">';
    $html .= '<div class="panel-heading"><strong>Reinstall Operating System</strong></div>';
    $html .= '<div class="panel-body">';
    $html .= '<div class="alert alert-danger">'
        . '<strong>Warning:</strong> Reinstall permanently replaces the current operating system and its disk data. '
        . 'Back up anything you need before continuing.'
        . '</div>';
    $html .= '<p>The replacement guest is prepared first using the Proxmox VE 9 API. '
        . 'The current guest is stopped only after the replacement has been created successfully.</p>';
    $html .= '<form method="post" action="">';
    $html .= '<input type="hidden" name="pvewhmcs_reinstall_action" value="execute">';
    $html .= '<input type="hidden" name="pvewhmcs_reinstall_nonce" value="' . pvewhmcs_reinstall_e($nonce) . '">';

    $html .= '<div class="form-group">';
    $html .= '<label for="pvewhmcs_reinstall_image">Operating System / Template</label>';
    $html .= '<select class="form-control" id="pvewhmcs_reinstall_image" name="pvewhmcs_reinstall_image" required>';
    $html .= '<option value="">Select an operating system...</option>';

    foreach ($options as $value => $label) {
        $html .= '<option value="' . pvewhmcs_reinstall_e($value) . '">'
            . pvewhmcs_reinstall_e($label)
            . '</option>';
    }

    $html .= '</select>';
    $html .= '</div>';

    $html .= '<div class="form-group">';
    $html .= '<label for="pvewhmcs_reinstall_phrase">Type <code>REINSTALL</code> to confirm</label>';
    $html .= '<input class="form-control" type="text" id="pvewhmcs_reinstall_phrase" '
        . 'name="pvewhmcs_reinstall_phrase" autocomplete="off" required>';
    $html .= '</div>';

    $html .= '<div class="checkbox"><label>';
    $html .= '<input type="checkbox" name="pvewhmcs_reinstall_confirm" value="yes" required> '
        . 'I understand that the current OS and disk data will be destroyed.';
    $html .= '</label></div>';

    $html .= '<button type="submit" class="btn btn-danger">'
        . '<i class="fa fa-refresh"></i> Reinstall Server'
        . '</button>';
    $html .= '</form>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

/**
 * Establish a PVE API connection using the service-assigned WHMCS server.
 */
function pvewhmcs_reinstall_connect(array $params)
{
    $service = Capsule::table('tblhosting')->find((int) $params['serviceid']);
    if (!$service) {
        throw new Exception('Unable to find the WHMCS service.');
    }

    $server = Capsule::table('tblservers')->where('id', '=', $service->server)->first();
    if (!$server) {
        throw new Exception('Unable to find the Proxmox server assigned to this service.');
    }

    $decrypted = localAPI('DecryptPassword', array(
        'password2' => $server->password,
    ));

    if (!isset($decrypted['password']) || $decrypted['password'] === '') {
        throw new Exception('Unable to decrypt the Proxmox API credential.');
    }

    $api = new PVE2_API(
        $server->ipaddress,
        $server->username,
        'pam',
        $decrypted['password'],
        $server->port
    );

    if (!$api->login()) {
        throw new Exception('Unable to authenticate to Proxmox VE.');
    }

    $version = $api->get_version();
    if (!is_string($version) || !preg_match('/^(\d+)(?:\.|$)/', $version, $matches)) {
        throw new Exception('Unable to determine the Proxmox VE version.');
    }

    if ((int) $matches[1] < 9) {
        throw new Exception(
            'Client reinstall requires Proxmox VE 9 or newer. Detected version: ' . $version
        );
    }

    return $api;
}

/**
 * Wait for a Proxmox asynchronous UPID task to finish.
 */
function pvewhmcs_reinstall_wait_task(PVE2_API $api, $node, $upid, $timeoutSeconds = 600)
{
    if ($upid === true || $upid === null || $upid === '') {
        return true;
    }

    if (is_array($upid) && isset($upid['upid'])) {
        $upid = $upid['upid'];
    }

    if (!is_string($upid) || strpos($upid, 'UPID:') !== 0) {
        throw new Exception('Unexpected Proxmox task response.');
    }

    $deadline = time() + (int) $timeoutSeconds;

    while (time() <= $deadline) {
        $status = $api->get('/nodes/' . $node . '/tasks/' . $upid . '/status');

        if (
            is_array($status)
            && isset($status['status'])
            && $status['status'] === 'stopped'
        ) {
            if (isset($status['exitstatus']) && $status['exitstatus'] !== 'OK') {
                throw new Exception(
                    'Proxmox task failed: ' . (string) $status['exitstatus']
                );
            }

            return true;
        }

        sleep(2);
    }

    throw new Exception('Timed out waiting for the Proxmox task to complete.');
}

/**
 * Wait until a guest reaches a desired runtime state.
 */
function pvewhmcs_reinstall_wait_guest_state(
    PVE2_API $api,
    $node,
    $guestType,
    $vmid,
    $wantedState,
    $timeoutSeconds = 60
) {
    $deadline = time() + (int) $timeoutSeconds;

    while (time() <= $deadline) {
        $status = $api->get(
            '/nodes/' . $node . '/' . $guestType . '/' . (int) $vmid . '/status/current'
        );

        if (isset($status['status']) && $status['status'] === $wantedState) {
            return true;
        }

        sleep(2);
    }

    throw new Exception(
        'Guest did not reach expected state "' . $wantedState . '" in time.'
    );
}

/**
 * Generate a strong service/root password for the rebuilt guest.
 */
function pvewhmcs_reinstall_generate_password($length = 20)
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $symbols = '!@#%+=_-';
    $all = $upper . $lower . $digits . $symbols;

    $chars = array(
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)],
    );

    while (count($chars) < $length) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }

    // Fisher-Yates using random_int rather than non-cryptographic shuffle().
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $chars[$i];
        $chars[$i] = $chars[$j];
        $chars[$j] = $tmp;
    }

    return implode('', $chars);
}

/**
 * Acquire a short-lived per-service MySQL advisory lock.
 */
function pvewhmcs_reinstall_acquire_lock($serviceId)
{
    $name = 'pvewhmcs_reinstall_' . (int) $serviceId;
    $rows = Capsule::select('SELECT GET_LOCK(?, 0) AS acquired', array($name));

    return !empty($rows) && (int) $rows[0]->acquired === 1;
}

/**
 * Release the per-service advisory lock.
 */
function pvewhmcs_reinstall_release_lock($serviceId)
{
    $name = 'pvewhmcs_reinstall_' . (int) $serviceId;

    try {
        Capsule::select('SELECT RELEASE_LOCK(?) AS released', array($name));
    } catch (\Throwable $e) {
        // Connection teardown will also release MySQL advisory locks.
    }
}

/**
 * Pick a PVE VMID that is free both in the live cluster and in module mappings.
 */
function pvewhmcs_reinstall_next_vmid(PVE2_API $api)
{
    $start = (int) Capsule::table('mod_pvewhmcs')->where('id', '=', 1)->value('start_vmid');

    for ($i = 0; $i < 1000; $i++) {
        $candidate = pvewhmcs_find_next_available_vmid($api, '', $start + $i);

        $mapped = Capsule::table('mod_pvewhmcs_vms')
            ->where('vmid', '=', (int) $candidate)
            ->exists();

        if (!$mapped) {
            return (int) $candidate;
        }

        $start = (int) $candidate;
    }

    throw new Exception('Unable to find a VMID that is free in both PVE and WHMCS mappings.');
}

/**
 * Locate a downloaded LXC template on a PVE 9 node.
 */
function pvewhmcs_reinstall_find_lxc_template_node(PVE2_API $api, $volid)
{
    $parts = explode(':', (string) $volid, 2);
    if (count($parts) !== 2) {
        throw new Exception('Invalid LXC template volume ID.');
    }

    $storage = $parts[0];

    foreach ($api->get_node_list() as $node) {
        try {
            $content = $api->get(
                '/nodes/' . $node . '/storage/' . rawurlencode($storage) . '/content?content=vztmpl'
            );

            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $item) {
                if (isset($item['volid']) && (string) $item['volid'] === (string) $volid) {
                    return $node;
                }
            }
        } catch (\Throwable $e) {
            // Storage may not exist on every node. Continue searching the cluster.
        }
    }

    throw new Exception('Selected LXC template was not found on any Proxmox node.');
}

/**
 * Ensure a QEMU reinstall source is a real Cloud-Init PVE template.
 */
function pvewhmcs_reinstall_validate_qemu_template(PVE2_API $api, $templateVmid)
{
    $templateNode = pvewhmcs_find_node_by_vmid($api, (int) $templateVmid);
    $config = $api->get(
        '/nodes/' . $templateNode . '/qemu/' . (int) $templateVmid . '/config'
    );

    if (empty($config['template'])) {
        throw new Exception('Selected QEMU source is not marked as a Proxmox template.');
    }

    $hasCloudInit = false;
    foreach ($config as $value) {
        if (is_string($value) && stripos($value, 'cloudinit') !== false) {
            $hasCloudInit = true;
            break;
        }
    }

    if (!$hasCloudInit) {
        throw new Exception(
            'Selected QEMU template has no Cloud-Init drive. Automated reinstall requires a Cloud-Init template.'
        );
    }

    return $templateNode;
}

/**
 * Stop a guest only when it currently exists and is running.
 *
 * Returns true when the guest had been running before the stop.
 */
function pvewhmcs_reinstall_stop_old_guest(PVE2_API $api, $node, $guestType, $vmid)
{
    if (!$node) {
        return false;
    }

    $status = $api->get(
        '/nodes/' . $node . '/' . $guestType . '/' . (int) $vmid . '/status/current'
    );

    $wasRunning = isset($status['status']) && $status['status'] !== 'stopped';

    if ($wasRunning) {
        $upid = $api->post(
            '/nodes/' . $node . '/' . $guestType . '/' . (int) $vmid . '/status/stop',
            array()
        );
        pvewhmcs_reinstall_wait_task($api, $node, $upid, 120);
        pvewhmcs_reinstall_wait_guest_state(
            $api,
            $node,
            $guestType,
            (int) $vmid,
            'stopped',
            60
        );
    }

    return $wasRunning;
}

/**
 * Destroy a guest after it has been replaced.
 */
function pvewhmcs_reinstall_destroy_guest(PVE2_API $api, $node, $guestType, $vmid)
{
    if (!$node) {
        return;
    }

    $upid = $api->delete(
        '/nodes/' . $node . '/' . $guestType . '/' . (int) $vmid
    );

    pvewhmcs_reinstall_wait_task($api, $node, $upid, 300);
}

/**
 * Build plan-derived QEMU settings for the cloned replacement.
 */
function pvewhmcs_reinstall_qemu_tweaks($plan, $guest, $password)
{
    $settings = array(
        'memory' => (int) $plan->memory,
        'ostype' => (string) $plan->ostype,
        'sockets' => (int) $plan->cpus,
        'cores' => (int) $plan->cores,
        'cpu' => (string) $plan->cpuemu,
        'kvm' => (int) $plan->kvm,
        'onboot' => (int) $plan->onboot,
        'nameserver' => '208.67.222.222 64.6.64.6',
        'ipconfig0' => 'ip=' . $guest->ipaddress . '/' . mask2cidr($guest->subnetmask)
            . ',gw=' . $guest->gateway,
        'cipassword' => $password,
    );

    if (!empty($plan->ipv6) && $plan->ipv6 !== '0') {
        switch ($plan->ipv6) {
            case 'auto':
                $settings['nameserver'] .= ' 2620:119:35::35 2620:74:1b::1:1';
                $settings['ipconfig1'] = 'ip6=auto';
                break;
            case 'dhcp':
                $settings['nameserver'] .= ' 2620:119:35::35 2620:74:1b::1:1';
                $settings['ipconfig1'] = 'ip6=dhcp';
                break;
        }
    }

    return $settings;
}

/**
 * Build plan-derived LXC settings for the replacement container.
 */
function pvewhmcs_reinstall_lxc_settings(array $params, $plan, $guest, $template, $newVmid, $password)
{
    $settings = array(
        'vmid' => (int) $newVmid,
        'ostemplate' => (string) $template,
        'hostname' => 'vps' . (int) $params['serviceid'] . '-cus' . (int) $params['userid'],
        'swap' => (int) $plan->swap,
        'rootfs' => $plan->storage . ':' . (int) $plan->disk,
        'bwlimit' => $plan->diskio,
        'nameserver' => '208.67.222.222 64.6.64.6',
        'net0' => 'name=eth0,bridge=' . $plan->bridge . $plan->vmbr
            . ',ip=' . $guest->ipaddress . '/' . mask2cidr($guest->subnetmask)
            . ',gw=' . $guest->gateway
            . ',rate=' . $plan->netrate,
        'onboot' => (int) $plan->onboot,
        'unprivileged' => (int) $plan->unpriv,
        'password' => $password,
        'memory' => (int) $plan->memory,
        'cpuunits' => (int) $plan->cpuunits,
        'cpulimit' => (int) $plan->cpulimit,
    );

    if (!empty($plan->cores)) {
        $settings['cores'] = (int) $plan->cores;
    }

    if (!empty($plan->vlanid)) {
        $settings['net0'] .= ',tag=' . (int) $plan->vlanid;
    }

    if (!empty($plan->ipv6) && $plan->ipv6 !== '0') {
        $settings['net1'] = 'name=eth1,bridge=' . $plan->bridge . $plan->vmbr
            . ',rate=' . $plan->netrate;

        switch ($plan->ipv6) {
            case 'auto':
                $settings['nameserver'] .= ' 2620:119:35::35 2620:74:1b::1:1';
                $settings['net1'] .= ',ip6=auto';
                break;
            case 'dhcp':
                $settings['nameserver'] .= ' 2620:119:35::35 2620:74:1b::1:1';
                $settings['net1'] .= ',ip6=dhcp';
                break;
        }

        if (!empty($plan->vlanid)) {
            $settings['net1'] .= ',tag=' . (int) $plan->vlanid;
        }
    }

    return $settings;
}

/**
 * Main WHMCS Client Area custom function.
 *
 * GET  -> render allowlisted OS/template selector.
 * POST -> validate nonce/ownership, stage replacement, cut over, update mapping.
 */
function pvewhmcs_Reinstall($params)
{
    @set_time_limit(600);

    $serviceId = (int) $params['serviceid'];
    $userId = isset($params['userid'])
        ? (int) $params['userid']
        : (int) ($params['clientsdetails']['userid'] ?? 0);

    $service = Capsule::table('tblhosting')
        ->where('id', '=', $serviceId)
        ->first();

    if (!$service || (int) $service->userid !== $userId) {
        return '<div class="alert alert-danger">Service ownership validation failed.</div>';
    }

    if ((string) $service->domainstatus !== 'Active') {
        return '<div class="alert alert-warning">Reinstall is available only for active services.</div>';
    }

    $guest = Capsule::table('mod_pvewhmcs_vms')
        ->where('id', '=', $serviceId)
        ->first();

    if (!$guest || (int) $guest->user_id !== $userId) {
        return '<div class="alert alert-danger">Unable to find a valid guest mapping for this service.</div>';
    }

    if (!in_array($guest->vtype, array('qemu', 'lxc'), true)) {
        return '<div class="alert alert-danger">Unsupported guest type.</div>';
    }

    $duplicateOwner = Capsule::table('mod_pvewhmcs_vms')
        ->where('vmid', '=', (int) $guest->vmid)
        ->where('id', '!=', $serviceId)
        ->first();

    if ($duplicateOwner) {
        return '<div class="alert alert-danger">'
            . 'Safety check failed: this VMID is mapped to another WHMCS service. '
            . 'No reinstall action was performed.'
            . '</div>';
    }

    $options = pvewhmcs_reinstall_allowed_images($params, $guest);

    if (
        $_SERVER['REQUEST_METHOD'] !== 'POST'
        || !isset($_POST['pvewhmcs_reinstall_action'])
        || $_POST['pvewhmcs_reinstall_action'] !== 'execute'
    ) {
        return pvewhmcs_reinstall_render_form($params, $guest, $options);
    }

    if (
        !pvewhmcs_reinstall_consume_nonce(
            $serviceId,
            isset($_POST['pvewhmcs_reinstall_nonce'])
                ? (string) $_POST['pvewhmcs_reinstall_nonce']
                : ''
        )
    ) {
        return '<div class="alert alert-danger">Reinstall request expired or failed CSRF validation. Please open Reinstall again.</div>';
    }

    if (
        !isset($_POST['pvewhmcs_reinstall_confirm'])
        || $_POST['pvewhmcs_reinstall_confirm'] !== 'yes'
        || !isset($_POST['pvewhmcs_reinstall_phrase'])
        || trim((string) $_POST['pvewhmcs_reinstall_phrase']) !== 'REINSTALL'
    ) {
        return '<div class="alert alert-danger">Reinstall confirmation was not completed. No changes were made.</div>';
    }

    $selectedImage = isset($_POST['pvewhmcs_reinstall_image'])
        ? trim((string) $_POST['pvewhmcs_reinstall_image'])
        : '';

    if (
        $selectedImage === ''
        || !isset($options[$selectedImage])
        || !pvewhmcs_reinstall_valid_image_value($guest->vtype, $selectedImage)
    ) {
        return '<div class="alert alert-danger">Invalid or non-allowlisted reinstall image. No changes were made.</div>';
    }

    if (!pvewhmcs_reinstall_acquire_lock($serviceId)) {
        return '<div class="alert alert-warning">A reinstall is already running for this service. Please do not submit it again.</div>';
    }

    $api = null;
    $newVmid = null;
    $newNode = null;
    $oldNode = null;
    $oldWasRunning = false;
    $replacementStarted = false;
    $newPassword = null;

    try {
        $api = pvewhmcs_reinstall_connect($params);

        $plan = Capsule::table('mod_pvewhmcs_plans')
            ->where('id', '=', $params['configoption1'])
            ->first();

        if (!$plan) {
            throw new Exception('The WHMCS product plan no longer exists.');
        }

        if (
            ($guest->vtype === 'qemu' && $plan->vmtype !== 'kvm')
            || ($guest->vtype === 'lxc' && $plan->vmtype === 'kvm')
        ) {
            throw new Exception('Service guest type does not match the assigned module plan.');
        }

        // The replacement receives a new VMID so the current service remains recoverable
        // until the new guest has been created successfully.
        $newVmid = pvewhmcs_reinstall_next_vmid($api);
        $newPassword = pvewhmcs_reinstall_generate_password();

        if ($guest->vtype === 'qemu') {
            if ((int) $selectedImage === (int) $guest->vmid) {
                throw new Exception('The current VM cannot be used as its own reinstall template.');
            }

            $newNode = pvewhmcs_reinstall_validate_qemu_template($api, (int) $selectedImage);

            $cloneParams = array(
                'newid' => (int) $newVmid,
                'full' => 1,
                'name' => 'vps' . $serviceId . '-cus' . $userId,
            );

            $cloneUpid = $api->post(
                '/nodes/' . $newNode . '/qemu/' . (int) $selectedImage . '/clone',
                $cloneParams
            );
            pvewhmcs_reinstall_wait_task($api, $newNode, $cloneUpid, 600);

            $api->put(
                '/nodes/' . $newNode . '/qemu/' . (int) $newVmid . '/config',
                pvewhmcs_reinstall_qemu_tweaks($plan, $guest, $newPassword)
            );
        } else {
            $newNode = pvewhmcs_reinstall_find_lxc_template_node($api, $selectedImage);

            $createUpid = $api->post(
                '/nodes/' . $newNode . '/lxc',
                pvewhmcs_reinstall_lxc_settings(
                    $params,
                    $plan,
                    $guest,
                    $selectedImage,
                    $newVmid,
                    $newPassword
                )
            );
            pvewhmcs_reinstall_wait_task($api, $newNode, $createUpid, 600);
        }

        // Resolve the old guest only after replacement creation succeeded.
        $oldNode = pvewhmcs_find_guest_node($api, $guest, $serviceId);

        // Cutover: stop old guest, then start the new guest with the same IP.
        $oldWasRunning = pvewhmcs_reinstall_stop_old_guest(
            $api,
            $oldNode,
            $guest->vtype,
            (int) $guest->vmid
        );

        try {
            $startUpid = $api->post(
                '/nodes/' . $newNode . '/' . $guest->vtype . '/' . (int) $newVmid . '/status/start',
                array()
            );
            pvewhmcs_reinstall_wait_task($api, $newNode, $startUpid, 180);
            pvewhmcs_reinstall_wait_guest_state(
                $api,
                $newNode,
                $guest->vtype,
                (int) $newVmid,
                'running',
                90
            );
            $replacementStarted = true;
        } catch (\Throwable $startError) {
            // Best-effort rollback while the old guest still exists.
            try {
                pvewhmcs_reinstall_destroy_guest(
                    $api,
                    $newNode,
                    $guest->vtype,
                    (int) $newVmid
                );
            } catch (\Throwable $cleanupError) {
                // Do not hide the original start failure.
            }

            if ($oldNode && $oldWasRunning) {
                try {
                    $restartUpid = $api->post(
                        '/nodes/' . $oldNode . '/' . $guest->vtype . '/' . (int) $guest->vmid . '/status/start',
                        array()
                    );
                    pvewhmcs_reinstall_wait_task($api, $oldNode, $restartUpid, 180);
                } catch (\Throwable $rollbackError) {
                    // Surface original error; Activity Log below will help support.
                }
            }

            throw new Exception('Replacement guest failed to start: ' . $startError->getMessage());
        }

        // Atomically move WHMCS mapping/custom-field state to the running replacement.
        Capsule::connection()->transaction(function () use (
            $serviceId,
            $guest,
            $newVmid,
            $params,
            $selectedImage
        ) {
            $updated = Capsule::table('mod_pvewhmcs_vms')
                ->where('id', '=', $serviceId)
                ->where('vmid', '=', (int) $guest->vmid)
                ->update(array(
                    'vmid' => (int) $newVmid,
                    'node_id' => null,
                ));

            if ($updated !== 1) {
                throw new Exception('Unable to atomically update the WHMCS-to-PVE guest mapping.');
            }

            pvewhmcs_reinstall_store_selected_image(
                $params,
                $guest->vtype,
                $selectedImage
            );
        });

        // Store the newly generated service password in WHMCS.
        $passwordUpdate = localAPI('UpdateClientProduct', array(
            'serviceid' => $serviceId,
            'servicepassword' => $newPassword,
        ));
        $passwordStored = isset($passwordUpdate['result'])
            && $passwordUpdate['result'] === 'success';

        $cleanupWarning = '';

        // Old VM/CT is now stopped and no longer mapped to the customer service.
        // Cleanup failure is non-fatal: the replacement is already running.
        if ($oldNode) {
            try {
                pvewhmcs_reinstall_destroy_guest(
                    $api,
                    $oldNode,
                    $guest->vtype,
                    (int) $guest->vmid
                );
            } catch (\Throwable $cleanupError) {
                $cleanupWarning = ' The previous guest could not be deleted automatically and remains stopped; support should remove VMID '
                    . (int) $guest->vmid . '.';
            }
        }

        if (function_exists('logActivity')) {
            logActivity(
                'PVEWHMCS reinstall completed for Service #' . $serviceId
                . ': old VMID ' . (int) $guest->vmid
                . ', new VMID ' . (int) $newVmid
                . ', image ' . $selectedImage
                . ($cleanupWarning !== '' ? ' (old guest cleanup pending)' : '')
            );
        }

        $html = '<div class="alert alert-success">';
        $html .= '<strong>Reinstall completed successfully.</strong><br>';
        $html .= 'New VMID: <code>' . pvewhmcs_reinstall_e($newVmid) . '</code><br>';
        $html .= 'New password: <code style="user-select:all;">'
            . pvewhmcs_reinstall_e($newPassword)
            . '</code><br>';
        $html .= '<small>Copy this password now. It is not written to module logs.</small>';

        if (!$passwordStored) {
            $html .= '<br><strong>Warning:</strong> WHMCS could not save the new password to the service record. '
                . 'Keep the password shown above and contact support.';
        }

        if ($cleanupWarning !== '') {
            $html .= '<br><strong>Cleanup warning:</strong>' . pvewhmcs_reinstall_e($cleanupWarning);
        }

        $html .= '</div>';

        return $html;
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity(
                'PVEWHMCS reinstall failed for Service #' . $serviceId
                . ': ' . substr($e->getMessage(), 0, 500)
            );
        }

        // If replacement creation succeeded but cutover failed before mapping moved,
        // attempt to remove the unused replacement.
        if (
            $api instanceof PVE2_API
            && $newVmid
            && $newNode
            && !$replacementStarted
        ) {
            try {
                pvewhmcs_reinstall_destroy_guest(
                    $api,
                    $newNode,
                    $guest->vtype,
                    (int) $newVmid
                );
            } catch (\Throwable $cleanupError) {
                // Leave details to Activity Log/support; never expose credentials.
            }
        }

        return '<div class="alert alert-danger">'
            . '<strong>Reinstall failed.</strong> '
            . pvewhmcs_reinstall_e($e->getMessage())
            . '<br>No password or API credential was logged by the reinstall feature.'
            . '</div>';
    } finally {
        pvewhmcs_reinstall_release_lock($serviceId);
    }
}
