#!/usr/bin/env php
<?php
// ShowPilot — Switch to Listen Only Mode (needs ShowPilot v0.33.249+).
// Viewers still see what's playing and can listen on their phones, but
// can't vote or request. "Turn Viewer Control On" goes back to the last
// Voting/Jukebox/Race mode.
$skipJSsettings = true;
require_once dirname(__DIR__) . '/showpilot_common.php';

exit(sp_set_viewer_mode('LISTEN') ? 0 : 1);
