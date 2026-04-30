<?php
// ShowPilot proxy — forwards UI requests to ShowPilot server so the browser
// never makes a cross-origin request (avoids ad blocker interference).
// Reads serverUrl + showToken from plugin config — token never touches browser.
header('Content-Type: application/json');
header('Cache-Control: no-store');
$skipJSsettings = true;
include_once "/opt/fpp/www/config.php";
include_once "/opt/fpp/www/common.php";
$pluginName = "showpilot";
$pluginConfigFile = $settings['configDirectory'] . "/plugin." . $pluginName;
$pluginSettings = @parse_ini_file($pluginConfigFile);
if (!$pluginSettings) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not read plugin config']);
    exit;
}
$serverUrl = rtrim(urldecode($pluginSettings['serverUrl'] ?? ''), '/');
$showToken  = urldecode($pluginSettings['showToken'] ?? '');
if (empty($serverUrl) || empty($showToken)) {
    http_response_code(400);
    echo json_encode(['error' => 'Server URL or Show Token not configured']);
    exit;
}

// Only allow specific paths — don't let this become an open proxy
$allowedPaths = [
    '/api/plugin/sync-sequences',
    '/api/plugin/health',
    '/api/plugin/heartbeat',
    '/api/plugin/playing',
    '/api/plugin/next',
    '/api/plugin/state',
    '/api/plugin/viewer-mode',
    // Audio cache — used by syncAudioCache() in the UI
    '/api/plugin/audio-cache/manifest',
    '/api/plugin/audio-cache/link',
    '/api/plugin/audio-cache/upload',
];

$path   = $_GET['path'] ?? '';
$path = str_replace('\\', '/', $path);
if ($path !== '' && $path[0] !== '/') {
    $path = '/' . $path;
}

// The JS passes hash/mediaName as query params embedded in the path string
// (e.g. /api/plugin/audio-cache/upload?hash=...&mediaName=...).
// Strip the query portion before the allow-list check and parse it as a
// fallback source for those params so the proxy picks them up correctly.
$embeddedQuery = '';
if (strpos($path, '?') !== false) {
    [$path, $embeddedQuery] = explode('?', $path, 2);
}
parse_str($embeddedQuery, $embeddedParams);

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($path, $allowedPaths, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Path not allowed: ' . $path]);
    exit;
}

$body = ($method === 'POST') ? file_get_contents('php://input') : null;

$headers = [
    "Authorization: Bearer $showToken",
    "Accept: application/json",
];

if ($body !== null) {
    // For the audio upload endpoint the browser sends raw binary with a
    // media Content-Type (audio/mpeg, audio/mp4, etc.). Forwarding that
    // type intact is what tells ShowPilot what kind of audio it received.
    // For every other POST the browser sends JSON, so we fall back to
    // application/json — same behaviour as before for those paths.
    $incomingContentType = $_SERVER['HTTP_CONTENT_TYPE']
        ?? $_SERVER['CONTENT_TYPE']
        ?? '';
    $headers[] = $path === '/api/plugin/audio-cache/upload' && $incomingContentType !== ''
        ? 'Content-Type: ' . $incomingContentType
        : 'Content-Type: application/json';
}

// The upload endpoint also passes hash and mediaName as query params.
// Forward them so ShowPilot receives them on the proxied request.
$queryForward = '';
if ($path === '/api/plugin/audio-cache/upload') {
    $params = [];
    // Accept hash/mediaName from either their own $_GET keys (future-proof)
    // or from the query string that was embedded in the path param (current JS behaviour).
    $hash      = $_GET['hash']      ?? $embeddedParams['hash']      ?? '';
    $mediaName = $_GET['mediaName'] ?? $embeddedParams['mediaName'] ?? '';
    if ($hash)      $params[] = 'hash='      . rawurlencode($hash);
    if ($mediaName) $params[] = 'mediaName=' . rawurlencode($mediaName);
    if ($params) $queryForward = '?' . implode('&', $params);
}

$options = [
    'http' => [
        'method'        => $method,
        'timeout'       => 60, // raised from 15s — large audio uploads need more headroom
        'header'        => implode("\r\n", $headers),
        'ignore_errors' => true,
    ],
];
if ($body !== null) {
    $options['http']['content'] = $body;
}

$context = stream_context_create($options);
$result  = @file_get_contents($serverUrl . $path . $queryForward, false, $context);

// Forward the response code
$code = 500;
if (isset($http_response_header)) {
    foreach ($http_response_header as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $code = (int)$m[1];
        }
    }
}
http_response_code($code);
echo $result !== false ? $result : json_encode(['error' => 'Request to ShowPilot failed']);
