<?php
// ============================================================
// ShowPilot — extract_audio.php
// ============================================================
// Extracts the audio track from a video in FPP's music or videos directory
// and returns it as MP3, so Sync can upload just the audio to ShowPilot
// instead of the whole video.
//
// MP3 rather than an AAC stream-copy: some browser/container combinations
// failed to decode the stream-copied M4A even with faststart muxing. The
// re-encode costs a few seconds of Pi CPU per video, once per manual Sync.
//
// Input:  ?file=<mediaName>   (a bare file name, no path)
// Output: 200 audio/mpeg | 400 bad name | 404 not found | 500 ffmpeg failure
// ============================================================

$skipJSsettings = true;
require_once __DIR__ . '/showpilot_common.php';

function fail($code, $message) {
    http_response_code($code);
    header('Content-Type: text/plain');
    echo $message;
    exit;
}

$rawFile = (string)($_GET['file'] ?? '');
if ($rawFile === '') {
    fail(400, "missing 'file' parameter");
}
// Anyone on the LAN can reach this page, so accept a bare file name only.
if ($rawFile !== basename($rawFile) || strpbrk($rawFile, "/\\\0") !== false || $rawFile[0] === '.') {
    fail(400, 'invalid filename');
}

// config.php defines these; the fallbacks cover older FPP releases.
$musicDir = $musicDirectory ?? $settings['mediaDirectory'] . '/music';
$videoDir = $videoDirectory ?? $settings['mediaDirectory'] . '/videos';

$srcPath = null;
foreach (array($musicDir, $videoDir) as $dir) {
    if (is_file("$dir/$rawFile") && is_readable("$dir/$rawFile")) {
        $srcPath = "$dir/$rawFile";
        break;
    }
}
if ($srcPath === null) {
    sp_log("404 for: $rawFile (checked music + videos)", 'extract_audio');
    fail(404, 'file not found in music or videos directory');
}

$ffmpeg = null;
foreach (['/usr/bin/ffmpeg', '/opt/fpp/external/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $candidate) {
    if (is_executable($candidate)) {
        $ffmpeg = $candidate;
        break;
    }
}
if ($ffmpeg === null) {
    sp_log('ffmpeg missing', 'extract_audio');
    fail(500, 'ffmpeg not found on this FPP');
}

// Encode to a temp file (not stdout) so ffmpeg can finalize the headers
// before we send anything. tempnam() creates the file; ffmpeg overwrites it.
$tmpFile = tempnam(sys_get_temp_dir(), 'sp-extract-');
$cmd = sprintf(
    '%s -y -hide_banner -loglevel error -i %s -vn -ar 44100 -ac 2 -b:a 192k -map_metadata -1 -f mp3 %s 2>&1',
    escapeshellarg($ffmpeg),
    escapeshellarg($srcPath),
    escapeshellarg($tmpFile)
);
$ffmpegOutput = array();
$exitCode = 0;
exec($cmd, $ffmpegOutput, $exitCode);

clearstatcache(true, $tmpFile);
if ($exitCode !== 0 || !is_file($tmpFile) || filesize($tmpFile) === 0) {
    @unlink($tmpFile);
    $errMsg = "ffmpeg exit $exitCode";
    if (!empty($ffmpegOutput)) {
        $errMsg .= ': ' . implode(' | ', array_slice($ffmpegOutput, 0, 3));
    }
    sp_log("ffmpeg failed for $rawFile: $errMsg", 'extract_audio');
    fail(500, $errMsg);
}

header('Content-Type: audio/mpeg');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
while (ob_get_level() > 0) ob_end_clean();

$totalBytes = readfile($tmpFile);
@unlink($tmpFile);

if (!$totalBytes) {
    sp_log("readfile failed for $rawFile", 'extract_audio');
} else {
    sp_log("extracted $totalBytes bytes from: $rawFile", 'extract_audio');
}
