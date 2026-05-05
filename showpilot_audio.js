#!/usr/bin/env node
// ============================================================
// ShowPilot Audio Daemon v1.0.0
// ============================================================
// Runs on the FPP Pi alongside fppd. Serves the currently-playing
// audio file as a live HTTP stream, paced to real playback speed.
//
// ShowPilot (on the LXC) opens exactly ONE connection to this daemon
// and fans the bytes out to all viewer phones. FPP sees one connection.
// Phones all receive the same bytes at the same wall-clock moment —
// automatic sync with no offset math, no drift correction, no seeking.
//
// This is the same architecture as the original OpenFalcon audio daemon
// that gave perfect sync. Now revived as ShowPilot's audio daemon with
// Node 18 available on the Pi via ShowPilot Lite.
//
// Environment variables (set by postStart.sh):
//   PORT        — HTTP port to listen on (default: 8090)
//   MEDIA_ROOT  — path to FPP music dir (default: /home/fpp/media/music)
//   FPP_HOST    — FPP API base URL (default: http://127.0.0.1)
//   LOG_FILE    — log file path (default: stderr)
//
// Endpoints:
//   GET /health                  — health check, returns version + status
//   GET /audio/:filename         — live stream of the named audio file
//   GET /status                  — current playback position from FPP API
// ============================================================

'use strict';

const http    = require('http');
const fs      = require('fs');
const path    = require('path');

const PORT       = parseInt(process.env.PORT       || '8090', 10);
const MEDIA_ROOT = process.env.MEDIA_ROOT           || '/home/fpp/media/music';
const FPP_HOST   = (process.env.FPP_HOST            || 'http://127.0.0.1').replace(/\/+$/, '');
const VERSION    = '1.0.0';

// ---- Logging ----

const LOG_FILE = process.env.LOG_FILE || null;
function log(...args) {
  const line = `[${new Date().toISOString()}] [showpilot-audio] ${args.join(' ')}\n`;
  if (LOG_FILE) {
    try { fs.appendFileSync(LOG_FILE, line); } catch (_) { process.stderr.write(line); }
  } else {
    process.stderr.write(line);
  }
}

// ---- FPP status polling ----
// We poll FPP's status API to know what's currently playing and where
// in the song we are. Used by /status endpoint and for pacing the stream.

let fppStatus = { playing: false, filename: null, positionSec: 0, durationSec: 0 };

// Duration cache — keyed by filename, populated from /api/media/<file>/meta
// which uses ffprobe and is always accurate. Never trust fppd/status for duration.
const durationCache = {};

async function getDuration(filename) {
  if (durationCache[filename]) return durationCache[filename];
  try {
    const res = await fetch(`${FPP_HOST}/api/media/${encodeURIComponent(filename)}/meta`, {
      signal: AbortSignal.timeout(3000),
    });
    if (res.ok) {
      const meta = await res.json();
      const dur = parseFloat(meta.format?.duration || meta.duration || 0);
      if (dur > 0) {
        durationCache[filename] = dur;
        log(`cached duration for "${filename}": ${dur}s`);
        return dur;
      }
    }
  } catch (_) {}
  return 0;
}

async function pollFppStatus() {
  try {
    const res = await fetch(`${FPP_HOST}/api/fppd/status`, {
      signal: AbortSignal.timeout(2000),
    });
    if (!res.ok) return;
    const data = await res.json();
    const playing = data.status === 1 || data.status === 'playing';
    const filename = data.current_song || null;
    fppStatus = {
      playing,
      filename,
      positionSec: parseFloat(data.seconds_elapsed || 0),
      // Duration intentionally omitted from live status — use getDuration() instead
      durationSec: 0,
    };
  } catch (_) {
    // FPP not reachable — keep last known state
  }
}

// Poll every 500ms — fast enough to detect song changes quickly
setInterval(pollFppStatus, 500);
pollFppStatus(); // immediate first poll

// ---- MIME types ----

function mimeForFile(filename) {
  const ext = path.extname(filename).toLowerCase();
  const map = {
    '.mp3': 'audio/mpeg',
    '.ogg': 'audio/ogg',
    '.wav': 'audio/wav',
    '.flac': 'audio/flac',
    '.aac': 'audio/aac',
    '.m4a': 'audio/mp4',
  };
  return map[ext] || 'audio/mpeg';
}

// ---- Live stream pacing ----
// Read the file in chunks and pace delivery to match real playback speed.
// This is what makes sync automatic — ShowPilot's relay receives bytes at
// the same rate FPP is feeding them to its speakers, so all phones that
// connect to the relay are in lockstep with the speakers.
//
// Key: chunks must arrive frequently enough that the browser never thinks
// the stream has stalled. 128kbps MP3 = 16,000 bytes/sec. We send ~1KB
// every 62ms — smooth and continuous, no 500ms gaps that trigger stall.
// For 320kbps files we send ~2.5KB every 62ms.

const CHUNK_BYTES = 4096; // 4KB — small enough for smooth pacing, triggers canplay quickly

function streamFile(filePath, startByte, fileSize, durationSec, res, onEnd) {
  let fd;
  try {
    fd = fs.openSync(filePath, 'r');
  } catch (err) {
    log('ERROR opening file:', err.message);
    onEnd();
    return;
  }

  let offset = startByte;
  let stopped = false;
  const buf = Buffer.alloc(CHUNK_BYTES);

  // Wall-clock pacing: track how many bytes should have been delivered by now
  // based on actual elapsed time. Send whenever we're behind, wait whenever
  // we're ahead. This gives exact real-time sync with FPP's playback regardless
  // of chunk size, while allowing an initial burst to fill the browser buffer fast.
  const bytesPerMs = (durationSec > 0 && fileSize > 0)
    ? Math.min(Math.max(fileSize / durationSec / 1000, 8), 80)
    : 16;
  log(`streaming: ${Math.round(bytesPerMs * 1000)} bytes/sec real-time paced`);

  const streamStartMs = Date.now();
  const streamStartByte = startByte;
  // Initial burst: deliver the first 3 seconds worth instantly so canplay fires fast
  const BURST_BYTES = Math.round(bytesPerMs * 3000);
  let totalSent = 0;

  function readChunk() {
    if (stopped) return;

    let bytesRead;
    try {
      bytesRead = fs.readSync(fd, buf, 0, CHUNK_BYTES, offset);
    } catch (err) {
      if (!stopped) log('ERROR reading:', err.message);
      cleanup();
      return;
    }

    if (bytesRead === 0) { cleanup(); return; }
    offset += bytesRead;
    totalSent += bytesRead;

    try {
      const ok = res.write(buf.slice(0, bytesRead));
      if (!ok) {
        res.once('drain', scheduleNext);
        return;
      }
    } catch (_) { cleanup(); return; }

    scheduleNext();
  }

  function scheduleNext() {
    if (stopped) return;
    // Target: always stay exactly BURST_BYTES ahead of real-time wall clock.
    // This gives 3s of pre-buffer for instant playback while pacing the
    // stream to match FPP's actual playback speed.
    //
    // totalSent should equal: bytesPerMs * elapsedMs + BURST_BYTES
    // If ahead: wait. If behind: send immediately.
    const elapsedMs = Date.now() - streamStartMs;
    const targetSent = (bytesPerMs * elapsedMs) + BURST_BYTES;
    const aheadBytes = totalSent - targetSent;
    if (aheadBytes > 0) {
      // We're ahead — wait until wall clock catches up
      const waitMs = Math.min(Math.round(aheadBytes / bytesPerMs), 200);
      setTimeout(readChunk, waitMs);
    } else {
      // Behind or on time — send immediately
      setImmediate(readChunk);
    }
  }

  function cleanup() {
    stopped = true;
    try { fs.closeSync(fd); } catch (_) {}
    onEnd();
  }

  res.once('close', () => { stopped = true; });
  readChunk();
}

function streamFileAtPace(filePath, startByte, fileSize, durationSec, res, onEnd) {
  const bytesPerMs = getBytesPerMs(fileSize, durationSec);
  const intervalMs = Math.max(20, Math.round(CHUNK_BYTES / bytesPerMs));
  log(`pacing: ${CHUNK_BYTES} bytes every ${intervalMs}ms (${Math.round(bytesPerMs * 1000)} bytes/sec, duration ${durationSec}s)`);

  let offset = startByte;
  let fd;

  try {
    fd = fs.openSync(filePath, 'r');
  } catch (err) {
    log('ERROR opening file:', err.message);
    onEnd();
    return;
  }

  let stopped = false;

  // Send an initial burst of ~3 seconds worth of audio so the browser
  // fires canplay quickly without waiting for paced chunks to trickle in.
  // After the burst, drop to paced delivery to stay in sync.
  const BURST_BYTES = Math.min(Math.round(bytesPerMs * 3000), 128 * 1024); // 3s worth, max 128KB
  const burstBuf = Buffer.alloc(BURST_BYTES);
  try {
    const bytesRead = fs.readSync(fd, burstBuf, 0, BURST_BYTES, offset);
    if (bytesRead > 0) {
      res.write(burstBuf.slice(0, bytesRead));
      offset += bytesRead;
      log(`burst: sent ${bytesRead} bytes to fill browser buffer`);
    }
  } catch (err) {
    log('ERROR in burst read:', err.message);
    try { fs.closeSync(fd); } catch (_) {}
    onEnd();
    return;
  }

  const buf = Buffer.alloc(CHUNK_BYTES);

  function readChunk() {
    if (stopped) return;

    let bytesRead;
    try {
      bytesRead = fs.readSync(fd, buf, 0, CHUNK_BYTES, offset);
    } catch (err) {
      if (!stopped) log('ERROR reading file:', err.message);
      cleanup();
      return;
    }

    if (bytesRead === 0) {
      cleanup();
      return;
    }

    offset += bytesRead;

    try {
      const ok = res.write(buf.slice(0, bytesRead));
      if (!ok) {
        res.once('drain', () => setTimeout(readChunk, intervalMs));
        return;
      }
    } catch (_) {
      cleanup();
      return;
    }

    setTimeout(readChunk, intervalMs);
  }

  function cleanup() {
    stopped = true;
    try { fs.closeSync(fd); } catch (_) {}
    onEnd();
  }

  res.once('close', () => { stopped = true; });

  // Start paced delivery after a short delay to let the burst settle
  setTimeout(readChunk, intervalMs);
}

// ---- HTTP server ----

const server = http.createServer((req, res) => {
  const url = new URL(req.url, `http://localhost:${PORT}`);
  const pathname = url.pathname;

  // CORS — ShowPilot server connects from a different origin
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  // ---- GET /health ----
  if (pathname === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      ok: true,
      version: VERSION,
      port: PORT,
      mediaRoot: MEDIA_ROOT,
      fppHost: FPP_HOST,
      fppStatus,
    }));
    return;
  }

  // ---- GET /status ----
  if (pathname === '/status') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    const cachedDur = fppStatus.filename ? (durationCache[fppStatus.filename] || 0) : 0;
    res.end(JSON.stringify({ ...fppStatus, durationSec: cachedDur }));
    return;
  }

  // ---- GET /audio/:filename ----
  if (pathname.startsWith('/audio/')) {
    const rawName = decodeURIComponent(pathname.slice('/audio/'.length));

    // Path traversal guard
    if (!rawName || rawName.includes('..') || rawName.includes('/') || rawName.includes('\\')) {
      res.writeHead(400, { 'Content-Type': 'text/plain' });
      res.end('Bad filename');
      return;
    }

    const filePath = path.join(MEDIA_ROOT, rawName);

    if (!filePath.startsWith(path.resolve(MEDIA_ROOT) + path.sep) &&
        filePath !== path.resolve(MEDIA_ROOT)) {
      res.writeHead(403, { 'Content-Type': 'text/plain' });
      res.end('Forbidden');
      return;
    }

    let stat;
    try {
      stat = fs.statSync(filePath);
    } catch (_) {
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      res.end('File not found');
      return;
    }

    // Async IIFE — request handler is sync but we need await for position calc
    (async () => {
      const positionSec = fppStatus.playing && fppStatus.filename === rawName
        ? fppStatus.positionSec
        : 0;

      // Get duration from cache or media meta API — never trust fppd/status
      let durationSec = await getDuration(rawName);

      const startByte = durationSec > 0
        ? Math.floor((positionSec / durationSec) * stat.size)
        : 0;

      const mime = mimeForFile(rawName);

      res.writeHead(200, {
        'Content-Type': mime,
        'Cache-Control': 'no-store',
        'X-Audio-Source': 'showpilot-daemon',
        'X-Audio-Version': VERSION,
        'X-Start-Byte': startByte,
        'X-Position-Sec': positionSec.toFixed(3),
      });

      log(`streaming "${rawName}" from byte ${startByte} (${positionSec.toFixed(1)}s)`);

      streamFile(filePath, startByte, stat.size, durationSec, res, () => {
        log(`stream ended for "${rawName}"`);
        try { res.end(); } catch (_) {}
      });
    })();

    return;
  }

  // ---- 404 ----
  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('Not found');
});

server.listen(PORT, '0.0.0.0', () => {
  log(`ShowPilot audio daemon v${VERSION} listening on port ${PORT}`);
  log(`Media root: ${MEDIA_ROOT}`);
  log(`FPP host: ${FPP_HOST}`);
});

server.on('error', (err) => {
  log('SERVER ERROR:', err.message);
  process.exit(1);
});

// Graceful shutdown
process.on('SIGTERM', () => { log('SIGTERM received, shutting down'); server.close(() => process.exit(0)); });
process.on('SIGINT',  () => { log('SIGINT received, shutting down');  server.close(() => process.exit(0)); });
