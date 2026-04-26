#!/usr/bin/env php
<?php
// ShowPilot — Restart Listener
//
// Defensive kill-then-spawn restart. Earlier versions tried to detect whether a
// listener was already running and only spawn one if not — but several edge
// cases (rapid double-clicks from the UI, stale browser-side polls, race
// conditions between detection and spawn) led to duplicate listeners
// accumulating. Killing first eliminates the entire class of bug: no detection
// = no detection failure = no duplicates.
//
// `pkill -f` matches the full command line including the script path, so we
// won't accidentally kill an unrelated PHP process.

$skipJSsettings = true;
include_once "/opt/fpp/www/config.php";
include_once "/opt/fpp/www/common.php";
$pluginName = "showpilot";

WriteSettingToFile("listenerEnabled", urlencode("true"), $pluginName);
WriteSettingToFile("listenerRestarting", urlencode("true"), $pluginName);

// Step 1: kill any existing listener processes.
// pkill exit codes: 0 = killed something, 1 = nothing matched. Both are fine.
@shell_exec("/usr/bin/pkill -f /home/fpp/media/plugins/showpilot/showpilot_listener.php 2>/dev/null");

// Step 2: give the OS a moment for SIGTERM to take effect cleanly.
// 500ms is plenty for a PHP CLI process to exit on signal.
usleep(500000);

// Step 3: spawn one fresh detached process.
// setsid + I/O redirection prevents PHP's shell_exec from holding onto the
// child or being held by it. The `&` backgrounds.
$cmd = '/usr/bin/setsid /usr/bin/php /home/fpp/media/plugins/showpilot/showpilot_listener.php </dev/null >/dev/null 2>&1 &';
@shell_exec($cmd);

// Step 4: brief pause so the caller (typically the UI) can immediately re-poll
// for status and see the new listener as already running.
usleep(200000);
?>
