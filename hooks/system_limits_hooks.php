<?php
defined('BASEPATH') or exit('No direct script access allowed');
function system_limits_register_record_hooks()
{
    static $registered = false;
    if ($registered) { return; }
    $registered = true;
    hooks()->add_action('before_lead_added', function($data=null){ system_limits_block_or_return('leads'); return $data; });
    hooks()->add_action('before_client_added', function($data=null){ system_limits_block_or_return('customers'); return $data; });
    hooks()->add_action('before_create_staff_member', function($data=null){ system_limits_block_or_return('staff'); return $data; });
    hooks()->add_action('before_invoice_added', function($data=null){ system_limits_block_or_return('invoices'); return $data; });
    hooks()->add_action('before_estimate_added', function($data=null){ system_limits_block_or_return('estimates'); return $data; });
    hooks()->add_filter('before_create_proposal', function($data){ system_limits_block_or_return('proposals'); return $data; });
    hooks()->add_filter('before_add_project', function($data){ system_limits_block_or_return('projects'); return $data; });
    hooks()->add_filter('before_add_task', function($data){ system_limits_block_or_return('tasks'); return $data; });

    // Ticket limit support: cover common Perfex ticket creation flows without heavy queries.
    hooks()->add_action('before_ticket_added', function($data=null){ system_limits_block_or_return('tickets'); return $data; });
    hooks()->add_action('before_ticket_created', function($data=null){ system_limits_block_or_return('tickets'); return $data; });
    hooks()->add_filter('before_create_ticket', function($data){ system_limits_block_or_return('tickets'); return $data; });
    hooks()->add_filter('before_add_ticket', function($data){ system_limits_block_or_return('tickets'); return $data; });
    hooks()->add_action('before_upload_project_attachment', function($project_id=null){ system_limits_check_pending_uploads(); });
    hooks()->add_filter('before_handle_project_file_uploads', function($data){ system_limits_check_pending_uploads(); return $data; });
}
