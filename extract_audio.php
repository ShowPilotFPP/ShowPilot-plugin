<?php
// ============================================================
// ShowPilot — extract_audio.php
// ============================================================
// Demuxes the audio track from a video file in FPP's music dir and
// streams it back as M4A (AAC in MP4 container). Used by the plugin
// browser UI when syncing video sequences (.mp4/.mov/.mkv/etc.) — we
// don't want to upload the full video bytes to ShowPilot when only
// the audio is needed for viewer playback.
//
// Why M4A instead of MP3?
//   - Video files in FPP shows almost always have AAC audio. Demuxing
//     into M4A is a stream copy (no re-encoding) — fast (sub-second
//     per file on a Pi) and lossless.
//   - Re-encoding to MP3 would add 3-8 seconds of CPU per file AND
//     introduce a second lossy encoding pass. Worse on every metric.
//   - Modern browsers all support AAC playback via Web Audio API.
//
// Why on-demand instead of pre-extracted?
//   - No temp file management, no cache invalidation when source MP4s
//     change, no extra disk usage on the SD card.
//   - The plugin only requests this when actually syncing, which is
//     a manual user action.
//
// Inputs (query string):
//   file       — mediaName of the video file (e.g. "intro.mp4")
//
// Output:
//   200 with audio/mp4 body on success
//   400 if file param missing or path invalid
//   404 if file doesn't exist or has no audio
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

// Build the ffmpeg command. Streaming output to stdout via `-` lets us
// pipe the audio directly into the HTTP response without a temp file.
//
// Flags rationale:
//   -i <input>           input file
//   -vn                  drop video stream
//   -acodec copy         stream-copy audio (no re-encoding)
//   -map_metadata -1     strip all metadata for hash stability — same
//                        input file always produces same output bytes
//   -f ipod              "iPod" muxer = MP4 container with audio only,
//                        commonly recognized as M4A. Required when
//                        outputting to a non-seekable destination
//                        (stdout) since MP4 normally needs to seek
//                        back to write the moov atom. The ipod muxer
//                        + frag flag arranges for a fragmented MP4
//                        that's stream-friendly.
//   -movflags +frag_keyframe+empty_moov
//                        write fragmented MP4 so headers can come
//                        before the body — required for streaming.
//   pipe:1               stdout
//   2>/dev/null          discard stderr, we don't need ffmpeg's banner
//                        in the response body
//
// We use proc_open instead of shell_exec so we can stream the output
// chunk-by-chunk to the HTTP response as ffmpeg produces it. shell_exec
// would buffer the entire output in PHP memory, which on a Pi with a
// large input file could cause OOM.
$cmd = sprintf(
    '%s -hide_banner -loglevel error -i %s -vn -acodec copy -map_metadata -1 ' .
    '-movflags +frag_keyframe+empty_moov -f ipod pipe:1 2>/dev/null',
    escapeshellcmd($ffmpeg),
    escapeshellarg($srcPath)
);

$descriptors = array(
    0 => array('pipe', 'r'),  // stdin (unused, but required)
    1 => array('pipe', 'w'),  // stdout
    2 => array('pipe', 'w'),  // stderr (we redirect to /dev/null in cmd, but this is here for safety)
);

$proc = @proc_open($cmd, $descriptors, $pipes);
if (!is_resource($proc)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "failed to spawn ffmpeg";
    elog("proc_open failed for: $rawFile");
    exit;
}
fclose($pipes[0]); // close stdin

// Stream the output. Use stream_set_blocking(false) on stdout so we
// can fall through to the loop and react to ffmpeg exiting.
stream_set_blocking($pipes[1], true);

// Now that we know we can run ffmpeg, send headers. Don't send Content-
// Length because we don't know the output size up front (streaming).
// Browsers handle chunked transfer encoding for fetch() responses fine.
header('Content-Type: audio/mp4');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Disable PHP output buffering so bytes go to the network as ffmpeg
// produces them. Without this, PHP collects the entire output before
// flushing — defeats the streaming.
while (ob_get_level() > 0) ob_end_clean();

$totalBytes = 0;
while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 65536);
    if ($chunk === false || $chunk === '') {
        // ffmpeg may have just closed the pipe — break and check exit code.
        break;
    }
    echo $chunk;
    $totalBytes += strlen($chunk);
    // Flush PHP's internal output buffer so the bytes hit Apache, then
    // Apache pushes them to the client. flush() is no-op for buffer-
    // less PHP but harmless.
    flush();
}
fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = proc_close($proc);
if ($exitCode !== 0) {
    elog("ffmpeg exit $exitCode for: $rawFile (sent $totalBytes bytes)");
    // Headers already sent — can't change status code now. The truncated
    // response will fail to decode on the client side, which will surface
    // as an upload error in the plugin UI. That's acceptable; the user
    // sees something went wrong.
} else {
    elog("extracted $totalBytes bytes from: $rawFile");
}
