<?php
// ShowPilot proxy — forwards the config page's requests to the ShowPilot
// server so the browser never makes a cross-origin request (and never needs
// a CSP exception or the show token). Only the paths below are forwarded.
header('Content-Type: application/json');
header('Cache-Control: no-store');
$skipJSsettings = true;
require_once __DIR__ . '/showpilot_common.php';

$serverUrl = sp_server_url();
$showToken = sp_get_show_token();
if ($serverUrl === '' || $showToken === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Server URL or Show Token not configured']);
    exit;
}

const UPLOAD_PATH = '/api/plugin/audio-cache/upload';

$allowedPaths = [
    '/api/plugin/sync-sequences',
    '/api/plugin/health',
    '/api/plugin/heartbeat',
    '/api/plugin/playing',
    '/api/plugin/next',
    '/api/plugin/state',
    '/api/plugin/viewer-mode',
    '/api/plugin/audio-cache/manifest',
    '/api/plugin/audio-cache/link',
    UPLOAD_PATH,
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// The UI embeds hash/mediaName for uploads in the path string itself
// (/api/plugin/audio-cache/upload?hash=...&mediaName=...), so split that off
// before the allow-list check.
$path = str_replace('\\', '/', (string)($_GET['path'] ?? ''));
$embeddedParams = [];
if (strpos($path, '?') !== false) {
    [$path, $embeddedQuery] = explode('?', $path, 2);
    parse_str($embeddedQuery, $embeddedParams);
}
if ($path !== '' && $path[0] !== '/') {
    $path = '/' . $path;
}
if (!in_array($path, $allowedPaths, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Path not allowed']);
    exit;
}

$body = null;
$contentType = 'application/json';
$query = '';
if ($method === 'POST') {
    $body = file_get_contents('php://input');
    if ($path === UPLOAD_PATH) {
        // Uploads are raw audio; forward the browser's media type if it is
        // a well-formed one so ShowPilot knows the format.
        $incoming = $_SERVER['CONTENT_TYPE'] ?? '';
        if (preg_match('#^audio/[A-Za-z0-9.+-]+$#', $incoming)) {
            $contentType = $incoming;
        }
    }
}
if ($path === UPLOAD_PATH) {
    $params = [];
    foreach (['hash', 'mediaName'] as $name) {
        $value = $_GET[$name] ?? $embeddedParams[$name] ?? '';
        if ($value !== '') $params[$name] = $value;
    }
    if ($params) $query = '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

// Large audio uploads need far more than the default 10s.
$result = sp_http($serverUrl, $showToken, $method, $path . $query, $body, $contentType, 60);

http_response_code($result['status'] ?: 502);
echo $result['body'] ?? json_encode(['error' => 'Request to ShowPilot failed']);
