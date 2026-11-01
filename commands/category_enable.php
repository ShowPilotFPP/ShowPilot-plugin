#!/usr/bin/env php
<?php
// ShowPilot — Enable Category (switches a ShowPilot category on; the
// category name is the command's first argument)
$skipJSsettings = true;
require_once dirname(__DIR__) . '/showpilot_common.php';

exit(sp_set_category_enabled($argv[1] ?? '', true) ? 0 : 1);
