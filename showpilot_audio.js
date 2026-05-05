#!/usr/bin/env node
// ============================================================
// ShowPilot Audio Daemon v2.0.0
// ============================================================
// Runs on the FPP Pi alongside fppd. Two responsibilities:
//
// 1. HTTP audio serving — serves audio files from local disk
//    with Range support. ShowPilot caches these so phones
//    only hit this once per song, not continuously.
//
// 2. WebSocket position broadcast — broadcasts FPP's actual
//    playback position every 250ms. ShowPilot opens ONE
//    WebSocket connection here and fans positions out to all
//    viewer phones via Socket.io. Phones use playbackRate to
//    track FPP's position — automatic sync with show speakers.
//
// Environment variables:
//   PORT        — HTTP/WS port (default: 8090)
//   MEDIA_ROOT  — FPP music dir (default: /home/fpp/media/music)
//   FPP_HOST    — FPP API base URL (default: http://127.0.0.1)
//   LOG_FILE    — log file path
// ============================================================

'use strict';

const http = require('http');
const fs   = require('fs');
const path = require('path');

const PORT       = parseInt(process.env.PORT    || '8090', 10);
const MEDIA_ROOT = process.env.MEDIA_ROOT       || '/home/fpp/media/music';
const FPP_HOST   = (process.env.FPP_HOST        || 'http://127.0.0.1').replace(/\/+$/, '');
const VERSION    = '2.0.0';
const LOG_FILE   = process.env.LOG_FILE || null;

// ---- Logging ----

function log(...args) {
  const line = `[${new Date().toISOString()}] [showpilot-audio] ${args.join(' ')}\n`;
  if (LOG_FILE) {
    try { fs.appendFileSync(LOG_FILE, line); } catch (_) { process.stderr.write(line); }
  } else {
    process.stderr.write(line);
  }
}

// ---- FPP status via FIFO (primary) + HTTP polling (fallback) ----
//
// The ShowPilot C++ FPP plugin hooks into FPP's MultiSync system and writes
// sync events to a named FIFO at /tmp/SHOWPILOT_FIFO. This gives us
// precise position data from FPP's internal clock, called directly by fppd.
//
// Falls back to HTTP polling /api/fppd/status if C++ plugin not installed.

const FIFO_PATH = '/tmp/SHOWPILOT_FIFO';
const { execSync } = require('child_process');

let fppStatus = { playing: false, filename: null, positionSec: 0 };
const durationCache = {};
let lastFifoMsgAt = 0;

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

function handleFppEvent(line) {
  const str = line.trim();
  if (!str) return;
  const parts = str.split('/');
  const type = parts[0];

  if (type === 'MediaSyncPacket' && parts.length >= 3) {
    const filename = parts.slice(1, -1).join('/'); // handle filenames with slashes
    const positionSec = parseFloat(parts[parts.length - 1]);
    const changed = filename !== fppStatus.filename;
    fppStatus = { playing: true, filename, positionSec };
    if (changed) {
      log(`[fifo] now playing: "${filename}" at ${positionSec.toFixed(3)}s`);
      lastSyncPointAt = Date.now() + 6000;
    }
    broadcastPosition();
    broadcastSyncPointIfDue();

  } else if (type === 'MediaSyncStart' && parts.length >= 2) {
    const filename = parts.slice(1).join('/');
    log(`[fifo] MediaSyncStart: "${filename}"`);
    const changed = filename !== fppStatus.filename;
    fppStatus = { playing: true, filename, positionSec: 0 };
    if (changed) lastSyncPointAt = Date.now() + 6000;
    broadcastPosition();

  } else if (type === 'MediaSyncStop' && parts.length >= 2) {
    log(`[fifo] MediaSyncStop: "${parts.slice(1).join('/')}"`);
    fppStatus = { playing: false, filename: fppStatus.filename, positionSec: fppStatus.positionSec };
    broadcastPosition();
  }
}

function startFifoListener() {
  // Create FIFO if it doesn't exist
  try {
    execSync(`[ -p ${FIFO_PATH} ] || mkfifo ${FIFO_PATH}`);
    execSync(`chmod 666 ${FIFO_PATH}`);
  } catch (_) {}

  let buf = '';
  let reopenDelay = 1000;

  function openFifo() {
    try {
      // Open RDWR so the FIFO stays open even when no writer (C++ plugin) is attached.
      // O_RDONLY + O_NONBLOCK returns EOF immediately when no writer exists.
      const fd = fs.openSync(FIFO_PATH, fs.constants.O_RDWR | fs.constants.O_NONBLOCK);
      const stream = fs.createReadStream(null, { fd, flags: 'r' });
      reopenDelay = 1000;

      stream.on('data', (chunk) => {
        lastFifoMsgAt = Date.now();
        buf += chunk.toString();
        const lines = buf.split('\n');
        buf = lines.pop();
        lines.forEach(handleFppEvent);
      });

      stream.on('end', () => {
        // Writer (C++ plugin) closed — reopen quietly, no log spam
        setTimeout(openFifo, 500);
      });

      stream.on('error', (err) => {
        if (err.code !== 'EAGAIN') log(`[fifo] error: ${err.message}`);
        setTimeout(openFifo, reopenDelay);
      });

    } catch (err) {
      // FIFO not ready or C++ plugin not writing yet — back off quietly
      reopenDelay = Math.min(reopenDelay * 2, 10000);
      setTimeout(openFifo, reopenDelay);
    }
  }

  log(`[fifo] listening on ${FIFO_PATH}`);
  openFifo();
}

// HTTP polling — only used when FIFO hasn't received data recently
async function pollFppStatus() {
  if (Date.now() - lastFifoMsgAt < 2000) return;
  try {
    const res = await fetch(`${FPP_HOST}/api/fppd/status`, {
      signal: AbortSignal.timeout(2000),
    });
    if (!res.ok) return;
    const data = await res.json();
    const playing = data.status === 1 || data.status === 'playing';
    const filename = data.current_song || null;
    const positionSec = parseFloat(data.seconds_elapsed || 0);
    const changed = filename !== fppStatus.filename || playing !== fppStatus.playing;
    fppStatus = { playing, filename, positionSec };
    if (changed && filename) {
      log(`[http] now playing: "${filename}" at ${positionSec.toFixed(1)}s`);
      lastSyncPointAt = Date.now() + 6000;
    }
    broadcastPosition();
    broadcastSyncPointIfDue();
  } catch (_) {}
}

startFifoListener();
setInterval(pollFppStatus, 250);
pollFppStatus();

// ---- WebSocket position broadcast ----

let wsClients = new Set();
let WebSocketServer = null;

try {
  WebSocketServer = require('ws').WebSocketServer;
  log('ws module loaded — WebSocket position broadcast enabled');
} catch (_) {
  log('WARN: ws module not installed — run: npm install ws in plugin dir');
}

function broadcastPosition() {
  if (wsClients.size === 0) return;
  const msg = JSON.stringify({
    type: 'position',
    playing: fppStatus.playing,
    filename: fppStatus.filename,
    positionSec: fppStatus.positionSec,
    serverTimestamp: Date.now(),
  });
  for (const ws of wsClients) {
    try { ws.send(msg); } catch (_) { wsClients.delete(ws); }
  }
}

// Broadcast a sync point every 2 seconds — a named checkpoint all viewers
// can use to coordinate play start. All devices waiting for the next sync
// point will receive the same positionSec and serverTimestamp, so they can
// all seek to the same position and play at the same wall-clock moment.
let lastSyncPointAt = 0;
function broadcastSyncPointIfDue() {
  if (wsClients.size === 0) return;
  const now = Date.now();
  if (now - lastSyncPointAt < 2000) return;
  lastSyncPointAt = now;
  const msg = JSON.stringify({
    type: 'syncPoint',
    playing: fppStatus.playing,
    filename: fppStatus.filename,
    positionSec: fppStatus.positionSec,
    serverTimestamp: now,
  });
  for (const ws of wsClients) {
    try { ws.send(msg); } catch (_) { wsClients.delete(ws); }
  }
}

// ---- MIME types ----

function mimeForFile(filename) {
  const ext = path.extname(filename).toLowerCase();
  return { '.mp3': 'audio/mpeg', '.ogg': 'audio/ogg', '.wav': 'audio/wav',
           '.flac': 'audio/flac', '.aac': 'audio/aac', '.m4a': 'audio/mp4' }[ext] || 'audio/mpeg';
}

// ---- HTTP audio serving with Range support ----

function serveAudioFile(req, res, filePath, filename) {
  let stat;
  try { stat = fs.statSync(filePath); } catch (_) {
    res.writeHead(404); res.end('Not found'); return;
  }

  const rangeHeader = req.headers['range'];
  const fileSize = stat.size;
  const mime = mimeForFile(filename);

  if (rangeHeader) {
    const [startStr, endStr] = rangeHeader.replace('bytes=', '').split('-');
    const start = parseInt(startStr, 10);
    const end = endStr ? parseInt(endStr, 10) : fileSize - 1;
    const chunkSize = end - start + 1;
    res.writeHead(206, {
      'Content-Range': `bytes ${start}-${end}/${fileSize}`,
      'Accept-Ranges': 'bytes',
      'Content-Length': chunkSize,
      'Content-Type': mime,
      'Cache-Control': 'no-store',
    });
    fs.createReadStream(filePath, { start, end }).pipe(res);
  } else {
    res.writeHead(200, {
      'Content-Length': fileSize,
      'Content-Type': mime,
      'Accept-Ranges': 'bytes',
      'Cache-Control': 'no-store',
      'X-Audio-Source': 'showpilot-daemon',
    });
    fs.createReadStream(filePath).pipe(res);
  }
}

// ---- HTTP server ----

const server = http.createServer((req, res) => {
  const parsedUrl = new URL(req.url, `http://localhost:${PORT}`);
  const pathname = parsedUrl.pathname;

  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');

  if (req.method === 'OPTIONS') { res.writeHead(204); res.end(); return; }

  if (pathname === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: true, version: VERSION, port: PORT,
      fppStatus, wsClients: wsClients.size }));
    return;
  }

  if (pathname === '/status') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    const dur = fppStatus.filename ? (durationCache[fppStatus.filename] || 0) : 0;
    res.end(JSON.stringify({ ...fppStatus, durationSec: dur, serverTimestamp: Date.now() }));
    return;
  }

  if (pathname.startsWith('/audio/')) {
    const rawName = decodeURIComponent(pathname.slice('/audio/'.length));
    if (!rawName || rawName.includes('..') || rawName.includes('/') || rawName.includes('\\')) {
      res.writeHead(400); res.end('Bad filename'); return;
    }
    const filePath = path.join(MEDIA_ROOT, rawName);
    if (!filePath.startsWith(path.resolve(MEDIA_ROOT) + path.sep)) {
      res.writeHead(403); res.end('Forbidden'); return;
    }
    serveAudioFile(req, res, filePath, rawName);
    return;
  }

  res.writeHead(404); res.end('Not found');
});

// ---- WebSocket upgrade handling ----

if (WebSocketServer) {
  const wss = new WebSocketServer({ server });
  wss.on('connection', (ws, req) => {
    wsClients.add(ws);
    log(`WebSocket connected (${wsClients.size} total) from ${req.socket.remoteAddress}`);
    // Send current state immediately
    ws.send(JSON.stringify({
      type: 'position',
      playing: fppStatus.playing,
      filename: fppStatus.filename,
      positionSec: fppStatus.positionSec,
      serverTimestamp: Date.now(),
    }));
    ws.on('close', () => { wsClients.delete(ws); log(`WebSocket disconnected (${wsClients.size} remaining)`); });
    ws.on('error', () => wsClients.delete(ws));
  });
}

server.listen(PORT, '0.0.0.0', () => {
  log(`ShowPilot audio daemon v${VERSION} listening on port ${PORT}`);
  log(`Media root: ${MEDIA_ROOT}`);
  log(`FPP host: ${FPP_HOST}`);
});

server.on('error', (err) => { log('SERVER ERROR:', err.message); process.exit(1); });
process.on('SIGTERM', () => { log('SIGTERM, shutting down'); server.close(() => process.exit(0)); });
process.on('SIGINT',  () => { log('SIGINT, shutting down');  server.close(() => process.exit(0)); });
