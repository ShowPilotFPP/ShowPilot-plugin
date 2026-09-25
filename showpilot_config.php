<?php
// ShowPilot config bridge — per-key saves and the Developer tab's raw editor.
// FPP's plugin-settings REST endpoints differ across versions, so the UI
// falls back to this endpoint when they fail.
//
// The show token is never part of the config file: it is stored in
// plugindata/ (see showpilot_common.php) and is write-only from the browser.
header('Cache-Control: no-store');
$skipJSsettings = true;
require_once __DIR__ . '/showpilot_common.php';

$allowedKeys = array(
    'serverUrl', 'showToken', 'remotePlaylist', 'interruptSchedule',
    'requestFetchTime', 'additionalWaitTime', 'fppStatusCheckTime',
    'heartbeatIntervalSec', 'verboseLogging', 'listenerEnabled',
    'listenerRestarting', 'audioDaemonPort',
);

function respondJson($code, $payload) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function validSetting($key, $value) {
    switch ($key) {
        case 'serverUrl':
            return $value === '' || sp_is_valid_server_url(rtrim($value, '/'));
        case 'audioDaemonPort':
            return ctype_digit($value) && (int)$value >= 1024 && (int)$value <= 65535;
        case 'remotePlaylist':
            return $value === '' || sp_playlist_path($value) !== null;
        default:
            return true;
    }
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Moves a token an older version left in config/ into plugindata/ before
// anything below reads or rewrites the config file.
sp_get_show_token();

if ($method === 'GET' && $action === 'raw') {
    header('Content-Type: text/plain');
    echo (string)@file_get_contents(sp_config_file());
    exit;
}

if ($method !== 'POST') {
    respondJson(405, array('error' => 'Method not allowed'));
}

$params = array();
parse_str(file_get_contents('php://input'), $params);

if ($action === 'raw') {
    $content = (string)($params['content'] ?? '');
    if (trim($content) === '') {
        respondJson(400, array('error' => 'Empty config'));
    }
    // A pasted showToken line is stored as the token, not written to config/.
    $lines = preg_split('/\r?\n/', $content);
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*showToken\s*=\s*"?(.*?)"?\s*$/', $line, $m)) {
            if ($m[1] !== '') sp_set_show_token(sp_smart_decode($m[1]));
            unset($lines[$i]);
        }
    }
    $path = sp_config_file();
    if (@file_put_contents($path, rtrim(implode("\n", $lines)) . "\n", LOCK_EX) === false) {
        respondJson(500, array('error' => 'Could not write plugin config'));
    }
    @chmod($path, 0660);
    respondJson(200, array('ok' => true));
}

$key = (string)($params['key'] ?? '');
$value = (string)($params['value'] ?? '');

if (!in_array($key, $allowedKeys, true)) {
    respondJson(400, array('error' => 'Invalid setting key'));
}
if (!validSetting($key, $value)) {
    respondJson(400, array('error' => 'Invalid value for ' . $key));
}

if ($key === 'showToken') {
    $ok = sp_set_show_token($value);
} else {
    // Older FPP's WriteSettingToFile() returns nothing, so confirm by reading back.
    WriteSettingToFile($key, urlencode($value), SP_SETTINGS_KEY);
    $ok = sp_setting(sp_read_config(), $key) === $value;
}

if (!$ok) {
    respondJson(500, array('error' => 'Could not save ' . $key));
}
respondJson(200, array('ok' => true));
