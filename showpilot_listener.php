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

// This script is a long-lived background daemon (spawned via postStart.sh
// with `setsid php ...`), never a web-accessible page. If a request somehow
// reaches it through the web server — direct URL hit, misconfigured routing,
// whatever — the `while (true)` polling loop further down would tie up a
// PHP-FPM/mod_php worker indefinitely. Bail out immediately for any SAPI
// other than CLI, before any of the FPP includes below run.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("ShowPilot listener runs as a background service only — not accessible via the web server.\n");
}

$skipJSsettings = true;
require_once __DIR__ . '/showpilot_common.php';

function logEntry($data) {
    sp_log($data);
}

function logEntry_verbose($data) {
    if (!empty($GLOBALS['cfg']['verboseLogging'])) {
        sp_log($data);
    }
}

// ============================================================
// Init defaults
// ============================================================

logEntry("Starting ShowPilot Plugin v" . $PLUGIN_VERSION);

WriteSettingToFile("pluginVersion", urlencode($PLUGIN_VERSION), SP_SETTINGS_KEY);

// showToken is deliberately absent: it lives in plugindata/, not config/.
$defaults = array(
    'serverUrl'             => '',
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
$pluginSettings = sp_read_config();
foreach ($defaults as $key => $val) {
    if (sp_setting($pluginSettings, $key) === '') {
        WriteSettingToFile($key, urlencode($val), SP_SETTINGS_KEY);
    }
}

function loadRuntimeSettings() {
    $s = sp_read_config();
    if (empty($s)) return null;
    $rawUrl = rtrim(sp_setting($s, 'serverUrl'), '/');
    $serverUrl = sp_server_url($s);
    if ($rawUrl !== '' && $serverUrl === '') {
        logEntry("WARNING - Server URL '$rawUrl' is not a plain http(s) URL; ignoring it.");
    }
    return array(
        'serverUrl'            => $serverUrl,
        'showToken'            => sp_get_show_token(),
        'remotePlaylist'       => sp_setting($s, 'remotePlaylist'),
        'interruptSchedule'    => sp_setting($s, 'interruptSchedule') === 'true',
        'requestFetchTime'     => max(1, intval(sp_setting($s, 'requestFetchTime'))),
        'additionalWaitTime'   => max(0, intval(sp_setting($s, 'additionalWaitTime'))),
        'fppStatusCheckTime'   => max(0.5, floatval(sp_setting($s, 'fppStatusCheckTime'))),
        'heartbeatIntervalSec' => max(5, intval(sp_setting($s, 'heartbeatIntervalSec'))),
        'verboseLogging'       => sp_setting($s, 'verboseLogging') === 'true',
    );
}

$cfg = loadRuntimeSettings();
if ($cfg === null) {
    logEntry("FATAL - Unable to read plugin config. Exiting.");
    exit(1);
}

logEntry("Server URL: " . $cfg['serverUrl']);
logEntry("Remote Playlist: " . $cfg['remotePlaylist']);
logEntry("Interrupt Schedule: " . ($cfg['interruptSchedule'] ? 'yes' : 'no'));
logEntry("Request Fetch Time: " . $cfg['requestFetchTime'] . "s");
logEntry("FPP Status Check Time: " . $cfg['fppStatusCheckTime'] . "s");

if (empty($cfg['serverUrl']) || empty($cfg['showToken'])) {
    logEntry("WARNING - Server URL or Show Token is empty. Plugin will idle until configured.");
}

// ============================================================
// API helpers
// ============================================================

// Returns the decoded JSON body of a 2xx response, or null.
function ofHttp($method, $path, $body = null) {
    global $cfg;

    $r = sp_http($cfg['serverUrl'], $cfg['showToken'], $method, $path,
        $body === null ? null : json_encode($body));
    if ($r === null) return null;

    if ($r['body'] === null) {
        logEntry_verbose("ERROR - Request to $path failed");
        return null;
    }
    if ($r['status'] >= 300 && $r['status'] < 400) {
        logEntry("ERROR - ShowPilot server redirected $path (HTTP " . $r['status']
            . "). Enter the server's final URL (e.g. https://) as the Server URL.");
        return null;
    }
    if ($r['status'] < 200 || $r['status'] >= 300) {
        logEntry_verbose("ERROR - $path returned HTTP " . $r['status']);
        return null;
    }

    $decoded = json_decode($r['body']);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        logEntry("ERROR - Invalid JSON from $path: " . json_last_error_msg());
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

    $data = sp_read_playlist($playlistName);
    if ($data === null) {
        logEntry("Playlist not found or unreadable: $playlistName");
        return null;
    }

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

// Resolve a sequence's position in the Remote Playlist by NAME at insert time.
//
// The server's playlistIndex is the position from the last Sync Now, which may
// have been against a different playlist (e.g. synced Halloween, listener still
// pointed at Christmas) or a since-edited one. Inserting by that number alone
// plays whatever happens to sit at that slot. Reading the live file here uses
// the same name/position rules as readFppPlaylistSequences, so it always lands
// on the song that actually won.
//
// Returns the 1-based position, or null if the sequence isn't in the playlist.
// If the name appears more than once, prefer the server's index when it points
// at a matching entry; otherwise take the first match.
function resolveRemotePlaylistIndex($playlistName, $sequenceName, $serverIdx) {
    $items = readFppPlaylistSequences($playlistName);
    if (!is_array($items)) return null;

    $first = null;
    foreach ($items as $item) {
        if (strcasecmp($item['name'], $sequenceName) !== 0) continue;
        if ($serverIdx !== null && $item['playlistIndex'] === intval($serverIdx)) {
            return $item['playlistIndex'];
        }
        if ($first === null) $first = $item['playlistIndex'];
    }
    return $first;
}

function prettifyName($name) {
    $name = preg_replace('/[_\-]+/', ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

// ============================================================
// Cooldown enforcement (v0.14.0+)
//
// When ShowPilot puts a sequence in cooldown, it sends playlistPatches in
// the /state response ({sequenceName, enabled:false, reenableAt}). We no
// longer edit the operator's FPP playlist files. Instead we keep a small
// state file listing active cooldowns, and the plugin's native C++ hook
// (src/FPPShowPilotSync.cpp, FPP's "query_next" playlist callback) reads
// it at each song transition and continues the playlist past any cooled
// item. If the plugin is removed or stops, FPP plays playlists as written.
//
// Active state file (showpilot-cooldown-active.json), read by fppd:
// {
//   "cooldowns": { "Disney_Princesses": 1780000000 },   // until, epoch secs
//   "exclude":   [ "<remotePlaylist>", "ShowPilot Queue" ]
// }
//
// Legacy (<= v0.13.x) state lived in showpilot-cooldowns.json alongside
// snapshots of playlists whose entries had been removed on disk. On
// startup we restore any such playlists exactly once (the last time this
// plugin ever writes a playlist file), carry the still-active cooldowns
// over, and delete the legacy file.
// ============================================================

$cooldownActiveFile = $settings['configDirectory'] . '/showpilot-cooldown-active.json';
$legacyCooldownFile = $settings['configDirectory'] . '/showpilot-cooldowns.json';
$activeCooldowns = array();       // name => until (epoch secs)
$cooldownExclude = array();

function saveActiveCooldowns() {
    global $cooldownActiveFile, $activeCooldowns, $cooldownExclude;
    $data = array(
        'cooldowns' => (object)$activeCooldowns,
        'exclude'   => array_values($cooldownExclude),
    );
    $tmp = $cooldownActiveFile . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT)) === false) {
        logEntry("[cooldown] ERROR: could not write $tmp");
        return;
    }
    // World-readable on purpose: fppd (the reader) may not run as the same
    // user as this listener, and the file holds only sequence names + times.
    @chmod($tmp, 0644);
    // Atomic replace so fppd never reads a half-written file.
    if (!@rename($tmp, $cooldownActiveFile)) {
        @unlink($tmp);
        logEntry("[cooldown] ERROR: could not update $cooldownActiveFile");
    }
}

function setCooldownExclude($list) {
    global $cooldownExclude;
    $list = array_values(array_unique(array_filter($list, 'strlen')));
    if ($list !== $cooldownExclude) {
        $cooldownExclude = $list;
        saveActiveCooldowns();
    }
}

// Drop expired entries. Returns true if anything changed.
function pruneExpiredCooldowns() {
    global $activeCooldowns;
    $now = time();
    $changed = false;
    foreach ($activeCooldowns as $name => $until) {
        if ($until <= $now) {
            unset($activeCooldowns[$name]);
            $changed = true;
            logEntry("[cooldown] '$name' cooldown ended");
        }
    }
    return $changed;
}

// Apply playlistPatches from /state. Patches are stdClass objects from ofHttp;
// anything that isn't a well-formed patch is ignored.
function recordCooldownPatches($patches) {
    global $activeCooldowns;
    if (!is_array($patches)) return;
    $changed = false;
    foreach ($patches as $patch) {
        if (!is_object($patch)) continue;
        $name = isset($patch->sequenceName) && is_string($patch->sequenceName) ? $patch->sequenceName : '';
        if ($name === '') continue;
        $enabled = !empty($patch->enabled);
        if ($enabled) {
            if (isset($activeCooldowns[$name])) {
                unset($activeCooldowns[$name]);
                $changed = true;
                logEntry("[cooldown] '$name' re-enabled by ShowPilot");
            }
            continue;
        }
        $until = isset($patch->reenableAt) && is_string($patch->reenableAt) ? strtotime($patch->reenableAt) : false;
        if ($until === false || $until <= time()) continue;
        if (!isset($activeCooldowns[$name]) || $activeCooldowns[$name] !== $until) {
            if (!isset($activeCooldowns[$name])) logEntry("[cooldown] '$name' in cooldown until " . date('c', $until));
            $activeCooldowns[$name] = $until;
            $changed = true;
        }
    }
    if (pruneExpiredCooldowns()) $changed = true;
    if ($changed) saveActiveCooldowns();
}

// One-time repair of playlists edited by plugin versions <= 0.13.x.
function migrateLegacyCooldowns() {
    global $legacyCooldownFile, $activeCooldowns;
    if (!file_exists($legacyCooldownFile)) return;
    $state = json_decode(@file_get_contents($legacyCooldownFile), true);
    if (is_array($state) && !empty($state['cooldowns']) && is_array($state['cooldowns'])) {
        $snapshots = isset($state['snapshot']) && is_array($state['snapshot']) ? $state['snapshot'] : array();
        foreach ($state['cooldowns'] as $name => $entry) {
            if (!is_array($entry)) continue;
            $playlist = isset($entry['playlist']) && is_string($entry['playlist']) ? $entry['playlist'] : '';
            if ($playlist !== '' && isset($snapshots[$playlist]) && is_array($snapshots[$playlist])) {
                legacyRestoreEntry((string)$name, $playlist, $snapshots[$playlist]);
            }
            $until = isset($entry['reenableAt']) && is_string($entry['reenableAt']) ? strtotime($entry['reenableAt']) : false;
            if ($until !== false && $until > time()) $activeCooldowns[(string)$name] = $until;
        }
    }
    @unlink($legacyCooldownFile);
    logEntry("[cooldown] Migrated legacy cooldown state; playlists are no longer modified");
}

function legacyRestoreEntry($name, $playlist, $snapshot) {
    // sp_read_playlist/sp_write_playlist reject names that would escape
    // FPP's playlist directory.
    $data = sp_read_playlist($playlist);
    if ($data === null || !isset($data['mainPlaylist']) || !is_array($data['mainPlaylist'])) return;

    $fileOf = function ($item) {
        $f = isset($item['sequenceName']) ? $item['sequenceName']
           : (isset($item['mediaName']) ? $item['mediaName'] : '');
        return pathinfo((string)$f, PATHINFO_FILENAME);
    };
    foreach ($data['mainPlaylist'] as $item) {
        if ($fileOf($item) === $name) return;   // already present
    }
    foreach ($snapshot as $idx => $item) {
        if ($fileOf($item) !== $name) continue;
        $at = min((int)$idx, count($data['mainPlaylist']));
        array_splice($data['mainPlaylist'], $at, 0, array($item));
        if (sp_write_playlist($playlist, $data)) {
            logEntry("[cooldown] Restored '$name' to playlist '$playlist' at index $at (legacy repair)");
        } else {
            logEntry("[cooldown] ERROR: could not restore '$name' to playlist '$playlist'");
        }
        return;
    }
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

const QUEUE_PLAYLIST = 'ShowPilot Queue';

// Rebuild the ShowPilot Queue playlist file from the current $pendingQueue.
// Returns true on success. The playlist contains exactly the pending songs
// in order at indices 1, 2, 3... so we can always insert range 1/N.
function rebuildQueuePlaylist($pendingQueue) {
    if (empty($pendingQueue)) return true;

    // We need the full entry objects from the remote playlist to write
    // a valid FPP playlist. Read the remote playlist to get entry metadata.
    $remoteName = $GLOBALS['cfg']['remotePlaylist'];
    $remoteData = sp_read_playlist($remoteName);
    if ($remoteData === null || !isset($remoteData['mainPlaylist'])) {
        logEntry("[queue] Remote playlist not found or unreadable: $remoteName");
        return false;
    }

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
        'name'         => QUEUE_PLAYLIST,
        'mainPlaylist' => $entries,
        'leadIn'       => array(),
        'leadOut'      => array(),
        'repeat'       => 0,
        'loopCount'    => 0,
        'description'  => 'Managed by ShowPilot plugin — do not edit manually',
    );

    if (!sp_write_playlist(QUEUE_PLAYLIST, $playlist)) {
        logEntry("[queue] ERROR: could not write queue playlist");
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

    // Find the item after the currently playing sequence
    $data = sp_read_playlist($currentPlaylist);
    if ($data === null || !isset($data['mainPlaylist']) || !is_array($data['mainPlaylist'])) return "";

    $items = array_values($data['mainPlaylist']);
    $count = count($items);
    for ($i = 0; $i < $count; $i++) {
        if (!isset($items[$i]['sequenceName'])) continue;
        $itemName = pathinfo($items[$i]['sequenceName'], PATHINFO_FILENAME);
        if ($itemName === $currentlyPlaying) {
            // Wrap to start if at end
            $nextItem = $items[($i + 1) % $count];
            if (isset($nextItem['sequenceName'])) {
                return pathinfo($nextItem['sequenceName'], PATHINFO_FILENAME);
            }
            if (isset($nextItem['mediaName'])) {
                return pathinfo($nextItem['mediaName'], PATHINFO_FILENAME);
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
$lastQueuedAt = 0;
$lastWasRemote = false;       // Tracks previous loop's $playingFromRemote value
// Pending queue: array of ['name' => sequenceName, 'idx' => playlistIndex]
// Tracks songs handed to FPP that haven't played yet. Used to rebuild the
// insertPlaylistAfterCurrent range whenever a new request comes in.
$pendingQueue = array();
$lastHeartbeat = 0;
$sequencesClearedWhenIdle = false;
// Throttles the "not in Remote Playlist" warning — in interrupt mode the
// server re-offers the same entry on every poll.
$lastUnresolvedSeq = '';
$lastUnresolvedAt = 0;

// Mode cache for the queue-decision logic. Refreshed periodically so we
// don't round-trip on every loop iteration just to know voting vs jukebox.
$cachedMode = null;
$cachedModeAt = 0;

// On startup: repair any playlists left edited by pre-0.14 versions, then
// publish the current cooldown state for the C++ hook.
migrateLegacyCooldowns();
pruneExpiredCooldowns();
saveActiveCooldowns();

while (true) {
    // Refresh settings each loop — allows the FPP UI to change things live
    $s = sp_read_config();
    if (empty($s)) {
        logEntry("ERROR - Unable to read plugin config. Retrying in 5s.");
        sleep(5);
        continue;
    }

    $enabled = sp_setting($s, 'listenerEnabled') === 'true';
    $restarting = sp_setting($s, 'listenerRestarting') === 'true';

    // The Remote Playlist dropdown saves immediately and Sync Now uses the new
    // value, so the listener must follow it live too. Holding the startup value
    // until a restart meant inserting positions from the new playlist into the
    // old one (e.g. a Halloween vote playing a Christmas song).
    $livePlaylist = sp_setting($s, 'remotePlaylist');
    if ($livePlaylist !== $cfg['remotePlaylist']) {
        logEntry("Remote Playlist changed: '" . $cfg['remotePlaylist'] . "' -> '$livePlaylist'");
        $cfg['remotePlaylist'] = $livePlaylist;
    }

    if ($restarting) {
        WriteSettingToFile("listenerEnabled", urlencode("true"), SP_SETTINGS_KEY);
        WriteSettingToFile("listenerRestarting", urlencode("false"), SP_SETTINGS_KEY);
        logEntry("Restarting ShowPilot Plugin v" . $PLUGIN_VERSION);
        $cfg = loadRuntimeSettings() ?? $cfg;
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
            $pendingQueue = array();
            $sequencesClearedWhenIdle = true;
            $lastQueuedAt = 0;
            $lastWasRemote = false;
            logEntry_verbose("FPP idle. Cleared sequences on server.");
        }
        usleep($cfg['fppStatusCheckTime'] * 1000000);
        continue;
    }

    $sequencesClearedWhenIdle = false;
    $currentlyPlaying = getSequenceName($fppStatus);

    $currentPlaylistNow = isset($fppStatus->current_playlist->playlist)
        ? $fppStatus->current_playlist->playlist : '';

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
            $playingFromQueue  = ($currentPlaylistNow2 === QUEUE_PLAYLIST);
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
    // the same second (loop runs every 1s, HTTP round trip takes ~100ms).
    // "Additional Wait Time" in the UI extends this hold.
    if ($shouldCheck && $lastQueuedAt > 0) {
        $sinceQueue = time() - $lastQueuedAt;
        if ($sinceQueue < 2 + $cfg['additionalWaitTime']) {
            $shouldCheck = false;
            logEntry_verbose("Post-insert hold ({$sinceQueue}s), skipping");
        }
    }

    if ($shouldCheck && !empty($cfg['remotePlaylist'])) {
        $state = ofGetState();
        if ($state !== null) {
            // Record cooldowns for the C++ playlist hook. No playlist files
            // are touched; the hook only ever acts on the top-level playlist
            // and skips anything listed in "exclude".
            if (isset($state->playlistPatches) && is_array($state->playlistPatches)) {
                recordCooldownPatches($state->playlistPatches);
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

            // Never trust the server's index blindly — verify it against the
            // Remote Playlist FPP will actually insert from (see
            // resolveRemotePlaylistIndex). If the song isn't in that playlist,
            // skip rather than play whatever sits at that position.
            if ($nextSeq !== null) {
                $resolvedIdx = resolveRemotePlaylistIndex($cfg['remotePlaylist'], $nextSeq, $nextIdx);
                if ($resolvedIdx === null) {
                    if ($nextSeq !== $lastUnresolvedSeq || (time() - $lastUnresolvedAt) >= 60) {
                        logEntry("WARN - '$nextSeq' is not in Remote Playlist '" . $cfg['remotePlaylist']
                            . "' — not inserting. Check the Remote Playlist setting, then Sync Now.");
                        $lastUnresolvedSeq = $nextSeq;
                        $lastUnresolvedAt = time();
                    }
                    $nextSeq = null;
                    $nextIdx = null;
                } elseif ($nextIdx === null || intval($nextIdx) !== $resolvedIdx) {
                    logEntry("'$nextSeq': server index " . ($nextIdx === null ? 'none' : intval($nextIdx))
                        . " is stale; using position $resolvedIdx in '" . $cfg['remotePlaylist']
                        . "'. Run Sync Now to refresh.");
                    $nextIdx = $resolvedIdx;
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
                        insertPlaylistAfterCurrent(QUEUE_PLAYLIST, 1, $queueCount);
                    } else {
                        // Fallback: single-song insert into remote playlist
                        $reason = $isVotingMode ? "voting mode" : "request queued";
                        logEntry("Queuing ($reason): $nextSeq at index $nextIdx");
                        insertPlaylistAfterCurrent($cfg['remotePlaylist'], intval($nextIdx));
                    }
                } else {
                    logEntry_verbose("'$nextSeq' already in pending queue, skipping");
                }

            } elseif (!$effectiveInterrupt) {
                // No winner/request. Hold off briefly so we don't re-poll
                // the same song end on every tick. (A sequence with no index
                // can't reach here: the resolve step above clears both.)
                $lastQueuedAt = time();
            }
        }
    }


    // Expire cooldowns promptly even when $shouldCheck is false or ShowPilot
    // is unreachable. The C++ hook also ignores expired entries on its own,
    // so this is housekeeping, not correctness.
    if (!empty($activeCooldowns) && pruneExpiredCooldowns()) {
        saveActiveCooldowns();
    }
    // Keep the hook's exclude list in sync with the configured request pool.
    setCooldownExclude(array($cfg['remotePlaylist'], QUEUE_PLAYLIST));

    usleep($cfg['fppStatusCheckTime'] * 1000000);
}
