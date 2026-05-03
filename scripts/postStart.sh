#!/bin/bash
# ShowPilot plugin postStart — runs every time fppd starts.
#
# This script is the single source of truth for getting the listener into a
# correct state. It does THREE things on every fppd cycle:
#
#   1. Self-heals file permissions. Plugin files arrive from `git clone` /
#      `git pull` without executable bits in some environments (Windows clones
#      uploaded later, manual extracts, restored backups). Without exec bits,
#      fppd cannot exec our command-button scripts ("Restart Listener", etc.)
#      and they fail silently. We chmod every cycle so this can never bite the
#      user, regardless of how the files arrived.
#
#   2. Kills any existing listener. Even though postStop should handle this,
#      there are edge cases where a stale listener survives (postStop got
#      skipped, kill signal lost, fppd hard-restarted). pkill catches them.
#
#   3. Spawns one fresh listener with the current code on disk.
#
# This sequence guarantees: after fppd restart, exactly one v-current listener
# is running, all command buttons work, and no manual intervention is needed.

PLUGIN_DIR="/home/fpp/media/plugins/showpilot"
LOG_DIR="/home/fpp/media/logs"
CONFIG_FILE="/home/fpp/media/config/plugin.showpilot"
mkdir -p "$LOG_DIR"

# 1. Self-heal permissions — make all command and script files executable.
# Failures are silenced because we may not own every file (rare but possible),
# and the postStart should not block on permission edge cases.
chmod +x "$PLUGIN_DIR/commands/"*.php 2>/dev/null
chmod +x "$PLUGIN_DIR/scripts/"*.sh 2>/dev/null
chmod +x "$PLUGIN_DIR/showpilot_listener.php" 2>/dev/null
chmod +x "$PLUGIN_DIR/listener_status.php" 2>/dev/null
chmod +x "$PLUGIN_DIR/extract_audio.php" 2>/dev/null

# Keep the plugin config writable by FPP's web/API process across older
# installs, restores, and listener-created first-run files.
touch "$CONFIG_FILE" 2>/dev/null
chown fpp:fpp "$CONFIG_FILE" 2>/dev/null
chmod 666 "$CONFIG_FILE" 2>/dev/null

# 2. Kill any existing listener. Idempotent — exit 0 if killed, 1 if none
# found. Both fine here.
pkill -f "php $PLUGIN_DIR/showpilot_listener.php" 2>/dev/null

# Brief pause so SIGTERM takes effect cleanly before we spawn the replacement.
sleep 1

# 3. Spawn the listener detached. We invoke PHP explicitly with /usr/bin/php
# rather than relying on the shebang line — this means the listener works
# even if its exec bit somehow ends up unset.
# setsid prevents fppd from holding onto it as a child process; redirecting
# stdio to /dev/null ensures the listener doesn't inherit any descriptors.
setsid /usr/bin/php "$PLUGIN_DIR/showpilot_listener.php" \
    </dev/null >/dev/null 2>&1 &

#postStart
