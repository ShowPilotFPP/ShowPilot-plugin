#!/bin/bash
# ShowPilot install — run by FPP after cloning the plugin, and again by
# "Reinstall All Plugins". Safe to run repeatedly.
#
# Fail fast, except for the steps explicitly marked best-effort below.
set -e
set -o pipefail

. "$(dirname "${BASH_SOURCE[0]}")/showpilot_env.sh"

ensure_runtime_files
chown -R fpp:fpp "$PLUGIN_DIR"
chmod +x "$PLUGIN_DIR"/commands/*.php "$PLUGIN_DIR"/scripts/*.sh

# ---- Node.js (audio daemon) ----
# Debian's own nodejs package. FPP 10+ also installs it from pluginInfo.json's
# dependencies; this covers older releases. Best-effort: without Node the
# listener still works, only the audio daemon is skipped.
if ! node_ok; then
    echo "Installing Node.js from the Debian archive..."
    apt-get install -y nodejs npm || echo "WARN: Node.js install failed — the audio daemon will not start"
fi
if node_ok; then
    install_node_modules || echo "WARN: npm install failed — WebSocket position sync disabled"
else
    echo "WARN: Node.js ${MIN_NODE_MAJOR}+ not available — the audio daemon will not start"
fi

# ---- C++ MultiSync plugin ----
# fppd dlopen()s lib<install-dir-name>.so (see Makefile). Best-effort: without
# it the audio daemon falls back to polling fppd over HTTP.
rm -f "$PLUGIN_DIR/libfpp-showpilot-sync.so" "$PLUGIN_DIR/libshowpilot.so" "$PLUGIN_DIR/lib${PLUGIN_NAME}.so"
if [ -f "$PLUGIN_DIR/Makefile" ] && [ -d "$SRCDIR" ]; then
    echo "Building ShowPilot MultiSync plugin..."
    if (cd "$PLUGIN_DIR" && make clean && make "SRCDIR=${SRCDIR}"); then
        echo "ShowPilot MultiSync plugin built successfully"
    else
        echo "WARN: C++ plugin build failed — falling back to HTTP polling"
    fi
else
    echo "WARN: FPP source not found at $SRCDIR — skipping C++ plugin build"
fi

remove_legacy_csp_origin

# FPP 10+ loads the plugin live after install; older releases in
# pluginInfo.json's versions[] range need an fppd restart to pick it up.
setSetting restartFlag 1

#fpp_install
