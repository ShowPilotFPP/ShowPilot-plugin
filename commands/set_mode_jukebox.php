#!/usr/bin/env php
<?php
// ShowPilot — Switch to Jukebox Mode
$skipJSsettings = true;
require_once dirname(__DIR__) . '/showpilot_common.php';

exit(sp_set_viewer_mode('JUKEBOX') ? 0 : 1);
