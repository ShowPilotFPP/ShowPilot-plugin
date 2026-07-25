#!/bin/bash
# ShowPilot — restart audio daemon
# Run this after a plugin update to pick up the new showpilot_audio.js
# without needing a full fppd restart.
#
# Usage: sudo /home/fpp/media/plugins/showpilot/scripts/restart-daemon.sh

PLUGIN_DIR="/home/fpp/media/plugins/showpilot"
LOG_DIR="/home/fpp/media/logs"
CONFIG_FILE="/home/fpp/media/config/plugin.showpilot"
PID_FILE="/tmp/showpilot-audio.pid"

# Kill existing daemon via PID file, fall back to pkill
if [ -f "$PID_FILE" ]; then
    OLD_PID=$(cat "$PID_FILE" 2>/dev/null)
    if [ -n "$OLD_PID" ] && kill -0 "$OLD_PID" 2>/dev/null; then
        echo "Stopping daemon (pid $OLD_PID)..."
        kill "$OLD_PID" 2>/dev/null
        sleep 0.5
        kill -9 "$OLD_PID" 2>/dev/null || true
    fi
    rm -f "$PID_FILE"
fi
pkill -f "node $PLUGIN_DIR/showpilot_audio.js" 2>/dev/null
sleep 1

# Verify it's gone
if pgrep -f "node $PLUGIN_DIR/showpilot_audio.js" >/dev/null 2>&1; then
    echo "ERROR: daemon still running after kill attempt"
    exit 1
fi

# Spawn new daemon
if ! command -v node >/dev/null 2>&1; then
    echo "ERROR: node not found"
    exit 1
fi

AUDIO_PORT=$(grep -E '^audioDaemonPort' "$CONFIG_FILE" 2>/dev/null | cut -d'"' -f2)
: "${AUDIO_PORT:=8090}"

PORT="$AUDIO_PORT" \
MEDIA_ROOT="/home/fpp/media/music" \
FPP_HOST="http://127.0.0.1" \
LOG_FILE="$LOG_DIR/plugin-showpilot-audio.log" \
setsid /usr/bin/node --max-old-space-size=64 "$PLUGIN_DIR/showpilot_audio.js" \
    </dev/null >>"$LOG_DIR/plugin-showpilot-audio.log" 2>&1 &

sleep 1
NEW_PID=$(cat "$PID_FILE" 2>/dev/null)
if [ -n "$NEW_PID" ] && kill -0 "$NEW_PID" 2>/dev/null; then
    echo "Daemon started (pid $NEW_PID)"
else
    echo "WARNING: daemon may not have started — check $LOG_DIR/plugin-showpilot-audio.log"
fi
