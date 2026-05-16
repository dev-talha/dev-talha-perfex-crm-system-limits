<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
$canViewFiles = !empty($can_view_files);
$currentSort = $sort_by ?? 'file_size';
$currentDir = $sort_dir ?? 'desc';
$filterParams = $filters ?? [];
$filterParams['per_page'] = $file_limit ?? 25;
function sl_sort_link($column, $label, $currentSort, $currentDir, $filterParams) {
    $dir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
    $params = array_merge($filterParams, ['sort_by' => $column, 'sort_dir' => $dir, 'file_page' => 1]);
    $icon = '';
    if ($currentSort === $column) { $icon = $currentDir === 'asc' ? ' <i class="fa fa-sort-asc"></i>' : ' <i class="fa fa-sort-desc"></i>'; }
    return '<a href="' . admin_url('system_limits/report?' . http_build_query($params)) . '">' . $label . $icon . '</a>';
}
?>
<style>
.sl-card{background:#fff;border:1px solid #eef1f5;border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:0 4px 18px rgba(18,38,63,.04)}
.sl-muted{color:#7b8794}.sl-progress{height:9px;border-radius:30px;background:#eef2f7;overflow:hidden}.sl-progress>span{display:block;height:100%;border-radius:30px}.sl-success{background:#22c55e}.sl-warning{background:#f59e0b}.sl-danger{background:#ef4444}
.sl-topbar{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.sl-actions{display:flex;gap:8px;flex-wrap:wrap}.sl-file-meta{font-size:12px;color:#6b7280}.sl-pagination{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.sl-responsive-table{width:100%}.sl-filter-panel{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin-bottom:15px}.sl-filter-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:12px;align-items:end}.sl-filter-grid .form-group{margin-bottom:0}.sl-table-tools{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px}.sl-table-tools .form-control{max-width:260px}.sl-nowrap{white-space:nowrap}.sl-file-name{max-width:280px;display:inline-block}.sl-loader{display:none;position:fixed;z-index:9999;inset:0;background:rgba(255,255,255,.78);align-items:center;justify-content:center;text-align:center}.sl-loader-box{background:#fff;border-radius:14px;padding:24px 30px;box-shadow:0 8px 40px rgba(0,0,0,.12);max-width:360px}.sl-spinner{width:38px;height:38px;border:4px solid #e5e7eb;border-top-color:#2563eb;border-radius:50%;margin:0 auto 12px;animation:slspin 1s linear infinite}@keyframes slspin{to{transform:rotate(360deg)}}
@media(max-width:991px){.sl-card{padding:15px}.sl-actions .btn{margin-bottom:6px}.sl-filter-grid{grid-template-columns:1fr 1fr;gap:10px}.sl-filter-col{width:auto;float:none}.sl-table-tools .form-control{max-width:100%}}
@media(max-width:575px){.sl-card{padding:13px}.table-responsive{border:0}.sl-filter-grid{grid-template-columns:1fr;gap:8px}.sl-filter-col{width:100%;float:none}.sl-actions,.sl-actions .btn{width:100%}.sl-actions .btn{margin:0 0 6px}.sl-responsive-table thead{display:none}.sl-responsive-table tr{display:block;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:12px;padding:8px;background:#fff}.sl-responsive-table td{display:flex;justify-content:space-between;gap:12px;border:0!important;padding:7px 4px!important;word-break:break-word}.sl-responsive-table td:before{content:attr(data-label);font-weight:600;color:#6b7280;min-width:120px}.sl-responsive-table td .sl-progress{min-width:110px}.panel-body{padding-left:12px!important;padding-right:12px!important}.sl-file-name{max-width:160px}.sl-table-tools{display:block}.sl-table-tools>*{margin-bottom:8px}}
</style>
<div class="sl-loader" id="sl-loader"><div class="sl-loader-box"><div class="sl-spinner"></div><strong>Preparing file list...</strong><br><span class="sl-muted">Please wait while the system loads optimized paginated results.</span></div></div>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <div class="sl-topbar"><div><h4 class="no-margin"><?php echo _l('system_limits_report_menu'); ?></h4><p class="sl-muted mtop5">Resource limit overview and optimized file list.</p></div><div class="sl-actions"><?php if(is_admin()): ?><a class="btn btn-default sl-action" href="<?php echo admin_url('system_limits/scan'); ?>">Run Full Storage Scan</a><?php endif; ?> <a class="btn btn-default sl-action" href="<?php echo admin_url('system_limits/export_csv?'.http_build_query($filters)); ?>">Export CSV</a></div></div>
    <hr class="hr-panel-heading" />

    <div class="sl-card">
      <h4>Resource Limit Overview</h4>
      <div class="table-responsive"><table class="table table-hover table-bordered sl-responsive-table"><thead><tr><th>Resource</th><th>Used</th><th>Limit</th><th>Remaining</th><th width="220">Usage</th><th>Status</th></tr></thead><tbody>
        <?php foreach($resources as $r): ?><tr><td data-label="Resource"><strong><?php echo html_escape($r['label']); ?></strong></td><td data-label="Used"><?php echo html_escape($r['used_label']); ?></td><td data-label="Limit"><?php echo html_escape($r['limit_label']); ?></td><td data-label="Remaining"><?php echo html_escape($r['remaining_label']); ?></td><td data-label="Usage"><div class="sl-progress"><span class="sl-<?php echo $r['status']; ?>" style="width:<?php echo min(100,(float)$r['usage_percent']); ?>%"></span></div><small><?php echo (float)$r['usage_percent']; ?>%</small></td><td data-label="Status"><span class="label label-<?php echo $r['status']; ?>"><?php echo ucfirst($r['status']); ?></span></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>

    <div class="sl-card">
      <div class="sl-topbar"><h4>File List</h4></div>
      <div class="sl-filter-panel">
        <?php echo form_open(admin_url('system_limits/report'), ['method'=>'get','class'=>'sl-filter-form']); ?>
        <input type="hidden" name="sort_by" value="file_size">
        <div class="sl-filter-grid">
          <div class="sl-filter-col"><?php echo render_input('search', 'Search', $filters['search'] ?? ''); ?></div>
          <div class="sl-filter-col"><?php echo render_date_input('date_from', 'system_limits_date_from', $filters['date_from'] ?? ''); ?></div>
          <div class="sl-filter-col"><?php echo render_date_input('date_to', 'system_limits_date_to', $filters['date_to'] ?? ''); ?></div>
          <div class="sl-filter-col">
            <label for="sort_dir">File Size Sort</label>
            <select name="sort_dir" id="sort_dir" class="form-control">
              <option value="desc" <?php echo $currentSort === 'file_size' && $currentDir === 'desc' ? 'selected' : ''; ?>>Descending</option>
              <option value="asc" <?php echo $currentSort === 'file_size' && $currentDir === 'asc' ? 'selected' : ''; ?>>Ascending</option>
            </select>
          </div>
          <div class="sl-filter-col"><label>&nbsp;</label><button class="btn btn-primary btn-block" type="submit"><?php echo _l('filter'); ?></button></div>
        </div>
        <?php echo form_close(); ?>
      </div>

      <div class="sl-table-tools">
        <div class="text-muted">Files: <?php echo (int)($files_total ?? 0); ?></div>
        <form method="get" action="<?php echo admin_url('system_limits/report'); ?>" class="form-inline sl-filter-form">
          <?php foreach(($filters ?? []) as $k=>$v): if($v !== '' && $v !== null): ?><input type="hidden" name="<?php echo html_escape($k); ?>" value="<?php echo html_escape($v); ?>"><?php endif; endforeach; ?>
          <input type="hidden" name="sort_by" value="<?php echo html_escape($currentSort); ?>"><input type="hidden" name="sort_dir" value="<?php echo html_escape($currentDir); ?>">
          <select name="per_page" class="form-control input-sm" onchange="this.form.submit()"><option value="10" <?php echo (int)$file_limit===10?'selected':''; ?>>10</option><option value="25" <?php echo (int)$file_limit===25?'selected':''; ?>>25</option><option value="50" <?php echo (int)$file_limit===50?'selected':''; ?>>50</option><option value="100" <?php echo (int)$file_limit===100?'selected':''; ?>>100</option></select>
        </form>
      </div>

      <div class="table-responsive"><table class="table table-bordered table-hover sl-responsive-table"><thead><tr>
        <th width="70">SL</th>
        <th><?php echo sl_sort_link('file_name','File', $currentSort, $currentDir, $filterParams); ?></th>
        <th class="sl-nowrap"><?php echo sl_sort_link('file_size','Size', $currentSort, $currentDir, $filterParams); ?></th>
        <th><?php echo sl_sort_link('module_name','Module / Entity', $currentSort, $currentDir, $filterParams); ?></th>
        <th><?php echo sl_sort_link('file_type','Type', $currentSort, $currentDir, $filterParams); ?></th>
        <th><?php echo sl_sort_link('uploaded_at','Upload Date', $currentSort, $currentDir, $filterParams); ?></th>
        <?php if($canViewFiles): ?><th>Action</th><?php endif; ?>
      </tr></thead><tbody>
        <?php foreach(($files ?? []) as $idx=>$f): ?><tr>
          <td data-label="SL"><?php echo (($file_page - 1) * $file_limit) + $idx + 1; ?></td>
          <td data-label="File"><strong class="sl-file-name" title="<?php echo html_escape($f['file_name']); ?>"><?php echo html_escape(system_limits_truncate_filename($f['file_name'], 20)); ?></strong><div class="sl-file-meta"><?php echo html_escape($f['mime_type'] ?: 'unknown'); ?></div></td>
          <td data-label="Size" class="sl-nowrap"><?php echo system_limits_format_bytes($f['file_size']); ?></td>
          <td data-label="Module / Entity"><?php echo html_escape($f['module_name'] ?: '-'); ?><?php echo !empty($f['related_id']) ? ' #'.(int)$f['related_id'] : ''; ?></td>
          <td data-label="Type"><?php echo html_escape($f['file_type'] ?: 'unknown'); ?></td>
          <td data-label="Upload Date"><?php echo !empty($f['uploaded_at']) ? _dt($f['uploaded_at']) : '-'; ?></td>
          <?php if($canViewFiles): ?><td data-label="Action" class="sl-nowrap">
            <a class="btn btn-default btn-xs" target="_blank" href="<?php echo admin_url('system_limits/view_file/'.(int)$f['id']); ?>">View</a>
          </td><?php endif; ?>
        </tr><?php endforeach; ?>
        <?php if(empty($files)): ?><tr><td colspan="<?php echo $canViewFiles ? 7 : 6; ?>" class="text-center text-muted">No files found. Run a full storage scan if uploads are not indexed yet.</td></tr><?php endif; ?>
      </tbody></table></div>
      <?php $totalPages = max(1, (int)ceil(($files_total ?? 0) / ($file_limit ?? 25))); $baseParams=$filterParams; $baseParams['sort_by']=$currentSort; $baseParams['sort_dir']=$currentDir; ?>
      <div class="sl-pagination">
        <span class="text-muted">Page <?php echo (int)$file_page; ?> of <?php echo $totalPages; ?></span>
        <?php if($file_page > 1): $baseParams['file_page']=$file_page-1; ?><a class="btn btn-default btn-sm sl-action" href="<?php echo admin_url('system_limits/report?'.http_build_query($baseParams)); ?>">Previous</a><?php endif; ?>
        <?php if($file_page < $totalPages): $baseParams['file_page']=$file_page+1; ?><a class="btn btn-default btn-sm sl-action" href="<?php echo admin_url('system_limits/report?'.http_build_query($baseParams)); ?>">Next</a><?php endif; ?>
      </div>
    </div>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?><script>
$(function(){
  function showLoader(){ $('#sl-loader').css('display','flex'); setTimeout(function(){ $('#sl-loader').hide(); }, 30000); }
  $('.sl-action').on('click',function(){ showLoader(); });
  $('.sl-filter-form').on('submit',function(){ showLoader(); });
  $(window).on('pageshow load', function(){ $('#sl-loader').hide(); });
});
</script>
