#!/bin/bash
# ShowPilot plugin install script
#
# Runs after FPP clones the repo on initial install, AND optionally when the
# user clicks "Run Install Script" or updates the plugin via FPP's plugin
# manager. Does NOT reliably run on every Update click — different FPP
# versions handle this differently.
#
# Strategy: keep this minimal and let FPP's normal restart flow handle the
# actual listener swap. We set restartFlag=1 to surface FPP's "Restart
# Required" banner; user clicks it; fppd cycles; postStop kills the listener;
# postStart spawns a fresh one with the new code. Same pattern Remote Falcon
# uses — proven to work reliably across FPP versions.

. ${FPPDIR}/scripts/common

PLUGIN_DIR="/home/fpp/media/plugins/showpilot"
CONFIG_FILE="/home/fpp/media/config/plugin.showpilot"

# Force-sync with origin/main, discarding any local changes.
# (Plugin code lives entirely in the repo — there shouldn't be any
#  local edits worth preserving. User config lives in
#  /home/fpp/media/config/plugin.showpilot, separate.)
if [ -d "$PLUGIN_DIR/.git" ]; then
    cd "$PLUGIN_DIR"
    git fetch origin 2>&1 || echo "WARN: git fetch failed (no internet?)"
    git reset --hard origin/main 2>&1 || echo "WARN: git reset failed"
fi

# Ensure correct ownership
chown -R fpp:fpp "$PLUGIN_DIR" 2>/dev/null

# Older FPP installs can leave plugin config owned by the listener user only.
# The web UI/API must also be able to update it when settings are changed.
touch "$CONFIG_FILE" 2>/dev/null
chown fpp:fpp "$CONFIG_FILE" 2>/dev/null
chmod 666 "$CONFIG_FILE" 2>/dev/null

# Make all command scripts and lifecycle scripts executable so FPP can run them.
# (git-tracked exec bit doesn't always survive every install path, so we do this
#  explicitly here to be safe.)
chmod +x "$PLUGIN_DIR/commands/"*.php 2>/dev/null
chmod +x "$PLUGIN_DIR/scripts/"*.sh 2>/dev/null

# ---- Node.js 18 installation ----
# Required for the ShowPilot audio daemon (showpilot_audio.js).
# ShowPilot Lite also requires Node 18 on the Pi, so this is consistent.
# If Node 18+ is already installed, this block is skipped entirely.
NODE_OK=0
if command -v node >/dev/null 2>&1; then
    NODE_MAJOR=$(node --version 2>/dev/null | sed 's/v//' | cut -d. -f1)
    if [ "${NODE_MAJOR:-0}" -ge 18 ]; then
        NODE_OK=1
        echo "Node.js $(node --version) already installed — skipping install"
    fi
fi

if [ "$NODE_OK" = "0" ]; then
    echo "Installing Node.js 18..."
    curl -fsSL https://deb.nodesource.com/setup_18.x | bash - 2>&1
    apt-get install -y nodejs 2>&1
    if command -v node >/dev/null 2>&1; then
        echo "Node.js $(node --version) installed successfully"
    else
        echo "WARN: Node.js installation failed — audio daemon will not start"
        echo "WARN: Install manually: curl -fsSL https://deb.nodesource.com/setup_18.x | bash - && apt-get install -y nodejs"
    fi
fi

# Surface FPP's "Restart Required" banner in the plugin manager UI.
# After the user clicks Restart, fppd cycles, postStop kills the listener,
# postStart spawns a fresh one with the new code.
setSetting restartFlag 1

#fpp_install
