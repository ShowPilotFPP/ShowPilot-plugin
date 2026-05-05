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
  process.stderr.write(line);
  if (LOG_FILE) {
    try { fs.appendFileSync(LOG_FILE, line); } catch (_) {}
  }
}

// ---- FPP status polling ----
// We poll FPP's status API to know what's currently playing and where
// in the song we are. Used by /status endpoint and for pacing the stream.

let fppStatus = { playing: false, filename: null, positionSec: 0, durationSec: 0 };

async function pollFppStatus() {
  try {
    const res = await fetch(`${FPP_HOST}/api/fppd/status`, {
      signal: AbortSignal.timeout(2000),
    });
    if (!res.ok) return;
    const data = await res.json();
    const playing = data.status === 1 || data.status === 'playing';
    fppStatus = {
      playing,
      filename: data.current_song || null,
      positionSec: parseFloat(data.seconds_elapsed || 0),
      durationSec: parseFloat(data.seconds_remaining
        ? (data.seconds_elapsed || 0) + data.seconds_remaining
        : (data.song_duration || 0)),
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

const CHUNK_BYTES = 1024; // 1KB per chunk for smooth delivery

function getBytesPerMs(fileSize, durationSec) {
  if (!durationSec || durationSec <= 0) return 16; // fallback: 128kbps
  // Add 10% headroom so we stay slightly ahead of playback
  return (fileSize / durationSec / 1000) * 1.1;
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

  const buf = Buffer.alloc(CHUNK_BYTES);
  let stopped = false;

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
      // End of file
      cleanup();
      return;
    }

    offset += bytesRead;

    try {
      const ok = res.write(buf.slice(0, bytesRead));
      if (!ok) {
        // Back-pressure — wait for drain before next chunk
        res.once('drain', () => setTimeout(readChunk, intervalMs));
        return;
      }
    } catch (_) {
      // Client disconnected
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

  // Allow caller to stop the stream (e.g. song changed, client disconnected)
  res.once('close', () => { stopped = true; });

  readChunk();
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
    res.end(JSON.stringify(fppStatus));
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

    // Confirm file is inside MEDIA_ROOT (secondary guard)
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

    // Calculate start byte from FPP's current position so ShowPilot's relay
    // starts reading from where FPP currently is in the song. This means
    // phones that connect mid-song join the stream at the correct position.
    const positionSec = fppStatus.playing && fppStatus.filename === rawName
      ? fppStatus.positionSec
      : 0;
    const durationSec = fppStatus.durationSec || 0;
    const startByte = durationSec > 0
      ? Math.floor((positionSec / durationSec) * stat.size)
      : 0;

    const mime = mimeForFile(rawName);

    // No Content-Length — this is a live paced stream
    res.writeHead(200, {
      'Content-Type': mime,
      'Cache-Control': 'no-store',
      'X-Audio-Source': 'showpilot-daemon',
      'X-Audio-Version': VERSION,
      'X-Start-Byte': startByte,
      'X-Position-Sec': positionSec.toFixed(3),
    });

    log(`streaming "${rawName}" from byte ${startByte} (${positionSec.toFixed(1)}s)`);

    streamFileAtPace(filePath, startByte, stat.size, fppStatus.durationSec || 0, res, () => {
      log(`stream ended for "${rawName}"`);
      try { res.end(); } catch (_) {}
    });

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
