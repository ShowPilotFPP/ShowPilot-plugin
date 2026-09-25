#!/usr/bin/env php
<?php
// ShowPilot — Restart Listener
//
// Kill-then-spawn rather than "start only if not running": detecting a
// running listener raced with rapid double-clicks and stale UI polls and let
// duplicate listeners accumulate. Killing first removes that whole class of
// bug. `pkill -f` matches the full script path, so no unrelated PHP process
// is touched.

$skipJSsettings = true;
require_once dirname(__DIR__) . '/showpilot_common.php';

$listenerPath = dirname(__DIR__) . "/showpilot_listener.php";

WriteSettingToFile("listenerEnabled", urlencode("true"), SP_SETTINGS_KEY);
WriteSettingToFile("listenerRestarting", urlencode("true"), SP_SETTINGS_KEY);

// pkill exits 1 when nothing matched; either outcome is fine.
@shell_exec("/usr/bin/pkill -f " . escapeshellarg($listenerPath) . " 2>/dev/null");

// Give SIGTERM a moment to land before spawning the replacement.
usleep(500000);

// fppd runs commands as root; the listener doesn't need it (see
// scripts/showpilot_env.sh). setpriv execs in place, so pkill still matches.
$dropRoot = (function_exists('posix_geteuid') && posix_geteuid() === 0 && is_executable('/usr/bin/setpriv'))
    ? '/usr/bin/setpriv --reuid=fpp --regid=fpp --init-groups '
    : '';

// setsid + full redirection detaches the child so shell_exec() returns
// immediately. PHP errors land in the plugin log alongside its own lines.
@shell_exec('/usr/bin/setsid ' . $dropRoot . '/usr/bin/php ' . escapeshellarg($listenerPath)
    . ' </dev/null >>' . escapeshellarg(sp_log_file()) . ' 2>&1 &');

// Brief pause so the UI's immediate status re-poll already sees it running.
usleep(200000);
