<?php
// ============================================================
// ShowPilot — extract_audio.php
// ============================================================
// Extracts the audio track from a video file in FPP's music or videos
// directory and returns it as MP3. Used by the plugin browser UI when
// syncing video sequences (.mp4/.mov/.mkv/etc.) — we don't want to
// upload the full video bytes to ShowPilot when only the audio is
// needed for viewer playback.
//
// Why MP3?
//   - Plays reliably in every browser (Chrome, Safari, Firefox, mobile).
//     Decoders accept MP3 from any source format without container
//     edge-cases.
//   - We tried M4A (AAC stream-copy) first — faster, no quality loss —
//     but found some browser/codec/container combinations that fail
//     to decode the resulting MP4 even with faststart muxing. The
//     symptom was duration loading correctly but playback failing.
//   - Pi CPU cost (~5-10 seconds per video file per sync) is one-time-
//     per-extraction. Sync is a manual user action; users don't notice.
//
// Why on-demand instead of pre-extracted?
//   - No temp file management, no cache invalidation when source files
//     change, no extra persistent disk usage on the SD card.
//   - The plugin only requests this when actually syncing.
//
// Inputs (query string):
//   file       — mediaName of the video file (e.g. "intro.mp4")
//
// Output:
//   200 with audio/mpeg body on success
//   400 if file param missing or path invalid
//   404 if file doesn't exist
//   500 on ffmpeg failure
// ============================================================

include_once "/opt/fpp/www/config.php";
include_once "/opt/fpp/www/common.php";

// FPP separates audio (music/) and video (videos/) into different
// subdirectories of the media root. We need to check both because the
// plugin sends mediaName based on what's in the playlist — that could
// be either folder. config.php sets these globals.
$musicDir = isset($musicDirectory) ? $musicDirectory : $settings['mediaDirectory'] . '/music';
$videoDir = isset($videoDirectory) ? $videoDirectory : $settings['mediaDirectory'] . '/videos';
$logFile = $settings['logDirectory'] . '/showpilot-listener.log';

function elog($msg) {
    global $logFile;
    $fp = @fopen($logFile, 'a');
    if (!$fp) return;
    fwrite($fp, '[' . date('Y-m-d H:i:s') . '] [extract_audio] ' . $msg . "\n");
    fclose($fp);
}

$rawFile = isset($_GET['file']) ? $_GET['file'] : '';
if ($rawFile === '') {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "missing 'file' parameter";
    exit;
}

// Path traversal defense. The plugin browser UI passes mediaName values
// straight through from FPP's playlist, but this endpoint could be
// called by anyone on the LAN. Reject anything with path separators
// or relative-path segments.
if (strpos($rawFile, '/') !== false ||
    strpos($rawFile, '\\') !== false ||
    strpos($rawFile, '..') !== false) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "invalid filename";
    exit;
}

$srcPath = null;
foreach (array($musicDir, $videoDir) as $dir) {
    $candidate = $dir . '/' . $rawFile;
    if (file_exists($candidate) && is_readable($candidate)) {
        $srcPath = $candidate;
        break;
    }
}
if ($srcPath === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "file not found in music or videos directory";
    elog("404 for: $rawFile (checked music + videos)");
    exit;
}

// Locate ffmpeg. FPP ships with it but the path varies slightly.
// Check common locations; fall back to the system PATH.
$ffmpeg = null;
foreach (['/usr/bin/ffmpeg', '/opt/fpp/external/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $candidate) {
    if (file_exists($candidate) && is_executable($candidate)) {
        $ffmpeg = $candidate;
        break;
    }
}
if ($ffmpeg === null) {
    $which = trim(@shell_exec('which ffmpeg 2>/dev/null'));
    if ($which !== '' && is_executable($which)) {
        $ffmpeg = $which;
    }
}
if ($ffmpeg === null) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "ffmpeg not found on this FPP";
    elog("ffmpeg missing");
    exit;
}

// Build the ffmpeg command. Use a temp file (NOT stdout streaming) so
// we can produce a STANDARD MP4 with the moov atom at the front. This
// is critical for browser playback:
//   - decodeAudioData() needs the full moov index to parse the file.
//   - Fragmented MP4 (which streaming-via-stdout requires) is decodable
//     by some browsers but not reliably across Chrome/Safari/Firefox.
//   - faststart writes moov at the front, so playback can start without
//     reading the whole file. This is the standard "web-compatible MP4"
//     pattern used by every major video host.
//
// The cost of using a temp file: ~1MB of /tmp space briefly per
// extraction. Negligible on any FPP install.
//
// Flags rationale:
//   -y                   overwrite output (no interactive prompt)
//   -i <input>           input file
//   -vn                  drop video stream
//   -acodec copy         stream-copy audio (no re-encoding) — fast
//   -map_metadata -1     strip all metadata for hash stability
//   -movflags +faststart move moov atom to start of file for fast
//                        playback start AND reliable decoding
//   <output>             temp file path
//
// We do NOT use proc_open + streaming here because:
//   (a) The output goes to a regular file, not a pipe, so we don't need
//       chunked output handling.
//   (b) After ffmpeg exits, we readfile() the result in one go — PHP's
//       readfile is memory-efficient (chunks internally) so even a
//       large M4A doesn't OOM.
$tmpFile = tempnam(sys_get_temp_dir(), 'sp-extract-') . '.mp3';
$cmd = sprintf(
    '%s -y -hide_banner -loglevel error -i %s -vn ' .
    '-ar 44100 -ac 2 -b:a 192k -map_metadata -1 -f mp3 %s 2>&1',
    escapeshellcmd($ffmpeg),
    escapeshellarg($srcPath),
    escapeshellarg($tmpFile)
);

// Run ffmpeg synchronously and capture output. exec() returns its
// stdout/stderr line array which is useful for error logging when
// extraction fails.
$ffmpegOutput = array();
$exitCode = 0;
exec($cmd, $ffmpegOutput, $exitCode);

if ($exitCode !== 0 || !file_exists($tmpFile) || filesize($tmpFile) === 0) {
    http_response_code(500);
    header('Content-Type: text/plain');
    $errMsg = "ffmpeg exit $exitCode";
    if (!empty($ffmpegOutput)) {
        $errMsg .= ': ' . implode(' | ', array_slice($ffmpegOutput, 0, 3));
    }
    echo $errMsg;
    elog("ffmpeg failed for $rawFile: $errMsg");
    @unlink($tmpFile);
    exit;
}

// Send headers, then stream the file out. readfile is memory-friendly
// (it chunks internally), so even a 50MB M4A doesn't OOM on a Pi.
header('Content-Type: audio/mpeg');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Disable PHP output buffering so bytes flow straight to the client.
while (ob_get_level() > 0) ob_end_clean();

$totalBytes = readfile($tmpFile);
@unlink($tmpFile);

if ($totalBytes === false || $totalBytes === 0) {
    elog("readfile failed for $rawFile (tmp: $tmpFile)");
} else {
    elog("extracted $totalBytes bytes from: $rawFile");
}

