<?php

/*
	Proxmox VE for WHMCS - Addon/Server Modules for WHMCS (& PVE)
	https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/
	File: /modules/servers/pvewhmcs/pvewhmcs.php (PVE Work)

	Copyright (C) The Network Crew Pty Ltd (TNC) & Co.
	For other Contributors to PVEWHMCS, see CONTRIBUTORS.md

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

// DEP: Proxmox API Class - make sure we can access via PVE via API
if (file_exists('../modules/addons/pvewhmcs/proxmox.php'))
	require_once('../modules/addons/pvewhmcs/proxmox.php');
else
	require_once(ROOTDIR . '/modules/addons/pvewhmcs/proxmox.php');

require_once(ROOTDIR . '/modules/addons/pvewhmcs/security.php');

// Client reinstall/rebuild workflow (Proxmox VE 9+)
require_once(__DIR__ . '/reinstall.php');

// Import SQL Connectivity (WHMCS)
use Illuminate\Database\Capsule\Manager as Capsule;

// Prepare to source Guest type
global $guest;

/**
 * Build a deliberately small, non-secret context for WHMCS module logging.
 *
 * Never pass the raw provisioning $params array to pvewhmcs_secure_log_module_call(): it can
 * contain PVE credentials and customer/root passwords.
 */
function pvewhmcs_safe_log_context(array $params) {
	return array(
		'serviceid' => isset($params['serviceid']) ? (int) $params['serviceid'] : null,
		'userid' => isset($params['userid'])
			? (int) $params['userid']
			: (isset($params['clientsdetails']['userid']) ? (int) $params['clientsdetails']['userid'] : null),
		'pid' => isset($params['pid']) ? (int) $params['pid'] : null,
		'serverid' => isset($params['serverid']) ? (int) $params['serverid'] : null,
	);
}

/**
 * Record internal client-action failure details server-side while returning a
 * stable public message that does not disclose VMID/node/API internals.
 */
function pvewhmcs_client_safe_error(array $params, $action, $detail = '') {
	pvewhmcs_secure_log_module_call(
		'pvewhmcs',
		(string) $action,
		pvewhmcs_safe_log_context($params),
		pvewhmcs_redact_log_value($detail)
	);

	return 'Unable to complete the requested server action. Please try again or contact support.';
}


/**
 * Execute a callback while holding a MySQL advisory lock.
 *
 * Advisory locks serialize only this module's critical allocation sections and
 * are released in finally even when provisioning throws.
 */
function pvewhmcs_with_advisory_lock($resource, $timeoutSeconds, callable $callback) {
	$lockName = 'pvewhmcs_' . substr(hash('sha256', (string) $resource), 0, 48);
	$rows = Capsule::select('SELECT GET_LOCK(?, ?) AS acquired', array(
		$lockName,
		max(0, (int) $timeoutSeconds),
	));

	if (empty($rows) || (int) $rows[0]->acquired !== 1) {
		throw new RuntimeException('Another provisioning operation is already using this resource. Please retry shortly.');
	}

	try {
		return $callback();
	} finally {
		try {
			Capsule::select('SELECT RELEASE_LOCK(?) AS released', array($lockName));
		} catch (\Throwable $e) {
			// MySQL also releases advisory locks when the DB connection closes.
		}
	}
}

/**
 * Atomically reserve one address from a module IPv4 pool.
 *
 * Existing service reservations are reused on retry when still valid.
 */
function pvewhmcs_reserve_pool_ip($poolId, $serviceId) {
	$poolId = (int) $poolId;
	$serviceId = (int) $serviceId;

	if ($poolId <= 0 || $serviceId <= 0) {
		throw new InvalidArgumentException('Invalid IP allocation context.');
	}

	return pvewhmcs_with_advisory_lock('ip_pool:' . $poolId, 15, function () use ($poolId, $serviceId) {
		$service = Capsule::table('tblhosting')->where('id', '=', $serviceId)->first();
		if (!$service) {
			throw new RuntimeException('Unable to find the WHMCS service during IP allocation.');
		}

		$currentIp = trim((string) $service->dedicatedip);
		if ($currentIp !== '') {
			$current = Capsule::table('mod_pvewhmcs_ip_addresses as i')
				->join('mod_pvewhmcs_ip_pools as p', 'i.pool_id', '=', 'p.id')
				->where('i.pool_id', '=', $poolId)
				->where('i.ipaddress', '=', $currentIp)
				->select('i.ipaddress', 'i.mask', 'p.gateway')
				->first();

			$usedByAnother = Capsule::table('tblhosting')
				->where('id', '!=', $serviceId)
				->whereIn('domainstatus', array('Active', 'Suspended', 'Completed', 'Pending'))
				->where('dedicatedip', '=', $currentIp)
				->exists();

			if ($current && !$usedByAnother) {
				return $current;
			}
		}

		$result = Capsule::select(
			'SELECT i.ipaddress, i.mask, p.gateway
			 FROM mod_pvewhmcs_ip_addresses i
			 INNER JOIN mod_pvewhmcs_ip_pools p ON (i.pool_id = p.id AND p.id = :pool_id)
			 WHERE i.ipaddress NOT IN (
				SELECT dedicatedip
				FROM tblhosting
				WHERE domainstatus IN ("Active", "Suspended", "Completed", "Pending")
				AND dedicatedip != ""
			 )
			 ORDER BY i.id ASC
			 LIMIT 1',
			array('pool_id' => $poolId)
		);

		if (empty($result)) {
			throw new RuntimeException('No free IP addresses available in the selected pool.');
		}

		$ip = $result[0];
		$updated = Capsule::table('tblhosting')
			->where('id', '=', $serviceId)
			->update(array('dedicatedip' => $ip->ipaddress));

		if ($updated < 0) {
			throw new RuntimeException('Unable to reserve the selected IP address.');
		}

		return $ip;
	});
}


// Fix the Server Test showing "Pvewhmcs" instead of pretty name
// ref: https://developers.whmcs.com/provisioning-modules/meta-data-params/
function pvewhmcs_MetaData() {
    return array(
        'DisplayName' => 'Proxmox VE',
        'APIVersion' => '1.1',
        'RequiresServer' => 'true',
        'DefaultSSLPort' => 8006,
	);
}

/**
 * AdminLink: show a direct link to the Proxmox UI on :8006.
 * Falls back to server IP if hostname is empty.
 */
function pvewhmcs_AdminLink(array $params) {
    $host = $params['serverhostname'] ?: $params['serverip'];
    $port = $params['serverport'];
    if (!$host) {
        // Nothing to link to – return the module page as a safe fallback
        return '<a href="addonmodules.php?module=pvewhmcs">Module Config</a>';
    }

    $url  = 'https://' . $host . ':' . $port;
    return '<form action="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" method="get" target="_blank">
                <input type="submit" value="Log in to PVE" class="btn btn-sm btn-default" />
            </form>';
}

// WHMCS CONFIG > SERVICES/PRODUCTS > Their Service > Tab #3 (Plan/Pool)
function pvewhmcs_ConfigOptions() {
	// Retrieve PVE for WHMCS Cluster
	$server=Capsule::table('tblservers')->where('type', '=', 'pvewhmcs')->get()[0] ;

	// Retrieve Plans
	foreach (Capsule::table('mod_pvewhmcs_plans')->get() as $plan) {
		$plans[$plan->id] = '(' . $plan->vmtype . ')&nbsp;' . $plan->title ;
	}

	// Retrieve IP Pools
	foreach (Capsule::table('mod_pvewhmcs_ip_pools')->get() as $ippool) {
		$ippools[$ippool->id] = $ippool->title ;
	}
	
	// OPTIONS FOR THE QEMU/LXC PACKAGE; ties WHMCS PRODUCT to MODULE PLAN/POOL
	// Ref: https://developers.whmcs.com/provisioning-modules/config-options/
	// SQL/Param: configoption1 configoption2
	$configarray = array(
		"Plan" => array(
			"FriendlyName" => "PVE Plan",
			"Type" => "dropdown",
			'Options' => $plans ,
			"Description" => "(QEMU/LXC) Plan Name"
		),
		"IPPool" => array(
			"FriendlyName" => "IPv4 Pool",
			"Type" => "dropdown",
			'Options'=> $ippools,
			"Description" => "(IPv4) Allocation Pool"
		),
	);

	// Deliver the options back into WHMCS
	return $configarray;
}

// SECURITY: Module logs must never receive raw $params, passwords, API-token
// secrets, PVE tickets, CSRF tokens, or VNC tickets.
//
// PVE API FUNCTION: Create the Service on the Hypervisor
function pvewhmcs_CreateAccount($params) {
	$serviceId = isset($params['serviceid']) ? (int) $params['serviceid'] : 0;
	if ($serviceId <= 0) {
		throw new InvalidArgumentException('Invalid WHMCS service ID.');
	}

	return pvewhmcs_with_advisory_lock('service:' . $serviceId, 5, function () use ($params, $serviceId) {
		if (Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $serviceId)->exists()) {
			// Treat a repeated WHMCS create call as idempotent once mapping exists.
			return true;
		}

		return pvewhmcs_CreateAccount_locked($params);
	});
}

function pvewhmcs_CreateAccount_locked($params) {
	// Make sure "WHMCS Admin > Products/Services > Proxmox-based Service -> Plan + Pool" are set. Else, fail early. (Issue #36)
	if (!isset($params['configoption1'], $params['configoption2'])) {
		throw new Exception("PVEWHMCS Error: Missing Config. Service/Product WHMCS Config not saved (Plan/Pool not assigned to WHMCS Service type). Check Support/Health tab in Module Config for info. Quick and easy fix.");
	}
	if (empty($params['configoption1'])) {
		throw new Exception("PVEWHMCS Error: Missing Config. Service/Product WHMCS Config not saved (Plan/Pool not assigned to WHMCS Service type). Check Support/Health tab in Module Config for info. Quick and easy fix.");
	}
	if (empty($params['configoption2'])) {
		throw new Exception("PVEWHMCS Error: Missing Config. Service/Product WHMCS Config not saved (Plan/Pool not assigned to WHMCS Service type). Check Support/Health tab in Module Config for info. Quick and easy fix.");
	}

	// Retrieve Plan from table
	$plan = Capsule::table('mod_pvewhmcs_plans')->where('id', '=', $params['configoption1'])->get()[0];

	// PVE Host - Connection Info
	$serverip = !empty($params["serverhostname"]) ? $params["serverhostname"] : $params["serverip"];
	$serverusername = $params["serverusername"];
	$serverpassword = $params["serverpassword"];
	$serverport = $params["serverport"];

	// Prepare the service config array
	$vm_settings = array();

	// Atomically reserve an IP address from the selected pool.
	$ip = pvewhmcs_reserve_pool_ip($params['configoption2'], $params['serviceid']);

	// Get the starting VMID from the config options
	$vmid = Capsule::table('mod_pvewhmcs')->where('id', '1')->value('start_vmid');

	////////////////////
	// CREATE IF QEMU //
	////////////////////
	if (!empty($params['customfields']['KVMTemplate'])) {
		// QEMU TEMPLATE - CREATION LOGIC
		$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword, $serverport, true, true);
		if ($proxmox->login()) {
			// Get template node: prefer TPL_Node_QEMU custom field, fallback to first node
			$nodes = $proxmox->get_node_list();
			if (!empty($params['customfields']['TPL_Node_QEMU'])) {
				$template_node = $params['customfields']['TPL_Node_QEMU'];
			} else {
				// AUTO-DISCOVERY: Find where the template lives
				$template_node = pvewhmcs_find_node_by_vmid($proxmox, $params['customfields']['KVMTemplate']);
			}

			// DEBUG: Log Node Selection logic
			if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
				pvewhmcs_secure_log_module_call(
					'pvewhmcs',
					'Node Selection Debug',
					array(
						'serviceid' => (int) $params['serviceid'],
						'template_vmid' => isset($params['customfields']['KVMTemplate'])
							? (int) $params['customfields']['KVMTemplate']
							: null,
						'tpl_node_input' => isset($params['customfields']['TPL_Node_QEMU'])
							? (string) $params['customfields']['TPL_Node_QEMU']
							: '',
						'available_nodes' => $nodes,
						'selected_template_node' => $template_node
					),
					'Checking if custom field is empty or fallback triggered'
				);
			}
			unset($nodes);
			// Serialize only VMID selection + clone submission. Once PVE accepts
			// the task, that VMID is reserved by the cluster and the lock can drop.
			$allocation = pvewhmcs_with_advisory_lock('vmid_allocator', 15, function () use (
				$proxmox,
				$template_node,
				$vmid,
				$params
			) {
				$allocatedVmid = pvewhmcs_find_next_available_vmid($proxmox, $template_node, $vmid);
				$settings = array(
					'newid' => $allocatedVmid,
					'name' => "vps" . $params["serviceid"] . "-cus" . $params['clientsdetails']['userid'],
					'full' => true,
					'target' => $template_node,
				);
				$path = '/nodes/' . $template_node . '/qemu/' . $params['customfields']['KVMTemplate'] . '/clone';
				$response = $proxmox->post($path, $settings);

				return array(
					'vmid' => $allocatedVmid,
					'settings' => $settings,
					'logrequest' => $path . ' vmid=' . (int) $allocatedVmid,
					'response' => $response,
				);
			});
			$vmid = $allocation['vmid'];
			$vm_settings = $allocation['settings'];
			$logrequest = $allocation['logrequest'];
			$response = $allocation['response'];

			// DEBUG - Log the request parameters before it's fired
			if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
				pvewhmcs_secure_log_module_call(
					'pvewhmcs',
					__FUNCTION__,
					$logrequest,
					pvewhmcs_safe_log_result($response)
				);
			}

			// Extract UPID from the response (Proxmox returns colon-delimited string)
			if (strpos($response, 'UPID:') === 0) {
				$upid = trim($response); // Extract the entire UPID including "UPID:"

				// Poll for task completion
				$max_retries = 10;  // Total retries (avoid infinite loop)
				$retry_interval = 15;  // Delay in seconds between retries
				$completed = false;  // Starting - not complete until done

				for ($i = 0; $i < $max_retries; $i++) {
					// Check task status
					$task_status = $proxmox->get('/nodes/' . $template_node . '/tasks/' . $upid . '/status');

					if (isset($task_status['status']) && $task_status['status'] === 'stopped') {
						// Task is completed, now check exit status
						if (isset($task_status['exitstatus']) && $task_status['exitstatus'] === 'OK') {
							$completed = true;
							break;
						} else {
							// Task stopped, but failed with an exit status
							throw new Exception("Proxmox Error: Task failed with exit status: " . $task_status['exitstatus']);
						}
					} elseif ($task_status['status'] === 'running') {
						// Task is still running, wait and retry
						sleep($retry_interval);
					} else {
						// Unexpected task status
						throw new Exception("Proxmox Error: Unexpected task status: " . json_encode($task_status));
					}
				}

				if (!$completed) {
					throw new Exception("Proxmox Error: Task did not complete in time. Adjust ~/modules/servers/pvewhmcs/pvewhmcs.php >> max_retries option (2 locations).");
				}

				// Task is completed, now update the database with VM details.
				Capsule::table('mod_pvewhmcs_vms')->insert(
					[
						'id' => $params['serviceid'],
						'vmid' => $vmid,
						'user_id' => $params['clientsdetails']['userid'],
						'vtype' => 'qemu',
						'ipaddress' => $ip->ipaddress,
						'subnetmask' => $ip->mask,
						'gateway' => $ip->gateway,
						'created' => date("Y-m-d H:i:s"),
						'v6prefix' => $plan->ipv6,
					]
				);

				// Update WHMCS Service with Dedicated IP
				Capsule::table('tblhosting')
					->where('id', $params['serviceid'])
					->update(['dedicatedip' => $ip->ipaddress]);

				// ISSUE #32 relates - amend post-clone to ensure excludes-disk amendments are all done, too.
				$cloned_tweaks['memory'] = $plan->memory;
				$cloned_tweaks['ostype'] = $plan->ostype;
				$cloned_tweaks['sockets'] = $plan->cpus;
				$cloned_tweaks['cores'] = $plan->cores;
				$cloned_tweaks['cpu'] = $plan->cpuemu;
				$cloned_tweaks['kvm'] = $plan->kvm;
				$cloned_tweaks['onboot'] = $plan->onboot;

				// Cloud-Init IP Configuration for Cloned VMs
				$cloned_tweaks['nameserver'] = '208.67.222.222 64.6.64.6';
				$cloned_tweaks['ipconfig0'] = 'ip=' . $ip->ipaddress . '/' . mask2cidr($ip->mask) . ',gw=' . $ip->gateway;
				if (!empty($plan->ipv6) && $plan->ipv6 != '0') {
					switch ($plan->ipv6) {
						case 'auto':
							// Pass in auto, triggering SLAAC
							$cloned_tweaks['nameserver'] .= ' 2620:119:35::35 2620:74:1b::1:1';
							$cloned_tweaks['ipconfig1'] = 'ip6=auto';
							break;
						case 'dhcp':
							// DHCP for IPv6 option
							$cloned_tweaks['nameserver'] .= ' 2620:119:35::35 2620:74:1b::1:1';
							$cloned_tweaks['ipconfig1'] = 'ip6=dhcp';
							break;
						case 'prefix':
							// Future development
							break;
						default:
							break;
					}
				}

				// Optionally set cloud-init password if provided
				if (!empty($params['password'])) {
					$cloned_tweaks['cipassword'] = $params['password'];
				}

				if (!empty($params['customfields']['Password'])) {
					$cloned_tweaks['cipassword'] = $params['customfields']['Password'];
				}

				// Apply VM configuration on the node where the VM was cloned
				$proxmox->post(
					'/nodes/' . $template_node . '/qemu/' . $vm_settings['newid'] . '/config',
					$cloned_tweaks
				);

				// Start the VM only if onboot is enabled
				if (!empty($plan->onboot)) {
					$proxmox->post(
						'/nodes/' . $template_node . '/qemu/' . $vm_settings['newid'] . '/status/start',
						array()
					);
				}

				return true;
			} else {
				throw new Exception("Proxmox Error: Failed to initiate clone. Response: " . json_encode(pvewhmcs_safe_log_result($response)));
			}
		} else {
			throw new Exception("Proxmox Error: PVE API login failed. Please check your credentials.");
		}
		/////////////////////////////////////////////////
		// PREPARE SETTINGS FOR QEMU/LXC EVENTUALITIES //
		/////////////////////////////////////////////////
	} else {
		// No longer inheriting WHMCS Service ID, so //
		// $vm_settings['vmid'] = $params["serviceid"];
		if ($plan->vmtype == 'lxc') {
			///////////////////////////
			// LXC: Preparation Work //
			///////////////////////////
			$vm_settings['ostemplate'] = $params['customfields']['Template'];
			$vm_settings['swap'] = $plan->swap;
			$vm_settings['rootfs'] = $plan->storage . ':' . $plan->disk;
			$vm_settings['bwlimit'] = $plan->diskio;
			$vm_settings['nameserver'] = '208.67.222.222 64.6.64.6';
			$vm_settings['net0'] = 'name=eth0,bridge=' . $plan->bridge . $plan->vmbr . ',ip=' . $ip->ipaddress . '/' . mask2cidr($ip->mask) . ',gw=' . $ip->gateway . ',rate=' . $plan->netrate;
			if (!empty($plan->ipv6) && $plan->ipv6 != '0') {
				// Standard prep for the 2nd int.
				$vm_settings['net1'] = 'name=eth1,bridge=' . $plan->bridge . $plan->vmbr . ',rate=' . $plan->netrate;
				switch ($plan->ipv6) {
					case 'auto':
						// Pass in auto, triggering SLAAC
						$vm_settings['nameserver'] .= ' 2620:119:35::35 2620:74:1b::1:1';
						$vm_settings['net1'] .= ',ip6=auto';
						break;
					case 'dhcp':
						// DHCP for IPv6 option
						$vm_settings['nameserver'] .= ' 2620:119:35::35 2620:74:1b::1:1';
						$vm_settings['net1'] .= ',ip6=dhcp';
						break;
					case 'prefix':
						// Future development
						break;
					default:
						break;
				}
				if (!empty($plan->vlanid)) {
					$vm_settings['net1'] .= ',tag=' . $plan->vlanid;
				}
			}
			if (!empty($plan->vlanid)) {
				$vm_settings['net0'] .= ',tag=' . $plan->vlanid;
			}
			$vm_settings['onboot'] = $plan->onboot;
			$vm_settings['unprivileged'] = $plan->unpriv;
			$vm_settings['password'] = $params['customfields']['Password'];
		} else {
			////////////////////////////
			// QEMU: Preparation Work //
			////////////////////////////
			$vm_settings['ostype'] = $plan->ostype;
			$vm_settings['scsihw'] = 'virtio-scsi-single';
			$vm_settings['sockets'] = $plan->cpus;
			$vm_settings['cores'] = $plan->cores;
			$vm_settings['cpu'] = $plan->cpuemu;
			$vm_settings['nameserver'] = '208.67.222.222 64.6.64.6';
			$vm_settings['ipconfig0'] = 'ip=' . $ip->ipaddress . '/' . mask2cidr($ip->mask) . ',gw=' . $ip->gateway;
			if (!empty($plan->ipv6) && $plan->ipv6 != '0') {
				switch ($plan->ipv6) {
					case 'auto':
						// Pass in auto, triggering SLAAC
						$vm_settings['nameserver'] .= ' 2620:119:35::35 2620:74:1b::1:1';
						$vm_settings['ipconfig1'] = 'ip6=auto';
						break;
					case 'dhcp':
						// DHCP for IPv6 option
						$vm_settings['nameserver'] .= ' 2620:119:35::35 2620:74:1b::1:1';
						$vm_settings['ipconfig1'] = 'ip6=dhcp';
						break;
					case 'prefix':
						// Future development
						break;
					default:
						break;
				}
			}
			$vm_settings['kvm'] = $plan->kvm;
			$vm_settings['onboot'] = $plan->onboot;

			$vm_settings[$plan->disktype . '0'] = $plan->storage . ':' . $plan->disk . ',format=' . $plan->diskformat;
			if (!empty($plan->diskcache)) {
				$vm_settings[$plan->disktype . '0'] .= ',cache=' . $plan->diskcache;
			}
			$vm_settings['bwlimit'] = $plan->diskio;

			// ISO: Attach file to the guest
			if (isset($params['customfields']['ISO'])) {
				$vm_settings['ide2'] = 'local:iso/' . $params['customfields']['ISO'] . ',media=cdrom';
			}

			// NET: Config specifics for guest networking
			if ($plan->netmode != 'none') {
				$vm_settings['net0'] = $plan->netmodel;
				if ($plan->netmode == 'bridge') {
					$vm_settings['net0'] .= ',bridge=' . $plan->bridge . $plan->vmbr;
				}
				$vm_settings['net0'] .= ',firewall=' . $plan->firewall;
				if (!empty($plan->netrate)) {
					$vm_settings['net0'] .= ',rate=' . $plan->netrate;
				}
				if (!empty($plan->vlanid)) {
					$vm_settings['net0'] .= ',tag=' . $plan->vlanid;
				}
				// IPv6: Same configs for second interface
				if (isset($vm_settings['ipconfig1'])) {
					$vm_settings['net1'] = $plan->netmodel;
					if ($plan->netmode == 'bridge') {
						$vm_settings['net1'] .= ',bridge=' . $plan->bridge . $plan->vmbr;
					}
					$vm_settings['net1'] .= ',firewall=' . $plan->firewall;
					if (!empty($plan->netrate)) {
						$vm_settings['net1'] .= ',rate=' . $plan->netrate;
					}
					if (!empty($plan->vlanid)) {
						$vm_settings['net1'] .= ',tag=' . $plan->vlanid;
					}
				}
			}
		}

		$vm_settings['cpuunits'] = $plan->cpuunits;
		$vm_settings['cpulimit'] = $plan->cpulimit;
		$vm_settings['memory'] = $plan->memory;

		////////////////////////////////////////////////////
		// CREATION: Attempt to Create Guest via PVE2 API //
		////////////////////////////////////////////////////
		try {
			$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword, $serverport, true, true);

			if ($proxmox->login()) {
				// Get template node: prefer TPL_Node_LXC custom field for LXC, fallback to first node
				$nodes = $proxmox->get_node_list();
				if ($plan->vmtype != 'kvm' && !empty($params['customfields']['TPL_Node_LXC'])) {
					$template_node = $params['customfields']['TPL_Node_LXC'];
				} else {
					$template_node = $nodes[0];
				}
				unset($nodes);

				if ($plan->vmtype == 'kvm') {
					$guest_type = 'qemu';
				} else {
					$guest_type = 'lxc';
				}

				// Serialize VMID selection and the create submission across all
				// module provisioning requests. The lock is released as soon as
				// PVE returns the UPID and therefore owns the VMID.
				$allocation = pvewhmcs_with_advisory_lock('vmid_allocator', 15, function () use (
					$proxmox,
					$template_node,
					$vmid,
					$vm_settings,
					$guest_type
				) {
					$allocatedVmid = pvewhmcs_find_next_available_vmid($proxmox, $template_node, $vmid);
					$settings = $vm_settings;
					$settings['vmid'] = $allocatedVmid;
					$path = '/nodes/' . $template_node . '/' . $guest_type;
					$response = $proxmox->post($path, $settings);

					return array(
						'vmid' => $allocatedVmid,
						'settings' => $settings,
						'logrequest' => $path . ' vmid=' . (int) $allocatedVmid,
						'response' => $response,
					);
				});
				$vmid = $allocation['vmid'];
				$vm_settings = $allocation['settings'];
				$logrequest = $allocation['logrequest'];
				$response = $allocation['response'];

				// DEBUG - Log the request parameters after it's fired
				if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
					pvewhmcs_secure_log_module_call(
						'pvewhmcs',
						__FUNCTION__,
						$logrequest,
						pvewhmcs_safe_log_result($response)
					);
				}

				// Extract UPID from the response (Proxmox returns colon-delimited string)
				if (strpos($response, 'UPID:') === 0) {
					$upid = trim($response); // Extract the entire UPID including "UPID:"

					// Poll for task completion
					$max_retries = 10;  // Total retries (avoid infinite loop)
					$retry_interval = 15;  // Number of seconds between retries
					$completed = false;

					for ($i = 0; $i < $max_retries; $i++) {
						// Check task status
						$task_status = $proxmox->get('/nodes/' . $template_node . '/tasks/' . $upid . '/status');

						if (isset($task_status['status']) && $task_status['status'] === 'stopped') {
							// Task is completed, now check exit status
							if (isset($task_status['exitstatus']) && $task_status['exitstatus'] === 'OK') {
								$completed = true;
								break;
							} else {
								// Task stopped, but failed with an exit status
								throw new Exception("Proxmox Error: Task failed with exit status: " . $task_status['exitstatus']);
							}
						} elseif ($task_status['status'] === 'running') {
							// Task is still running, wait and retry
							sleep($retry_interval);
						} else {
							// Unexpected task status
							throw new Exception("Proxmox Error: Unexpected task status: " . json_encode($task_status));
						}
					}

					if (!$completed) {
						throw new Exception("Proxmox Error: Task did not complete in time. Adjust ~/modules/servers/pvewhmcs/pvewhmcs.php >> max_retries option (2 locations).");
					}

					// Task is completed, now update the database with VM details.
					Capsule::table('mod_pvewhmcs_vms')->insert(
						[
							'id' => $params['serviceid'],
							'vmid' => $vmid,
							'user_id' => $params['clientsdetails']['userid'],
							'vtype' => $guest_type,
							'ipaddress' => $ip->ipaddress,
							'subnetmask' => $ip->mask,
							'gateway' => $ip->gateway,
							'created' => date("Y-m-d H:i:s"),
							'v6prefix' => $plan->ipv6,
						]
					);

					// Update WHMCS Service with Dedicated IP
					Capsule::table('tblhosting')
						->where('id', $params['serviceid'])
						->update(['dedicatedip' => $ip->ipaddress]);
					return true;
				} else {
					throw new Exception("Proxmox Error: Failed to initiate creation. Response: " . json_encode(pvewhmcs_safe_log_result($response)));
				}
			} else {
				throw new Exception("Proxmox Error: PVE API login failed. Please check your credentials.");
			}
		} catch (PVE2_Exception $e) {
			$safeError = pvewhmcs_redact_log_value($e->getMessage());
			// Record only a redacted error in WHMCS's module log.
			if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
				pvewhmcs_secure_log_module_call(
					'pvewhmcs',
					__FUNCTION__,
					pvewhmcs_safe_log_context($params),
					$safeError
				);
			}
			return $safeError;
		}
		unset($vm_settings);
	}
}

/**
 * Find the next available VMID in the Proxmox cluster.
 *
 * This function first tries to use Proxmox's /cluster/nextid endpoint directly,
 * which is the most reliable method. If the returned VMID is below the configured
 * start_vmid, it will probe for an available VMID starting from start_vmid.
 *
 * @param PVE2_API $proxmox    Proxmox API client (logged in)
 * @param string   $node       Ignored (VMIDs are cluster-wide)
 * @param int      $start_vmid Starting VMID from Module config
 * @return int     The next available VMID
 * @throws Exception on unexpected API errors or if no free VMID found
 */
function pvewhmcs_find_next_available_vmid($proxmox, $node, $start_vmid) {
	$start_vmid = max(100, (int) $start_vmid);
	$candidate = $start_vmid;

	// Use PVE's cluster suggestion as a starting point, but never trust it as
	// a reservation. The caller must hold the module's vmid_allocator lock
	// until the create/clone request is accepted by PVE.
	try {
		$clusterNext = (int) $proxmox->get('/cluster/nextid');
		if ($clusterNext >= $candidate) {
			$candidate = $clusterNext;
		}
	} catch (\Throwable $e) {
		// Fall back to the configured start VMID and probe explicitly.
	}

	for ($i = 0; $i < 1000; $i++, $candidate++) {
		// Never reuse a VMID that this module still maps to another service,
		// even if PVE reports it as currently free.
		if (Capsule::table('mod_pvewhmcs_vms')->where('vmid', '=', $candidate)->exists()) {
			continue;
		}

		try {
			// /cluster/nextid supports an optional vmid query parameter. Put it
			// directly in the request path; PVE2_API::get() does not take a
			// second query-parameter argument.
			$available = $proxmox->get('/cluster/nextid?vmid=' . rawurlencode((string) $candidate));

			if ((int) $available === $candidate) {
				return $candidate;
			}
		} catch (\Throwable $e) {
			$msg = strtolower($e->getMessage());

			// Expected occupied-ID responses: continue probing.
			if (
				strpos($msg, 'already exists') !== false
				|| strpos($msg, 'parameter verification failed') !== false
				|| strpos($msg, 'vm ') !== false
				|| strpos($msg, 'ct ') !== false
			) {
				continue;
			}

			throw $e;
		}
	}

	throw new Exception(
		"Unable to find a free VMID starting at {$start_vmid} after 1000 attempts"
	);
}

// PVE API FUNCTION, ADMIN: Test Connection with Proxmox node
function pvewhmcs_TestConnection(array $params) {
	try {
		// Call the service's connection test function
		$serverip = !empty($params["serverhostname"]) ? $params["serverhostname"] : $params["serverip"];
		$serverusername = $params["serverusername"];
		$serverpassword = $params["serverpassword"];
		$serverport = $params["serverport"];
		$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword, $serverport, true, true);

		// Set success if login succeeded
		if ($proxmox->login()) {
			$success = true;
			$errorMsg = '';
		}
	} catch (Exception $e) {
		$safeError = pvewhmcs_redact_log_value($e->getMessage());
		pvewhmcs_secure_log_module_call(
			'pvewhmcs',
			__FUNCTION__,
			pvewhmcs_safe_log_context($params),
			$safeError
		);
		$success = false;
		$errorMsg = $safeError;
	}
	// Return success or error, and info
	return array(
		'success' => $success,
		'error' => $errorMsg,
	);
}

// PVE API FUNCTION, ADMIN: Suspend a Service on the hypervisor
function pvewhmcs_SuspendAccount(array $params) {
	$serverip = !empty($params["serverhostname"]) ? $params["serverhostname"] : $params["serverip"];
	$serverusername = $params["serverusername"];
	$serverpassword = $params["serverpassword"];
	$serverport = $params["serverport"];
	
	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword, $serverport, true, true);
	if ($proxmox->login()) {
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->first();
		if ($guest === null) {
			return "Error performing action. Unable to find guest linked to Service ID ({$params['serviceid']})";
		}
		$guest_node = pvewhmcs_find_guest_node($proxmox, $guest, $params['serviceid']);
		if (empty($guest_node)) {
			return "Error performing action. Unable to determine node for VMID {$guest->vmid}.";
		}
		$pve_cmdparam = array();
		// Log and fire request
		$logrequest = '/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid . '/status/stop';
		$response = $proxmox->post($logrequest, $pve_cmdparam);
	}

	// DEBUG - Log the request parameters before it's fired
	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		pvewhmcs_secure_log_module_call(
			'pvewhmcs',
			__FUNCTION__,
			$logrequest,
			json_encode(pvewhmcs_safe_log_result($response))
		);
	}
	// Return success only if no errors returned by PVE
	if (isset($response) && !isset($response['errors'])) {
		return "success";
	} else {
		// Handle the case where there are errors
		$response_message = isset($response['errors']) ? json_encode($response['errors']) : "Unknown Error, consider using Debug Mode.";
		return "Error performing action. " . $response_message;
	}
}

// PVE API FUNCTION, ADMIN: Unsuspend a Service on the hypervisor
function pvewhmcs_UnsuspendAccount(array $params) {
	$serverip = !empty($params["serverhostname"]) ? $params["serverhostname"] : $params["serverip"];
	$serverusername = $params["serverusername"];
	$serverpassword = $params["serverpassword"];
	$serverport = $params["serverport"];
	
	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword, $serverport, true, true);
	if ($proxmox->login()) {
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->first();
		$guest_node = pvewhmcs_find_guest_node($proxmox, $guest, $params['serviceid']);
		if (empty($guest_node)) {
			return "Error performing action. Unable to determine node for VMID {$guest->vmid}.";
		}
		$pve_cmdparam = array();
		// Log and fire request
		$logrequest = '/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid . '/status/start';
		$response = $proxmox->post($logrequest, $pve_cmdparam);
	}

	// DEBUG - Log the request parameters before it's fired
	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		pvewhmcs_secure_log_module_call(
			'pvewhmcs',
			__FUNCTION__,
			$logrequest,
			json_encode(pvewhmcs_safe_log_result($response))
		);
	}
	// Return success only if no errors returned by PVE
	if (isset($response) && !isset($response['errors'])) {
		return "success";
	} else {
		// Handle the case where there are errors
		$response_message = isset($response['errors']) ? json_encode($response['errors']) : "Unknown Error, consider using Debug Mode.";
		return "Error performing action. " . $response_message;
	}
}

// PVE API FUNCTION, ADMIN: Terminate a Service on the hypervisor
// Sequence:
//   1. Connect to PVE and look up the guest record in mod_pvewhmcs_vms.
//   2. Locate which cluster node the VMID currently lives on.
//      - If not found on the cluster, the VM was already removed manually:
//        clean up the DB row and return success.
//   3. Guard against VMID reuse (Issue #194): if another WHMCS service now
//      holds the same VMID in mod_pvewhmcs_vms, Proxmox recycled it after a
//      manual termination left the original row orphaned. Clean up the stale
//      row and abort — the live VM must not be touched.
//   4. All checks passed: stop the guest (if running), delete it from PVE,
//      then remove the DB row.
function pvewhmcs_TerminateAccount(array $params) {
	$serverip = !empty($params["serverhostname"]) ? $params["serverhostname"] : $params["serverip"];
	$serverusername = $params["serverusername"];
	$serverpassword = $params["serverpassword"];
	$serverport = $params["serverport"];

	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword, $serverport, true, true);
	if ($proxmox->login()){

		// STEP 1: Look up the guest record for this WHMCS Service ID.
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->first();
		if ($guest === null) {
			return "Error performing action. Unable to find guest linked to Service ID ({$params['serviceid']})";
		}

		// STEP 2: Locate the cluster node the VMID lives on.
		$guest_node = pvewhmcs_find_guest_node($proxmox, $guest, $params['serviceid']);
		if (empty($guest_node)) {
			// VM is no longer present on the cluster — already removed manually.
			// Clean up the orphaned DB row so the VMID cannot match a future reused guest.
			Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->delete();
			return "success";
		}

		// STEP 3: Guard against VMID reuse (Issue #194).
		// If mod_pvewhmcs_vms contains another row for this VMID, Proxmox recycled it
		// for a new service after the original was manually terminated without going
		// through this function. The VM on PVE now belongs to the new service — abort.
		$vmid_owner = Capsule::table('mod_pvewhmcs_vms')
			->where('vmid', '=', $guest->vmid)
			->where('id', '!=', $params['serviceid'])
			->first();
		if ($vmid_owner !== null) {
			Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->delete();
			return "Error: VMID {$guest->vmid} is now assigned to Service #{$vmid_owner->id}. Stale record for Service #{$params['serviceid']} cleaned up. VM was NOT deleted.";
		}

		// STEP 4: Ownership confirmed. Stop the guest (if running) then delete it.
		$pve_cmdparam = array();
		$guest_specific = $proxmox->get('/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid . '/status/current');
		if ($guest_specific['status'] != 'stopped') {
			$proxmox->post('/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid . '/status/stop', $pve_cmdparam);
			sleep(30);
		}
		$delete_response = $proxmox->delete('/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid, array('skiplock' => 1));
		if ($delete_response) {
			// Delete the DB row now that the guest has been removed from PVE.
			Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->delete();
			return "success";
		} else {
			$response_message = isset($delete_response['errors']) ? json_encode($delete_response['errors']) : "Unknown Error, consider using Debug Mode.";
			return "Error terminating account: {$response_message}";
		}
	} else {
		return "Error terminating account. Couldn't login to PVE.";
	}
}

// Legacy custom SHA1/MD5/XOR WHMCS decryption code was removed.
// Server credentials are decrypted exclusively through the supported WHMCS
// DecryptPassword Local API at the point of use.

// MODULE BUTTONS: Admin Interface button regos
function pvewhmcs_AdminCustomButtonArray() {
	$buttonarray = array(
		"Start" => "vmStart",
		"Reboot" => "vmReboot",
		"Soft Stop" => "vmShutdown",
		"Hard Stop" => "vmStop",
	);
	return $buttonarray;
}

// MODULE BUTTONS: Client Interface button regos
function pvewhmcs_ClientAreaCustomButtonArray() {
	$buttonarray = array(
		"<i class='fa fa-2x fa-flag-checkered'></i> Start" => "vmStart",
		"<i class='fa fa-2x fa-sync'></i> Reboot" => "vmReboot",
		"<i class='fa fa-2x fa-power-off'></i> Power Off" => "vmShutdown",
		"<i class='fa fa-2x fa-stop'></i>  Hard Stop" => "vmStop",
		"<i class='fa fa-2x fa-chart-bar'></i>  Statistics" => "vmStat",
		"<i class='fa fa-2x fa-search'></i>  Check Status" => "vmCheck",
		"<img src='./modules/servers/pvewhmcs/img/novnc.png'/> noVNC (HTML5)" => "noVNC",
		"<i class='fa fa-refresh'></i> Reinstall OS" => "Reinstall",
	);
	return $buttonarray;
}

/**
 * Fetch RRD statistics from Proxmox with graceful error handling.
 *
 * Proxmox RRD schema changed in PVE 9 from pve2-{type} to pve-{type}-9.0.
 * The ds parameter names (cpu, mem, netin, netout, diskread, diskwrite) remain valid
 * across both old and new schemas - verified in pve-cluster/src/pmxcfs/status.c.
 *
 * RRD data may be unavailable when:
 *   - VM/CT was just created (RRD takes ~60s to populate)
 *   - RRD schema migration is incomplete on the PVE host
 *   - RRD files are corrupted or missing
 *
 * Refs:
 *   - Issue #162: https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/issues/162
 *   - PVE RRD schema: https://github.com/proxmox/pve-cluster/blob/master/src/pmxcfs/status.c
 *   - Schema change: https://www.mail-archive.com/pve-devel@lists.proxmox.com/msg28317.html
 *
 * @param PVE2_API $proxmox    The Proxmox API client instance
 * @param string   $node       The Proxmox node name
 * @param string   $vtype      Guest type: 'qemu' or 'lxc'
 * @param int      $vmid       The VM/CT ID
 * @param string   $timeframe  RRD timeframe: 'day', 'week', 'month', 'year'
 * @param string   $ds         Data source(s): 'cpu', 'mem', 'netin,netout', 'diskread,diskwrite'
 * @return string|null         Base64-encoded PNG image, or null if unavailable
 */
function pvewhmcs_fetch_rrd_stat($proxmox, $node, $vtype, $vmid, $timeframe, $ds) {
	// Build the API path and query params for RRD image
	$rrd_path = '/nodes/' . $node . '/' . $vtype . '/' . $vmid . '/rrd';
	$rrd_params = '?timeframe=' . $timeframe . '&ds=' . $ds . '&cf=AVERAGE';
	
	try {
		// Attempt to fetch RRD graph image from PVE API
		$vm_rrd = $proxmox->get($rrd_path . $rrd_params);
		
		// Check if we got a valid response with image data
		if (isset($vm_rrd['image']) && !empty($vm_rrd['image'])) {
			// Decode and re-encode the image data for template use
			$image = utf8_decode($vm_rrd['image']);
			return base64_encode($image);
		}
	} catch (Exception $e) {
		// RRD data unavailable - this is normal for new VMs or during migration.
		// Log if debug mode is on, but don't crash the Client Area.
		if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			pvewhmcs_secure_log_module_call(
				'pvewhmcs',
				'pvewhmcs_fetch_rrd_stat',
				'RRD fetch failed for ' . $vtype . '/' . $vmid . ' (' . $ds . ', ' . $timeframe . ')',
				pvewhmcs_redact_log_value($e->getMessage())
			);
		}
	}
	
	// Return null when RRD data is unavailable
	return null;
}

// OUTPUT: Module output to the Client Area
function pvewhmcs_ClientArea($params) {
	// Retrieve virtual machine info from table mod_pvewhmcs_vms
	$guest=Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->get()[0] ;
	
	// Gather access credentials for PVE, as these are no longer passed for Client Area
	$pveservice=Capsule::table('tblhosting')->find($params['serviceid']) ;
	$pveserver=Capsule::table('tblservers')->where('id','=',$pveservice->server)->get()[0] ;

	// Get IP and User for Hypervisor
	$serverip = !empty($pveserver->hostname) ? $pveserver->hostname : $pveserver->ipaddress;
	$serverusername = $pveserver->username;
	// Password access is different in Client Area, so retrieve and decrypt
	$api_data = array(
		'password2' => $pveserver->password,
	);
	$serverpassword = localAPI('DecryptPassword', $api_data);
	$serverport = $pveserver->port;

	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword['password'], $serverport, true, true);
	if ($proxmox->login()) {
		//$proxmox->setCookie();
		// Where node lives ? 
		$guest_node = pvewhmcs_find_guest_node($proxmox, $guest, $params['serviceid']);
		if (empty($guest_node)) {
			throw new Exception(
			"PVEWHMCS Error: Unable to determine node for VMID {$guest->vmid} (Service #{$params['serviceid']})."
			);
		}

		# Get and set VM variables
		$vm_config = $proxmox->get('/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid . '/config');
		$cluster_resources = $proxmox->get('/cluster/resources');
		$vm_status = null;
		// DEBUG - Log the /cluster/resources and /config for the VM/CT, if enabled
		if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			pvewhmcs_secure_log_module_call(
				'pvewhmcs',
				__FUNCTION__,
				array(
					'serviceid' => (int) $params['serviceid'],
					'vmid' => (int) $guest->vmid,
					'vtype' => (string) $guest->vtype,
					'node' => (string) $guest_node,
					'cluster_resource_count' => is_array($cluster_resources) ? count($cluster_resources) : 0,
				),
				'Client-area guest data loaded successfully'
			);
		}

		# Loop through data, find ID
		$vm_status = null;
		foreach ($cluster_resources as $vm) {
			// Using vmid directly, from Module Table against API Response (ignoring Service ID now)
			if ($vm['vmid'] == $guest->vmid && $vm['type'] == $guest->vtype) {
				$vm_status = $vm;
				break;
			}

			// If the vmid is not found, check against serviceid (<v1.2.9 case)
			if ($vm['vmid'] == $params['serviceid'] && $vm['type'] == $guest->vtype) {
				$vm_status = $vm;
				break;
			}
		}

		# Retrieve & set usage data appropriately
		if ($vm_status !== null) {
			$vm_status['uptime'] = time2format($vm_status['uptime']);
			$vm_status['cpu'] = round($vm_status['cpu'] * 100, 2);

			$vm_status['diskusepercent'] = intval($vm_status['disk'] * 100 / $vm_status['maxdisk']);
			$vm_status['memusepercent'] = intval($vm_status['mem'] * 100 / $vm_status['maxmem']);

			if ($guest->vtype == 'lxc') {
				// Check on swap before setting graph value
				$ct_specific = $proxmox->get('/nodes/' . $guest_node . '/lxc/' . $guest->vmid . '/status/current');
				if ($ct_specific['maxswap'] != 0) {
					$vm_status['swapusepercent'] = intval($ct_specific['swap'] * 100 / $ct_specific['maxswap']);
				}
			} else {
				// Fall back to 0% usage to satisfy chart requirement
				$vm_status['swapusepercent'] = 0;
			}
		} else {
	    		// Handle the VM not found in the cluster resources (Optional)
			echo "VM/CT not found in Cluster Resources.";
		}

		// ----------------------------------------------------------------
		// Fetch RRD statistics graphs from Proxmox.
		// Uses pvewhmcs_fetch_rrd_stat() for graceful error handling.
		// RRD data may be unavailable for new VMs or during PVE migration.
		// ----------------------------------------------------------------

		// CPU usage statistics (day/week/month/year)
		$vm_statistics['cpu']['year']  = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'year', 'cpu');
		$vm_statistics['cpu']['month'] = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'month', 'cpu');
		$vm_statistics['cpu']['week']  = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'week', 'cpu');
		$vm_statistics['cpu']['day']   = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'day', 'cpu');

		// Memory usage statistics (day/week/month/year)
		$vm_statistics['mem']['year']  = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'year', 'mem');
		$vm_statistics['mem']['month'] = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'month', 'mem');
		$vm_statistics['mem']['week']  = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'week', 'mem');
		$vm_statistics['mem']['day']   = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'day', 'mem');

		// Network I/O statistics (day/week/month/year)
		$vm_statistics['netinout']['year']  = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'year', 'netin,netout');
		$vm_statistics['netinout']['month'] = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'month', 'netin,netout');
		$vm_statistics['netinout']['week']  = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'week', 'netin,netout');
		$vm_statistics['netinout']['day']   = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'day', 'netin,netout');

		// Disk I/O statistics (day/week/month/year)
		$vm_statistics['diskrw']['year']  = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'year', 'diskread,diskwrite');
		$vm_statistics['diskrw']['month'] = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'month', 'diskread,diskwrite');
		$vm_statistics['diskrw']['week']  = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'week', 'diskread,diskwrite');
		$vm_statistics['diskrw']['day']   = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, 'day', 'diskread,diskwrite');

		$vm_config['vtype'] = $guest->vtype ;
		$vm_config['ipv4'] = $guest->ipaddress ;
		$vm_config['netmask4'] = $guest->subnetmask ;
		$vm_config['gateway4'] = $guest->gateway ;
		$vm_config['created'] = $guest->created ;
		$vm_config['v6prefix'] = $guest->v6prefix ;
	}
	else {
		echo '<center><strong>Error: Unable to gather data from Hypervisor.<br>Please contact Tech Support!</strong></center>';
		exit;
	}

	return array(
		'templatefile' => 'clientarea',
		'vars' => array(
			// Do not expose the full WHMCS provisioning params array to Smarty;
			// it can contain server credentials and other secrets.
			'vm_config' => $vm_config,
			'vm_status' => $vm_status,
			'vm_statistics' => $vm_statistics,
			'vm_vncproxy' => $vm_vncproxy,
		),
	);
}

// OUTPUT: VM Statistics/Graphs render to Client Area
function pvewhmcs_vmStat($params) {
	return true;
}

// VNC: Console access to VM/CT via noVNC
function pvewhmcs_noVNC($params) {
	global $CONFIG;

	if (session_status() !== PHP_SESSION_ACTIVE) {
		return 'Unable to initialize a secure console session.';
	}

	$serviceId = isset($params['serviceid']) ? (int) $params['serviceid'] : 0;
	$userId = isset($params['userid'])
		? (int) $params['userid']
		: (isset($params['clientsdetails']['userid']) ? (int) $params['clientsdetails']['userid'] : 0);

	if ($serviceId <= 0 || $userId <= 0) {
		return 'Unable to validate the console request.';
	}

	$service = Capsule::table('tblhosting')
		->where('id', '=', $serviceId)
		->where('userid', '=', $userId)
		->first();

	if (!$service || (string) $service->domainstatus !== 'Active') {
		return 'Console access is available only for an active service owned by this client.';
	}

	$guest = Capsule::table('mod_pvewhmcs_vms')
		->where('id', '=', $serviceId)
		->where('user_id', '=', $userId)
		->first();

	if (!$guest) {
		return 'Unable to find the guest mapped to this service.';
	}

	if (!pvewhmcs_has_vnc_secret()) {
		return 'Console access is not configured. Please contact Technical Support.';
	}

	if (!isset($_SESSION['pvewhmcs_console'])) {
		$_SESSION['pvewhmcs_console'] = array();
	}

	// Keep only live sessions and cap outstanding nonces to avoid
	// client-session storage growth when a user repeatedly opens the action.
	$now = time();
	foreach ($_SESSION['pvewhmcs_console'] as $key => $entry) {
		if (!is_array($entry) || empty($entry['expires']) || (int) $entry['expires'] < $now) {
			unset($_SESSION['pvewhmcs_console'][$key]);
		}
	}
	while (count($_SESSION['pvewhmcs_console']) >= 5) {
		reset($_SESSION['pvewhmcs_console']);
		$oldestKey = key($_SESSION['pvewhmcs_console']);
		if ($oldestKey === null) {
			break;
		}
		unset($_SESSION['pvewhmcs_console'][$oldestKey]);
	}

	$nonce = bin2hex(random_bytes(32));
	$_SESSION['pvewhmcs_console'][$nonce] = array(
		'serviceid' => $serviceId,
		'userid' => $userId,
		'created' => $now,
		'expires' => $now + 60,
	);

	$whmcsBase = rtrim((string) $CONFIG['SystemURL'], '/');
	$url = $whmcsBase . '/modules/servers/pvewhmcs/novnc_router.php';

	return '<div class="alert alert-success" style="text-align:center;">'
		. '<strong>Secure console session prepared.</strong><br>'
		. '<form method="post" action="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;margin-top:8px;">'
		. '<input type="hidden" name="session" value="' . htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
		. '<button type="submit" class="btn btn-primary">Open noVNC Console</button>'
		. '</form></div>';
}

// VNC: Console access to VM/CT via SPICE
function pvewhmcs_SPICE($params) {
	return 'SPICE console access is disabled for security. Use the secure noVNC console instead.';
}

// PVE API FUNCTION, CLIENT/ADMIN: Start the VM/CT
function pvewhmcs_vmStart($params) {
	try {
		return pvewhmcs_vmStart_internal($params);
	} catch (\Throwable $e) {
		return pvewhmcs_client_safe_error(
			$params,
			'pvewhmcs_vmStart',
			$e->getMessage()
		);
	}
}

function pvewhmcs_vmStart_internal($params) {
	// Gather access credentials for PVE, as these are no longer passed for Client Area
	$pveservice = Capsule::table('tblhosting')->find($params['serviceid']) ;
	$pveserver = Capsule::table('tblservers')->where('id','=',$pveservice->server)->get()[0] ;
	$serverip = !empty($pveserver->hostname) ? $pveserver->hostname : $pveserver->ipaddress;
	$serverusername = $pveserver->username;

	$api_data = array(
		'password2' => $pveserver->password,
	);
	$serverpassword = localAPI('DecryptPassword', $api_data);
	$serverport = $pveserver->port;

	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword['password'], $serverport, true, true);
	if ($proxmox->login()) {
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->first();
		if ($guest === null) {
			return pvewhmcs_client_safe_error($params, __FUNCTION__, 'Guest mapping was not found for the requested service.');
		}
		$guest_node = pvewhmcs_find_guest_node($proxmox, $guest, $params['serviceid']);
		if (empty($guest_node)) {
			return pvewhmcs_client_safe_error($params, __FUNCTION__, array('stage' => 'node-resolution', 'vmid' => isset($guest->vmid) ? (int) $guest->vmid : null));
		}
		$pve_cmdparam = array();
		$logrequest = '/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid . '/status/start';
		$response = $proxmox->post($logrequest, $pve_cmdparam);
	}
	// DEBUG - Log the request parameters before it's fired
	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		pvewhmcs_secure_log_module_call(
			'pvewhmcs',
			__FUNCTION__,
			$logrequest,
			json_encode(pvewhmcs_safe_log_result($response))
		);
	}
	// Return success only if no errors returned by PVE
	if (isset($response) && !isset($response['errors'])) {
		return "success";
	} else {
		return pvewhmcs_client_safe_error(
			$params,
			__FUNCTION__,
			array(
				'stage' => 'pve-response',
				'response' => isset($response) ? pvewhmcs_redact_log_value($response) : 'No response / login failed',
			)
		);
	}
}

// PVE API FUNCTION, CLIENT/ADMIN: Reboot the VM/CT
function pvewhmcs_vmReboot($params) {
	try {
		return pvewhmcs_vmReboot_internal($params);
	} catch (\Throwable $e) {
		return pvewhmcs_client_safe_error(
			$params,
			'pvewhmcs_vmReboot',
			$e->getMessage()
		);
	}
}

function pvewhmcs_vmReboot_internal($params) {
	// Gather access credentials for PVE, as these are no longer passed for Client Area
	$pveservice = Capsule::table('tblhosting')->find($params['serviceid']) ;
	$pveserver = Capsule::table('tblservers')->where('id','=',$pveservice->server)->get()[0] ;
	$serverip = !empty($pveserver->hostname) ? $pveserver->hostname : $pveserver->ipaddress;
	$serverusername = $pveserver->username;

	$api_data = array(
		'password2' => $pveserver->password,
	);
	$serverpassword = localAPI('DecryptPassword', $api_data);
	$serverport = $pveserver->port;

	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword['password'], $serverport, true, true);
	if ($proxmox->login()) {
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->first();
		if ($guest === null) {
			return pvewhmcs_client_safe_error($params, __FUNCTION__, 'Guest mapping was not found for the requested service.');
		}
		$guest_node = pvewhmcs_find_guest_node($proxmox, $guest, $params['serviceid']);
		if (empty($guest_node)) {
			return pvewhmcs_client_safe_error($params, __FUNCTION__, array('stage' => 'node-resolution', 'vmid' => isset($guest->vmid) ? (int) $guest->vmid : null));
		}
		$pve_cmdparam = array();
		// Check status before doing anything
		$guest_specific = $proxmox->get('/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid . '/status/current');
		if ($guest_specific['status'] == 'stopped') {
			// START if Stopped
			$logrequest = '/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid . '/status/start';
			$response = $proxmox->post($logrequest, $pve_cmdparam);
		} else {
			// REBOOT if Started
			$logrequest = '/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid . '/status/reboot';
			$response = $proxmox->post($logrequest, $pve_cmdparam);
		}
	}

	// DEBUG - Log the request parameters before it's fired
	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		pvewhmcs_secure_log_module_call(
			'pvewhmcs',
			__FUNCTION__,
			$logrequest,
			json_encode(pvewhmcs_safe_log_result($response))
		);
	}
	// Return success only if no errors returned by PVE
	if (isset($response) && !isset($response['errors'])) {
		return "success";
	} else {
		return pvewhmcs_client_safe_error(
			$params,
			__FUNCTION__,
			array(
				'stage' => 'pve-response',
				'response' => isset($response) ? pvewhmcs_redact_log_value($response) : 'No response / login failed',
			)
		);
	}
}

// PVE API FUNCTION, CLIENT/ADMIN: Shutdown the VM/CT
function pvewhmcs_vmShutdown($params) {
	try {
		return pvewhmcs_vmShutdown_internal($params);
	} catch (\Throwable $e) {
		return pvewhmcs_client_safe_error(
			$params,
			'pvewhmcs_vmShutdown',
			$e->getMessage()
		);
	}
}

function pvewhmcs_vmShutdown_internal($params) {
	// Gather access credentials for PVE, as these are no longer passed for Client Area
	$pveservice = Capsule::table('tblhosting')->find($params['serviceid']) ;
	$pveserver = Capsule::table('tblservers')->where('id','=',$pveservice->server)->get()[0] ;
	
	$serverip = !empty($pveserver->hostname) ? $pveserver->hostname : $pveserver->ipaddress;
	$serverusername = $pveserver->username;

	$api_data = array(
		'password2' => $pveserver->password,
	);
	$serverpassword = localAPI('DecryptPassword', $api_data);
	$serverport = $pveserver->port;

	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword['password'], $serverport, true, true);
	if ($proxmox->login()) {
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->first();
		if ($guest === null) {
			return pvewhmcs_client_safe_error($params, __FUNCTION__, 'Guest mapping was not found for the requested service.');
		}
		$guest_node = pvewhmcs_find_guest_node($proxmox, $guest, $params['serviceid']);
		if (empty($guest_node)) {
			return pvewhmcs_client_safe_error($params, __FUNCTION__, array('stage' => 'node-resolution', 'vmid' => isset($guest->vmid) ? (int) $guest->vmid : null));
		}
		$pve_cmdparam = array();
		// $pve_cmdparam['timeout'] = '60';
		$logrequest = '/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid . '/status/shutdown';
		$response = $proxmox->post($logrequest, $pve_cmdparam);
	}

	// DEBUG - Log the request parameters before it's fired
	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		pvewhmcs_secure_log_module_call(
			'pvewhmcs',
			__FUNCTION__,
			$logrequest,
			json_encode(pvewhmcs_safe_log_result($response))
		);
	}
	// Return success only if no errors returned by PVE
	if (isset($response) && !isset($response['errors'])) {
		return "success";
	} else {
		return pvewhmcs_client_safe_error(
			$params,
			__FUNCTION__,
			array(
				'stage' => 'pve-response',
				'response' => isset($response) ? pvewhmcs_redact_log_value($response) : 'No response / login failed',
			)
		);
	}
}

// PVE API FUNCTION, CLIENT/ADMIN: Stop the VM/CT
function pvewhmcs_vmStop($params) {
	try {
		return pvewhmcs_vmStop_internal($params);
	} catch (\Throwable $e) {
		return pvewhmcs_client_safe_error(
			$params,
			'pvewhmcs_vmStop',
			$e->getMessage()
		);
	}
}

function pvewhmcs_vmStop_internal($params) {
	// Gather access credentials for PVE, as these are no longer passed for Client Area
	$pveservice = Capsule::table('tblhosting')->find($params['serviceid']) ;
	$pveserver = Capsule::table('tblservers')->where('id','=',$pveservice->server)->get()[0] ;
	$serverip = !empty($pveserver->hostname) ? $pveserver->hostname : $pveserver->ipaddress;
	$serverusername = $pveserver->username;

	$api_data = array(
		'password2' => $pveserver->password,
	);
	$serverpassword = localAPI('DecryptPassword', $api_data);
	$serverport = $pveserver->port;

	$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword['password'], $serverport, true, true);
	if ($proxmox->login()) {
		$guest = Capsule::table('mod_pvewhmcs_vms')->where('id','=',$params['serviceid'])->first();
		if ($guest === null) {
			return pvewhmcs_client_safe_error($params, __FUNCTION__, 'Guest mapping was not found for the requested service.');
		}
		$guest_node = pvewhmcs_find_guest_node($proxmox, $guest, $params['serviceid']);
		if (empty($guest_node)) {
			return pvewhmcs_client_safe_error($params, __FUNCTION__, array('stage' => 'node-resolution', 'vmid' => isset($guest->vmid) ? (int) $guest->vmid : null));
		}
		$pve_cmdparam = array();
		// $pve_cmdparam['timeout'] = '60';
		$logrequest = '/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid . '/status/stop';
		$response = $proxmox->post($logrequest, $pve_cmdparam);
	}

	// DEBUG - Log the request parameters before it's fired
	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		pvewhmcs_secure_log_module_call(
			'pvewhmcs',
			__FUNCTION__,
			$logrequest,
			json_encode(pvewhmcs_safe_log_result($response))
		);
	}
	// Return success only if no errors returned by PVE
	if (isset($response) && !isset($response['errors'])) {
		return "success";
	} else {
		return pvewhmcs_client_safe_error(
			$params,
			__FUNCTION__,
			array(
				'stage' => 'pve-response',
				'response' => isset($response) ? pvewhmcs_redact_log_value($response) : 'No response / login failed',
			)
		);
	}
}

/**
 * Find which node a specific VMID resides on using cluster resources.
 *
 * @param PVE2_API $proxmox
 * @param int $vmid
 * @return string Node name
 * @throws Exception if VMID not found in cluster
 */
function pvewhmcs_find_node_by_vmid($proxmox, $vmid) {
	// targeted search for vm
	$resources = $proxmox->get('/cluster/resources?type=vm');
	foreach ($resources as $res) {
		if (isset($res['vmid']) && (int)$res['vmid'] == (int)$vmid) {
			return $res['node'];
		}
	}
	throw new Exception("PVEWHMCS Auto-Discovery: Template/VM ID {$vmid} not found in the cluster.");
}

/**
 * Locate the Proxmox node that hosts a given VM/CT.
 *
 * @param PVE2_API $proxmox
 * @param object   $guest   Row from mod_pvewhmcs_vms (expects ->vmid, ->vtype)
 * @param int      $serviceId WHMCS service ID (compatibility)
 * @return string|null
 */
function pvewhmcs_find_guest_node(PVE2_API $proxmox, $guest, $serviceId)
{
    // Where does the Guest live?
    $cluster_resources = $proxmox->get('/cluster/resources');

    if (is_array($cluster_resources)) {
        foreach ($cluster_resources as $res) {
			// Ensure required keys exist before accessing
            if (!isset($res['type'], $res['vmid'], $res['node'])) {
                continue;
            }

            // Match the vmid + Guest type (most reliable)
            if ($res['vmid'] == $guest->vmid && $res['type'] === $guest->vtype) {
                return $res['node'];
            }

            // Legacy (<1.2.9): vmid == serviceid
            if ($res['vmid'] == $serviceId && $res['type'] === $guest->vtype) {
                return $res['node'];
            }
        }
    }

    return null;
}

// CLIENT AREA: REFRESH TO CHECK STATUS ON-CLICK
function pvewhmcs_vmCheck($params) {
	return "success";
}

// NETWORKING FUNCTION: Convert subnet mask to CIDR
function mask2cidr($mask){
	$long = ip2long($mask);
	$base = ip2long('255.255.255.255');
	return 32-log(($long ^ $base)+1,2);
}

function bytes2format($bytes, $precision = 2, $_1024 = true) {
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
	$bytes = max( $bytes, 0 );
	$pow = floor( ($bytes ? log( $bytes ) : 0) / log( ($_1024 ? 1024 : 1000) ) );
	$pow = min( $pow, count( $units ) - 1 );
	$bytes /= pow( ($_1024 ? 1024 : 1000), $pow );
	return round( $bytes, $precision ) . ' ' . $units[$pow];
}

function time2format($s) {
	$d = intval( $s / 86400 );
	if ($d < '10') {
		$d = '0' . $d;
	}
	$s -= $d * 86400;
	$h = intval( $s / 3600 );
	if ($h < '10') {
		$h = '0' . $h;
	}
	$s -= $h * 3600;
	$m = intval( $s / 60 );
	if ($m < '10') {
		$m = '0' . $m;
	}
	$s -= $m * 60;
	if ($s < '10') {
		$s = '0' . $s;
	}
	if ($d) {
		$str = $d . ' days ';
	}
	if ($h) {
		$str .= $h . ':';
	}
	if ($m) {
		$str .= $m . ':';
	}
	if ($s) {
		$str .= $s . '';
	}
	return $str;
}
?>
