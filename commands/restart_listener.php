#!/usr/bin/env php
<?php
// OpenFalcon — Restart Listener
//
// Sets the enabled flag, then either signals an existing listener to reload
// (it'll see listenerRestarting=true) OR spawns a fresh process if none is running.

$skipJSsettings = true;
include_once "/opt/fpp/www/config.php";
include_once "/opt/fpp/www/common.php";
$pluginName = "openfalcon";

WriteSettingToFile("listenerEnabled", urlencode("true"), $pluginName);
WriteSettingToFile("listenerRestarting", urlencode("true"), $pluginName);

// Check if a listener is currently running.
// Use pgrep -fa and filter to the actual listener (not pgrep itself, not this script).
$out = @shell_exec("pgrep -fa openfalcon_listener.php 2>/dev/null");
$running = false;
if ($out) {
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        // Only count `php …openfalcon_listener.php` (matches the listener, not commands/restart_listener.php)
        if (preg_match('#(^|/)php\s+\S*openfalcon_listener\.php#', $line)) {
            $running = true;
            break;
        }
    }
}

if (!$running) {
    // Spawn a fresh detached process. We use `setsid` to fully detach from
    // the current session AND redirect all I/O so PHP's shell_exec doesn't
    // wait for or track it. The `nohup … &` trick alone isn't reliable from PHP.
    $cmd = 'setsid /usr/bin/php /home/fpp/media/plugins/openfalcon/openfalcon_listener.php </dev/null >/dev/null 2>&1 &';
    @shell_exec($cmd);
    // Give the OS a moment to actually fork
    usleep(200000);
}
?>
