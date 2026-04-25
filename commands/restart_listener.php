#!/usr/bin/env php
<?php
// OpenFalcon — Restart Listener
//
// Sets the enabled flag, then either signals an existing listener to reload
// (it'll see listenerRestarting=true) OR spawns a fresh process if none is running
// (e.g. after a Stop Listener command, or on first install).

$skipJSsettings = true;
include_once "/opt/fpp/www/config.php";
include_once "/opt/fpp/www/common.php";
$pluginName = "openfalcon";

WriteSettingToFile("listenerEnabled", urlencode("true"), $pluginName);
WriteSettingToFile("listenerRestarting", urlencode("true"), $pluginName);

// Check if a listener is currently running
$ps = @shell_exec("pgrep -f openfalcon_listener.php");
if (empty(trim((string)$ps))) {
    // None running — spawn a fresh one detached
    @shell_exec("nohup /usr/bin/php /home/fpp/media/plugins/openfalcon/openfalcon_listener.php > /dev/null 2>&1 &");
}
?>
