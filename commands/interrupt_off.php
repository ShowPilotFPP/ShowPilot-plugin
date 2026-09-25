#!/usr/bin/env php
<?php
// ShowPilot — Turn Interrupt Schedule Off
$skipJSsettings = true;
require_once dirname(__DIR__) . '/showpilot_common.php';

WriteSettingToFile("interruptSchedule", urlencode("false"), SP_SETTINGS_KEY);
WriteSettingToFile("listenerRestarting", urlencode("true"), SP_SETTINGS_KEY);
