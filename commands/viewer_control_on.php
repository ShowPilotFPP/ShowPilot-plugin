#!/usr/bin/env php
<?php
// ShowPilot — Turn Viewer Control On (restores last active mode)
$skipJSsettings = true;
require_once dirname(__DIR__) . '/showpilot_common.php';

exit(sp_set_viewer_mode('ON') ? 0 : 1);
