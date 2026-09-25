#!/bin/bash
# ShowPilot — restart the audio daemon in place (used by fpp_upgrade.sh).

. "$(dirname "${BASH_SOURCE[0]}")/showpilot_env.sh"

ensure_runtime_files
stop_audio_daemon
if pgrep -f "$AUDIO_DAEMON" >/dev/null 2>&1; then
    echo "ERROR: audio daemon still running after kill attempt"
    exit 1
fi

start_audio_daemon || exit 1
sleep 1
if [ -n "$(config_value serverUrl)" ] && ! pgrep -f "$AUDIO_DAEMON" >/dev/null 2>&1; then
    echo "WARNING: audio daemon did not start — check $PLUGIN_LOG"
    exit 1
fi
echo "Audio daemon restarted"
