#!/bin/bash
# ShowPilot plugin postStart — runs every time fppd starts.
#
# Uses a lock file to guard against FPP running postStart twice on some
# versions, which caused the daemon to spawn twice and corrupt the relay
# with interleaved bytes from two instances.

PLUGIN_DIR="/home/fpp/media/plugins/showpilot"
LOG_DIR="/home/fpp/media/logs"
CONFIG_FILE="/home/fpp/media/config/plugin.showpilot"
LOCK_FILE="/tmp/showpilot-poststart.lock"
mkdir -p "$LOG_DIR"

# Guard against double-invocation — FPP calls postStart twice on some versions.
# If another instance of this script is already running, exit immediately.
if [ -f "$LOCK_FILE" ]; then
    PID=$(cat "$LOCK_FILE" 2>/dev/null)
    if kill -0 "$PID" 2>/dev/null; then
        exit 0
    fi
fi
echo $$ > "$LOCK_FILE"
trap "rm -f '$LOCK_FILE'" EXIT

# 1. Self-heal permissions
chmod +x "$PLUGIN_DIR/commands/"*.php 2>/dev/null
chmod +x "$PLUGIN_DIR/scripts/"*.sh 2>/dev/null
chmod +x "$PLUGIN_DIR/showpilot_listener.php" 2>/dev/null
chmod +x "$PLUGIN_DIR/listener_status.php" 2>/dev/null
chmod +x "$PLUGIN_DIR/extract_audio.php" 2>/dev/null
chmod +x "$PLUGIN_DIR/audio_daemon_status.php" 2>/dev/null

# Keep the plugin config writable
touch "$CONFIG_FILE" 2>/dev/null
chown fpp:fpp "$CONFIG_FILE" 2>/dev/null
chmod 666 "$CONFIG_FILE" 2>/dev/null

# 2. Kill any existing processes
pkill -f "php $PLUGIN_DIR/showpilot_listener.php" 2>/dev/null
pkill -f "node $PLUGIN_DIR/showpilot_audio.js" 2>/dev/null
sleep 1

# 3. Spawn listener
setsid /usr/bin/php "$PLUGIN_DIR/showpilot_listener.php" \
    </dev/null >/dev/null 2>&1 &

# 4. Spawn audio daemon if Node 18+ available
if command -v node >/dev/null 2>&1; then
    NODE_MAJOR=$(node --version 2>/dev/null | sed 's/v//' | cut -d. -f1)
    if [ "${NODE_MAJOR:-0}" -ge 18 ]; then
        AUDIO_PORT=$(grep -E '^audioDaemonPort' "$CONFIG_FILE" 2>/dev/null | cut -d'"' -f2)
        : "${AUDIO_PORT:=8090}"
        PORT="$AUDIO_PORT" \
        MEDIA_ROOT="/home/fpp/media/music" \
        FPP_HOST="http://127.0.0.1" \
        LOG_FILE="$LOG_DIR/showpilot-audio.log" \
        setsid /usr/bin/node "$PLUGIN_DIR/showpilot_audio.js" \
            </dev/null >>"$LOG_DIR/showpilot-audio.log" 2>&1 &
    fi
fi

#postStart
