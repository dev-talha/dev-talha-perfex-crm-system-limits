<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Migration_Version_2_0_0 extends App_module_migration { public function up() { system_limits_ensure_schema(); } }
