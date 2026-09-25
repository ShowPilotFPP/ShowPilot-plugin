<?php
// ============================================================
// ShowPilot — Audio Daemon Status Proxy
// ============================================================
// The config page can't fetch http://<fpp>:8090/health itself: a different
// port is a different origin, which FPP's Content-Security-Policy blocks.
// This runs server-side and returns the daemon's health as same-origin JSON.
// ============================================================

header('Content-Type: application/json');
header('Cache-Control: no-store');
$skipJSsettings = true;
require_once __DIR__ . '/showpilot_common.php';

$port = (int)sp_setting(sp_read_config(), 'audioDaemonPort', '8090');
if ($port < 1024 || $port > 65535) $port = 8090;

$ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
$result = @file_get_contents("http://127.0.0.1:{$port}/health", false, $ctx);

if ($result === false) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'daemon not reachable']);
} else {
    echo $result;
}
