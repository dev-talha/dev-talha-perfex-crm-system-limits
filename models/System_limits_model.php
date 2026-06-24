<?php
defined('BASEPATH') or exit('No direct script access allowed');

class System_limits_model extends App_Model
{
    private $limits_table;
    private $storage_table;
    private $audit_table;

    public function __construct()
    {
        parent::__construct();
        $this->limits_table = db_prefix().'system_limits';
        $this->storage_table = db_prefix().'system_storage_files';
        $this->audit_table = db_prefix().'system_limits_audit_logs';
    }

    private function save_option_value($name, $value)
    {
        $value = (string)$value;
        if (get_option($name) === false) {
            return add_option($name, $value);
        }
        return update_option($name, $value);
    }

    public function get_all()
    {
        return $this->db->table_exists($this->limits_table) ? $this->db->order_by('resource','ASC')->get($this->limits_table)->result_array() : [];
    }

    public function get_limit($resource)
    {
        if (!$this->db->table_exists($this->limits_table)) { return null; }
        return $this->db->where('resource', $resource)->get($this->limits_table)->row_array();
    }

    public function upsert($resource, $limit_value, $is_enabled)
    {
        $limit_value = ($limit_value === '' || $limit_value === null) ? null : max(0, (int)$limit_value);
        $row = $this->get_limit($resource);
        $data = ['limit_value'=>$limit_value, 'is_enabled'=>(int)$is_enabled, 'updated_at'=>date('Y-m-d H:i:s')];
        if ($row) { return $this->db->where('id', $row['id'])->update($this->limits_table, $data); }
        $data['resource'] = $resource;
        return $this->db->insert($this->limits_table, $data);
    }

    public function save_storage_settings($post)
    {
        $old = [
            'enabled'=>get_option('system_limits_storage_enabled'),
            'limit'=>get_option('system_limits_storage_limit_bytes'),
            'unit'=>get_option('system_limits_storage_unit'),
            'threshold'=>get_option('system_limits_warning_threshold'),
        ];

        $storageUnit = strtoupper((string)($post['storage_unit'] ?? get_option('system_limits_storage_unit') ?: 'GB'));
        if (!in_array($storageUnit, ['MB','GB','TB'], true)) { $storageUnit = 'GB'; }

        $singleUnit = strtoupper((string)($post['max_single_file_unit'] ?? get_option('system_limits_max_single_file_unit') ?: 'MB'));
        if (!in_array($singleUnit, ['MB','GB'], true)) { $singleUnit = 'MB'; }

        $restriction = (string)($post['restriction_behavior'] ?? get_option('system_limits_restriction_behavior') ?: 'block');
        if (!in_array($restriction, ['block','warn'], true)) { $restriction = 'block'; }

        $this->save_option_value('system_limits_storage_enabled', isset($post['storage_enabled']) ? '1' : '0');
        $this->save_option_value('system_limits_storage_unit', $storageUnit);
        $this->save_option_value('system_limits_storage_limit_bytes', (string)system_limits_to_bytes($post['storage_limit'] ?? 0, $storageUnit));
        $this->save_option_value('system_limits_warning_threshold', (string)min(100, max(1, (int)($post['warning_threshold'] ?? 80))));
        $this->save_option_value('system_limits_restriction_behavior', $restriction);
        $this->save_option_value('system_limits_allowed_file_types', trim((string)($post['allowed_file_types'] ?? '')));
        $this->save_option_value('system_limits_max_single_file_unit', $singleUnit);
        $this->save_option_value('system_limits_max_single_file_bytes', (string)system_limits_to_bytes($post['max_single_file'] ?? 0, $singleUnit));
        $this->save_option_value('system_limits_notify_email', trim((string)($post['notify_email'] ?? '')));
        $this->add_audit('settings_updated', 'Storage settings updated.', ['old'=>$old, 'new'=>$post]);
        return true;
    }

    public function module_usage($filters = [])
    {
        $this->db->select('module_name, COUNT(id) as total_files, COALESCE(SUM(file_size),0) as total_bytes', false);
        $this->db->from($this->storage_table);
        $this->db->where('is_deleted', 0);
        $this->apply_filters($filters);
        $this->apply_hidden_staff_file_exclusion('staff_id');
        return $this->db->group_by('module_name')->order_by('total_bytes','DESC')->get()->result_array();
    }

    public function type_breakdown($filters = [])
    {
        $this->db->select('file_type, COUNT(id) as total_files, COALESCE(SUM(file_size),0) as total_bytes', false);
        $this->db->from($this->storage_table);
        $this->db->where('is_deleted', 0);
        $this->apply_filters($filters);
        $this->apply_hidden_staff_file_exclusion('staff_id');
        return $this->db->group_by('file_type')->order_by('total_bytes','DESC')->get()->result_array();
    }

    public function activity_by_date($filters = [])
    {
        $this->db->select('DATE(uploaded_at) as date, COUNT(id) as total_files, COALESCE(SUM(file_size),0) as total_bytes', false);
        $this->db->from($this->storage_table);
        $this->db->where('is_deleted', 0);
        $this->apply_filters($filters);
        $this->apply_hidden_staff_file_exclusion('staff_id');
        return $this->db->group_by('DATE(uploaded_at)')->order_by('date','DESC')->limit(60)->get()->result_array();
    }

    public function audit_logs($limit = 100)
    {
        return $this->db->order_by('id','DESC')->limit($limit)->get($this->audit_table)->result_array();
    }

    public function add_audit($action, $description, $metadata = [])
    {
        if (!$this->db->table_exists($this->audit_table)) { return false; }
        return $this->db->insert($this->audit_table, [
            'staff_id'=>function_exists('get_staff_user_id') ? get_staff_user_id() : null,
            'action'=>$action,
            'description'=>$description,
            'metadata'=>json_encode($metadata),
            'created_at'=>date('Y-m-d H:i:s'),
        ]);
    }

    private function largest_file($staff_id, $filters = [])
    {
        $this->db->select('file_name,file_size,file_type,module_name,uploaded_at')->from($this->storage_table)->where('is_deleted',0)->where('staff_id',$staff_id);
        $this->apply_filters($filters);
        return $this->db->order_by('file_size','DESC')->limit(1)->get()->row_array();
    }

    private function apply_filters($filters, $prefix = '')
    {
        $p = $prefix ? $prefix . '.' : '';
        if (!empty($filters['staff_id'])) { $this->db->where($p . 'staff_id', (int)$filters['staff_id']); }
        if (!empty($filters['module_name'])) { $this->db->where($p . 'module_name', $filters['module_name']); }
        if (!empty($filters['file_type'])) { $this->db->where($p . 'file_type', strtolower($filters['file_type'])); }
        if (!empty($filters['date_from'])) { $this->db->where($p . 'uploaded_at >=', $filters['date_from'] . ' 00:00:00'); }
        if (!empty($filters['date_to'])) { $this->db->where($p . 'uploaded_at <=', $filters['date_to'] . ' 23:59:59'); }
    }

    public function apply_report_filters_array($filters)
    {
        if (!empty($filters['staff_id'])) { $this->db->where('staff_id', (int)$filters['staff_id']); }
        if (!empty($filters['module_name'])) { $this->db->where('module_name', $filters['module_name']); }
        if (!empty($filters['file_type'])) { $this->db->where('file_type', strtolower($filters['file_type'])); }
        if (!empty($filters['date_from'])) { $this->db->where('uploaded_at >=', $filters['date_from'] . ' 00:00:00'); }
        if (!empty($filters['date_to'])) { $this->db->where('uploaded_at <=', $filters['date_to'] . ' 23:59:59'); }
    }

    private function apply_filters_fixed($filters)
    {
        if (!empty($filters['staff_id'])) { $this->db->where('staff_id', (int)$filters['staff_id']); }
        if (!empty($filters['module_name'])) { $this->db->where('module_name', $filters['module_name']); }
        if (!empty($filters['file_type'])) { $this->db->where('file_type', strtolower($filters['file_type'])); }
        if (!empty($filters['date_from'])) { $this->db->where('uploaded_at >=', $filters['date_from'] . ' 00:00:00'); }
        if (!empty($filters['date_to'])) { $this->db->where('uploaded_at <=', $filters['date_to'] . ' 23:59:59'); }
    }


    private function apply_hidden_staff_file_exclusion($column = 'staff_id')
    {
        if (function_exists('system_limits_hidden_admin_privacy_enabled') && system_limits_hidden_admin_privacy_enabled() && !system_limits_current_user_can_view_hidden_admins()) {
            $ids = function_exists('system_limits_hidden_admin_ids') ? array_map('intval', system_limits_hidden_admin_ids()) : [];
            if (!empty($ids)) {
                $this->db->group_start();
                $this->db->where_not_in($column, $ids);
                $this->db->or_where($column . ' IS NULL', null, false);
                $this->db->group_end();
            }
        }
    }



    private function storage_sync_interval_seconds()
    {
        $seconds = (int)get_option('system_limits_storage_sync_interval_seconds');
        return $seconds > 0 ? $seconds : 120;
    }

    private function storage_last_sync_at()
    {
        return (int)get_option('system_limits_storage_last_sync_at');
    }

    private function storage_sync_due($force = false)
    {
        if ($force) {
            return true;
        }

        $last = $this->storage_last_sync_at();
        if ($last <= 0) {
            return true;
        }

        return (time() - $last) >= $this->storage_sync_interval_seconds();
    }

    private function storage_normalize_path($path)
    {
        $path = trim(str_replace(["\r", "\n"], '', (string)$path));
        if ($path === '') {
            return '';
        }

        $real = @realpath($path);
        if ($real !== false && $real !== '') {
            $path = $real;
        }

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    private function storage_build_key($data)
    {
        if (!empty($data['storage_key'])) {
            return (string)$data['storage_key'];
        }

        if (!empty($data['source_table']) && !empty($data['source_id']) && strtolower((string)$data['source_table']) !== 'filesystem') {
            return strtolower((string)$data['source_table']) . '#' . (string)$data['source_id'];
        }

        $path = !empty($data['file_path']) ? $this->storage_normalize_path($data['file_path']) : '';
        if ($path !== '') {
            return 'filesystem#' . sha1(strtolower($path));
        }

        if (!empty($data['source_table']) && !empty($data['source_id'])) {
            return strtolower((string)$data['source_table']) . '#' . (string)$data['source_id'];
        }

        return 'filesystem#' . sha1(json_encode($data));
    }

    private function storage_should_skip_path($path)
    {
        $name = strtolower(basename((string)$path));
        if ($name === '') {
            return true;
        }

        if (in_array($name, ['index.html', '.ds_store', 'thumbs.db', 'desktop.ini', '.htaccess', '.gitkeep'], true)) {
            return true;
        }

        $normalized = str_replace('\\', '/', (string)$path);
        if (strpos($normalized, '/.tmb/') !== false || strpos($normalized, '/.thumbs/') !== false) {
            return true;
        }

        return false;
    }

    private function storage_root_paths()
    {
        if (function_exists('system_limits_storage_roots')) {
            $roots = system_limits_storage_roots();
            if (is_array($roots) && !empty($roots)) {
                return $roots;
            }
        }

        $roots = [];
        if (defined('FCPATH')) {
            $roots[] = rtrim(FCPATH, '/\\') . DIRECTORY_SEPARATOR . 'uploads';
            $roots[] = rtrim(FCPATH, '/\\') . DIRECTORY_SEPARATOR . 'media';
        }
        return array_values(array_unique(array_filter($roots)));
    }

    public function register_storage_asset($data, $replace = true)
    {
        return $this->upsert_storage_file($data, $replace);
    }

    public function forget_storage_asset($reference)
    {
        if (!$this->db->table_exists($this->storage_table)) {
            return false;
        }

        if (is_array($reference)) {
            if (!empty($reference['id'])) {
                $reference = (int)$reference['id'];
            } elseif (!empty($reference['file_path'])) {
                $reference = $reference['file_path'];
            } elseif (!empty($reference['source_table']) && !empty($reference['source_id'])) {
                $reference = strtolower((string)$reference['source_table']) . '#' . (string)$reference['source_id'];
            } else {
                return false;
            }
        }

        $this->db->where('is_deleted', 0);
        if (is_numeric($reference)) {
            $row = $this->db->where('id', (int)$reference)->get($this->storage_table)->row_array();
        } else {
            $reference = $this->storage_normalize_path((string)$reference);
            $row = $this->db->group_start()
                ->where('storage_key', $reference)
                ->or_where('file_path', $reference)
                ->group_end()
                ->get($this->storage_table)->row_array();
        }

        if (!$row) {
            return false;
        }

        $this->db->where('id', (int)$row['id'])->update($this->storage_table, ['is_deleted' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->add_audit('file_deleted', 'Tracked file was removed from central storage tracking.', ['id' => (int)$row['id'], 'file' => $row['file_name'] ?? '', 'path' => $row['file_path'] ?? '']);
        return true;
    }

    public function sync_storage_inventory($force = false, $limit = 20000)
    {
        if (!$this->db->table_exists($this->storage_table)) {
            return 0;
        }

        if (!$this->storage_sync_due($force)) {
            return 0;
        }

        $limit = max(1000, (int)$limit);
        $count = 0;
        $now = date('Y-m-d H:i:s');

        if ($this->db->table_exists(db_prefix() . 'files')) {
            $rows = $this->db->get(db_prefix() . 'files')->result_array();
            foreach ($rows as $row) {
                if ($count >= $limit) {
                    break;
                }
                $path = $this->resolve_file_path($row['rel_type'] ?? '', $row['rel_id'] ?? '', $row['file_name'] ?? '');
                $this->upsert_storage_file([
                    'storage_key' => 'files#' . (int)($row['id'] ?? 0),
                    'staff_id' => $row['staffid'] ?? null,
                    'client_id' => $row['contact_id'] ?? null,
                    'module_name' => system_limits_guess_module_from_rel_type($row['rel_type'] ?? ''),
                    'related_id' => $row['rel_id'] ?? null,
                    'file_name' => $row['file_name'] ?? '',
                    'file_path' => $path,
                    'file_type' => strtolower(pathinfo($row['file_name'] ?? '', PATHINFO_EXTENSION)),
                    'mime_type' => $row['filetype'] ?? '',
                    'file_size' => is_file($path) ? filesize($path) : 0,
                    'source_table' => 'files',
                    'source_id' => $row['id'] ?? null,
                    'uploaded_at' => $row['dateadded'] ?? null,
                    'is_deleted' => is_file($path) ? 0 : 1,
                ]);
                $count++;
            }
        }

        if ($this->db->table_exists(db_prefix() . 'project_files')) {
            $rows = $this->db->get(db_prefix() . 'project_files')->result_array();
            foreach ($rows as $row) {
                if ($count >= $limit) {
                    break;
                }
                $path = get_upload_path_by_type('project') . ($row['project_id'] ?? '') . '/' . ($row['file_name'] ?? '');
                $this->upsert_storage_file([
                    'storage_key' => 'project_files#' . (int)($row['id'] ?? 0),
                    'staff_id' => $row['staffid'] ?? null,
                    'client_id' => $row['contact_id'] ?? null,
                    'module_name' => 'Projects',
                    'related_id' => $row['project_id'] ?? null,
                    'file_name' => $row['original_file_name'] ?: ($row['file_name'] ?? ''),
                    'file_path' => $path,
                    'file_type' => strtolower(pathinfo($row['file_name'] ?? '', PATHINFO_EXTENSION)),
                    'mime_type' => $row['filetype'] ?? '',
                    'file_size' => is_file($path) ? filesize($path) : 0,
                    'source_table' => 'project_files',
                    'source_id' => $row['id'] ?? null,
                    'uploaded_at' => $row['dateadded'] ?? null,
                    'is_deleted' => is_file($path) ? 0 : 1,
                ]);
                $count++;
            }
        }

        foreach ($this->storage_root_paths() as $root) {
            if ($count >= $limit) {
                break;
            }
            if (!$root || !is_dir($root)) {
                continue;
            }

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if ($count >= $limit) {
                        break;
                    }
                    if (!$file->isFile()) {
                        continue;
                    }
                    $path = $this->storage_normalize_path($file->getPathname());
                    if ($path === '' || $this->storage_should_skip_path($path)) {
                        continue;
                    }

                    $name = $file->getFilename();
                    $this->upsert_storage_file([
                        'storage_key' => 'filesystem#' . sha1(strtolower($path)),
                        'staff_id' => null,
                        'client_id' => null,
                        'module_name' => 'Filesystem / Third Party',
                        'related_id' => null,
                        'file_name' => $name,
                        'file_path' => $path,
                        'file_type' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                        'mime_type' => function_exists('mime_content_type') ? @mime_content_type($path) : '',
                        'file_size' => (int)$file->getSize(),
                        'source_table' => 'filesystem',
                        'source_id' => sha1(strtolower($path)),
                        'uploaded_at' => date('Y-m-d H:i:s', $file->getMTime()),
                        'is_deleted' => 0,
                    ]);
                    $count++;
                }
            } catch (Throwable $e) {
                log_message('error', 'System Limits storage inventory scan failed for ' . $root . ': ' . $e->getMessage());
            }
        }

        $this->refresh_deleted_flags(0);
        $this->rebuild_usage_cache(true);
        update_option('system_limits_storage_last_sync_at', (string)time());
        return $count;
    }

    public function scan_storage_files()
    {
        return $this->sync_storage_inventory(true);
    }

    private function upsert_storage_file($data, $replace = true)
    {
        if (empty($data['source_table']) && empty($data['file_path'])) {
            return 0;
        }

        $data['file_path'] = !empty($data['file_path']) ? $this->storage_normalize_path($data['file_path']) : '';
        $data['storage_key'] = $this->storage_build_key($data);
        $data['updated_at'] = date('Y-m-d H:i:s');

        $existing = null;
        if (!empty($data['source_table']) && !empty($data['source_id']) && strtolower((string)$data['source_table']) !== 'filesystem') {
            $existing = $this->db->where('source_table', $data['source_table'])->where('source_id', $data['source_id'])->get($this->storage_table)->row_array();
        }

        if (!$existing && !empty($data['storage_key'])) {
            $existing = $this->db->where('storage_key', $data['storage_key'])->get($this->storage_table)->row_array();
        }

        if (!$existing && !empty($data['file_path'])) {
            $existing = $this->db->where('file_path', $data['file_path'])->get($this->storage_table)->row_array();
        }

        if ($existing) {
            if (empty($data['source_table']) && !empty($existing['source_table'])) {
                $data['source_table'] = $existing['source_table'];
            }
            if (empty($data['source_id']) && !empty($existing['source_id'])) {
                $data['source_id'] = $existing['source_id'];
            }
            if (empty($data['storage_key']) && !empty($existing['storage_key'])) {
                $data['storage_key'] = $existing['storage_key'];
            }

            if (!$replace) {
                return (int)$existing['id'];
            }

            $this->db->where('id', $existing['id'])->update($this->storage_table, $data);
            return (int)$existing['id'];
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $this->db->insert($this->storage_table, $data);
        return (int)$this->db->insert_id();
    }

    private function resolve_file_path($rel_type, $rel_id, $file_name)
    {
        if (function_exists('get_upload_path_by_type')) {
            return get_upload_path_by_type($rel_type) . $rel_id . '/' . $file_name;
        }
        return FCPATH . 'uploads/' . $rel_type . '/' . $rel_id . '/' . $file_name;
    }

    public function export_rows($filters = [])
    {
        $this->db->select('*')->from($this->storage_table)->where('is_deleted',0);
        $this->apply_filters_fixed($filters);
        $this->apply_hidden_staff_file_exclusion('staff_id');
        return $this->db->order_by('uploaded_at','DESC')->limit(5000)->get()->result_array();
    }

    public function can_accept_upload($incomingBytes, $fileName = '')
    {
        $incomingBytes = max(0, (int)$incomingBytes);
        $summary = $this->storage_summary(false);
        $limit = (int)get_option('system_limits_storage_limit_bytes');
        if ($limit <= 0 || get_option('system_limits_storage_enabled') != '1') {
            return ['success'=>true, 'message'=>''];
        }
        $projected = $summary['used_bytes'] + $incomingBytes;
        $threshold = min(100, max(1, (int)get_option('system_limits_warning_threshold')));
        $critical = min(100, max($threshold, (int)get_option('system_limits_critical_threshold')));
        $percent = $limit > 0 ? round(($projected / $limit) * 100, 2) : 0;
        if ($percent >= $threshold) {
            $this->send_usage_warning_if_needed('storage_'.$threshold, 'storage_warning', [
                'Storage' => $percent . '%',
                'Used' => system_limits_format_bytes($summary['used_bytes']),
                'Incoming Upload' => system_limits_format_bytes($incomingBytes),
                'Limit' => system_limits_format_bytes($limit),
            ]);
        }
        if ($percent >= $critical) {
            $this->send_usage_warning_if_needed('storage_'.$critical, 'storage_critical', [
                'Storage' => $percent . '%',
                'Used' => system_limits_format_bytes($summary['used_bytes']),
                'Limit' => system_limits_format_bytes($limit),
            ]);
        }
        if ($projected > $limit && get_option('system_limits_restriction_behavior') !== 'warn') {
            $msg = 'Storage limit exceeded. Your CRM has reached the maximum allowed storage. Please delete unused files or upgrade your package.';
            $this->add_audit('upload_blocked', $msg, ['file'=>$fileName,'incoming_bytes'=>$incomingBytes,'used_bytes'=>$summary['used_bytes'],'limit_bytes'=>$limit]);
            return ['success'=>false, 'message'=>$msg];
        }
        return ['success'=>true, 'message'=>''];
    }

    public function storage_summary($refresh = false)
    {
        if (!$this->db->table_exists($this->storage_table)) {
            $limit = (int)get_option('system_limits_storage_limit_bytes');
            return ['used_bytes'=>0,'total_files'=>0,'limit_bytes'=>$limit,'remaining_bytes'=>$limit,'usage_percent'=>0];
        }

        if ($refresh || $this->storage_sync_due(false)) {
            $this->sync_storage_inventory($refresh);
        }

        $row = $this->db->select('COALESCE(SUM(file_size),0) as used_bytes, COUNT(id) as total_files')
            ->where('is_deleted', 0)->get($this->storage_table)->row_array();
        $limit = (int)get_option('system_limits_storage_limit_bytes');
        $used = (int)($row['used_bytes'] ?? 0);
        return [
            'used_bytes'=>$used,
            'total_files'=>(int)($row['total_files'] ?? 0),
            'limit_bytes'=>$limit,
            'remaining_bytes'=>max(0, $limit - $used),
            'usage_percent'=>$limit > 0 ? round(($used / $limit) * 100, 2) : 0,
        ];
    }

    public function resource_overview()
    {
        $resources = ['leads','staff','customers','proposals','estimates','invoices','projects','tasks','tickets','media'];
        $out = [];
        foreach ($resources as $resource) {
            $row = $this->get_limit($resource);
            $used = $resource === 'media' ? (int)$this->storage_summary(false)['total_files'] : (int)system_limits_usage($resource);
            $limit = $row && !empty($row['is_enabled']) ? (int)$row['limit_value'] : 0;
            if ($resource === 'media') {
                $storage = $this->storage_summary(false);
                $used = $storage['used_bytes'];
                $limit = (int)$storage['limit_bytes'];
                $remaining = $storage['remaining_bytes'];
                $percent = $storage['usage_percent'];
                $used_label = system_limits_format_bytes($used);
                $limit_label = $limit > 0 ? system_limits_format_bytes($limit) : 'Unlimited';
                $remaining_label = $limit > 0 ? system_limits_format_bytes($remaining) : 'Unlimited';
            } else {
                $remaining = $limit > 0 ? max(0, $limit - $used) : null;
                $percent = $limit > 0 ? round(($used / $limit) * 100, 2) : 0;
                $used_label = (string)$used; $limit_label = $limit > 0 ? (string)$limit : 'Unlimited'; $remaining_label = $limit > 0 ? (string)$remaining : 'Unlimited';
            }
            $out[] = ['resource'=>$resource,'label'=>system_limits_resource_label($resource),'used'=>$used,'used_label'=>$used_label,'limit'=>$limit,'limit_label'=>$limit_label,'remaining'=>$remaining,'remaining_label'=>$remaining_label,'usage_percent'=>$percent,'enabled'=>$row ? (int)$row['is_enabled'] : 0,'status'=>system_limits_progress_class($percent)];
            if ($limit > 0 && $percent >= (int)get_option('system_limits_warning_threshold')) {
                $this->send_usage_warning_if_needed($resource.'_'.floor($percent/5)*5, 'resource_warning', [$resource => $percent.'%', 'Used'=>$used_label, 'Limit'=>$limit_label]);
            }
        }
        return $out;
    }

    public function staff_usage($filters = [])
    {
        $limit = (int)get_option('system_limits_storage_limit_bytes');
        $hasFileFilters = !empty($filters['module_name']) || !empty($filters['file_type']) || !empty($filters['date_from']) || !empty($filters['date_to']);

        if (!$hasFileFilters && empty($filters['staff_id'])) {
            $this->rebuild_usage_cache(false);
            $this->db->select('s.staffid, CONCAT(s.firstname," ",s.lastname) as staff_name, r.name as role_name, COALESCE(SUM(c.total_files),0) as total_files, COALESCE(SUM(c.total_size),0) as total_bytes, MAX(c.last_upload_date) as last_upload_date, MAX(c.largest_file) as largest_file_name, MAX(c.largest_file_size) as largest_file_size', false);
            $this->db->from(db_prefix().'staff s');
            $this->db->join(db_prefix().'roles r', 'r.roleid=s.role', 'left');
            $this->db->join(db_prefix().'system_storage_usage_cache c', 'c.staff_id=s.staffid', 'left');
            if (function_exists('system_limits_apply_hidden_staff_exclusion')) { system_limits_apply_hidden_staff_exclusion('s'); }
            $this->db->where('s.active', 1);
            $this->db->group_by('s.staffid');
            $rows = $this->db->order_by('total_bytes','DESC')->limit(250)->get()->result_array();
        } else {
            $this->db->select('s.staffid, CONCAT(s.firstname," ",s.lastname) as staff_name, r.name as role_name, COUNT(f.id) as total_files, COALESCE(SUM(f.file_size),0) as total_bytes, MAX(f.uploaded_at) as last_upload_date, SUBSTRING_INDEX(GROUP_CONCAT(f.file_name ORDER BY f.file_size DESC SEPARATOR "||"), "||", 1) as largest_file_name, MAX(f.file_size) as largest_file_size', false);
            $this->db->from(db_prefix().'staff s');
            $this->db->join(db_prefix().'roles r', 'r.roleid=s.role', 'left');
            $this->db->join($this->storage_table.' f', 'f.staff_id=s.staffid AND f.is_deleted=0', 'left');
            if (!empty($filters['staff_id'])) { $this->db->where('s.staffid', (int)$filters['staff_id']); }
            if (!empty($filters['module_name'])) { $this->db->where('f.module_name', $filters['module_name']); }
            if (!empty($filters['file_type'])) { $this->db->where('f.file_type', strtolower($filters['file_type'])); }
            if (!empty($filters['date_from'])) { $this->db->where('f.uploaded_at >=', $filters['date_from'] . ' 00:00:00'); }
            if (!empty($filters['date_to'])) { $this->db->where('f.uploaded_at <=', $filters['date_to'] . ' 23:59:59'); }
            if (function_exists('system_limits_apply_hidden_staff_exclusion')) { system_limits_apply_hidden_staff_exclusion('s'); }
            $this->db->where('s.active', 1);
            $this->db->group_by('s.staffid');
            $rows = $this->db->order_by('total_bytes','DESC')->limit(250)->get()->result_array();
        }

        foreach ($rows as &$row) {
            $row['largest_file'] = !empty($row['largest_file_name']) ? ['file_name'=>$row['largest_file_name'], 'file_size'=>(int)$row['largest_file_size']] : null;
            $row['usage_percent'] = $limit > 0 ? round(((int)$row['total_bytes'] / $limit) * 100, 2) : 0;
            $row['remaining_bytes'] = max(0, $limit - (int)$row['total_bytes']);
            $row['storage_limit'] = $limit;
            $row['most_used_module'] = $this->most_used_module((int)$row['staffid']);
        }
        return $rows;
    }

    private function most_used_module($staff_id)
    {
        $row = $this->db->where('staff_id',$staff_id)->order_by('total_size','DESC')->limit(1)->get(db_prefix().'system_storage_usage_cache')->row_array();
        return $row ? $row['module_name'] : '-';
    }

    public function rebuild_usage_cache($force = true)
    {
        $cache = db_prefix().'system_storage_usage_cache';
        if (!$this->db->table_exists($cache)) { return false; }
        if (!$force && (int)$this->db->count_all($cache) > 0) { return true; }
        $this->db->truncate($cache);
        if (!$this->db->table_exists($this->storage_table)) { return true; }
        $rows = $this->db->select('staff_id,module_name,COUNT(id) as total_files,COALESCE(SUM(file_size),0) as total_size,MAX(uploaded_at) as last_upload_date', false)
            ->where('is_deleted',0)->group_by(['staff_id','module_name'])->get($this->storage_table)->result_array();
        foreach ($rows as $r) {
            $largest = $this->db->select('file_name,file_size')->where('is_deleted',0)->where('staff_id',$r['staff_id'])->where('module_name',$r['module_name'])->order_by('file_size','DESC')->limit(1)->get($this->storage_table)->row_array();
            $this->db->insert($cache, ['staff_id'=>$r['staff_id'],'module_name'=>$r['module_name'],'total_files'=>$r['total_files'],'total_size'=>$r['total_size'],'last_upload_date'=>$r['last_upload_date'],'largest_file'=>$largest['file_name']??null,'largest_file_size'=>$largest['file_size']??0,'updated_at'=>date('Y-m-d H:i:s')]);
        }
        return true;
    }

    public function scan_media_folder($limit = 5000)
    {
        // Compatibility wrapper: the central inventory sync now includes media and uploads.
        return 0;
    }

    private function staff_media_slug_map()
    {
        $map=[];
        if ($this->db->field_exists('media_path_slug', db_prefix().'staff')) {
            foreach($this->db->select(db_prefix().'staff.staffid, '.db_prefix().'staff.media_path_slug')->get(db_prefix().'staff')->result_array() as $r) { if($r['media_path_slug']) $map[$r['media_path_slug']]=$r['staffid']; }
        }
        return $map;
    }

    public function refresh_deleted_flags($limit = 0)
    {
        if (!$this->db->table_exists($this->storage_table)) { return; }

        $queryLimit = ((int)$limit > 0) ? (int)$limit : 500;
        do {
            $rows = $this->db->select('id,file_path')->where('is_deleted',0)->limit($queryLimit)->get($this->storage_table)->result_array();
            foreach ($rows as $row) {
                if (!empty($row['file_path']) && !is_file($row['file_path'])) {
                    $this->db->where('id',$row['id'])->update($this->storage_table, ['is_deleted'=>1,'updated_at'=>date('Y-m-d H:i:s')]);
                }
            }
            $scanned = count($rows);
        } while ($limit <= 0 && $scanned === $queryLimit);
    }

    public function send_usage_warning_if_needed($eventKey, $eventType, $payload = [])
    {
        if (get_option('system_limits_enable_email_alerts') != '1') return false;
        $table = db_prefix().'system_notification_logs';
        if (!$this->db->table_exists($table)) return false;
        $key = $eventType.'_'.$eventKey.'_'.date('Ymd');
        if ($this->db->where('event_key',$key)->get($table)->row_array()) return false;
        $recipient = trim((string)get_option('system_limits_notify_email'));
        if (!$recipient) { $recipient = get_option('companyemail'); }
        $message = "Your CRM resource/storage usage is approaching the configured limit.\n\nPlease review your current usage or upgrade your package.\n\nCurrent Usage:\n";
        foreach ($payload as $k=>$v) { $message .= '- '.$k.': '.$v."\n"; }
        $message .= "\nTo avoid service interruption, please contact support or upgrade your package.";
        if (function_exists('send_mail_template')) {
            // Fallback to CodeIgniter email below when no custom template exists.
        }
        $CI=&get_instance(); $CI->load->library('email');
        $CI->email->from(get_option('smtp_email') ?: get_option('companyemail'), get_option('companyname'));
        $CI->email->to($recipient); $CI->email->subject('System Resource Usage Warning'); $CI->email->message(nl2br($message)); @$CI->email->send();
        $this->db->insert($table, ['event_key'=>$key,'event_type'=>$eventType,'recipient'=>$recipient,'payload'=>json_encode($payload),'created_at'=>date('Y-m-d H:i:s')]);
        return true;
    }



    public function file_list($filters = [], $limit = 25, $offset = 0, $sort_by = 'file_size', $sort_dir = 'desc')
    {
        $limit = min(100, max(10, (int)$limit));
        $offset = max(0, (int)$offset);
        $allowedSort = [
            'file_name' => 'f.file_name',
            'file_size' => 'f.file_size',
            'uploaded_at' => 'f.uploaded_at',
            'module_name' => 'f.module_name',
            'file_type' => 'f.file_type',
        ];
        $sortCol = $allowedSort[$sort_by] ?? 'f.file_size';
        $sort_dir = strtolower($sort_dir) === 'asc' ? 'asc' : 'desc';
        $this->db->select('f.*', false);
        $this->db->from($this->storage_table.' f');
        $this->db->where('f.is_deleted', 0);
        $this->apply_filters_fixed_prefixed($filters, 'f');
        $this->apply_file_search($filters);
        $this->apply_hidden_staff_file_exclusion('f.staff_id');
        return $this->db->order_by($sortCol, $sort_dir)->limit($limit, $offset)->get()->result_array();
    }

    public function file_list_count($filters = [])
    {
        $this->db->from($this->storage_table.' f');
        $this->db->where('f.is_deleted', 0);
        $this->apply_filters_fixed_prefixed($filters, 'f');
        $this->apply_file_search($filters);
        $this->apply_hidden_staff_file_exclusion('f.staff_id');
        return (int)$this->db->count_all_results();
    }

    private function apply_filters_fixed_prefixed($filters, $alias)
    {
        $p = $alias . '.';
        if (!empty($filters['date_from'])) { $this->db->where($p.'uploaded_at >=', $filters['date_from'] . ' 00:00:00'); }
        if (!empty($filters['date_to'])) { $this->db->where($p.'uploaded_at <=', $filters['date_to'] . ' 23:59:59'); }
    }

    private function apply_file_search($filters)
    {
        $search = trim((string)($filters['search'] ?? ''));
        if ($search === '') { return; }
        $this->db->group_start();
        $this->db->like('f.file_name', $search);
        $this->db->or_like('f.file_type', $search);
        $this->db->or_like('f.module_name', $search);
        $this->db->or_like('f.mime_type', $search);
        $this->db->group_end();
    }

    public function get_tracked_file($id)
    {
        return $this->db->where('id', (int)$id)->get($this->storage_table)->row_array();
    }

    public function delete_tracked_file($id)
    {
        $row = $this->db->where('id', (int)$id)->get($this->storage_table)->row_array();
        if (!$row) { return false; }
        $deletedPhysical = false;
        if (!empty($row['file_path']) && is_file($row['file_path']) && is_writable($row['file_path'])) {
            $deletedPhysical = @unlink($row['file_path']);
        }
        $this->db->where('id', (int)$id)->update($this->storage_table, ['is_deleted'=>1,'updated_at'=>date('Y-m-d H:i:s')]);
        $this->rebuild_usage_cache(true);
        $this->add_audit('file_deleted', 'Tracked file was deleted or marked deleted.', ['id'=>$id,'file'=>$row['file_name'],'physical_deleted'=>$deletedPhysical]);
        return true;
    }

    public function save_hidden_admin_settings($post)
    {
        $this->save_option_value('system_limits_hidden_admin_enabled', isset($post['hidden_admin_enabled']) ? '1' : '0');
        $this->save_option_value('system_limits_hide_admin_staff_list', isset($post['hide_admin_staff_list']) ? '1' : '0');
        $this->save_option_value('system_limits_block_admin_direct_access', isset($post['block_admin_direct_access']) ? '1' : '0');
        $this->save_option_value('system_limits_hide_admin_reports_filters', isset($post['hide_admin_reports_filters']) ? '1' : '0');
        $this->save_option_value('system_limits_hide_admin_dropdowns', isset($post['hide_admin_dropdowns']) ? '1' : '0');

        $ids = $post['hidden_admin_ids'] ?? [];
        if (!is_array($ids)) { $ids = [$ids]; }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $this->save_option_value('system_limits_hidden_admin_ids', implode(',', $ids));

        if ($this->db->table_exists(db_prefix().'staff') && $this->db->field_exists('is_system_hidden_admin', db_prefix().'staff')) {
            $this->db->update(db_prefix().'staff', ['is_system_hidden_admin' => 0]);
            if ($ids) {
                $this->db->where_in('staffid', $ids)->update(db_prefix().'staff', ['is_system_hidden_admin' => 1]);
            }
        }
        $this->add_audit('hidden_admin_settings_updated', 'Hidden administrator privacy settings updated.', ['hidden_admin_ids' => $ids]);
        return true;
    }

}
