#!/bin/bash
# ShowPilot upgrade — run by the Plugin Manager's Update button (instead of
# fpp_install.sh) after FPP has already pulled the new code.
#
# Update doesn't go through fppd's plugin load/unload the way Install does,
# so without the steps below fppd would keep running the old .so. On FPP 10+
# we hot-swap it via /api/fppd/plugin/<name>/{unload,load}; anywhere that
# isn't confirmed (older FPP, fppd down, build failure) we fall back to
# setSetting restartFlag 1.
#
# No `set -e`: every step has a fallback, and a failure partway should land
# on the restart-required path rather than abort.

. "$(dirname "${BASH_SOURCE[0]}")/showpilot_env.sh"

HOTRELOAD_OK=1

ensure_runtime_files
chown -R fpp:fpp "$PLUGIN_DIR"
chmod +x "$PLUGIN_DIR"/commands/*.php "$PLUGIN_DIR"/scripts/*.sh

fppd_plugin_call() {
    curl -s -m 5 -X POST "http://localhost/api/fppd/plugin/${PLUGIN_NAME}/$1" 2>/dev/null \
        | grep -q '"Status"[[:space:]]*:[[:space:]]*"OK"'
}

# ---- 1. Unload the running C++ plugin (FPP 10+ only).
if fppd_plugin_call unload; then
    echo "fppd confirmed plugin unload"
else
    echo "WARN: fppd hot-unload not confirmed (older FPP, or fppd not reachable) — will request a restart"
    HOTRELOAD_OK=0
fi

# ---- 2. Rebuild. `make clean` gives the new .so a fresh inode, which is what
# FPP's loader needs to treat it as new code.
rm -f "$PLUGIN_DIR/lib${PLUGIN_NAME}.so"
if [ -f "$PLUGIN_DIR/Makefile" ] && [ -d "$SRCDIR" ]; then
    echo "Rebuilding ShowPilot MultiSync plugin..."
    if (cd "$PLUGIN_DIR" && make clean && make "SRCDIR=${SRCDIR}") 2>&1; then
        echo "ShowPilot MultiSync plugin rebuilt successfully"
    else
        echo "WARN: C++ plugin rebuild failed — falling back to HTTP polling until a restart"
        HOTRELOAD_OK=0
    fi
else
    echo "WARN: FPP source not found at $SRCDIR — skipping C++ plugin rebuild"
    HOTRELOAD_OK=0
fi

# ---- 3. Load it back in, only if fppd confirmed the unload.
if [ "$HOTRELOAD_OK" = "1" ]; then
    if fppd_plugin_call load; then
        echo "fppd confirmed plugin load — new MultiSync code is live"
    else
        echo "WARN: fppd hot-load not confirmed — will request a restart"
        HOTRELOAD_OK=0
    fi
fi

# ---- 4. Node dependencies may have changed with the new code.
if node_ok; then
    install_node_modules || echo "WARN: npm install failed — WebSocket position sync disabled"
fi

remove_legacy_csp_origin

# ---- 5. The listener and audio daemon are plain background processes, not
# part of fppd's plugin lifecycle, so restart them directly.
"$PLUGIN_DIR/scripts/restart-daemon.sh" || { echo "WARN: audio daemon restart reported a problem"; HOTRELOAD_OK=0; }
stop_listener
start_listener

if [ "$HOTRELOAD_OK" = "1" ]; then
    echo "ShowPilot updated in place — no fppd restart needed"
else
    setSetting restartFlag 1
fi

#fpp_upgrade
