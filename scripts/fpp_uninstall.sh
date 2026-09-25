#!/bin/bash
# ShowPilot uninstall — undo what the plugin set up outside its own directory,
# except the operator's settings and token (kept for reinstalls). Safe to run
# more than once.

. "$(dirname "${BASH_SOURCE[0]}")/showpilot_env.sh"

stop_listener
stop_audio_daemon

remove_legacy_csp_origin

# Cooldown state (v0.14.0+) lives in FPP's config dir, outside the plugin
# directory, so FPP's own cleanup won't remove it. The legacy (<=0.13.x)
# file is removed too; it only mattered while that version was installed.
rm -f "${MEDIADIR}/config/showpilot-cooldown-active.json" "${MEDIADIR}/config/showpilot-cooldown-active.json.tmp"
rm -f "${MEDIADIR}/config/showpilot-cooldowns.json"

# Settings (config/plugin.showpilot) and the show token
# (plugindata/<repoName>/showToken) are kept on purpose so a reinstall picks
# up where it left off. Delete both by hand to remove every trace.

# The request-queue playlist the listener maintains.
rm -f "${MEDIADIR}/playlists/ShowPilot Queue.json"

if [ -p "$FIFO_PATH" ] && [ ! -L "$FIFO_PATH" ]; then
    rm -f "$FIFO_PATH"
fi

# Versions before 0.14.2 added NodeSource's apt repository. Remove it only if
# it is exactly the entry we wrote; the installed nodejs package stays.
NODESOURCE_LIST=/etc/apt/sources.list.d/nodesource.list
if [ -f "$NODESOURCE_LIST" ] && grep -qx 'deb \[signed-by=/etc/apt/keyrings/nodesource.gpg\] https://deb.nodesource.com/node_22.x nodistro main' "$NODESOURCE_LIST"; then
    rm -f "$NODESOURCE_LIST" /etc/apt/keyrings/nodesource.gpg
fi

# FPP 10+ unloads the plugin live; older releases need an fppd restart to
# drop the MultiSync .so and the registered commands.
setSetting restartFlag 1

#fpp_uninstall
