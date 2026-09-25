#!/usr/bin/env php
<?php
// ShowPilot — Switch to Race Mode
$skipJSsettings = true;
require_once dirname(__DIR__) . '/showpilot_common.php';

exit(sp_set_viewer_mode('RACE') ? 0 : 1);
