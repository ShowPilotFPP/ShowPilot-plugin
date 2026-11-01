#!/usr/bin/env php
<?php
// ShowPilot — Set Viewer Page (makes one of ShowPilot's viewer page templates
// live; the page name is the command's first argument). Needs ShowPilot
// v0.33.249+.
$skipJSsettings = true;
require_once dirname(__DIR__) . '/showpilot_common.php';

exit(sp_set_viewer_page($argv[1] ?? '') ? 0 : 1);
