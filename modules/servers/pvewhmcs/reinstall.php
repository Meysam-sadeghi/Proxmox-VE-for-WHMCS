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
 * Build the WHMCS custom client-area page for reinstall.
 *
 * Returning templatefile + vars is the supported WHMCS provisioning-module
 * contract for a custom client action. This avoids depending on a theme to
 * interpret raw HTML returned from the module function.
 */
function pvewhmcs_reinstall_render_page(
    array $params,
    array $options = array(),
    $messageType = '',
    $message = '',
    $showForm = true,
    $newVmid = null,
    $newPassword = null,
    $passwordStored = true,
    $cleanupWarning = ''
) {
    $nonce = '';
    if ($showForm && !empty($options)) {
        $nonce = pvewhmcs_reinstall_issue_nonce((int) $params['serviceid']);
    }

    return array(
        'templatefile' => 'reinstall',
        'vars' => array(
            'reinstall_service_id' => (int) $params['serviceid'],
            'reinstall_options' => $options,
            'reinstall_nonce' => $nonce,
            'reinstall_show_form' => (bool) ($showForm && !empty($options)),
            'reinstall_message_type' => (string) $messageType,
            'reinstall_message' => (string) $message,
            'reinstall_new_vmid' => $newVmid !== null ? (int) $newVmid : null,
            'reinstall_new_password' => $newPassword,
            'reinstall_password_stored' => (bool) $passwordStored,
            'reinstall_cleanup_warning' => (string) $cleanupWarning,
        ),
    );
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

    $apiHost = !empty($server->hostname) ? $server->hostname : $server->ipaddress;

    $api = new PVE2_API(
        $apiHost,
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
    $probeStart = (int) Capsule::table('mod_pvewhmcs')
        ->where('id', '=', 1)
        ->value('start_vmid');

    for ($i = 0; $i < 1000; $i++) {
        $candidate = pvewhmcs_find_next_available_vmid($api, '', $probeStart);

        $mapped = Capsule::table('mod_pvewhmcs_vms')
            ->where('vmid', '=', (int) $candidate)
            ->exists();

        if (!$mapped) {
            return (int) $candidate;
        }

        // A stale WHMCS mapping can reference a VMID that is already free in PVE.
        // Move beyond it before asking the cluster for the next candidate again.
        $probeStart = (int) $candidate + 1;
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

    // Apply the WHMCS plan network policy instead of trusting whatever NIC
    // configuration happened to be baked into the selected template.
    if ($plan->netmode !== 'none' && !empty($plan->netmodel)) {
        $net0 = (string) $plan->netmodel;

        if ($plan->netmode === 'bridge') {
            $net0 .= ',bridge=' . $plan->bridge . $plan->vmbr;
        }

        $net0 .= ',firewall=' . (int) $plan->firewall;

        if (!empty($plan->netrate)) {
            $net0 .= ',rate=' . (int) $plan->netrate;
        }

        if (!empty($plan->vlanid)) {
            $net0 .= ',tag=' . (int) $plan->vlanid;
        }

        $settings['net0'] = $net0;
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
        return pvewhmcs_reinstall_render_page($params, array(), 'danger', 'Service ownership validation failed.', false);
    }

    if ((string) $service->domainstatus !== 'Active') {
        return pvewhmcs_reinstall_render_page($params, array(), 'warning', 'Reinstall is available only for active services.', false);
    }

    $guest = Capsule::table('mod_pvewhmcs_vms')
        ->where('id', '=', $serviceId)
        ->first();

    if (!$guest || (int) $guest->user_id !== $userId) {
        return pvewhmcs_reinstall_render_page($params, array(), 'danger', 'Unable to find a valid guest mapping for this service.', false);
    }

    if (!in_array($guest->vtype, array('qemu', 'lxc'), true)) {
        return pvewhmcs_reinstall_render_page($params, array(), 'danger', 'Unsupported guest type.', false);
    }

    $duplicateOwner = Capsule::table('mod_pvewhmcs_vms')
        ->where('vmid', '=', (int) $guest->vmid)
        ->where('id', '!=', $serviceId)
        ->first();

    if ($duplicateOwner) {
        return pvewhmcs_reinstall_render_page(
            $params,
            array(),
            'danger',
            'Safety check failed: this VMID is mapped to another WHMCS service. No reinstall action was performed.',
            false
        );
    }

    $options = pvewhmcs_reinstall_allowed_images($params, $guest);

    if (
        $_SERVER['REQUEST_METHOD'] !== 'POST'
        || !isset($_POST['pvewhmcs_reinstall_action'])
        || $_POST['pvewhmcs_reinstall_action'] !== 'execute'
    ) {
        if (empty($options)) {
            return pvewhmcs_reinstall_render_page(
                $params,
                array(),
                'warning',
                'Reinstall is not configured for this service. Configure allowlisted KVMTemplate options for QEMU or Template options for LXC.',
                false
            );
        }

        return pvewhmcs_reinstall_render_page($params, $options);
    }

    // WHMCS custom module actions are not automatically CSRF-validated.
    // Require the standard WHMCS client-area token in addition to our one-time
    // per-service nonce. check_token() reads the posted "token" value.
    if (function_exists('check_token')) {
        check_token();
    }

    if (
        !pvewhmcs_reinstall_consume_nonce(
            $serviceId,
            isset($_POST['pvewhmcs_reinstall_nonce'])
                ? (string) $_POST['pvewhmcs_reinstall_nonce']
                : ''
        )
    ) {
        return pvewhmcs_reinstall_render_page(
            $params,
            $options,
            'danger',
            'Reinstall request expired or failed CSRF validation. Please try again.'
        );
    }

    if (
        !isset($_POST['pvewhmcs_reinstall_confirm'])
        || $_POST['pvewhmcs_reinstall_confirm'] !== 'yes'
        || !isset($_POST['pvewhmcs_reinstall_phrase'])
        || trim((string) $_POST['pvewhmcs_reinstall_phrase']) !== 'REINSTALL'
    ) {
        return pvewhmcs_reinstall_render_page(
            $params,
            $options,
            'danger',
            'Reinstall confirmation was not completed. No changes were made.'
        );
    }

    $selectedImage = isset($_POST['pvewhmcs_reinstall_image'])
        ? trim((string) $_POST['pvewhmcs_reinstall_image'])
        : '';

    if (
        $selectedImage === ''
        || !isset($options[$selectedImage])
        || !pvewhmcs_reinstall_valid_image_value($guest->vtype, $selectedImage)
    ) {
        return pvewhmcs_reinstall_render_page(
            $params,
            $options,
            'danger',
            'Invalid or non-allowlisted reinstall image. No changes were made.'
        );
    }

    if (!pvewhmcs_reinstall_acquire_lock($serviceId)) {
        return pvewhmcs_reinstall_render_page(
            $params,
            $options,
            'warning',
            'A reinstall is already running for this service. Please do not submit it again.'
        );
    }

    $api = null;
    $newVmid = null;
    $newNode = null;
    $oldNode = null;
    $oldWasRunning = false;
    $replacementStarted = false;
    $mappingMoved = false;
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
        $mappingMoved = true;

        // Store the newly generated service password in WHMCS. A password-record
        // update failure must not roll back a successfully cut-over VM.
        $passwordStored = false;
        try {
            $passwordUpdate = localAPI('UpdateClientProduct', array(
                'serviceid' => $serviceId,
                'servicepassword' => $newPassword,
            ));
            $passwordStored = isset($passwordUpdate['result'])
                && $passwordUpdate['result'] === 'success';
        } catch (\Throwable $passwordError) {
            $passwordStored = false;
        }

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

        return pvewhmcs_reinstall_render_page(
            $params,
            $options,
            'success',
            'Reinstall completed successfully.',
            false,
            $newVmid,
            $newPassword,
            $passwordStored,
            $cleanupWarning
        );
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity(
                'PVEWHMCS reinstall failed for Service #' . $serviceId
                . ': ' . substr($e->getMessage(), 0, 500)
            );
        }

        // Before the WHMCS mapping is moved, the old guest is still authoritative.
        // Remove any staged replacement and restore the old runtime state so a
        // database/cutover failure cannot leave an untracked VM using the same IP.
        if (
            $api instanceof PVE2_API
            && $newVmid
            && $newNode
            && !$mappingMoved
        ) {
            try {
                if ($replacementStarted) {
                    pvewhmcs_reinstall_stop_old_guest(
                        $api,
                        $newNode,
                        $guest->vtype,
                        (int) $newVmid
                    );
                }

                pvewhmcs_reinstall_destroy_guest(
                    $api,
                    $newNode,
                    $guest->vtype,
                    (int) $newVmid
                );
            } catch (\Throwable $cleanupError) {
                // Leave details to Activity Log/support; never expose credentials.
            }

            if ($oldNode && $oldWasRunning) {
                try {
                    $restartUpid = $api->post(
                        '/nodes/' . $oldNode . '/' . $guest->vtype . '/' . (int) $guest->vmid . '/status/start',
                        array()
                    );
                    pvewhmcs_reinstall_wait_task($api, $oldNode, $restartUpid, 180);
                } catch (\Throwable $rollbackError) {
                    // Best effort: support can use Activity Log and PVE task history.
                }
            }
        }

        return pvewhmcs_reinstall_render_page(
            $params,
            $options,
            'danger',
            'Reinstall failed: ' . $e->getMessage() . ' No password or API credential was logged by the reinstall feature.'
        );
    } finally {
        pvewhmcs_reinstall_release_lock($serviceId);
    }
}
