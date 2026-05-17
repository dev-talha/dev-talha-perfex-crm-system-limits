<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<style>
.sl-settings-wrap .panel-body{overflow:hidden}.sl-settings-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:16px}.sl-box{background:#fff;border:1px solid #eef1f5;border-radius:14px;padding:16px;margin-bottom:18px}.sl-box h4{margin-top:0}.sl-actions{display:flex;flex-wrap:wrap;gap:8px}.sl-responsive-table{width:100%}@media(max-width:991px){.sl-settings-grid{grid-template-columns:repeat(6,minmax(0,1fr))}.sl-md-12{grid-column:span 6!important}.sl-md-6{grid-column:span 6!important}.sl-md-4{grid-column:span 3!important}.sl-md-3{grid-column:span 3!important}.sl-md-2{grid-column:span 3!important}}@media(max-width:575px){.sl-settings-grid{display:block}.sl-box{padding:13px}.sl-actions .btn{width:100%;margin:0!important}.table-responsive{border:0}.sl-responsive-table thead{display:none}.sl-responsive-table tr{display:block;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:12px;padding:8px;background:#fff}.sl-responsive-table td{display:flex;justify-content:space-between;gap:12px;border:0!important;padding:7px 4px!important}.sl-responsive-table td:before{content:attr(data-label);font-weight:600;color:#6b7280}.sl-responsive-table td .checkbox{margin:0}.bootstrap-select{width:100%!important}}
</style>
<div id="wrapper"><div class="content sl-settings-wrap"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <h4 class="no-margin"><?php echo _l('system_limits_menu'); ?></h4>
    <p class="text-muted mtop5">Administrator-only configuration for CRM storage, resource limits and hidden administrator privacy.</p>
    <hr class="hr-panel-heading" />
    <?php echo form_open(admin_url('system_limits')); ?>


    <div class="sl-box">
      <h4><?php echo _l('system_limits_storage_settings'); ?></h4>
      <div class="sl-settings-grid">
        <div class="sl-md-3" style="grid-column:span 3"><div class="checkbox checkbox-primary"><input type="checkbox" id="storage_enabled" name="storage_enabled" <?php echo get_option('system_limits_storage_enabled')=='1'?'checked':''; ?>><label for="storage_enabled"><?php echo _l('system_limits_enable_storage_limit'); ?></label></div></div>
        <div class="sl-md-3" style="grid-column:span 3"><?php $limit=(int)get_option('system_limits_storage_limit_bytes'); $storageUnit=get_option('system_limits_storage_unit') ?: 'GB'; $divisor=$storageUnit==='TB'?1099511627776:($storageUnit==='MB'?1048576:1073741824); $displayLimit=$limit>0?round($limit/$divisor,2):0; echo render_input('storage_limit', 'system_limits_global_storage_limit', $displayLimit, 'number', ['step'=>'0.01','min'=>'0']); ?></div>
        <div class="sl-md-2" style="grid-column:span 2"><?php echo render_select('storage_unit', [['id'=>'MB','name'=>'MB'],['id'=>'GB','name'=>'GB'],['id'=>'TB','name'=>'TB']], ['id','name'], 'system_limits_unit', get_option('system_limits_storage_unit') ?: 'GB'); ?></div>
        <div class="sl-md-2" style="grid-column:span 2"><?php echo render_input('warning_threshold', 'system_limits_warning_threshold', get_option('system_limits_warning_threshold'), 'number', ['min'=>'1','max'=>'100']); ?></div>
        <div class="sl-md-2" style="grid-column:span 2"><?php echo render_select('restriction_behavior', [['id'=>'block','name'=>_l('system_limits_block_upload')],['id'=>'warn','name'=>_l('system_limits_warn_only')]], ['id','name'], 'system_limits_restriction_behavior', get_option('system_limits_restriction_behavior')); ?></div>
        <div class="sl-md-4" style="grid-column:span 4"><?php echo render_input('allowed_file_types', 'system_limits_allowed_file_types', get_option('system_limits_allowed_file_types'), 'text', [], [], 'mb-0', 'placeholder="jpg,png,pdf,docx,zip"'); ?><p class="text-muted"><?php echo _l('system_limits_allowed_file_types_help'); ?></p></div>
        <div class="sl-md-3" style="grid-column:span 3"><?php $max=(int)get_option('system_limits_max_single_file_bytes'); $singleUnit=get_option('system_limits_max_single_file_unit') ?: 'MB'; $singleDivisor=$singleUnit==='GB'?1073741824:1048576; $maxDisplay=$max>0?round($max/$singleDivisor,2):0; echo render_input('max_single_file', 'system_limits_max_single_file', $maxDisplay, 'number', ['step'=>'0.01','min'=>'0']); ?></div>
        <div class="sl-md-2" style="grid-column:span 2"><?php echo render_select('max_single_file_unit', [['id'=>'MB','name'=>'MB'],['id'=>'GB','name'=>'GB']], ['id','name'], 'system_limits_unit', get_option('system_limits_max_single_file_unit') ?: 'MB'); ?></div>
        <div class="sl-md-3" style="grid-column:span 3"><?php echo render_input('notify_email', 'system_limits_notify_email', get_option('system_limits_notify_email')); ?></div>
      </div>
      <div class="alert alert-info no-margin">
        <strong><?php echo _l('system_limits_current_usage'); ?>:</strong>
        <?php echo system_limits_format_bytes($summary['used_bytes']); ?> / <?php echo system_limits_format_bytes($summary['limit_bytes']); ?>
        (<?php echo (float)$summary['usage_percent']; ?>%) - <?php echo _l('system_limits_remaining'); ?>: <?php echo system_limits_format_bytes($summary['remaining_bytes']); ?>
      </div>
    </div>

    <div class="sl-box">
      <h4><?php echo _l('system_limits_hidden_admin_privacy'); ?></h4>
      <p class="text-muted"><?php echo _l('system_limits_hidden_admin_privacy_help'); ?></p>
      <div class="row">
        <div class="col-md-12"><div class="checkbox checkbox-primary"><input type="checkbox" id="hidden_admin_enabled" name="hidden_admin_enabled" <?php echo get_option('system_limits_hidden_admin_enabled')=='1'?'checked':''; ?>><label for="hidden_admin_enabled"><?php echo _l('system_limits_enable_hidden_admin'); ?></label></div></div>
        <div class="col-md-6">
          <?php
            $hiddenIds = array_filter(array_map('intval', explode(',', (string)get_option('system_limits_hidden_admin_ids'))));
            $staffOptions = [];
            foreach (($all_staff ?? []) as $s) { $staffOptions[] = ['id'=>$s['staffid'], 'name'=>trim($s['firstname'].' '.$s['lastname']).' - '.$s['email']]; }
            echo render_select('hidden_admin_ids[]', $staffOptions, ['id','name'], 'system_limits_select_hidden_admins', $hiddenIds, ['multiple'=>true, 'data-actions-box'=>'true'], [], '', '', false);
          ?>
        </div>
        <div class="col-md-6">
          <div class="panel panel-default"><div class="panel-body">
            <p class="bold"><?php echo _l('system_limits_hide_admin_where'); ?></p>
            <div class="checkbox checkbox-primary"><input type="checkbox" id="hide_admin_staff_list" name="hide_admin_staff_list" <?php echo get_option('system_limits_hide_admin_staff_list')=='1'?'checked':''; ?>><label for="hide_admin_staff_list"><?php echo _l('system_limits_hide_admin_staff_list'); ?></label></div>
            <div class="checkbox checkbox-primary"><input type="checkbox" id="block_admin_direct_access" name="block_admin_direct_access" <?php echo get_option('system_limits_block_admin_direct_access')=='1'?'checked':''; ?>><label for="block_admin_direct_access"><?php echo _l('system_limits_block_admin_direct_access'); ?></label></div>
            <div class="checkbox checkbox-primary"><input type="checkbox" id="hide_admin_reports_filters" name="hide_admin_reports_filters" <?php echo get_option('system_limits_hide_admin_reports_filters')=='1'?'checked':''; ?>><label for="hide_admin_reports_filters"><?php echo _l('system_limits_hide_admin_reports_filters'); ?></label></div>
            <div class="checkbox checkbox-primary"><input type="checkbox" id="hide_admin_dropdowns" name="hide_admin_dropdowns" <?php echo get_option('system_limits_hide_admin_dropdowns')=='1'?'checked':''; ?>><label for="hide_admin_dropdowns"><?php echo _l('system_limits_hide_admin_dropdowns'); ?></label></div>
            <p class="text-muted mtop10">Applies to Staff list, direct profile access, lead filters, lead add/edit, task add/edit, report filters and most future staff/user dropdowns rendered in admin pages.</p>
          </div></div>
        </div>
      </div>
      <div class="alert alert-warning no-margin"><?php echo _l('system_limits_hidden_admin_note'); ?></div>
    </div>

    <div class="sl-box">
      <h4><?php echo _l('system_limits_record_limits'); ?></h4>
      <p class="text-muted"><?php echo _l('system_limits_hint'); ?></p>
      <div class="table-responsive"><table class="table table-bordered table-striped sl-responsive-table"><thead><tr><th><?php echo _l('system_limits_resource'); ?></th><th><?php echo _l('system_limits_enabled'); ?></th><th><?php echo _l('system_limits_limit'); ?></th><th><?php echo _l('system_limits_used'); ?></th></tr></thead><tbody>
        <?php $map=[]; foreach($limits as $row){$map[$row['resource']]=$row;} $resources=['leads','staff','customers','proposals','estimates','invoices','projects','tasks','tickets','media']; foreach($resources as $r): $row=$map[$r]??['limit_value'=>null,'is_enabled'=>0]; ?>
        <tr><td data-label="<?php echo _l('system_limits_resource'); ?>"><strong><?php echo _l('system_limits_'.$r); ?></strong></td><td data-label="<?php echo _l('system_limits_enabled'); ?>"><div class="checkbox checkbox-primary"><input type="checkbox" id="enabled_<?php echo $r; ?>" name="enabled_<?php echo $r; ?>" <?php echo (int)$row['is_enabled']===1?'checked':''; ?>><label for="enabled_<?php echo $r; ?>"><?php echo _l('system_limits_enforce'); ?></label></div></td><td data-label="<?php echo _l('system_limits_limit'); ?>"><input type="number" min="0" class="form-control" name="limit_<?php echo $r; ?>" value="<?php echo html_escape($row['limit_value']); ?>" placeholder="<?php echo _l('system_limits_unlimited'); ?>"></td><td data-label="<?php echo _l('system_limits_used'); ?>"><?php echo (int)system_limits_usage($r); ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </div>

    <div class="sl-actions">
      <button type="submit" class="btn btn-primary"><?php echo _l('system_limits_save'); ?></button>
      <a href="<?php echo admin_url('system_limits/scan'); ?>" class="btn btn-default"><?php echo _l('system_limits_run_scan'); ?></a>
      <a href="<?php echo admin_url('system_limits/report'); ?>" class="btn btn-info"><?php echo _l('system_limits_report_menu'); ?></a>
      <a href="<?php echo admin_url('system_limits/audit_logs'); ?>" class="btn btn-default"><?php echo _l('system_limits_audit_logs'); ?></a>
    </div>
    <?php echo form_close(); ?>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?>
