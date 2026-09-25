#!/usr/bin/env php
<?php
// ShowPilot — Stop Listener. The listener sees listenerEnabled=false on its
// next loop and exits.
$skipJSsettings = true;
require_once dirname(__DIR__) . '/showpilot_common.php';

WriteSettingToFile("listenerEnabled", urlencode("false"), SP_SETTINGS_KEY);
