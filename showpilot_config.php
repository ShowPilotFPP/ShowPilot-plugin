<?php
// ShowPilot config bridge. FPP's plugin-settings REST endpoints have changed
// across versions, so this endpoint updates the plugin config file directly.
header('Cache-Control: no-store');
$skipJSsettings = true;
include_once "/opt/fpp/www/config.php";

$pluginName = "showpilot";
$pluginConfigFile = $settings['configDirectory'] . "/plugin." . $pluginName;

$allowedKeys = array(
    'serverUrl' => true,
    'showToken' => true,
    'remotePlaylist' => true,
    'interruptSchedule' => true,
    'requestFetchTime' => true,
    'additionalWaitTime' => true,
    'fppStatusCheckTime' => true,
    'heartbeatIntervalSec' => true,
    'verboseLogging' => true,
    'listenerEnabled' => true,
    'listenerRestarting' => true,
);

function respondJson($code, $payload) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function ensureConfigFile($path) {
    if (!file_exists($path)) {
        @touch($path);
    }
    @chmod($path, 0666);
    return file_exists($path) && is_readable($path);
}

function writePluginSetting($path, $key, $value) {
    $encodedValue = urlencode($value);
    $lines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : array();
    if ($lines === false) {
        $lines = array();
    }

    $written = false;
    for ($i = 0; $i < count($lines); $i++) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $lines[$i])) {
            $lines[$i] = $key . ' = ' . $encodedValue;
            $written = true;
            break;
        }
    }

    if (!$written) {
        $lines[] = $key . ' = ' . $encodedValue;
    }

    $ok = @file_put_contents($path, implode("\n", $lines) . "\n");
    @chmod($path, 0666);
    return $ok !== false;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && $action === 'raw') {
    if (!ensureConfigFile($pluginConfigFile)) {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo 'Could not read plugin config';
        exit;
    }
    header('Content-Type: text/plain');
    echo file_get_contents($pluginConfigFile);
    exit;
}

if ($method !== 'POST') {
    respondJson(405, array('error' => 'Method not allowed'));
}

$body = file_get_contents('php://input');
$params = array();
parse_str($body, $params);

if ($action === 'raw') {
    $content = $params['content'] ?? '';
    if (trim($content) === '') {
        respondJson(400, array('error' => 'Empty config'));
    }
    $ok = @file_put_contents($pluginConfigFile, $content);
    @chmod($pluginConfigFile, 0666);
    if ($ok === false) {
        respondJson(500, array('error' => 'Could not write plugin config'));
    }
    respondJson(200, array('ok' => true));
}

$key = $params['key'] ?? '';
$value = $params['value'] ?? '';

if (!isset($allowedKeys[$key])) {
    respondJson(400, array('error' => 'Invalid setting key'));
}

if (!ensureConfigFile($pluginConfigFile)) {
    respondJson(500, array('error' => 'Could not create plugin config'));
}

if (!writePluginSetting($pluginConfigFile, $key, $value)) {
    respondJson(500, array('error' => 'Could not write plugin config'));
}
respondJson(200, array('ok' => true));
