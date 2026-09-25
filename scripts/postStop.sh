#!/bin/bash
# ShowPilot postStop — stop what postStart started.

. "$(dirname "${BASH_SOURCE[0]}")/showpilot_env.sh"

stop_listener
stop_audio_daemon

#postStop
