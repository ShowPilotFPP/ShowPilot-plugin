#!/bin/bash
# OpenFalcon plugin install script
#
# Runs after FPP clones the repo, AND any time the user clicks
# "Run Install Script" or updates the plugin via FPP's plugin manager.
#
# We force-sync with origin/main here because FPP's built-in updater
# is unreliable about actually pulling fresh code. Doing it ourselves
# guarantees an Update click is always honored.

. ${FPPDIR}/scripts/common

PLUGIN_DIR="/home/fpp/media/plugins/openfalcon"

# Force-sync with origin/main, discarding any local changes.
# (Plugin code lives entirely in the repo — there shouldn't be any
#  local edits worth preserving. User config lives in
#  /home/fpp/media/config/plugin.openfalcon, separate.)
if [ -d "$PLUGIN_DIR/.git" ]; then
    cd "$PLUGIN_DIR"
    git fetch origin 2>&1 || echo "WARN: git fetch failed (no internet?)"
    git reset --hard origin/main 2>&1 || echo "WARN: git reset failed"
fi

# Ensure correct ownership
chown -R fpp:fpp "$PLUGIN_DIR" 2>/dev/null

# Make all command scripts and lifecycle scripts executable so FPP can run them.
# (git-tracked exec bit doesn't always survive every install path, so we do this
#  explicitly here to be safe.)
chmod +x "$PLUGIN_DIR/commands/"*.php 2>/dev/null
chmod +x "$PLUGIN_DIR/scripts/"*.sh 2>/dev/null

# Restart fppd after install so it picks up our commands and starts the listener
setSetting restartFlag 1
