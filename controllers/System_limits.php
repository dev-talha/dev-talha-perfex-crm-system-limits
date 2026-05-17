<?php
defined('BASEPATH') or exit('No direct script access allowed');

class System_limits extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->helper('system_limits/system_limits');
        $this->load->model('system_limits/System_limits_model', 'sl_model');
        $this->lang->load('system_limits', 'english', false, true, module_dir_path('system_limits', ''));
    }

    public function index()
    {
        // Storage settings and record limits are provider/default administrator only.
        // These management capabilities are intentionally removed from role permissions.
        if (!is_admin()) {
            access_denied('system_limits');
        }

        if ($this->input->post()) {
            $resources = ['leads','staff','customers','proposals','estimates','invoices','projects','tasks','tickets','media'];
            foreach ($resources as $r) {
                $this->sl_model->upsert($r, $this->input->post('limit_'.$r), $this->input->post('enabled_'.$r) ? 1 : 0);
            }
            $this->sl_model->save_storage_settings($this->input->post());
            $this->sl_model->save_hidden_admin_settings($this->input->post());
            set_alert('success', _l('system_limits_saved'));
            redirect(admin_url('system_limits'));
        }

        $data['title'] = _l('system_limits_menu');
        $data['limits'] = $this->sl_model->get_all();
        $data['summary'] = $this->sl_model->storage_summary();
        $data['all_staff'] = $this->db->select(db_prefix().'staff.staffid, '.db_prefix().'staff.firstname, '.db_prefix().'staff.lastname, '.db_prefix().'staff.email, '.db_prefix().'staff.admin')->where(db_prefix().'staff.active',1)->order_by(db_prefix().'staff.firstname','ASC')->get(db_prefix().'staff')->result_array();
        $this->load->view('settings', $data);
    }

    public function report()
    {
        if (!is_admin() && !has_permission('system_limits', '', 'view_storage_reports')) {
            access_denied('system_limits');
        }
        $filters = $this->report_filters();
        $page = max(1, (int)$this->input->get('file_page'));
        $limit = min(100, max(10, (int)($this->input->get('per_page') ?: 25)));
        $offset = ($page - 1) * $limit;
        $sort_by = $this->input->get('sort_by') ?: 'file_size';
        $sort_dir = strtolower($this->input->get('sort_dir') ?: 'desc') === 'asc' ? 'asc' : 'desc';

        $data['title'] = _l('system_limits_report_menu');
        $data['summary'] = $this->sl_model->storage_summary();
        $data['resources'] = $this->sl_model->resource_overview();
        $data['files'] = $this->sl_model->file_list($filters, $limit, $offset, $sort_by, $sort_dir);
        $data['files_total'] = $this->sl_model->file_list_count($filters);
        $data['file_page'] = $page;
        $data['file_limit'] = $limit;
        $data['sort_by'] = $sort_by;
        $data['sort_dir'] = $sort_dir;
        $data['can_view_files'] = is_admin() || has_permission('system_limits', '', 'view_files');
        $data['filters'] = $filters;
        $this->load->view('report', $data);
    }

    public function scan()
    {
        if (!is_admin()) { access_denied('system_limits'); }
        $count = $this->sl_model->scan_storage_files();
        $count += $this->sl_model->scan_media_folder();
        $this->sl_model->rebuild_usage_cache(true);
        set_alert('success', sprintf(_l('system_limits_scan_completed'), $count));
        redirect(admin_url('system_limits/report'));
    }

    public function audit_logs()
    {
        if (!is_admin()) { access_denied('system_limits'); }
        $data['title'] = _l('system_limits_audit_logs');
        $data['logs'] = $this->sl_model->audit_logs(200);
        $this->load->view('audit_logs', $data);
    }

    public function export_csv()
    {
        if (!is_admin() && !has_permission('system_limits', '', 'view_storage_reports')) { access_denied('system_limits'); }
        $filters = $this->report_filters();
        $rows = $this->sl_model->export_rows($filters);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="system-limit-files-'.date('Y-m-d').'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Module','Related ID','File Name','File Type','Size Bytes','Size','Staff ID','Uploaded At','Source']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['module_name'],$r['related_id'],$r['file_name'],$r['file_type'],$r['file_size'],system_limits_format_bytes($r['file_size']),$r['staff_id'],$r['uploaded_at'],$r['source_table'].'#'.$r['source_id']]);
        }
        fclose($out); exit;
    }

    public function delete_file($id)
    {
        // File deletion from the report has been disabled intentionally.
        access_denied('system_limits');
    }

    private function report_filters()
    {
        return [
            'date_from' => $this->input->get('date_from'),
            'date_to' => $this->input->get('date_to'),
            'search' => $this->input->get('search'),
        ];
    }

    public function view_file($id)
    {
        if (!is_admin() && !has_permission('system_limits', '', 'view_files')) { access_denied('system_limits'); }
        $file = $this->sl_model->get_tracked_file((int)$id);
        if (!$file || !empty($file['is_deleted']) || empty($file['file_path']) || !is_file($file['file_path'])) {
            show_404();
        }
        $real = realpath($file['file_path']);
        $base = realpath(FCPATH);
        if (!$real || !$base || strpos($real, $base) !== 0) {
            access_denied('system_limits');
        }
        $mime = !empty($file['mime_type']) ? $file['mime_type'] : 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($file['file_name'])) . '"');
        header('Content-Length: ' . filesize($real));
        readfile($real);
        exit;
    }
}
