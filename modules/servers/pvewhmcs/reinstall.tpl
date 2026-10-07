{* Proxmox VE for WHMCS - Client Reinstall / Rebuild page *}

<div class="pve-reinstall-page">
    <div class="panel panel-danger">
        <div class="panel-heading">
            <strong><i class="fa fa-refresh"></i> Reinstall Operating System</strong>
        </div>
        <div class="panel-body">
            {if $reinstall_message}
                <div class="alert alert-{if $reinstall_message_type}{$reinstall_message_type|escape:'html':'UTF-8'}{else}info{/if}">
                    <strong>
                        {if $reinstall_message_type == 'success'}Success:{elseif $reinstall_message_type == 'danger'}Error:{elseif $reinstall_message_type == 'warning'}Notice:{else}Information:{/if}
                    </strong>
                    {$reinstall_message|escape:'html':'UTF-8'}
                </div>
            {/if}

            {if $reinstall_message_type == 'success'}
                {if $reinstall_new_vmid}
                    <p><strong>New VMID:</strong> <code>{$reinstall_new_vmid|escape:'html':'UTF-8'}</code></p>
                {/if}

                {if $reinstall_new_password}
                    <div class="alert alert-info">
                        <strong>New password</strong><br>
                        <code style="font-size:16px; user-select:all;">{$reinstall_new_password|escape:'html':'UTF-8'}</code>
                        <br><small>Copy this password now. The reinstall workflow does not write it to module logs.</small>
                    </div>
                {/if}

                {if !$reinstall_password_stored}
                    <div class="alert alert-warning">
                        WHMCS could not save the new password to the service record. Keep the password shown above and contact support.
                    </div>
                {/if}

                {if $reinstall_cleanup_warning}
                    <div class="alert alert-warning">
                        <strong>Cleanup warning:</strong>
                        {$reinstall_cleanup_warning|escape:'html':'UTF-8'}
                    </div>
                {/if}

                <p>
                    <a class="btn btn-default" href="clientarea.php?action=productdetails&id={$reinstall_service_id|escape:'url'}">
                        <i class="fa fa-arrow-left"></i> Back to Service
                    </a>
                </p>
            {elseif $reinstall_show_form}
                <div class="alert alert-danger">
                    <strong>Warning:</strong> Reinstall permanently replaces the current operating system and its disk data.
                    Back up anything you need before continuing.
                </div>

                <p>
                    The replacement guest is prepared first through the Proxmox VE 9 API. The current guest is stopped
                    only after the replacement has been created successfully.
                </p>

                <form method="post" action="clientarea.php?action=productdetails">
                    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
                    <input type="hidden" name="id" value="{$reinstall_service_id|escape:'html':'UTF-8'}">
                    <input type="hidden" name="modop" value="custom">
                    <input type="hidden" name="a" value="Reinstall">
                    <input type="hidden" name="pvewhmcs_reinstall_action" value="execute">
                    <input type="hidden" name="pvewhmcs_reinstall_nonce" value="{$reinstall_nonce|escape:'html':'UTF-8'}">

                    <div class="form-group">
                        <label for="pvewhmcs_reinstall_image">Operating System / Template</label>
                        <select class="form-control" id="pvewhmcs_reinstall_image" name="pvewhmcs_reinstall_image" required>
                            <option value="">Select an operating system...</option>
                            {foreach from=$reinstall_options key=value item=label}
                                <option value="{$value|escape:'html':'UTF-8'}">{$label|escape:'html':'UTF-8'}</option>
                            {/foreach}
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="pvewhmcs_reinstall_phrase">
                            Type <code>REINSTALL</code> to confirm
                        </label>
                        <input
                            class="form-control"
                            type="text"
                            id="pvewhmcs_reinstall_phrase"
                            name="pvewhmcs_reinstall_phrase"
                            autocomplete="off"
                            required
                        >
                    </div>

                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="pvewhmcs_reinstall_confirm" value="yes" required>
                            I understand that the current OS and disk data will be destroyed.
                        </label>
                    </div>

                    <button type="submit" class="btn btn-danger">
                        <i class="fa fa-refresh"></i> Reinstall Server
                    </button>

                    <a class="btn btn-default" href="clientarea.php?action=productdetails&id={$reinstall_service_id|escape:'url'}">
                        Cancel
                    </a>
                </form>
            {else}
                <p>
                    <a class="btn btn-default" href="clientarea.php?action=productdetails&id={$reinstall_service_id|escape:'url'}">
                        <i class="fa fa-arrow-left"></i> Back to Service
                    </a>
                </p>
            {/if}
        </div>
    </div>
</div>
