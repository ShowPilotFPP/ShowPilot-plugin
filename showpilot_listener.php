<?php
// ============================================================
// ShowPilot FPP Plugin — Listener
// Runs as a background service on FPP. Polls FPP status and
// ShowPilot server; queues sequences when viewers vote/request.
// ============================================================

// Plugin version is defined in version.php — DO NOT hardcode here.
// See that file for why we centralized it. Including with require_once
// (not include_once) so a missing version file is a hard error rather
// than silently running with $PLUGIN_VERSION undefined.
require_once __DIR__ . '/version.php';

// Suppress FPP web UI JS output when running from CLI
$skipJSsettings = true;
include_once "/opt/fpp/www/config.php";
include_once "/opt/fpp/www/common.php";

$pluginName = basename(dirname(__FILE__));
$pluginPath = $settings['pluginDirectory'] . "/" . $pluginName . "/";
$logFile = $settings['logDirectory'] . "/plugin-" . $pluginName . ".log";
$pluginConfigFile = $settings['configDirectory'] . "/plugin." . $pluginName;

function logEntry($data) {
    global $logFile;
    $fp = @fopen($logFile, "a");
    if ($fp === false) {
        error_log("ShowPilot listener cannot open log file: " . $logFile . " | " . $data);
        return;
    }
    fwrite($fp, "[" . date("Y-m-d H:i:s") . "] " . $data . "\n");
    fclose($fp);
}

function logEntry_verbose($data) {
    if (isset($GLOBALS['verboseLogging']) && $GLOBALS['verboseLogging'] === true) {
        logEntry($data);
    }
}

// ============================================================
// smartDecode — tolerant decoder for plugin config values
// ============================================================
// The plugin has THREE write paths to plugin.showpilot, and they don't all
// use the same encoding:
//   1. FPP's native /api/plugin/<plugin>/settings/<key>     → URL-encoded
//   2. showpilot_config.php (per-key bypass endpoint)       → URL-encoded
//   3. savePluginSettingViaConfigFile / Developer raw editor → plain text
// A blind urldecode() works fine for path 1 & 2, and is mostly a no-op for
// path 3 — except for plain values that happen to contain '+' (which
// urldecode turns into a space) or '%XX' sequences (decoded unexpectedly).
// We detect URL-encoding by looking for %XX patterns; if absent, we assume
// the value is already plain and return it as-is. This makes the listener
// tolerant of all three write paths, including users hand-editing the config
// in Developer mode.
function smartDecode($value) {
    if ($value === null || $value === '') return $value;
    // %XX with hex digits is the unambiguous signal of URL-encoding.
    if (preg_match('/%[0-9a-fA-F]{2}/', $value)) {
        return urldecode($value);
    }
    return $value;
}

// ============================================================
// Init defaults
// ============================================================

$pluginSettings = parse_ini_file($pluginConfigFile);

// First-run: create the config file if it doesn't exist
if (!file_exists($pluginConfigFile)) {
    @touch($pluginConfigFile);
}
@chmod($pluginConfigFile, 0666);
$pluginSettings = @parse_ini_file($pluginConfigFile);
if ($pluginSettings === false) $pluginSettings = array();

logEntry("Starting ShowPilot Plugin v" . $PLUGIN_VERSION);

WriteSettingToFile("pluginVersion", urlencode($PLUGIN_VERSION), $pluginName);

$defaults = array(
    'serverUrl'             => '',
    'showToken'             => '',
    'remotePlaylist'        => '',
    'interruptSchedule'     => 'false',
    'requestFetchTime'      => '3',
    'additionalWaitTime'    => '0',
    'fppStatusCheckTime'    => '0.5',
    'heartbeatIntervalSec'  => '15',
    'verboseLogging'        => 'false',
    'listenerEnabled'       => 'true',
    'listenerRestarting'    => 'false',
);
foreach ($defaults as $key => $val) {
    if (!isset($pluginSettings[$key]) || strlen(urldecode($pluginSettings[$key])) < 1) {
        WriteSettingToFile($key, urlencode($val), $pluginName);
    }
}
$pluginSettings = parse_ini_file($pluginConfigFile);

// Load runtime settings
function loadRuntimeSettings() {
    global $pluginConfigFile;
    $s = parse_ini_file($pluginConfigFile);
    if ($s === false) return null;
    return array(
        'serverUrl'          => rtrim(smartDecode($s['serverUrl']), '/'),
        'showToken'          => smartDecode($s['showToken']),
        'remotePlaylist'     => smartDecode($s['remotePlaylist']),
        'interruptSchedule'  => smartDecode($s['interruptSchedule']) === 'true',
        'requestFetchTime'   => max(1, intVal(smartDecode($s['requestFetchTime']))),
        'additionalWaitTime' => max(0, intVal(smartDecode($s['additionalWaitTime']))),
        'fppStatusCheckTime' => max(0.5, floatval(smartDecode($s['fppStatusCheckTime']))),
        'heartbeatIntervalSec' => max(5, intVal(smartDecode($s['heartbeatIntervalSec']))),
        'verboseLogging'     => smartDecode($s['verboseLogging']) === 'true',
    );
}

$cfg = loadRuntimeSettings();
if ($cfg === null) {
    logEntry("FATAL - Unable to read plugin config. Exiting.");
    exit(1);
}
$GLOBALS['cfg'] = $cfg; // make accessible inside functions via $GLOBALS['cfg']
$GLOBALS['verboseLogging'] = $cfg['verboseLogging'];

logEntry("Server URL: " . $cfg['serverUrl']);
logEntry("Remote Playlist: " . $cfg['remotePlaylist']);
logEntry("Interrupt Schedule: " . ($cfg['interruptSchedule'] ? 'yes' : 'no'));
logEntry("Request Fetch Time: " . $cfg['requestFetchTime'] . "s");
logEntry("FPP Status Check Time: " . $cfg['fppStatusCheckTime'] . "s");

// ============================================================
// Register the configured ShowPilot URL with FPP's Content Security
// Policy whitelist.
// ============================================================
// FPP's Apache config has a strict CSP that blocks the plugin UI from
// making fetch() calls to non-whitelisted origins. Without this, the
// browser console fills with "Refused to connect" errors on the very
// first Sync attempt — a confusing first-run experience.
//
// /opt/fpp/scripts/ManageApacheContentPolicy.sh maintains a
// per-directive whitelist file that Apache reads on each request.
// Adding our origin once is idempotent (no harm in re-adding).
//
// We do this at listener startup rather than on settings save because
// (a) there's no save hook in the plugin save flow, and (b) running
// it at startup means a listener restart (which users already do via
// the plugin UI) re-registers any newly-changed URL.
function registerCspOrigin($url) {
    if (empty($url)) return;
    $script = '/opt/fpp/scripts/ManageApacheContentPolicy.sh';
    if (!file_exists($script)) {
        logEntry("CSP register skipped - $script not found (older FPP?)");
        return;
    }
    $parsed = parse_url($url);
    if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
        logEntry("CSP register skipped - cannot parse origin from URL: $url");
        return;
    }
    // Build origin: scheme + host + optional port. CSP whitelist entries
    // are origin-only (no path). Default ports (80 for http, 443 for https)
    // can be implied by the scheme but we explicitly include any non-default
    // port for clarity.
    $origin = $parsed['scheme'] . '://' . $parsed['host'];
    if (!empty($parsed['port'])) {
        $origin .= ':' . $parsed['port'];
    }
    // escapeshellarg to defend against any weirdness in the URL even
    // though we already validated parse_url. Belt-and-suspenders.
    $cmd = $script . ' add connect-src ' . escapeshellarg($origin) . ' 2>&1';
    $output = array();
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    if ($exitCode === 0) {
        logEntry("CSP register OK: connect-src $origin");
    } else {
        logEntry("CSP register FAILED ($exitCode): " . implode(' | ', $output));
    }
    // Apache picks up CSP changes from the regenerated config file
    // automatically (no restart required for the connect-src list).
}
registerCspOrigin($cfg['serverUrl']);

if (empty($cfg['serverUrl']) || empty($cfg['showToken'])) {
    logEntry("WARNING - Server URL or Show Token is empty. Plugin will idle until configured.");
}

// ============================================================
// API helpers
// ============================================================

function ofHttp($method, $path, $body = null) {
    global $cfg;

    if (empty($cfg['serverUrl']) || empty($cfg['showToken'])) {
        return null;
    }

    $url = $cfg['serverUrl'] . $path;
    $headers = array(
        "Authorization: Bearer " . $cfg['showToken'],
        "Accept: application/json",
    );
    if ($body !== null) {
        $headers[] = "Content-Type: application/json";
    }

    $options = array(
        'http' => array(
            'method'        => $method,
            'timeout'       => 10,
            'header'        => implode("\r\n", $headers),
            'ignore_errors' => true,
        ),
    );
    if ($body !== null) {
        $options['http']['content'] = json_encode($body);
    }

    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    if ($result === false) {
        logEntry_verbose("ERROR - Request to $url failed");
        return null;
    }

    $decoded = json_decode($result);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        logEntry("ERROR - Invalid JSON from $url: " . json_last_error_msg());
        return null;
    }
    return $decoded;
}

// Get consolidated state from ShowPilot (mode, winning vote, next queued request)
function ofGetState() {
    return ofHttp('GET', '/api/plugin/state');
}

// Tell ShowPilot what's currently playing
function ofReportPlaying($sequenceName, $secondsPlayed = null) {
    $payload = array('sequence' => $sequenceName);
    if ($secondsPlayed !== null) {
        $payload['seconds_played'] = $secondsPlayed;
    }
    return ofHttp('POST', '/api/plugin/playing', $payload);
}

// Tell ShowPilot the live FPP playback position. Called on every loop
// iteration (~2x/sec) so the server has near-real-time tracking of
// where FPP's audio output actually is. Phones use this as the
// authoritative anchor for playback sync, replacing extrapolation
// from a fixed track-start timestamp. This is what gives speaker-
// accurate sync — FPP's seconds_played reflects where its hardware
// audio output is, including buffer delay, so phones aligning to
// this number naturally match what the speakers are emitting.
//
// Designed to be cheap on both sides: small payload, fire-and-forget
// (we don't care about the response). If a request times out or the
// server is unreachable, we just skip it and try again next tick —
// no retry, no backoff, no logging spam. The next 500ms tick has
// fresher data anyway.
function ofReportPosition($sequenceName, $secondsPlayed) {
    return ofHttp('POST', '/api/plugin/position', array(
        'sequence' => $sequenceName,
        'position' => $secondsPlayed,
    ));
}

// Tell ShowPilot what's scheduled next
function ofReportNext($sequenceName) {
    return ofHttp('POST', '/api/plugin/next', array('sequence' => $sequenceName));
}

// Heartbeat
function ofHeartbeat() {
    global $PLUGIN_VERSION;
    return ofHttp('POST', '/api/plugin/heartbeat', array(
        'pluginVersion' => $PLUGIN_VERSION,
    ));
}

// Push full sequence list for the configured playlist
function ofSyncSequences($playlistName) {
    $sequences = readFppPlaylistSequences($playlistName);
    if ($sequences === null) {
        logEntry("Unable to read sequences from FPP playlist: $playlistName");
        return null;
    }
    logEntry("Syncing " . count($sequences) . " sequences from playlist '$playlistName'");
    return ofHttp('POST', '/api/plugin/sync-sequences', array(
        'playlistName' => $playlistName,
        'sequences'    => $sequences,
    ));
}

// Read FPP playlist JSON and return a clean list of sequences for sync
function readFppPlaylistSequences($playlistName) {
    if (empty($playlistName)) return null;

    $playlistPath = "/home/fpp/media/playlists/" . $playlistName . ".json";
    if (!file_exists($playlistPath)) {
        logEntry("Playlist file not found: $playlistPath");
        return null;
    }

    $json = @file_get_contents($playlistPath);
    if ($json === false) return null;

    $data = json_decode($json, true);
    if (!is_array($data)) return null;

    // FPP playlists have a `mainPlaylist` array. Each entry is a sequence or media item.
    $items = isset($data['mainPlaylist']) ? $data['mainPlaylist'] : array();
    if (!is_array($items)) return null;

    $result = array();
    $position = 0;
    foreach ($items as $item) {
        // Possible item types: 'sequence', 'both' (sequence + media), 'media', 'pause', 'branch', etc.
        // We care about sequences and 'both' (which plays a sequence with associated media)
        $type = isset($item['type']) ? $item['type'] : '';
        $sequenceFile = '';

        if ($type === 'both' || $type === 'sequence') {
            $sequenceFile = isset($item['sequenceName']) ? $item['sequenceName'] : '';
        } elseif ($type === 'media' && isset($item['mediaName'])) {
            // Media-only entries — use media name as sequence identifier
            $sequenceFile = $item['mediaName'];
        }

        $position++;  // Increment for EVERY item — this is the FPP playlist position (1-indexed)
        if ($sequenceFile === '') continue;

        // Strip .fseq / .mp3 / etc. for the "name"
        $name = pathinfo($sequenceFile, PATHINFO_FILENAME);

        $result[] = array(
            'name'            => $name,
            'displayName'     => prettifyName($name),
            'durationSeconds' => isset($item['duration']) ? intval($item['duration']) : null,
            'playlistIndex'   => $position,  // <-- CRITICAL: this is what FPP uses for Insert Playlist
        );
    }

    return $result;
}

function prettifyName($name) {
    $name = preg_replace('/[_\-]+/', ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

// ============================================================
// FPP helpers
// ============================================================

// ============================================================
// Playlist cooldown patching (v0.13.42+)
//
// When ShowPilot puts a sequence in cooldown, it sends playlistPatches
// in the /state response. The plugin removes the sequence from FPP's
// playlist file entirely so FPP cannot play it in normal rotation.
// When the cooldown expires, the entry is re-inserted at its original
// position using a per-show snapshot taken when the playlist first starts.
//
// State file layout (showpilot-cooldowns.json):
// {
//   "snapshot": {
//     "MyShow": [ ...full mainPlaylist array at show start... ]
//   },
//   "cooldowns": {
//     "Disney_Princesses": {
//       "reenableAt": "2026-05-10T21:42:00Z",
//       "playlist":   "MyShow"
//     }
//   }
// }
//
// The snapshot is the source of truth for both the entry object and its
// original index. Multiple simultaneous cooldowns are independent — each
// re-insertion looks up its own slot in the snapshot. If the operator
// edits the playlist mid-show, changes don't take effect until the next
// show start, at which point a fresh snapshot is taken.
// ============================================================

$cooldownStateFile = $settings['configDirectory'] . '/showpilot-cooldowns.json';

function loadCooldownState() {
    global $cooldownStateFile;
    if (!file_exists($cooldownStateFile)) return array('snapshot' => array(), 'cooldowns' => array());
    $json = @file_get_contents($cooldownStateFile);
    if ($json === false) return array('snapshot' => array(), 'cooldowns' => array());
    $data = json_decode($json, true);
    if (!is_array($data)) return array('snapshot' => array(), 'cooldowns' => array());
    if (!isset($data['snapshot'])) $data['snapshot'] = array();
    if (!isset($data['cooldowns'])) $data['cooldowns'] = array();
    return $data;
}

function saveCooldownState($state) {
    global $cooldownStateFile;
    @file_put_contents($cooldownStateFile, json_encode($state, JSON_PRETTY_PRINT));
}

// Snapshot the playlist at show start. Called when the plugin detects a new
// playlist is playing. Stores the full mainPlaylist array so re-insertions
// can restore entries to their exact original position and content.
// Only snapshots if we don't already have one for this playlist — so a
// plugin restart mid-show doesn't overwrite a snapshot that cooled-down
// entries were already removed from.
function maybeSnapshotPlaylist($playlistName) {
    if (empty($playlistName)) return;

    $state = loadCooldownState();

    // Already have a snapshot for this playlist — don't overwrite.
    // The snapshot was taken at show start and is the reference for all
    // cooldown re-insertions this session. Overwriting mid-show would
    // lose the original positions of already-removed entries.
    if (isset($state['snapshot'][$playlistName])) return;

    $playlistPath = '/home/fpp/media/playlists/' . $playlistName . '.json';
    if (!file_exists($playlistPath)) return;
    $json = @file_get_contents($playlistPath);
    if ($json === false) return;
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['mainPlaylist'])) return;

    $state['snapshot'][$playlistName] = $data['mainPlaylist'];
    saveCooldownState($state);
    logEntry("[cooldown] Snapshotted playlist '$playlistName' (" . count($data['mainPlaylist']) . " items)");
}


// Write a playlist array back to disk atomically.
function writePlaylist($playlistName, $data) {
    $playlistPath = '/home/fpp/media/playlists/' . $playlistName . '.json';
    $tmp = $playlistPath . '.tmp';
    $written = @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
    if ($written === false) {
        logEntry("[cooldown] ERROR: could not write playlist temp file for '$playlistName'");
        return false;
    }
    if (!@rename($tmp, $playlistPath)) {
        logEntry("[cooldown] ERROR: could not rename temp playlist file for '$playlistName'");
        @unlink($tmp);
        return false;
    }
    return true;
}

// Apply playlistPatches from /state. For sequences entering cooldown,
// remove them from the live playlist. For sequences leaving cooldown
// (enabled:true patches), re-insert from snapshot.
// Patches are stdClass objects from ofHttp — use object property access.
function applyPlaylistPatches($patches, $currentPlaylist) {
    if (empty($currentPlaylist) || !is_array($patches) || count($patches) === 0) return;

    $playlistPath = '/home/fpp/media/playlists/' . $currentPlaylist . '.json';
    if (!file_exists($playlistPath)) {
        logEntry("[cooldown] Playlist file not found: $playlistPath");
        return;
    }

    $json = @file_get_contents($playlistPath);
    if ($json === false) return;
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['mainPlaylist'])) return;

    $state = loadCooldownState();
    $modified = false;

    foreach ($patches as $patch) {
        $name      = isset($patch->sequenceName) ? $patch->sequenceName : '';
        $enabled   = !empty($patch->enabled);
        $reenableAt = isset($patch->reenableAt) ? $patch->reenableAt : null;
        if ($name === '') continue;

        if (!$enabled) {
            // --- Sequence entering cooldown: remove from live playlist ---

            // Already removed (cooldown already active from a previous poll)
            if (isset($state['cooldowns'][$name])) continue;

            // Find and remove the entry from the live array
            $removed = false;
            foreach ($data['mainPlaylist'] as $idx => $item) {
                $entryFile = isset($item['sequenceName']) ? $item['sequenceName']
                           : (isset($item['mediaName']) ? $item['mediaName'] : '');
                if (pathinfo($entryFile, PATHINFO_FILENAME) !== $name) continue;
                array_splice($data['mainPlaylist'], $idx, 1);
                $removed = true;
                $modified = true;
                logEntry("[cooldown] Removed '$name' from playlist '$currentPlaylist'");
                break;
            }

            if ($removed && $reenableAt) {
                $state['cooldowns'][$name] = array(
                    'reenableAt' => $reenableAt,
                    'playlist'   => $currentPlaylist,
                );
            }
        } else {
            // --- Sequence leaving cooldown: re-insert from snapshot ---
            // This path handles the case where ShowPilot sends enabled:true
            // (cooldown expired server-side) before our own timer fires.
            reinsertFromSnapshot($name, $currentPlaylist, $data, $state);
            $modified = true;
        }
    }

    if ($modified) {
        writePlaylist($currentPlaylist, $data);
        saveCooldownState($state);
    }
}

// Re-insert a sequence into the live playlist array using the snapshot
// for both the entry object and original index. Modifies $data and $state
// in place — caller is responsible for writing both back to disk.
function reinsertFromSnapshot($name, $playlist, &$data, &$state) {
    // Find original entry and index in snapshot
    if (!isset($state['snapshot'][$playlist])) {
        logEntry("[cooldown] No snapshot for '$playlist' — cannot re-insert '$name'");
        unset($state['cooldowns'][$name]);
        return;
    }

    $snapshot = $state['snapshot'][$playlist];
    $origIndex = null;
    $origEntry = null;
    foreach ($snapshot as $idx => $item) {
        $entryFile = isset($item['sequenceName']) ? $item['sequenceName']
                   : (isset($item['mediaName']) ? $item['mediaName'] : '');
        if (pathinfo($entryFile, PATHINFO_FILENAME) === $name) {
            $origIndex = $idx;
            $origEntry = $item;
            break;
        }
    }

    if ($origEntry === null) {
        logEntry("[cooldown] '$name' not found in snapshot for '$playlist' — skipping re-insert");
        unset($state['cooldowns'][$name]);
        return;
    }

    // Don't re-insert if it's already in the live playlist (avoid duplicates)
    foreach ($data['mainPlaylist'] as $item) {
        $entryFile = isset($item['sequenceName']) ? $item['sequenceName']
                   : (isset($item['mediaName']) ? $item['mediaName'] : '');
        if (pathinfo($entryFile, PATHINFO_FILENAME) === $name) {
            // Already present — just clear the cooldown state
            unset($state['cooldowns'][$name]);
            return;
        }
    }

    // Insert at original index, clamped to current array length
    $insertAt = min($origIndex, count($data['mainPlaylist']));
    array_splice($data['mainPlaylist'], $insertAt, 0, array($origEntry));
    unset($state['cooldowns'][$name]);
    logEntry("[cooldown] Re-inserted '$name' into playlist '$playlist' at index $insertAt");
}

// Check if any pending re-enables have come due. Called every loop iteration.
function processPendingReenables($currentPlaylist) {
    $state = loadCooldownState();
    if (empty($state['cooldowns'])) return;

    $now = time();
    $changed = false;

    foreach ($state['cooldowns'] as $name => $entry) {
        $reenableAt = isset($entry['reenableAt']) ? strtotime($entry['reenableAt']) : 0;
        if ($reenableAt === false || $reenableAt > $now) continue;

        // Due — re-insert into the playlist
        $playlist = isset($entry['playlist']) ? $entry['playlist'] : $currentPlaylist;
        if (empty($playlist)) {
            unset($state['cooldowns'][$name]);
            $changed = true;
            continue;
        }

        $playlistPath = '/home/fpp/media/playlists/' . $playlist . '.json';
        if (!file_exists($playlistPath)) {
            unset($state['cooldowns'][$name]);
            $changed = true;
            continue;
        }

        $json = @file_get_contents($playlistPath);
        if ($json === false) continue;
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['mainPlaylist'])) continue;

        reinsertFromSnapshot($name, $playlist, $data, $state);
        writePlaylist($playlist, $data);
        $changed = true;
    }

    if ($changed) saveCooldownState($state);
}

// ============================================================
// Dynamic queue playlist management
//
// We maintain a dedicated FPP playlist file ("ShowPilot Queue") that
// always reflects the current pending request order. Each time the queue
// changes, we rewrite this file and insert it as a range. FPP plays
// through the full range and skip works correctly across all queued songs.
//
// The request pool playlist (remotePlaylist) is never modified — it's
// only used for sort_order lookups. The queue playlist is a separate file
// we own entirely.
// ============================================================

$queuePlaylistName = 'ShowPilot Queue';

function getQueuePlaylistPath() {
    return '/home/fpp/media/playlists/ShowPilot Queue.json';
}

// Rebuild the ShowPilot Queue playlist file from the current $pendingQueue.
// Returns true on success. The playlist contains exactly the pending songs
// in order at indices 1, 2, 3... so we can always insert range 1/N.
function rebuildQueuePlaylist($pendingQueue) {
    if (empty($pendingQueue)) return true;

    // We need the full entry objects from the remote playlist to write
    // a valid FPP playlist. Read the remote playlist to get entry metadata.
    $remotePath = '/home/fpp/media/playlists/' . basename($GLOBALS['cfg']['remotePlaylist']) . '.json';
    if (!file_exists($remotePath)) {
        logEntry("[queue] Remote playlist file not found: $remotePath");
        return false;
    }
    $json = @file_get_contents($remotePath);
    if ($json === false) return false;
    $remoteData = json_decode($json, true);
    if (!is_array($remoteData) || !isset($remoteData['mainPlaylist'])) return false;

    // Build a lookup: index (1-based) => entry object
    $byIndex = array();
    foreach ($remoteData['mainPlaylist'] as $i => $item) {
        $byIndex[$i + 1] = $item;
    }

    // Build the queue playlist entries in pending order
    $entries = array();
    foreach ($pendingQueue as $pending) {
        $idx = $pending['idx'];
        if (isset($byIndex[$idx])) {
            $entries[] = $byIndex[$idx];
        } else {
            logEntry("[queue] Warning: index $idx not found in remote playlist");
        }
    }

    if (empty($entries)) return false;

    $playlist = array(
        'name'         => 'ShowPilot Queue',
        'mainPlaylist' => $entries,
        'leadIn'       => array(),
        'leadOut'      => array(),
        'repeat'       => 0,
        'loopCount'    => 0,
        'description'  => 'Managed by ShowPilot plugin — do not edit manually',
    );

    $path = getQueuePlaylistPath();
    $tmp  = $path . '.tmp';
    $written = @file_put_contents($tmp, json_encode($playlist, JSON_PRETTY_PRINT));
    if ($written === false) {
        logEntry("[queue] ERROR: could not write queue playlist");
        return false;
    }
    if (!@rename($tmp, $path)) {
        logEntry("[queue] ERROR: could not rename queue playlist");
        @unlink($tmp);
        return false;
    }
    logEntry_verbose("[queue] Rebuilt queue playlist with " . count($entries) . " songs");
    return true;
}

function getFppStatus() {
    $options = array('http' => array('timeout' => 5));
    $context = stream_context_create($options);
    $result = @file_get_contents("http://127.0.0.1/api/system/status", false, $context);
    if ($result === false) return null;
    return json_decode($result);
}

function insertPlaylistAfterCurrent($playlistName, $startIndex, $endIndex = null) {
    // Insert a range from the remote playlist after the current song.
    // Passing start != end queues multiple songs FPP can skip through.
    $playlist = rawurlencode($playlistName);
    $start = intval($startIndex);
    $end   = ($endIndex !== null) ? intval($endIndex) : $start;
    $url = "http://127.0.0.1/api/command/Insert%20Playlist%20After%20Current/"
         . $playlist . "/" . $start . "/" . $end;
    $options = array('http' => array('timeout' => 5));
    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

function insertPlaylistImmediate($playlistName, $playlistIndex) {
    $playlist = rawurlencode($playlistName);
    $idx = intval($playlistIndex);
    $url = "http://127.0.0.1/api/command/Insert%20Playlist%20Immediate/" . $playlist . "/" . $idx . "/" . $idx;
    $options = array('http' => array('timeout' => 5));
    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
}


function getSequenceName($fppStatus) {
    $name = pathinfo($fppStatus->current_sequence, PATHINFO_FILENAME);
    if ($name === "") $name = pathinfo($fppStatus->current_song, PATHINFO_FILENAME);
    return $name;
}

function getNextScheduledSequence($fppStatus, $currentlyPlaying, $remotePlaylist) {
    // If nothing's playing, we have no basis for "what's next"
    if (empty($currentlyPlaying)) return "";

    // Determine the current playlist name
    if (!isset($fppStatus->current_playlist) || $fppStatus->current_playlist === null) return "";
    $currentPlaylist = isset($fppStatus->current_playlist->playlist) ? $fppStatus->current_playlist->playlist : "";
    if ($currentPlaylist === "") return "";

    // When the remote (pool) playlist is what's active, "next" is whatever gets voted/requested.
    // Don't overwrite what ShowPilot already knows in that case.
    if ($currentPlaylist === $remotePlaylist) return "";

    // Read the playlist file and find the item after the currently playing sequence
    $playlistPath = "/home/fpp/media/playlists/" . $currentPlaylist . ".json";
    if (!file_exists($playlistPath)) return "";

    $json = @file_get_contents($playlistPath);
    $data = @json_decode($json);
    if (!$data || !isset($data->mainPlaylist) || !is_array($data->mainPlaylist)) return "";

    $items = $data->mainPlaylist;
    $count = count($items);
    for ($i = 0; $i < $count; $i++) {
        if (!isset($items[$i]->sequenceName)) continue;
        $itemName = pathinfo($items[$i]->sequenceName, PATHINFO_FILENAME);
        if ($itemName === $currentlyPlaying) {
            // Wrap to start if at end
            $nextIdx = ($i + 1) >= $count ? 0 : ($i + 1);
            $nextItem = $items[$nextIdx];
            if (isset($nextItem->sequenceName)) {
                return pathinfo($nextItem->sequenceName, PATHINFO_FILENAME);
            }
            if (isset($nextItem->mediaName)) {
                return pathinfo($nextItem->mediaName, PATHINFO_FILENAME);
            }
            return "";
        }
    }
    return "";
}

// ============================================================
// Main loop
// ============================================================

$lastPlayingReported = "";
$lastNextReported = "";
$lastQueuedForSequence = "";
$lastQueuedAt = 0;
$lastInsertedSequence = "";   // (legacy; kept for compat — no longer drives logic)
$lastWasRemote = false;       // Tracks previous loop's $playingFromRemote value
// Pending queue: array of ['name' => sequenceName, 'idx' => playlistIndex]
// Tracks songs handed to FPP that haven't played yet. Used to rebuild the
// insertPlaylistAfterCurrent range whenever a new request comes in.
$pendingQueue = array();
$lastHeartbeat = 0;
$sequencesClearedWhenIdle = false;

// Mode cache for the queue-decision logic. Refreshed periodically so we
// don't round-trip on every loop iteration just to know voting vs jukebox.
$cachedMode = null;
$cachedModeAt = 0;

// On startup: process any cooldown re-enables that came due while the plugin
// was stopped. Pass empty string for playlist — processPendingReenables uses
// the stored playlist name from the cooldown state file, so it works even
// before the first FPP status poll.
processPendingReenables('');

while (true) {
    // Refresh settings each loop — allows the FPP UI to change things live
    $s = parse_ini_file($pluginConfigFile);
    if ($s === false) {
        logEntry("ERROR - Unable to read plugin config. Retrying in 5s.");
        sleep(5);
        continue;
    }

    $enabled = smartDecode($s['listenerEnabled']) === 'true';
    $restarting = smartDecode($s['listenerRestarting']) === 'true';

    if ($restarting) {
        WriteSettingToFile("listenerEnabled", urlencode("true"), $pluginName);
        WriteSettingToFile("listenerRestarting", urlencode("false"), $pluginName);
        logEntry("Restarting ShowPilot Plugin v" . $PLUGIN_VERSION);
        $cfg = loadRuntimeSettings();
        $GLOBALS['verboseLogging'] = $cfg['verboseLogging'];
        logEntry("Server URL: " . $cfg['serverUrl']);
    }

    if (!$enabled) {
        // Stop command was fired — actually exit the process so postStart.sh
        // (or a manual restart) can launch a fresh one. This way pgrep shows
        // accurate status.
        logEntry("Listener disabled via stop command — exiting.");
        exit(0);
    }

    // Heartbeat
    if (time() - $lastHeartbeat >= $cfg['heartbeatIntervalSec']) {
        ofHeartbeat();
        $lastHeartbeat = time();
    }

    // Poll FPP
    $fppStatus = getFppStatus();
    if ($fppStatus === null) {
        logEntry_verbose("FPP status unavailable");
        sleep(5);
        continue;
    }

    $statusName = $fppStatus->status_name ?? '';

    if ($statusName === 'idle') {
        if (!$sequencesClearedWhenIdle) {
            ofReportPlaying('');
            ofReportNext('');
            $lastPlayingReported = '';
            $lastNextReported = '';
            $lastInsertedSequence = '';
            $lastImmediateAt = 0;
            $pendingRequests = array();
            $pendingQueue = array();
            $sequencesClearedWhenIdle = true;
            $lastQueuedForSequence = '';
            $lastQueuedAt = 0;
            $lastWasRemote = false;
            // Clear all playlist snapshots so the next show start gets fresh ones.
            // We clear everything in the snapshot key rather than tracking which
            // playlist was active — simpler and equally correct since idle means
            // the show is done for now.
            $idleState = loadCooldownState();
            if (!empty($idleState['snapshot'])) {
                $idleState['snapshot'] = array();
                saveCooldownState($idleState);
                logEntry("[cooldown] Cleared playlist snapshots (show ended)");
            }
            logEntry_verbose("FPP idle. Cleared sequences on server.");
        }
        usleep($cfg['fppStatusCheckTime'] * 1000000);
        continue;
    }

    $sequencesClearedWhenIdle = false;
    $currentlyPlaying = getSequenceName($fppStatus);

    // Snapshot the main scheduled playlist for cooldown re-insertion.
    // maybeSnapshotPlaylist is idempotent — it only writes once and never
    // overwrites an existing snapshot. We call it every loop so we don't
    // need to track playlist transitions; it's a no-op after the first call.
    // Never snapshot the remotePlaylist (ShowPilot's request pool).
    $currentPlaylistNow = isset($fppStatus->current_playlist->playlist)
        ? $fppStatus->current_playlist->playlist : '';
    if (!empty($currentPlaylistNow) && $currentPlaylistNow !== $cfg['remotePlaylist']) {
        maybeSnapshotPlaylist($currentPlaylistNow);
    }

    // Only report changes
    if ($currentlyPlaying !== '' && $currentlyPlaying !== $lastPlayingReported) {
        logEntry("Now playing: $currentlyPlaying");
        // Pull current playback position from FPP status — used by the server to
        // compute correct started_at when a sequence is resumed mid-track (e.g.
        // after a request interrupt). FPP exposes seconds_played as a float.
        $secondsPlayed = isset($fppStatus->seconds_played)
            ? floatval($fppStatus->seconds_played)
            : null;
        ofReportPlaying($currentlyPlaying, $secondsPlayed);
        $lastPlayingReported = $currentlyPlaying;

        // When a sequence starts playing, update our pending queue.
        // Find it by name; if found, remove it and everything before it
        // (FIFO — earlier entries already played). Rebuild FPP's after-current
        // range from whatever is still pending.
        $foundIdx = -1;
        foreach ($pendingQueue as $i => $entry) {
            if ($entry['name'] === $currentlyPlaying) {
                $foundIdx = $i;
                break;
            }
        }
        if ($foundIdx >= 0) {
            $pendingQueue = array_slice($pendingQueue, $foundIdx + 1);
            // FPP already has the remaining songs queued from the original
            // insertPlaylistAfterCurrent range — no need to re-insert.
            // Re-inserting while FPP is already playing from ShowPilot Queue
            // resets FPP's internal queue pointer and causes it to loop back
            // to song 1 instead of continuing to the next queued song.
            // We only re-insert when a NEW request is added (handled below).
            if (!empty($pendingQueue)) {
                logEntry_verbose("Queue advanced past '$currentlyPlaying': " . count($pendingQueue) . " songs remaining");
            } else {
                logEntry_verbose("Queue exhausted after '$currentlyPlaying'");
            }
        } else {
            // Not one of ours — schedule resumed, clear pending queue.
            // But don't clear if we're still playing from ShowPilot Queue
            // (FPP is advancing through our queued range).
            $currentPlaylistNow2 = isset($fppStatus->current_playlist->playlist)
                ? $fppStatus->current_playlist->playlist : '';
            $playingFromRemote = ($currentPlaylistNow2 === $cfg['remotePlaylist']);
            $playingFromQueue  = ($currentPlaylistNow2 === 'ShowPilot Queue');
            if (!$playingFromRemote && !$playingFromQueue && !empty($pendingQueue)) {
                logEntry_verbose("Schedule resumed; clearing pending queue");
                $pendingQueue = array();
            }
        }
    }

    // Live position report — every loop iteration when audio is playing.
    // This is the new sync mechanism: viewers receive these positions in
    // near-real-time and use them as the authoritative anchor for audio
    // playback alignment, instead of extrapolating from a track-start
    // timestamp. The plugin reports "FPP is at position X.Y right now,"
    // server stores it with arrival timestamp, viewers compute their
    // target position from (X.Y + elapsed_since_arrival).
    //
    // Fired regardless of whether the sequence changed — the whole point
    // is continuous fresh data, not edge-triggered like ofReportPlaying.
    // Only suppressed when nothing is playing (sequence name empty).
    //
    // Field selection: FPP's `milliseconds_elapsed` (introduced in
    // mid-2024 FPP versions) gives millisecond-precision playback time.
    // The older `seconds_played` and `seconds_elapsed` fields are
    // integer-rounded and therefore unsuitable for sub-second sync — a
    // 1Hz integer with up to 999ms of phase error inside each tick is
    // worse than not reporting at all for our purposes. We only report
    // when milliseconds_elapsed is available; older FPP versions fall
    // back to track-start extrapolation on the viewer side, which is
    // what we had before this feature.
    if ($currentlyPlaying !== '' && isset($fppStatus->milliseconds_elapsed)) {
        $livePos = floatval($fppStatus->milliseconds_elapsed) / 1000.0;
        ofReportPosition($currentlyPlaying, $livePos);
    }

    $nextScheduled = getNextScheduledSequence($fppStatus, $currentlyPlaying, $cfg['remotePlaylist']);
    if ($nextScheduled !== $lastNextReported) {
        // Always report — including empty string, so server clears its value
        ofReportNext($nextScheduled);
        $lastNextReported = $nextScheduled;
    }

    // Check whether we should queue a viewer-selected sequence
    //
    // Voting mode is round-based: a winner is decided per-song, and the
    // winner becomes the NEXT song. Continuously polling and inserting
    // would (a) advance the round on the first vote that comes in, and
    // (b) potentially interrupt the current song mid-way. Both wrong.
    // So in voting mode, we behave like non-interrupt regardless of
    // the interruptSchedule config flag.
    //
    // Jukebox mode keeps interruptSchedule as configured — that's the
    // mode where "play this song right now" makes sense.
    //
    // To know which mode we're in without a round-trip on every loop,
    // we cache the last-seen mode. Refresh once per minute or when we
    // need to fetch state for a queue decision anyway. Cache vars are
    // declared in outer scope (above the while loop).
    if ($cachedMode === null || (time() - $cachedModeAt) > 60) {
        $modeState = ofGetState();
        if ($modeState !== null && isset($modeState->mode)) {
            $cachedMode = $modeState->mode;
            $cachedModeAt = time();
        }
    }
    $isVotingMode = ($cachedMode === 'VOTING');
    $isRaceMode   = ($cachedMode === 'RACE');
    // For race mode we need the fresh state to know if interrupt is set,
    // so we start conservative (no interrupt) and override inside $shouldCheck
    // after fetching fresh state. Voting never interrupts.
    $effectiveInterrupt = $cfg['interruptSchedule'] && !$isVotingMode && !$isRaceMode;

    // ----------------------------------------------------------------
    // Queue decision logic
    //
    // FPP supports inserting a playlist RANGE (startIndex/endIndex) via
    // insertPlaylistAfterCurrent. Multiple songs inserted as a range all
    // queue up in FPP and can be skipped through. We maintain $pendingQueue
    // (ordered list of {name, idx}) and rebuild the range on every new
    // request so FPP always has the full remaining queue.
    //
    // Flow:
    //   First request, main playlist playing → insertPlaylistImmediate(song)
    //   Additional requests → append to $pendingQueue, rebuild afterCurrent range
    //   Song starts playing → remove from $pendingQueue, rebuild range
    // ----------------------------------------------------------------
    $playingFromRemote = isset($fppStatus->current_playlist->playlist)
        && $fppStatus->current_playlist->playlist === $cfg['remotePlaylist'];

    // Detect transitions for logging
    if ($lastWasRemote && !$playingFromRemote) {
        logEntry_verbose("Remote playlist ended — returned to main playlist");
    }
    $lastWasRemote = $playingFromRemote;

    // Always check for new requests — we handle rate limiting via $lastQueuedAt
    $shouldCheck = true;
    // In non-interrupt / voting mode, only check near end of song.
    // Race mode always fetches fresh state so the inner race block can
    // apply its own seconds_remaining gate based on the interrupt flag.
    if (!$effectiveInterrupt && !$isRaceMode || $isVotingMode) {
        $secondsRemaining = intVal($fppStatus->seconds_remaining ?? 999);
        $shouldCheck = ($secondsRemaining < $cfg['requestFetchTime']);
    }
    // For race mode with no interrupt configured, also gate on seconds_remaining
    // here to avoid hammering the API every second while waiting for song end.
    if ($isRaceMode && !$cfg['interruptSchedule']) {
        $secondsRemaining = intVal($fppStatus->seconds_remaining ?? 999);
        $shouldCheck = ($secondsRemaining < $cfg['requestFetchTime']);
    }

    // After inserting, hold briefly to avoid duplicate fetches within
    // the same second (loop runs every 1s, HTTP round trip takes ~100ms)
    if ($shouldCheck && $lastQueuedAt > 0) {
        $sinceQueue = time() - $lastQueuedAt;
        if ($sinceQueue < 2) {
            $shouldCheck = false;
            logEntry_verbose("Post-insert hold ({$sinceQueue}s), skipping");
        }
    }

    if ($shouldCheck && !empty($cfg['remotePlaylist'])) {
        $state = ofGetState();
        if ($state !== null) {
            // Apply playlist cooldown patches — main playlist only
            if (isset($state->playlistPatches) && is_array($state->playlistPatches)) {
                $currentPlaylistName = isset($fppStatus->current_playlist->playlist)
                    ? $fppStatus->current_playlist->playlist : '';
                if (!empty($currentPlaylistName) && $currentPlaylistName !== $cfg['remotePlaylist']) {
                    applyPlaylistPatches($state->playlistPatches, $currentPlaylistName);
                }
            }

            $nextSeq = null;
            $nextIdx = null;
            $raceInterrupt = false; // overridden inside RACE block if applicable

            if (isset($state->mode) && $state->mode === 'VOTING' && isset($state->winningVote)) {
                $nextSeq = $state->winningVote->sequence ?? null;
                $nextIdx = $state->winningVote->playlistIndex ?? null;
                if ($nextSeq) logEntry("Voting: winner is $nextSeq (index $nextIdx)");
            } elseif (isset($state->mode) && $state->mode === 'JUKEBOX' && isset($state->nextRequest)) {
                $nextSeq = $state->nextRequest->sequence ?? null;
                $nextIdx = $state->nextRequest->playlistIndex ?? null;
                if ($nextSeq) logEntry("Jukebox: next request is $nextSeq (index $nextIdx)");
            } elseif (isset($state->mode) && $state->mode === 'RACE') {
                // Race mode (v0.13.58+)
                // When a winner is decided, queue it immediately regardless of
                // seconds_remaining. If interrupt is on, insertPlaylistImmediate
                // fires; if off, insertPlaylistAfterCurrent fires — the song plays
                // next without waiting for the current song to be almost over.
                if (isset($state->raceWinner)) {
                    $raceInterrupt = !empty($state->raceWinner->interrupt);
                    $nextSeq = $state->raceWinner->sequence ?? null;
                    $nextIdx = $state->raceWinner->playlistIndex ?? null;
                    if ($nextSeq) logEntry("Race: queuing winner $nextSeq (index $nextIdx, interrupt=" . ($raceInterrupt ? 'yes' : 'no') . ")");
                } else {
                    logEntry_verbose("Race mode active, no winner yet");
                }
            }

            if ($nextSeq !== null && $nextIdx !== null) {
                // Check if this song is already in our pending queue (shouldn't
                // happen since ShowPilot pops on handoff, but be safe)
                $alreadyPending = false;
                foreach ($pendingQueue as $entry) {
                    if ($entry['name'] === $nextSeq) { $alreadyPending = true; break; }
                }

                if (!$alreadyPending) {
                    // Add to pending queue and rebuild the ShowPilot Queue playlist
                    $pendingQueue[] = ['name' => $nextSeq, 'idx' => intval($nextIdx)];
                    $lastQueuedAt = time();
                    $queueCount = count($pendingQueue);

                    // Rebuild the dynamic queue playlist file with all pending songs
                    $rebuilt = rebuildQueuePlaylist($pendingQueue);

                    if (!$playingFromRemote && $queueCount === 1 && ($effectiveInterrupt || $raceInterrupt)) {
                        // First request, main playlist playing — interrupt immediately
                        logEntry("Interrupting schedule with: $nextSeq at playlist index $nextIdx");
                        insertPlaylistImmediate($cfg['remotePlaylist'], $nextIdx);
                    } elseif ($rebuilt && $queueCount > 1) {
                        // Additional requests — insert full queue as a range so
                        // skip works through all pending songs
                        $reason = $isVotingMode ? "voting mode"
                            : (!$cfg['interruptSchedule'] ? "non-interrupt mode"
                            : "request queued");
                        logEntry("Queuing ($reason): $nextSeq added ($queueCount songs total in queue)");
                        insertPlaylistAfterCurrent('ShowPilot Queue', 1, $queueCount);
                    } else {
                        // Fallback: single-song insert into remote playlist
                        $reason = $isVotingMode ? "voting mode" : "request queued";
                        logEntry("Queuing ($reason): $nextSeq at index $nextIdx");
                        insertPlaylistAfterCurrent($cfg['remotePlaylist'], intval($nextIdx));
                    }
                } else {
                    logEntry_verbose("'$nextSeq' already in pending queue, skipping");
                }

                        } elseif ($nextSeq !== null && $nextIdx === null) {
                logEntry("WARN - Got sequence '$nextSeq' but no playlist index. Sync playlist first?");
                // Mark as checked so we don't spam
                $lastQueuedForSequence = $currentlyPlaying;
                $lastQueuedAt = time();
            } else {
                // No winner/request. Mark checked to prevent re-polling the same song.
                if (!$effectiveInterrupt) {
                    $lastQueuedForSequence = $currentlyPlaying;
                    $lastQueuedAt = time();
                }
            }
        }
    }

    // Check for cooldown re-enables that have come due. This fires on every
    // loop iteration so re-enables are prompt even when $shouldCheck is false
    // (e.g. outside the request-fetch window) or ShowPilot is unreachable.
    // Pass the current playlist only if it's the main one, not the remote pool.
    $currentPlaylistForReenables = isset($fppStatus->current_playlist->playlist)
        ? $fppStatus->current_playlist->playlist
        : '';
    if ($currentPlaylistForReenables === $cfg['remotePlaylist']) {
        $currentPlaylistForReenables = '';
    }
    processPendingReenables($currentPlaylistForReenables);

    usleep($cfg['fppStatusCheckTime'] * 1000000);
}
