<?php
defined('BASEPATH') or exit('No direct script access allowed');

function system_limits_format_bytes($bytes, $precision = 2)
{
    $bytes = (float) $bytes;
    if ($bytes <= 0) { return '0 B'; }
    $units = ['B','KB','MB','GB','TB'];
    $pow = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

function system_limits_to_bytes($value, $unit = 'GB')
{
    $value = max(0, (float) $value);
    $unit = strtoupper($unit);
    $map = ['B'=>1,'KB'=>1024,'MB'=>1048576,'GB'=>1073741824,'TB'=>1099511627776];
    return (int) round($value * ($map[$unit] ?? 1073741824));
}

function system_limits_get($resource)
{
    $CI = &get_instance();
    $CI->load->model('system_limits/System_limits_model', 'sl_model');
    return $CI->sl_model->get_limit($resource);
}

function system_limits_usage($resource)
{
    $CI = &get_instance();
    switch ($resource) {
        case 'leads': return (int) $CI->db->count_all(db_prefix().'leads');
        case 'staff':
            if ($CI->db->field_exists('is_system_hidden_admin', db_prefix().'staff')) {
                return (int) $CI->db->where('is_system_hidden_admin !=', 1)->count_all_results(db_prefix().'staff');
            }
            return (int) $CI->db->count_all(db_prefix().'staff');
        case 'customers': return (int) $CI->db->count_all(db_prefix().'clients');
        case 'proposals': return (int) $CI->db->count_all(db_prefix().'proposals');
        case 'estimates': return (int) $CI->db->count_all(db_prefix().'estimates');
        case 'invoices': return (int) $CI->db->count_all(db_prefix().'invoices');
        case 'projects': return (int) $CI->db->count_all(db_prefix().'projects');
        case 'tasks': return (int) $CI->db->count_all(db_prefix().'tasks');
        case 'tickets':
            return $CI->db->table_exists(db_prefix().'tickets') ? (int) $CI->db->count_all(db_prefix().'tickets') : 0;
        case 'media':
            if ($CI->db->table_exists(db_prefix().'system_storage_files')) {
                return (int)$CI->db->where('is_deleted',0)->count_all_results(db_prefix().'system_storage_files');
            }
            return $CI->db->table_exists(db_prefix().'files') ? (int)$CI->db->count_all(db_prefix().'files') : 0;
        default: return 0;
    }
}

function system_limits_apply_hidden_staff_exclusion($alias = null)
{
    $CI = &get_instance();
    if (function_exists('system_limits_hidden_admin_privacy_enabled') && system_limits_hidden_admin_privacy_enabled() && !system_limits_current_user_can_view_hidden_admins()) {
        $table = db_prefix().'staff';
        if ($CI->db->field_exists('is_system_hidden_admin', $table)) {
            $column = $alias ? $alias.'.is_system_hidden_admin' : 'is_system_hidden_admin';
            $CI->db->where($column.' !=', 1);
        }
    }
}

function system_limits_can_add($resource)
{
    if (function_exists('is_super_admin') && is_super_admin()) { return [true, null]; }
    $row = system_limits_get($resource);
    if (!$row || empty($row['is_enabled'])) { return [true, null]; }
    $limit = isset($row['limit_value']) ? (int) $row['limit_value'] : 0;
    if ($limit <= 0) { return [true, null]; }
    if (system_limits_usage($resource) >= $limit) {
        return [false, _l('system_limits_reached')];
    }
    return [true, null];
}

function system_limits_block_or_return($resource)
{
    [$ok, $msg] = system_limits_can_add($resource);
    if ($ok) { return true; }
    $CI = &get_instance();
    if ($CI->input->is_ajax_request()) {
        http_response_code(400);
        // Some Perfex AJAX forms display the raw response body when an error is returned.
        // Return plain text for lead/ticket limit blocks so users see only the clean message.
        if (in_array($resource, ['leads', 'tickets'], true)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $msg; exit;
        }
        echo json_encode(['success'=>false,'message'=>$msg]); exit;
    }
    set_alert('danger', $msg);
    redirect($_SERVER['HTTP_REFERER'] ?? admin_url()); exit;
}

function system_limits_flatten_uploaded_files($files)
{
    $out = [];
    foreach ($files as $field => $file) {
        if (!isset($file['name'])) { continue; }
        if (is_array($file['name'])) {
            $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($file['name']));
            foreach ($it as $keys => $name) {
                $path = [];
                for ($i = 0; $i < $it->getDepth(); $i++) { $path[] = $it->getSubIterator($i)->key(); }
                $path[] = $it->key();
                $tmp = $file['tmp_name']; $size = $file['size']; $type = $file['type']; $err = $file['error'];
                foreach ($path as $p) { $tmp = $tmp[$p]; $size = $size[$p]; $type = $type[$p]; $err = $err[$p]; }
                if ($name !== '' && (int)$err === 0) { $out[] = ['field'=>$field,'name'=>$name,'tmp_name'=>$tmp,'size'=>(int)$size,'type'=>$type]; }
            }
        } elseif ($file['name'] !== '' && (int)$file['error'] === 0) {
            $out[] = ['field'=>$field,'name'=>$file['name'],'tmp_name'=>$file['tmp_name'],'size'=>(int)$file['size'],'type'=>$file['type']];
        }
    }
    return $out;
}

function system_limits_check_pending_uploads($client_area = false)
{
    if (empty($_FILES) || get_option('system_limits_storage_enabled') != '1') { return true; }
    $CI = &get_instance();
    $CI->load->model('system_limits/System_limits_model', 'sl_model');
    $files = system_limits_flatten_uploaded_files($_FILES);
    if (!$files) { return true; }

    $totalNew = 0; $largest = 0; $blockedName = '';
    $allowed = trim((string)get_option('system_limits_allowed_file_types'));
    $maxSingle = (int)get_option('system_limits_max_single_file_bytes');
    $allowedList = array_filter(array_map('trim', explode(',', strtolower($allowed))));

    foreach ($files as $f) {
        $totalNew += (int)$f['size'];
        if ($f['size'] > $largest) { $largest = $f['size']; $blockedName = $f['name']; }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($allowedList && !in_array($ext, $allowedList, true)) {
            system_limits_upload_denied(sprintf(_l('system_limits_file_type_blocked'), html_escape($ext)));
        }
        if ($maxSingle > 0 && $f['size'] > $maxSingle) {
            system_limits_upload_denied(sprintf(_l('system_limits_single_file_too_large'), html_escape($blockedName), system_limits_format_bytes($maxSingle)));
        }
    }

    $summary = $CI->sl_model->storage_summary();
    $limit = (int)get_option('system_limits_storage_limit_bytes');
    $projected = $summary['used_bytes'] + $totalNew;
    $threshold = min(100, max(1, (int)get_option('system_limits_warning_threshold')));
    if ($limit > 0 && (($projected / $limit) * 100) >= $threshold) {
        $CI->sl_model->add_audit('storage_warning_threshold', 'Storage warning threshold reached or exceeded.', ['projected_bytes'=>$projected,'limit_bytes'=>$limit,'threshold'=>$threshold]);
        if (!$CI->input->is_ajax_request()) {
            set_alert('warning', sprintf(_l('system_limits_storage_threshold_warning'), $threshold, system_limits_format_bytes($projected), system_limits_format_bytes($limit)));
        }
    }
    if ($limit > 0 && $projected > $limit) {
        $CI->sl_model->add_audit('upload_blocked', 'Upload blocked because global storage limit would be exceeded.', ['new_upload_bytes'=>$totalNew,'used_bytes'=>$summary['used_bytes'],'limit_bytes'=>$limit]);
        if (get_option('system_limits_restriction_behavior') === 'warn') {
            if (!$CI->input->is_ajax_request()) {
                set_alert('warning', sprintf(_l('system_limits_storage_exceeded_warn_only'), system_limits_format_bytes($limit), system_limits_format_bytes($summary['used_bytes'])));
            }
            return true;
        }
        system_limits_upload_denied(sprintf(_l('system_limits_storage_exceeded'), system_limits_format_bytes($limit), system_limits_format_bytes($summary['used_bytes'])), true);
    }
    return true;
}

function system_limits_upload_denied($message, $plainTextAjax = false)
{
    $CI = &get_instance();
    if ($CI->input->is_ajax_request()) {
        http_response_code(400);
        if ($plainTextAjax) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $message; exit;
        }
        echo json_encode(['success'=>false,'message'=>$message]); exit;
    }
    set_alert('danger', $message); redirect($_SERVER['HTTP_REFERER'] ?? admin_url()); exit;
}

function system_limits_guess_module_from_rel_type($type)
{
    $type = strtolower((string)$type);
    $map = ['lead'=>'Leads','project'=>'Projects','task'=>'Tasks','ticket'=>'Tickets','contract'=>'Contracts','customer'=>'Customers','expense'=>'Expenses','proposal'=>'Proposals','estimate'=>'Estimates','invoice'=>'Invoices','newsfeed_post'=>'Newsfeed','estimate_request'=>'Estimate Requests'];
    return $map[$type] ?? ucfirst($type ?: 'Other');
}


function system_limits_elfinder_upload_presave($cmd, &$result, $args, $elfinder, $volume)
{
    if (get_option('system_limits_storage_enabled') != '1') { return true; }
    $size = 0; $name = '';
    if (isset($args['FILES']['upload'])) {
        $files = system_limits_flatten_uploaded_files(['upload' => $args['FILES']['upload']]);
        foreach ($files as $f) { $size += (int)$f['size']; $name = $f['name']; }
    } elseif (!empty($_FILES)) {
        foreach (system_limits_flatten_uploaded_files($_FILES) as $f) { $size += (int)$f['size']; $name = $f['name']; }
    }
    $CI = &get_instance(); $CI->load->model('system_limits/System_limits_model', 'sl_model');
    $check = $CI->sl_model->can_accept_upload($size, $name);
    if (!$check['success']) {
        $result = ['error' => $check['message']];
        return false;
    }
    return true;
}

function system_limits_elfinder_removed($cmd, $result, $args, $elfinder, $volume)
{
    $CI = &get_instance();
    $CI->load->model('system_limits/System_limits_model', 'sl_model');
    $CI->sl_model->refresh_deleted_flags(250);
    $CI->sl_model->rebuild_usage_cache();
    return true;
}

function system_limits_resource_label($resource)
{
    $labels = [
        'leads'=>'Leads','staff'=>'Staff','customers'=>'Customers','proposals'=>'Proposals','estimates'=>'Estimates','invoices'=>'Invoices','projects'=>'Projects','tasks'=>'Tasks','tickets'=>'Tickets','media'=>'Media / Attachments'
    ];
    return $labels[$resource] ?? ucfirst($resource);
}

function system_limits_progress_class($percent)
{
    $percent = (float)$percent;
    if ($percent >= 95) return 'danger';
    if ($percent >= 80) return 'warning';
    return 'success';
}


function system_limits_truncate_filename($filename, $max = 20)
{
    $filename = (string)$filename;
    if (function_exists('mb_strlen')) {
        if (mb_strlen($filename) <= $max) { return $filename; }
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = $ext ? '.' . $ext : '';
        $keep = max(6, $max - mb_strlen($suffix) - 3);
        $front = max(4, (int)ceil($keep * 0.55));
        $back = max(3, $keep - $front);
        $base = $suffix ? mb_substr($filename, 0, -mb_strlen($suffix)) : $filename;
        return mb_substr($base, 0, $front) . '...' . mb_substr($base, -$back) . $suffix;
    }
    if (strlen($filename) <= $max) { return $filename; }
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $suffix = $ext ? '.' . $ext : '';
    $keep = max(6, $max - strlen($suffix) - 3);
    $front = max(4, (int)ceil($keep * 0.55));
    $back = max(3, $keep - $front);
    $base = $suffix ? substr($filename, 0, -strlen($suffix)) : $filename;
    return substr($base, 0, $front) . '...' . substr($base, -$back) . $suffix;
}
