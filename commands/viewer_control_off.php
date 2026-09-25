#!/usr/bin/env php
<?php
// ShowPilot — Turn Viewer Control Off
$skipJSsettings = true;
require_once dirname(__DIR__) . '/showpilot_common.php';

exit(sp_set_viewer_mode('OFF') ? 0 : 1);
