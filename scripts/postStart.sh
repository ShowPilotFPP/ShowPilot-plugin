#!/bin/bash
# OpenFalcon plugin postStart — launches the PHP listener alongside fppd.
# Started detached so it survives script exit.

PLUGIN_DIR="/home/fpp/media/plugins/openfalcon"
LOG_DIR="/home/fpp/media/logs"
mkdir -p "$LOG_DIR"

# PHP listener (handles voting, queue, plugin sync to OpenFalcon)
setsid /usr/bin/php "$PLUGIN_DIR/openfalcon_listener.php" \
    </dev/null >/dev/null 2>&1 &

#postStart
