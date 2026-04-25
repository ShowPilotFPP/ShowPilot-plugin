<?php
// OpenFalcon listener status check — used by config UI to live-update
// the running/stopped banner without a full page refresh.
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$ps = @shell_exec("pgrep -f openfalcon_listener.php");
$running = !empty(trim((string)$ps));

echo json_encode([
    'running' => $running,
    'pid' => $running ? trim((string)$ps) : null,
    'checkedAt' => date('c'),
]);
