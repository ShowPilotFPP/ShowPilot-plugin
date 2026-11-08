#!/bin/bash
# ShowPilot — start the audio daemon if it should be running but isn't.
#
# The daemon only starts once a Server URL is set (see start_audio_daemon),
# and postStart.sh only runs when fppd starts. So on an FPP that started
# before the URL was entered, nothing ever started it. Restart Listener runs
# this. It never restarts a daemon that is already running, so phones that
# are already in sync aren't interrupted.

. "$(dirname "${BASH_SOURCE[0]}")/showpilot_env.sh"

if pgrep -f "$AUDIO_DAEMON" >/dev/null 2>&1; then
    exit 0
fi
ensure_runtime_files
start_audio_daemon
