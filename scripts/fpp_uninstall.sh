#!/bin/bash
# ShowPilot plugin uninstall script — runs when the user removes the plugin
# via FPP's Plugin Manager.

. ${FPPDIR}/scripts/common

# Derived, not hardcoded — see fpp_install.sh for why (fpp-data#209 fallout).
# FPP invokes this by its own full path (scripts/uninstall_plugin), so this
# resolves correctly regardless of what the install directory is named.
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Stop the running listener/audio daemon so nothing keeps running against a
# plugin directory that's about to be deleted.
pkill -f "php $PLUGIN_DIR/showpilot_listener.php" 2>/dev/null || true
pkill -f "node $PLUGIN_DIR/showpilot_audio.js" 2>/dev/null || true

# Remove all plugin-generated PHP files. The repo itself is deleted by
# FPP's plugin manager after this script runs — we only need to clean up
# files that might otherwise persist.
rm -f "$PLUGIN_DIR/showpilot_listener.php"
rm -f "$PLUGIN_DIR/showpilot_proxy.php"
rm -f "$PLUGIN_DIR/extract_audio.php"
rm -f "$PLUGIN_DIR/listener_status.php"

# Cooldown state (v0.14.0+) lives in FPP's config dir, outside the plugin
# directory, so FPP's own cleanup won't remove it. The legacy (<=0.13.x)
# file is removed too; it only mattered while that version was installed.
CFG_DIR="${MEDIADIR:-/home/fpp/media}/config"
rm -f "$CFG_DIR/showpilot-cooldown-active.json" "$CFG_DIR/showpilot-cooldown-active.json.tmp"
rm -f "$CFG_DIR/showpilot-cooldowns.json"

# Surface FPP's "Restart Required" banner so fppd cycles and releases any
# handles it was holding on the plugin (listener process, MultiSync .so) —
# fpp_install.sh already does this on install; uninstall needs it too.
#
# Keep this unconditional for the same reason as fpp_install.sh: versions[]
# spans FPP releases both before and after hot-load/unload support existed,
# on a single branch/sha, so uninstall must fall back to a full restart for
# the older ones in that range regardless of whether this specific FPP host
# could have hot-unloaded it.
setSetting restartFlag 1

#fpp_uninstall
