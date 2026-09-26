#!/bin/bash
# ShowPilot — shared setup for the lifecycle scripts. Sourced, never run.
#
# Every path is derived at runtime: FPP names the install directory after
# pluginInfo.json's repoName, which has changed before (fpp-data#209), and
# the media directory can be relocated.

: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common"   # MEDIADIR, LOGDIR, SRCDIR, setSetting

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_NAME="$(basename "$PLUGIN_DIR")"

# Fixed settings key (config/plugin.showpilot), deliberately NOT derived from
# PLUGIN_NAME: every existing install's saved settings live under it.
CONFIG_FILE="${MEDIADIR}/config/plugin.showpilot"
DATA_DIR="${MEDIADIR}/plugindata/${PLUGIN_NAME}"
PLUGIN_LOG="${LOGDIR}/plugin-${PLUGIN_NAME}.log"

LISTENER="${PLUGIN_DIR}/showpilot_listener.php"
AUDIO_DAEMON="${PLUGIN_DIR}/showpilot_audio.js"
FIFO_PATH="/tmp/SHOWPILOT_FIFO"
MIN_NODE_MAJOR=18

plugin_log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [lifecycle] $*" >> "$PLUGIN_LOG"
}

# Raw value of one key in the plugin config (may still be URL-encoded).
config_value() {
    sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*\"\{0,1\}\([^\"]*\)\"\{0,1\}[[:space:]]*\$/\1/p" \
        "$CONFIG_FILE" 2>/dev/null | head -n 1
}

# Hooks and install scripts run as root; the listener and audio daemon don't
# need to. setpriv execs in place, so pkill/pgrep patterns still match.
run_as_fpp=()
if [ "$(id -u)" = "0" ] && command -v setpriv >/dev/null 2>&1; then
    run_as_fpp=(setpriv --reuid=fpp --regid=fpp --init-groups)
fi

ensure_runtime_files() {
    if [ ! -d "${MEDIADIR}/plugindata" ]; then
        mkdir -p "${MEDIADIR}/plugindata"
        chown fpp:fpp "${MEDIADIR}/plugindata"
    fi
    mkdir -p "$DATA_DIR"
    chown fpp:fpp "$DATA_DIR"
    chmod 700 "$DATA_DIR"

    touch "$CONFIG_FILE" "$PLUGIN_LOG"
    chown fpp:fpp "$CONFIG_FILE" "$PLUGIN_LOG"
    chmod 660 "$CONFIG_FILE"

    # The FIFO may have been created by fppd (root) before the daemon, which
    # runs as fpp, gets to it. Never touch it through a symlink.
    if [ -p "$FIFO_PATH" ] && [ ! -L "$FIFO_PATH" ]; then
        chown fpp:fpp "$FIFO_PATH"
        chmod 660 "$FIFO_PATH"
    fi
}

node_ok() {
    command -v node >/dev/null 2>&1 || return 1
    local major
    major=$(node --version 2>/dev/null | sed 's/^v//; s/\..*//')
    [ "${major:-0}" -ge "$MIN_NODE_MAJOR" ] 2>/dev/null
}

# Installs Debian's nodejs package only. The daemon's one library (ws) is
# vendored in vendor/ws, so npm isn't needed — Debian's npm package alone
# pulls in hundreds of packages. Retries once after refreshing package lists,
# which are often stale on a fresh FPP image.
install_nodejs() {
    node_ok && return 0
    echo "Installing Node.js (nodejs only, no npm) from the Debian archive..."
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends nodejs \
        || { apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends nodejs; }
    node_ok
}

# Versions before 0.14.5 ran npm into node_modules/; ws now ships in vendor/.
remove_legacy_node_modules() {
    rm -rf "$PLUGIN_DIR/node_modules"
}

wait_for_exit() {
    local pattern="$1" ticks=0
    while pgrep -f "$pattern" >/dev/null 2>&1 && [ "$ticks" -lt 10 ]; do
        sleep 0.1
        ticks=$((ticks + 1))
    done
}

# Patterns are the scripts' full paths, so nothing unrelated matches.
stop_listener() {
    pkill -f "$LISTENER" 2>/dev/null
    wait_for_exit "$LISTENER"
}

stop_audio_daemon() {
    pkill -f "$AUDIO_DAEMON" 2>/dev/null
    wait_for_exit "$AUDIO_DAEMON"
    pkill -9 -f "$AUDIO_DAEMON" 2>/dev/null
    return 0
}

start_listener() {
    setsid "${run_as_fpp[@]}" php "$LISTENER" </dev/null >>"$PLUGIN_LOG" 2>&1 &
}

# The daemon listens on the LAN, so it only starts once the operator has
# pointed the plugin at a ShowPilot server (FPP guideline §14.13).
start_audio_daemon() {
    if [ -z "$(config_value serverUrl)" ]; then
        plugin_log "Audio daemon not started: no Server URL configured yet"
        return 0
    fi
    if ! node_ok; then
        plugin_log "Audio daemon not started: Node.js ${MIN_NODE_MAJOR}+ not found"
        return 1
    fi
    local port
    port=$(config_value audioDaemonPort)
    case "$port" in ''|*[!0-9]*) port=8090 ;; esac

    PORT="$port" MEDIA_ROOT="${MEDIADIR}/music" FPP_HOST="http://127.0.0.1" LOG_FILE="$PLUGIN_LOG" \
        setsid "${run_as_fpp[@]}" "$(command -v node)" --max-old-space-size=64 "$AUDIO_DAEMON" \
        </dev/null >>"$PLUGIN_LOG" 2>&1 &
}

# Versions before 0.14.2 added the ShowPilot server to FPP's connect-src CSP
# list and never removed it. The config page proxies every request, so the
# entry was never needed; take it back out.
remove_legacy_csp_origin() {
    local csp="${FPPDIR}/scripts/ManageApacheContentPolicy.sh" origin
    [ -x "$csp" ] && [ -f "$CONFIG_FILE" ] && command -v php >/dev/null 2>&1 || return 0
    origin=$(php -r '
        $s = @parse_ini_file($argv[1]);
        $u = rtrim((string)($s["serverUrl"] ?? ""), "/");
        if (preg_match("/%[0-9a-fA-F]{2}/", $u)) $u = urldecode($u);
        $p = parse_url($u);
        if (!empty($p["scheme"]) && !empty($p["host"]))
            echo $p["scheme"], "://", $p["host"], isset($p["port"]) ? ":" . $p["port"] : "";
    ' "$CONFIG_FILE" 2>/dev/null) || true
    [ -n "$origin" ] || return 0
    # Captured first: under pipefail, `show | grep -q` can report a match as
    # a failure when grep exits early and SIGPIPEs `show`.
    local listing
    listing=$("$csp" show 2>/dev/null) || true
    if grep -qF "\"$origin\"" <<<"$listing"; then
        (cd "$DATA_DIR" 2>/dev/null || cd /tmp; "$csp" remove connect-src "$origin") >/dev/null 2>&1 || true
        echo "Removed legacy CSP entry for $origin"
    fi
}
