#!/usr/bin/env node
// ============================================================
// ShowPilot Audio Daemon v2.1.0
// ============================================================
// Runs on the FPP Pi alongside fppd. Two responsibilities:
//
// 1. HTTP audio serving — serves audio files from FPP's music directory
//    with Range support. ShowPilot caches these so phones only hit this
//    once per song, not continuously.
//
// 2. WebSocket position broadcast — broadcasts FPP's actual playback
//    position. ShowPilot opens ONE WebSocket connection here and fans
//    positions out to all viewer phones, which use playbackRate to track
//    FPP's position — automatic sync with the show speakers.
//
// Environment variables:
//   PORT        — HTTP/WS port (default: 8090)
//   MEDIA_ROOT  — FPP music dir (default: /home/fpp/media/music)
//   FPP_HOST    — FPP API base URL (default: http://127.0.0.1)
//   LOG_FILE    — the plugin's log file (default: stderr)
// ============================================================

'use strict';

const http = require('http');
const fs   = require('fs');
const path = require('path');
const net  = require('net');
const { execFileSync } = require('child_process');

const PORT       = parseInt(process.env.PORT || '8090', 10);
const MEDIA_ROOT = path.resolve(process.env.MEDIA_ROOT || '/home/fpp/media/music');
const FPP_HOST   = (process.env.FPP_HOST || 'http://127.0.0.1').replace(/\/+$/, '');
const LOG_FILE   = process.env.LOG_FILE || null;
const VERSION    = '2.1.0';

// Written by the C++ MultiSync plugin inside fppd.
const FIFO_PATH = '/tmp/SHOWPILOT_FIFO';

const AUDIO_MIME = {
  '.mp3': 'audio/mpeg', '.ogg': 'audio/ogg', '.wav': 'audio/wav',
  '.flac': 'audio/flac', '.aac': 'audio/aac', '.m4a': 'audio/mp4',
};

// ---- Logging ----

function log(...args) {
  const line = `[${new Date().toISOString()}] [audio] ${args.join(' ')}\n`;
  if (LOG_FILE) {
    try { fs.appendFileSync(LOG_FILE, line); return; } catch (_) { /* fall through */ }
  }
  process.stderr.write(line);
}

// ---- Shared state ----

// `at` = Date.now() when positionSec was observed (v0.14.6+), so every
// broadcast can advance the position to the moment it is stamped.
let fppStatus = { playing: false, filename: null, positionSec: 0, at: 0 };
let lastFifoMsgAt = 0;
let lastSyncPointAt = 0;
const wsClients = new Set();

// ---- WebSocket broadcast ----

function broadcast(payload) {
  if (wsClients.size === 0) return;
  const msg = JSON.stringify(payload);
  for (const ws of wsClients) {
    try { ws.send(msg); } catch (_) { wsClients.delete(ws); }
  }
}

// v0.14.6: position is advanced to the send moment. Before, a position was
// stamped with the time it was SENT, however long ago FPP reported it —
// 0-100ms late from FIFO polling, and up to ~500ms for the forced sync point
// after a song change — which made viewers' first estimate jump.
function positionMessage(type) {
  const now = Date.now();
  let positionSec = fppStatus.positionSec;
  if (fppStatus.playing && fppStatus.at > 0) {
    const ageSec = (now - fppStatus.at) / 1000;
    if (ageSec > 0 && ageSec < 5) positionSec += ageSec;
  }
  return {
    type,
    playing: fppStatus.playing,
    filename: fppStatus.filename,
    positionSec,
    serverTimestamp: now,
  };
}

function broadcastPosition() {
  broadcast(positionMessage('position'));
}

// A sync point is a named checkpoint every viewer waiting to start playback
// receives at once, so they all seek to the same position at the same
// wall-clock moment. Never sent while stopped or before the filename is
// known: a null sync point would snap viewers back to 0:00.
function broadcastSyncPointIfDue() {
  if (wsClients.size === 0 || !fppStatus.playing || !fppStatus.filename) return;
  const now = Date.now();
  if (now - lastSyncPointAt < 1000) return;
  lastSyncPointAt = now;
  broadcast(positionMessage('syncPoint'));
}

// ---- FPP status via FIFO (primary) + HTTP polling (fallback) ----
//
// The C++ plugin hooks FPP's MultiSync system and writes one event per line:
//   MediaSyncStart/<file>, MediaSyncStop/<file>, MediaSyncPacket/<file>/<sec>

function handleFppEvent(line) {
  const str = line.trim();
  if (!str) return;
  const parts = str.split('/');
  const type = parts[0];

  if (type === 'MediaSyncPacket' && parts.length >= 3) {
    const filename = parts.slice(1, -1).join('/');
    const positionSec = parseFloat(parts[parts.length - 1]);
    const changed = filename !== fppStatus.filename;
    fppStatus = { playing: true, filename, positionSec, at: Date.now() };
    if (changed) {
      log(`[fifo] now playing: "${filename}" at ${positionSec.toFixed(3)}s`);
      lastSyncPointAt = Date.now() + 800;
      setTimeout(() => {
        if (fppStatus.filename === filename && fppStatus.playing) {
          lastSyncPointAt = 0;
          broadcastSyncPointIfDue();
        }
      }, 1000);
    }
    broadcastPosition();
    broadcastSyncPointIfDue();

  } else if (type === 'MediaSyncStart' && parts.length >= 2) {
    const filename = parts.slice(1).join('/');
    log(`[fifo] MediaSyncStart: "${filename}"`);
    fppStatus = { playing: true, filename, positionSec: 0, at: Date.now() };
    // Suppress sync points until the first packet carries a real position.
    lastSyncPointAt = Date.now() + 1000;
    broadcastPosition();

  } else if (type === 'MediaSyncStop' && parts.length >= 2) {
    log(`[fifo] MediaSyncStop: "${parts.slice(1).join('/')}"`);
    fppStatus = { ...fppStatus, playing: false };
    broadcastPosition();
  }
}

// /tmp is world-writable, so refuse anything at FIFO_PATH that isn't a
// FIFO (a planted symlink or regular file) instead of opening through it.
function ensureFifo() {
  let st = null;
  try { st = fs.lstatSync(FIFO_PATH); } catch (_) { /* doesn't exist yet */ }
  if (st && !st.isFIFO()) {
    throw new Error(`${FIFO_PATH} exists and is not a FIFO`);
  }
  if (!st) {
    execFileSync('mkfifo', ['-m', '660', FIFO_PATH]);
  }
}

function startFifoListener() {
  let buf = '';

  function onData(text) {
    lastFifoMsgAt = Date.now();
    buf += text;
    if (buf.length > 65536) buf = buf.slice(-4096);  // bound runaway lines
    const lines = buf.split('\n');
    buf = lines.pop();
    lines.forEach(handleFppEvent);
  }

  function openFifo() {
    let fd = -1;
    try {
      ensureFifo();
      // O_RDWR keeps the FIFO open even with no writer attached (no EOF).
      fd = fs.openSync(FIFO_PATH,
        fs.constants.O_RDWR | fs.constants.O_NONBLOCK | fs.constants.O_NOFOLLOW);
    } catch (err) {
      log(`[fifo] open failed: ${err.message}`);
      setTimeout(openFifo, 2000);
      return;
    }

    // v0.14.6: event-driven — each FPP event is handled the moment it is
    // written (libuv watches the pipe), instead of being picked up by a
    // 100ms poll that stamped positions 0-100ms late.
    let pipe;
    try {
      pipe = new net.Socket({ fd, readable: true, writable: false });
    } catch (err) {
      log(`[fifo] event-driven read unavailable (${err.message}); falling back to 20ms polling`);
      pollLoop(fd);
      return;
    }
    pipe.setEncoding('utf8');
    pipe.on('data', onData);
    let reopening = false;
    const reopen = (why) => {
      if (reopening) return;
      reopening = true;
      log(`[fifo] ${why}; reopening`);
      try { pipe.destroy(); } catch (_) { /* already closed */ }
      setTimeout(openFifo, 1000);
    };
    pipe.on('error', (err) => reopen(`read error: ${err.message}`));
    pipe.on('close', () => reopen('closed'));
    log(`[fifo] listening on ${FIFO_PATH} (event-driven)`);
  }

  // Fallback only: short-interval non-blocking reads.
  function pollLoop(fd) {
    const readBuf = Buffer.alloc(4096);
    (function tick() {
      try {
        const n = fs.readSync(fd, readBuf, 0, readBuf.length, null);
        if (n > 0) onData(readBuf.toString('utf8', 0, n));
      } catch (err) {
        if (err.code !== 'EAGAIN' && err.code !== 'EWOULDBLOCK') {
          log(`[fifo] read error: ${err.message}`);
          try { fs.closeSync(fd); } catch (_) { /* already closed */ }
          setTimeout(openFifo, 1000);
          return;
        }
      }
      setTimeout(tick, 20);
    })();
  }

  openFifo();
}

// HTTP polling — only used when the FIFO hasn't delivered anything recently
// (C++ plugin not built, or fppd restarting).
async function pollFppStatus() {
  if (Date.now() - lastFifoMsgAt < 2000) return;
  try {
    const res = await fetch(`${FPP_HOST}/api/fppd/status`, { signal: AbortSignal.timeout(2000) });
    if (!res.ok) return;
    const data = await res.json();
    const playing = data.status === 1 || data.status === 'playing';
    const filename = data.current_song || null;
    const positionSec = parseFloat(data.seconds_elapsed || 0);
    const changed = filename !== fppStatus.filename || playing !== fppStatus.playing;
    fppStatus = { playing, filename, positionSec, at: Date.now() };
    if (changed && filename) {
      log(`[http] now playing: "${filename}" at ${positionSec.toFixed(1)}s`);
    }
    broadcastPosition();
    broadcastSyncPointIfDue();
  } catch (_) { /* fppd not reachable — try again next tick */ }
}

// ---- HTTP audio serving with Range support ----

// Returns {start, end} for a single satisfiable byte range, null when there
// is no usable Range header (serve the whole file), or 'invalid' for 416.
function parseRange(header, size) {
  if (!header) return null;
  const m = /^bytes=(\d*)-(\d*)$/.exec(header.trim());
  if (!m || (m[1] === '' && m[2] === '')) return null;
  let start;
  let end;
  if (m[1] === '') {
    // Suffix range: the last N bytes.
    const suffix = parseInt(m[2], 10);
    if (suffix === 0) return 'invalid';
    start = Math.max(0, size - suffix);
    end = size - 1;
  } else {
    start = parseInt(m[1], 10);
    end = m[2] === '' ? size - 1 : Math.min(parseInt(m[2], 10), size - 1);
  }
  if (start >= size || start > end) return 'invalid';
  return { start, end };
}

function serveAudioFile(req, res, filePath, mime) {
  let stat;
  try { stat = fs.statSync(filePath); } catch (_) { stat = null; }
  if (!stat || !stat.isFile()) {
    res.writeHead(404); res.end('Not found'); return;
  }

  const size = stat.size;
  const range = parseRange(req.headers.range, size);
  const headers = { 'Content-Type': mime, 'Accept-Ranges': 'bytes', 'Cache-Control': 'no-store' };

  if (range === 'invalid') {
    res.writeHead(416, { 'Content-Range': `bytes */${size}` });
    res.end();
    return;
  }

  let stream;
  if (range) {
    res.writeHead(206, {
      ...headers,
      'Content-Range': `bytes ${range.start}-${range.end}/${size}`,
      'Content-Length': range.end - range.start + 1,
    });
    stream = fs.createReadStream(filePath, range);
  } else {
    res.writeHead(200, { ...headers, 'Content-Length': size, 'X-Audio-Source': 'showpilot-daemon' });
    stream = fs.createReadStream(filePath);
  }
  stream.on('error', () => res.destroy());
  stream.pipe(res);
}

// Maps /audio/<name> to a file directly inside MEDIA_ROOT, or null.
function resolveAudioPath(encodedName) {
  let name;
  try { name = decodeURIComponent(encodedName); } catch (_) { return null; }
  if (!name || name !== path.basename(name) || name.startsWith('.') || name.includes('\0')) return null;
  if (!AUDIO_MIME[path.extname(name).toLowerCase()]) return null;
  return path.join(MEDIA_ROOT, name);
}

const server = http.createServer((req, res) => {
  const pathname = new URL(req.url, 'http://localhost').pathname;

  // Read-only media; ShowPilot and viewer pages fetch it cross-origin.
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');

  if (req.method === 'OPTIONS') { res.writeHead(204); res.end(); return; }
  if (req.method !== 'GET' && req.method !== 'HEAD') { res.writeHead(405); res.end(); return; }

  if (pathname === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: true, version: VERSION, port: PORT, fppStatus, wsClients: wsClients.size }));
    return;
  }

  if (pathname === '/status') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ...fppStatus, serverTimestamp: Date.now() }));
    return;
  }

  if (pathname.startsWith('/audio/')) {
    const filePath = resolveAudioPath(pathname.slice('/audio/'.length));
    if (!filePath) { res.writeHead(400); res.end('Bad filename'); return; }
    serveAudioFile(req, res, filePath, AUDIO_MIME[path.extname(filePath).toLowerCase()]);
    return;
  }

  res.writeHead(404); res.end('Not found');
});

// ---- WebSocket upgrade handling ----

let WebSocketServer = null;
// v0.14.5: ws is vendored in vendor/ws (no npm needed). The plain
// require('ws') fallback covers a node_modules copy from older installs.
for (const mod of [path.join(__dirname, 'vendor', 'ws'), 'ws']) {
  try { ({ WebSocketServer } = require(mod)); break; } catch (_) {}
}
if (!WebSocketServer) {
  log('WARN: ws module not found (vendor/ws missing?) — position broadcast disabled.');
}

if (WebSocketServer) {
  const wss = new WebSocketServer({ server });

  wss.on('connection', (ws, req) => {
    wsClients.add(ws);
    log(`WebSocket connected (${wsClients.size} total) from ${req.socket.remoteAddress}`);
    ws.send(JSON.stringify(positionMessage('position')));
    ws.on('close', () => { wsClients.delete(ws); log(`WebSocket disconnected (${wsClients.size} remaining)`); });
    ws.on('error', () => wsClients.delete(ws));
  });

  // Protocol-level ping every 30s keeps idle connections from being dropped.
  setInterval(() => {
    for (const ws of wsClients) {
      if (ws.readyState === ws.OPEN) {
        try { ws.ping(); } catch (_) { wsClients.delete(ws); }
      }
    }
  }, 30000);
}

// App-level heartbeat every 5s, so clients notice a stalled daemon even
// while FPP is idle and no positions are flowing.
setInterval(() => {
  broadcast({ type: 'heartbeat', playing: fppStatus.playing, serverTimestamp: Date.now() });
}, 5000);

// ---- Start ----

startFifoListener();
setInterval(pollFppStatus, 250);
pollFppStatus();

server.listen(PORT, '0.0.0.0', () => {
  log(`ShowPilot audio daemon v${VERSION} listening on port ${PORT} (pid ${process.pid})`);
  log(`Media root: ${MEDIA_ROOT}`);
});

server.on('error', (err) => { log('SERVER ERROR:', err.message); process.exit(1); });

for (const signal of ['SIGTERM', 'SIGINT']) {
  process.on(signal, () => {
    log(`${signal}, shutting down`);
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(0), 2000).unref();
  });
}
