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
$logFile = $settings['logDirectory'] . "/" . $pluginName . "-listener.log";
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
        'serverUrl'          => rtrim(urldecode($s['serverUrl']), '/'),
        'showToken'          => urldecode($s['showToken']),
        'remotePlaylist'     => urldecode($s['remotePlaylist']),
        'interruptSchedule'  => urldecode($s['interruptSchedule']) === 'true',
        'requestFetchTime'   => max(1, intVal(urldecode($s['requestFetchTime']))),
        'additionalWaitTime' => max(0, intVal(urldecode($s['additionalWaitTime']))),
        'fppStatusCheckTime' => max(0.5, floatval(urldecode($s['fppStatusCheckTime']))),
        'heartbeatIntervalSec' => max(5, intVal(urldecode($s['heartbeatIntervalSec']))),
        'verboseLogging'     => urldecode($s['verboseLogging']) === 'true',
    );
}

$cfg = loadRuntimeSettings();
if ($cfg === null) {
    logEntry("FATAL - Unable to read plugin config. Exiting.");
    exit(1);
}
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

function getFppStatus() {
    $options = array('http' => array('timeout' => 5));
    $context = stream_context_create($options);
    $result = @file_get_contents("http://127.0.0.1/api/system/status", false, $context);
    if ($result === false) return null;
    return json_decode($result);
}

function insertPlaylistAfterCurrent($playlistName, $playlistIndex) {
    // FPP API: Insert Playlist After Current/<playlist>/<startIndex>/<endIndex>
    // start and end both set to the sequence's index so only that one plays
    $playlist = rawurlencode($playlistName);
    $idx = intval($playlistIndex);
    $url = "http://127.0.0.1/api/command/Insert%20Playlist%20After%20Current/" . $playlist . "/" . $idx . "/" . $idx;
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
$lastImmediateAt = 0;         // Timestamp of last Immediate insert. We treat the
                              // ~8 seconds after as "request still settling" so
                              // rapid follow-up requests queue, not clobber.
$pendingRequests = array();   // Sequences we've queued/inserted that haven't
                              // yet been confirmed as played. As long as this
                              // is non-empty, new requests get queued (After Current).
$lastHeartbeat = 0;
$sequencesClearedWhenIdle = false;

// Mode cache for the queue-decision logic. Refreshed periodically so we
// don't round-trip on every loop iteration just to know voting vs jukebox.
$cachedMode = null;
$cachedModeAt = 0;

while (true) {
    // Refresh settings each loop — allows the FPP UI to change things live
    $s = parse_ini_file($pluginConfigFile);
    if ($s === false) {
        logEntry("ERROR - Unable to read plugin config. Retrying in 5s.");
        sleep(5);
        continue;
    }

    $enabled = urldecode($s['listenerEnabled']) === 'true';
    $restarting = urldecode($s['listenerRestarting']) === 'true';

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
            $sequencesClearedWhenIdle = true;
            logEntry_verbose("FPP idle. Cleared sequences on server.");
        }
        usleep($cfg['fppStatusCheckTime'] * 1000000);
        continue;
    }

    $sequencesClearedWhenIdle = false;
    $currentlyPlaying = getSequenceName($fppStatus);

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

        // When a new sequence starts playing, clean up our queue tracking.
        // If the sequence is one we queued: remove it AND everything before it
        //   (those earlier ones must have played already; FIFO order).
        // If the sequence isn't ours: schedule has resumed — clear all.
        $idx = array_search($currentlyPlaying, $pendingRequests, true);
        if ($idx !== false) {
            $pendingRequests = array_slice($pendingRequests, $idx + 1);
        } else {
            // Could be schedule resuming, OR it could be an unrelated sequence
            // playing briefly between our requests. Be cautious — only clear
            // the queue if we're sure (current playlist isn't the remote one).
            $playingFromRemote = isset($fppStatus->current_playlist->playlist)
                && $fppStatus->current_playlist->playlist === $cfg['remotePlaylist'];
            if (!$playingFromRemote) {
                if (!empty($pendingRequests)) {
                    logEntry_verbose("Schedule resumed (playing $currentlyPlaying); clearing queue tracker");
                }
                $pendingRequests = array();
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
    $effectiveInterrupt = $cfg['interruptSchedule'] && !$isVotingMode;

    $shouldCheck = false;
    if ($effectiveInterrupt) {
        // Interrupt mode (jukebox only): check on every loop when not currently playing from remote playlist
        $shouldCheck = true;
    } else {
        // Non-interrupt OR voting mode: only check when current song is about to end
        $secondsRemaining = intVal($fppStatus->seconds_remaining ?? 999);
        if ($secondsRemaining <= $cfg['requestFetchTime']) {
            $shouldCheck = true;
        }
    }

    // Avoid queueing twice for the same currently-playing sequence in non-interrupt mode
    if ($shouldCheck && !$effectiveInterrupt) {
        if ($currentlyPlaying === $lastQueuedForSequence) {
            $sinceQueue = time() - $lastQueuedAt;
            if ($sinceQueue < ($cfg['requestFetchTime'] + $cfg['additionalWaitTime'] + 2)) {
                $shouldCheck = false;
            }
        }
    }

    if ($shouldCheck && !empty($cfg['remotePlaylist'])) {
        $state = ofGetState();
        if ($state !== null) {
            $nextSeq = null;
            $nextIdx = null;

            if (isset($state->mode) && $state->mode === 'VOTING' && isset($state->winningVote)) {
                $nextSeq = $state->winningVote->sequence ?? null;
                $nextIdx = $state->winningVote->playlistIndex ?? null;
                if ($nextSeq) logEntry("Voting: winner is $nextSeq (index $nextIdx)");
            } elseif (isset($state->mode) && $state->mode === 'JUKEBOX' && isset($state->nextRequest)) {
                $nextSeq = $state->nextRequest->sequence ?? null;
                $nextIdx = $state->nextRequest->playlistIndex ?? null;
                if ($nextSeq) logEntry("Jukebox: next request is $nextSeq (index $nextIdx)");
            }

            if ($nextSeq !== null && $nextIdx !== null) {
                // Avoid clobbering a viewer request that's already playing.
                // Three checks together close timing races:
                //   (a) currently-playing matches a sequence we previously queued
                //       (FPP is currently playing a viewer request)
                //   (b) we did an Immediate insert recently (FPP may not have
                //       reported the new current_sequence yet — race window)
                //   (c) we have any sequences queued that haven't played yet
                //       (don't Immediate over a queued chain)
                $within_cooldown = ($lastImmediateAt > 0 && (time() - $lastImmediateAt) < 8);
                $isViewerRequestPlaying = in_array($currentlyPlaying, $pendingRequests, true);
                $haveQueuedRequests = count($pendingRequests) > 0;
                $shouldQueue = $within_cooldown || $isViewerRequestPlaying || $haveQueuedRequests;

                if ($effectiveInterrupt && !$shouldQueue) {
                    logEntry("Interrupting schedule with: $nextSeq at playlist index $nextIdx");
                    insertPlaylistImmediate($cfg['remotePlaylist'], $nextIdx);
                    $lastImmediateAt = time();
                    $pendingRequests[] = $nextSeq;
                } else {
                    // Voting mode always lands here (effectiveInterrupt is
                    // false for voting), as does the configured non-interrupt
                    // case for jukebox. The reason string explains why we
                    // chose the queued path so logs are easy to follow.
                    $reason = $isVotingMode
                        ? "voting mode (winner queued for after current song)"
                        : (!$cfg['interruptSchedule']
                            ? "non-interrupt mode"
                            : ($isViewerRequestPlaying
                                ? "viewer request playing"
                                : ($haveQueuedRequests
                                    ? "requests still in queue"
                                    : "cooldown after recent insert")));
                    logEntry("Queueing after current ($reason): $nextSeq at playlist index $nextIdx");
                    insertPlaylistAfterCurrent($cfg['remotePlaylist'], $nextIdx);
                    $pendingRequests[] = $nextSeq;
                }
                $lastQueuedForSequence = $currentlyPlaying;
                $lastQueuedAt = time();

                if (!$effectiveInterrupt) {
                    $waitTime = $cfg['requestFetchTime'] + $cfg['additionalWaitTime'];
                    logEntry("Sleeping $waitTime seconds after queue");
                    sleep($waitTime);
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

    usleep($cfg['fppStatusCheckTime'] * 1000000);
}
