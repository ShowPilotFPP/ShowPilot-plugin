# ShowPilot Plugin Primer

This document gives you (Claude, in a future conversation) the context you need to help work on the ShowPilot FPP plugin effectively. Read this before any other project files.

---

## What the ShowPilot Plugin is

The ShowPilot Plugin is the FPP-side companion to [ShowPilot](https://github.com/ShowPilotFPP/ShowPilot). It runs inside Falcon Player (FPP) on the show host (Pi or BeagleBone Black) and bridges FPP playback state to the ShowPilot server.

What it does:
- **Listener** (`showpilot_listener.php`): polls FPP's `/api/status` and pushes playback state to ShowPilot via `POST /api/plugin/state`. Reports current sequence, position, next sequence, and whether FPP is playing.
- **Audio daemon** (`showpilot_audio.js`): a long-running Node process that listens to FPP's FIFO (`/tmp/SHOWPILOT_FIFO`) for `MediaSyncStart/Stop/Packet` events, then broadcasts `position` and `syncPoint` WebSocket events to the ShowPilot LXC for viewer audio sync.
- **Scheduler commands** (`commands/`): PHP scripts registered with FPP's event scheduler so operators can switch ShowPilot modes, toggle viewer control on/off, and more at specific playlist positions — without touching a web UI.
- **Admin UI** (`showpilot_ui.html`): FPP-embedded config page for setting the ShowPilot server URL and show token.
- **Native plugin** (`src/FPPShowPilotSync.cpp` → `lib<plugin-dir>.so`, loaded by fppd): writes MultiSync media events to the FIFO for the audio daemon, and (v0.14.0+) enforces sequence cooldowns through FPP's `query_next` playlist hook — see "Cooldown enforcement" below.

---

## Architecture

```
FPP playback
    ↓ status poll (every ~1s)
showpilot_listener.php
    → POST /api/plugin/state   (ShowPilot LXC)
    → POST /api/plugin/playing (on sequence change)

FPP FIFO (/tmp/SHOWPILOT_FIFO)
    ↓ MediaSyncStart/Stop/Packet events
showpilot_audio.js  (port 8090, WebSocket server)
    → ws://[showpilot-lxc]:8090  received by audio-position-relay.js
    → relay emits fppPosition / fppSyncPoint via Socket.io to viewer browsers
```

**Key files:**
```
showpilot-plugin/                   (FPP plugin dir — named from pluginInfo.json's repoName,
                                      e.g. /home/fpp/media/plugins/showpilot-plugin/. Scripts
                                      below derive this at runtime rather than hardcoding it —
                                      see the v0.13.74 changelog entry for why that matters.)
├── showpilot_listener.php          — main polling loop
├── showpilot_audio.js              — audio sync daemon
├── showpilot_ui.html               — admin config UI embedded in FPP
├── version.php                     — single version source of truth ($PLUGIN_VERSION)
├── pluginInfo.json                 — FPP plugin manifest (name, version, homeURL)
├── callbacks.sh                    — FPP lifecycle hooks
├── src/FPPShowPilotSync.cpp        — native fppd plugin: MultiSync FIFO writer + cooldown playlist hook
├── scripts/
│   ├── postStart.sh                — starts audio daemon after FPP starts
│   ├── preStop.sh                  — stops daemon cleanly before FPP stops
│   ├── restart-daemon.sh           — restarts daemon without a full fppd cycle
│   ├── fpp_install.sh              — fresh install only; always requests a restart (v0.13.73+)
│   ├── fpp_uninstall.sh            — runs on removal; always requests a restart
│   └── fpp_upgrade.sh              — runs on "Update"; tries FPP 10+ hot-reload first, only falls back to a restart if that isn't confirmed (v0.13.73+)
└── commands/
    ├── descriptions.json           — FPP scheduler command registry
    ├── viewer_control_on.php       — restore viewer control (ON)
    ├── viewer_control_off.php      — disable viewer control (OFF)
    ├── set_mode_voting.php         — switch to Voting mode
    ├── set_mode_jukebox.php        — switch to Jukebox mode
    ├── set_mode_race.php           — switch to Race mode (v0.13.64+)
    ├── interrupt_on.php            — enable interrupt schedule
    ├── interrupt_off.php           — disable interrupt schedule
    ├── restart_listener.php        — restart the PHP listener
    └── stop_listener.php           — stop the PHP listener
```

---

## Versioning

**Single source of truth:** `version.php` — the `$PLUGIN_VERSION` string. Both `showpilot_listener.php` and `showpilot_ui.html` include this file. **Edit only `version.php` when bumping.** Do not search-and-replace version strings in other files.

`pluginInfo.json` has its own version tracking for FPP's plugin manager; keep it in sync with `version.php`.

---

## Scheduler commands

All commands in `commands/` follow the same pattern:
1. Read plugin config from FPP's config file (`plugin.showpilot` ini file)
2. Extract `serverUrl` and `showToken`
3. POST to the ShowPilot API endpoint with a JSON payload
4. Exit silently (FPP scheduler doesn't process output)

The `descriptions.json` file is FPP's registry — every command that should appear in FPP's Event Scheduler UI must have an entry here. Format:
```json
{ "name": "ShowPilot - Human Readable Name", "script": "filename.php", "args": [] }
```

**Adding a new command:**
1. Create the `.php` file, using an existing command as a template
2. Add an entry to `descriptions.json`
3. Bump `version.php`
4. Package tarball

---

## Deployment

The plugin is installed via FPP's Plugin Manager (paste the GitHub URL). Updates are applied via `git pull` on the FPP host inside the plugin directory, then a listener restart. The install directory's actual name is whatever `pluginInfo.json`'s `repoName` was at install time (`showpilot-plugin` as of v0.13.74) — substitute your host's real path below; every script in this plugin (as of v0.13.74) derives it at runtime instead of assuming a fixed name:

```bash
# After updating plugin files:
cd /home/fpp/media/plugins/showpilot-plugin   # or whatever FPP actually named it
git pull origin main

# If only listener/command PHP changed:
# (FPP picks up PHP changes on next poll cycle — no explicit restart needed
#  unless you want to be sure)

# If showpilot_audio.js changed:
sudo ./scripts/restart-daemon.sh
```

**Packaging a tarball for ShipPilot:**
```bash
tar --exclude='showpilot-plugin/.git' \
    -czf /mnt/user-data/outputs/showpilot-plugin-vX.Y.Z.tar.gz showpilot-plugin/
```

The tarball must include `.release.json` at the root:
```json
{
  "repo": "showpilot-plugin",
  "version": "0.X.Y",
  "commit_message": "vX.Y.Z — description",
  "tag": "vX.Y.Z"
}
```

---

## Audio daemon details

`showpilot_audio.js` is a dependency-free Node.js process. Key behaviors:

- Writes PID to `/tmp/showpilot-audio.pid` on startup; cleans it on exit.
- Broadcasts `position` events every ~500ms and `syncPoint` events every ~1s.
- `syncPoint` suppression windows (to avoid false snaps on song change): `MediaSyncStart` → 1000ms; `MediaSyncPacket` song-change → 800ms; broadcast interval gate → 1000ms; initial forced syncPoint → 1000ms after first start.
- HTTP poll endpoint (`GET /status`) for health checks — must NOT update `lastSyncPointAt` (only the FIFO handler controls syncPoint suppression).
- The daemon restarts automatically after an FPP restart via `postStart.sh`. After a plugin-only update (no fppd restart), run `restart-daemon.sh` manually.

---

## Cooldown enforcement (v0.14.0+)

**Rule: the plugin never edits the operator's FPP playlist files.** Versions up to 0.13.x removed a cooled sequence from the playlist JSON on disk and re-inserted it later from a snapshot. That left playlists missing entries if FPP rebooted, the listener died, or the plugin was removed mid-cooldown, and it could clash with the operator's own edits. It was flagged in an external security/standards review and replaced in 0.14.0.

How it works now:

1. ShowPilot's `/api/plugin/state` response carries `playlistPatches` (`{sequenceName, enabled:false, reenableAt}` while cooled, `enabled:true` when cleared). The server side is unchanged.
2. `showpilot_listener.php` (`recordCooldownPatches()`) keeps an in-memory map and writes `<media>/config/showpilot-cooldown-active.json` atomically (temp file + rename, mode 0644 so fppd can read it whatever user it runs as): `{"cooldowns": {"Name": <until epoch secs>}, "exclude": [<remotePlaylist>, "ShowPilot Queue"]}`. Expired entries are pruned every loop.
3. The native plugin overrides `playlistCallback()` and acts only on `action == "query_next"`, which FPP calls synchronously when a playlist item finishes, before advancing. It acts only on the **top-level** playlist (callback name must equal `Player::INSTANCE.GetPlaylistName()`, so inserted playlists such as ShowPilot's request queue are ignored) and skips anything in `exclude`.
4. It reads the live playlist order via `Player::INSTANCE.GetConfig()` (reflects shuffle), finds the next main-playlist item, and if that item is cooled, walks forward to the first non-cooled item.
5. The jump runs on a **worker thread**, not inside the callback. The callback runs on fppd's playlist thread holding the playlist mutex mid-transition; FPP 10 defers a re-entrant `Play()` safely but FPP 8.x/9.x would run it inline and corrupt the transition. The worker blocks on that same mutex (`Player::GetInfo()`), confirms FPP is actually on the cooled item (`Player::GetPosition()` == expected, same playlist name), then calls `Player::INSTANCE.StartPlaylist(name, repeat, targetPos)`. With the same file, same repeat and `position >= 0`, FPP takes its "jump to index in the already-running playlist" branch in `Playlist::Play` — no reload, shuffle order and schedule entry preserved. Verified present in FPP 8.5, 9.4 and 10.x source.

Deliberate limits (do not "fix" without re-reading FPP's `Playlist.cpp`):
- The cooled item has technically been started by FPP when the jump lands; in live testing the cut happens before any frame or audio is perceptible. A true zero-start skip needs an FPP change (e.g. letting `query_next` return a new next position); worth proposing upstream.
- Items at the end of the main playlist are not skipped across the loop boundary — jumping there would bypass FPP's loop/lead-out accounting.
- If every remaining item is cooled, nothing is skipped, so the show never stalls.
- Lead-in and lead-out items are never touched; lead-in → first main item is handled.
- If the native `.so` isn't built (e.g. FPP source missing at `/opt/fpp/src`), cooldowns simply don't affect the scheduled playlist. fppd's log shows `ShowPilot: cooldown playlist hook active` when it is loaded.

Migration: on listener startup, `migrateLegacyCooldowns()` reads the legacy `showpilot-cooldowns.json`, restores any entries 0.13.x removed from playlists (using its snapshots), carries still-active cooldowns into the new file, then deletes the legacy file. This is the only place the plugin writes a playlist file, and only to undo the old behavior. `fpp_uninstall.sh` removes both state files.

Manual test: stop the listener, write `{"cooldowns":{"<sequence name without .fseq>":<epoch secs>},"exclude":[]}` to the active file with `sudo tee` (the file is root-owned), and watch fppd's log. The listener rewrites the file from memory when it restarts, which wipes a hand-written entry.

Log lines to look for: `[cooldown] ... in cooldown until ...` (listener log) and `ShowPilot: next item ... is in cooldown; will continue at ...` / `ShowPilot: skipped cooled ...` (fppd log, VB_PLUGIN).

---

## Race mode (v0.13.63+)

ShowPilot v0.33.155+ introduced Race mode — a tap-to-win competitive viewer interaction. The plugin's role:

- `/api/plugin/state` response includes `raceWinner` when ShowPilot decides a winner.
- The listener checks `raceWinner`; if set and `race_interrupt_winner` is enabled, it applies `effectiveInterrupt` to immediately queue the winning sequence.
- `set_mode_race.php` (v0.13.64+) is the scheduler command to activate race mode at a specific playlist position.

---

## FPP plugin-manager compatibility (v0.13.66+)

FPP's 10.x-beta Plugin Manager (July 2026) resolves each plugin's "Open" button URL by statically scanning `content_menu.inc`/`menu.inc` for a literal, quoted `page=....php` value, rather than executing the file the way FPP's nav sidebar does. Two consequences to keep in mind for any future menu link changes:

- **`nopage=1` must always precede `page=...` in the href.** The scanner's regex extracts `page=` by substring, not real query parsing, and `"nopage=1"` contains the substring `"page=1"`. If it lands after the real `page=`, the scanner grabs `"1"` instead and the Open button 404s. Always write `...&nopage=1&page=...`.
- **The page value must be a literal, static `.php` filename that exists in the plugin dir** — not a variable or a computed URL. If a future menu entry needs to point somewhere dynamic (a different host/port, etc.), route it through a small static `.php` redirect file the scanner can find, and do the dynamic computation inside that file at request time. `ShowPilot-Lite`'s `open.php` (added in Lite v0.5.47) is the reference pattern for this.

If FPP's scanner logic changes again, re-check `www/api/controllers/plugin.php` in `FalconChristmas/fpp` (functions `_PluginGetBestPageUrl`, `_PluginScanMenuPagesRaw`, `_PluginExtractPageFromHtml`, `_PluginExtractPageFromRaw`) before assuming a fix still holds.

---

## Working style

Same as the other ShowPilot repos:

- **Don't fabricate.** Look at the code before claiming behavior.
- **Surgical edits.** New commands follow existing command patterns exactly.
- **Version bump in `version.php` only.** No other file should hardcode the version.
- **Sanity-check PHP before packaging:** `php -l commands/your_new_command.php`
- **Comments explain WHY.** Especially in `showpilot_audio.js` — the suppression windows and the HTTP poll restriction exist for specific reasons and are easy to accidentally "clean up" away.

---

## Recent version history

| Version | Change |
|---------|--------|
| 0.13.37 | Reduce syncPoint suppression timings: first syncPoint at ~3s instead of ~4s. |
| 0.13.38 | Further reduce: first syncPoint at ~2s. |
| 0.13.39 | PID file written on startup; `restart-daemon.sh` helper added. |
| 0.13.40 | Cooldown suppression: handle `playlistPatches` from ShowPilot `/state`, disable/re-enable sequences in FPP playlist JSON. (Superseded in 0.14.0 — playlists are no longer edited.) |
| 0.13.41 | Fix PHP 8 crash in `applyPlaylistPatches`: stdClass objects require `->` not `[]`. |
| 0.13.63 | Race mode: `raceWinner` field handling; `effectiveInterrupt` for race winner playback. |
| 0.13.64 | `set_mode_race.php` scheduler command. Activates Race mode via `POST /api/plugin/viewer-mode`. |
| 0.13.66 | Fix "Open" button in FPP's new 10.x-beta (July 2026) Plugin Manager, which linked to a broken page (404: `requesting a page that doesn't exist: showpilot/1`). Cause: FPP's plugin-manager scanner extracts a plugin's page URL from `content_menu.inc` via a substring regex, not real query parsing — our href had `page=showpilot_ui.html&nopage=1`, and `"nopage=1"` itself contains the substring `"page=1"`, so the scanner's greedy match grabbed `"1"` as the page instead of `showpilot_ui.html`. Fix: reorder to `nopage=1&page=showpilot_ui.html` (nopage before page). Verified against FPP's actual scanner source (`www/api/controllers/plugin.php`, `FalconChristmas/fpp` master) before shipping. The same underlying bug hit `ShowPilot-Lite`'s `menu.inc`, fixed there in Lite v0.5.47 — see that repo's primer for the fuller writeup (Lite's fix also needed a new static redirect page since its Open target is an external, dynamically-computed URL). |
| 0.13.67 | FPP 10.0-beta compatibility audit (source-level, not live-tested): MultiSyncPlugin ABI, build system paths, and all REST endpoints used by the plugin confirmed unchanged. `pluginInfo.json` split the open-ended "9.0+" entry into 9.0–9.99 and an explicit 10.0+ entry. |
| 0.13.68 | `fpp_install.sh` was pinning Node.js 18 (EOL April 2025) via NodeSource; caught via the deprecation banner in a live FPP 10.0-beta install-log review. Node 20 is also now EOL (April 2026), so bumped the floor to Node 22 (Maintenance LTS through April 2027) rather than just chasing the previous version. |
| 0.13.69 | Added `icon.png` (256x256, repo root) plus `iconURL` in `pluginInfo.json` for FPP 10.x-beta's new Plugin Manager thumbnail grid. Reconstructed from the ShowPilot admin webapp's `svg.app-brand-mark` (`--logo-primary: #f59e0b`, `--logo-accent: #ef4444`, `--logo-dim: #f59e0b @ 45%`) — rendered directly from the rect geometry rather than from a rasterized screenshot, so it's crisp at both 128 and 256px. |
| 0.13.71 | FPP 10 compatibility remediation, addressing the automated review posted to `FalconChristmas/fpp-data#209`: **(1)** `postStart.sh` no longer runs `make` on every fppd startup — the C++ MultiSync plugin is already built by `fpp_install.sh` at install/upgrade time, so a missing `libshowpilot.so` now just logs a WARN telling the user to re-run the Install Script, instead of rebuilding synchronously and delaying boot. **(2)** FIFO permissions tightened from `0666` (world-writable) to `0660` in both `src/FPPShowPilotSync.cpp` (the writer, running inside fppd) and `showpilot_audio.js` (the reader) — they run as the same user, so group-level access is sufficient and nothing else on the host needs to touch `/tmp/SHOWPILOT_FIFO`. **(3)** `fpp_install.sh` gained `set -e` / `set -o pipefail`; the handful of steps that are meant to fail soft (Node/apt install, `npm install ws`, the C++ build) are now explicitly guarded with `\|\| true` or a WARN echo, so a genuinely unexpected failure elsewhere now stops the script instead of it silently reporting success in a broken state. **(4)** `fpp_uninstall.sh` now sources FPP's `common` script, stops the listener/audio daemon, and calls `setSetting restartFlag 1` — previously only `fpp_install.sh` requested a restart, so fppd could keep holding onto a listener process/`.so` handle from a just-removed plugin. **(5)** `pluginInfo.json`'s `repoName` corrected from `showpilot` to `ShowPilot-plugin` to match the actual GitHub repo name. **(6)** `postStart.sh`'s flat `sleep 0.5` (old-PID teardown) and `sleep 1` (post-`pkill`) replaced with `wait_for_pid_exit`/`wait_for_pattern_exit` polling helpers that return as soon as the process is actually gone, capped at the same 0.5s/1s budgets. **(7)** `showpilot_listener.php` now exits with a 403 immediately if invoked outside the CLI SAPI (before any FPP includes run), so a direct web request can't tie up a PHP-FPM/mod_php worker in the daemon's `while(true)` polling loop — verified every existing call site (`postStart.sh`, `restart_listener.php`, `deploy.sh`) already invokes it via `php`/`setsid`, not HTTP. **(8)** Declared `minMemoryMB: 256` / `minCpuCores: 1` in `pluginInfo.json` per the review's optional recommendation, for FPP 10.x's Plugin Manager resource hints. |
| 0.13.72 | Second pass on the same `fpp-data#209` review, after re-running it post-push turned up one real miss and closed out three items that were either already fixed or are inherent to the plugin's supported version range: **(1) Blocker, actually fixed:** `0.13.71` only tightened the FIFO's permissions — missed that `plugin.showpilot` (holds the ShowPilot server URL + show token) was *also* being chmod'd `0666` in four places: `showpilot_config.php` (both write paths — the per-key endpoint and the raw-editor endpoint), `showpilot_listener.php`, `fpp_install.sh`, and `postStart.sh`. All four now chmod `0660`; ownership is already `fpp:fpp` via the existing `chown` calls, and the CLI listener, the web-invoked `showpilot_config.php`, and fppd itself all run as `fpp` on every FPP image we support, so group access covers every legitimate writer. **(2) Best practice, no code change — documented instead:** the reviewer flagged that `pluginInfo.json`'s single `versions[]` entry per major spans FPP releases from before plugin hot-load existed through FPP 10+, so `restartFlag` must stay unconditional rather than being skipped on hot-load-capable hosts. That was already true (`setSetting restartFlag 1` in both `fpp_install.sh` and `fpp_uninstall.sh` runs unconditionally) — added comments at both call sites spelling out why, so a future edit doesn't accidentally make it conditional. **(3) Best practice, false positive — no code change:** flagged `postStart.sh`'s `sleep 0.1` as "blocks startup for that long on every run." That line is inside the `wait_for_pid_exit`/`wait_for_pattern_exit` poll loops added in `0.13.71` specifically to *replace* flat sleeps — it only runs while the loop condition (process still alive) holds, capped at 0.5s/1s, and returns immediately once the process is gone. The reviewer's static scan matches on the literal `sleep` token without seeing the surrounding loop condition; added inline comments at each `sleep 0.1` call to make the bounded-poll intent unmistakable to both humans and future automated passes. **(4) Best practice, already fixed — no code change:** re-flagged `showpilot_listener.php`'s `while (true) {` (now at line 814, shifted down by the `0.13.71` CLI guard) as tying up a web worker "if reachable directly." It isn't — the CLI-SAPI guard added in `0.13.71` sits *before* the loop and calls `exit()` with a 403 for any non-CLI invocation, and every real call site (`postStart.sh`, `restart_listener.php`, `deploy.sh`) already invokes it via `php`/`setsid`. The reviewer's message explicitly hedges ("worth a human look to confirm reachability") rather than asserting it found a live issue — this entry is that confirmation for the next person who sees this line flagged again. |
| 0.13.73 | Third pass on `fpp-data#209`: a maintainer commented directly (not the automated bot) that `0.13.72`'s "restartFlag must stay unconditional because versions[] spans pre/post hot-load FPP" reasoning was incomplete — FPP 10 does not require the restart *when a plugin implements hot-reload properly*, and most plugins avoid the always-restart requirement with a separate FPP-10-only branch. Rather than branch-split (real ongoing maintenance cost — two install paths to keep in sync forever), read FPP's actual `master`-branch source (`src/Plugins.cpp`, `www/api/controllers/plugin.php`) instead of guessing, and found: **(a)** FPP 10's `PluginManager::loadPlugin()`/`unloadPlugin()` can genuinely hot-swap a compiled `.so` at runtime, reachable at `POST http://localhost/api/fppd/plugin/<name>/<load\|unload>` — `loadSHLIBPlugin()` even has a generation-link trick specifically to defeat `dlopen()`'s "already loaded by this path" cache when a rebuilt file has a new inode, which is exactly what our `make clean && make` produces on every rebuild. Our `ShowPilotPlugin` C++ class already cleans up correctly for this (`MultiSync::INSTANCE.removeMultiSyncPlugin()` in its destructor). **(b)** Critically, `UpgradePlugin()` — the function behind the exact git-pull-then-rerun-install-script flow every ShipPilot release goes through — never calls that load/unload lifecycle at all. It's only invoked automatically around a fresh Install and a full Uninstall. So on FPP 10 *and* every older version, a routine version-bump update was leaving the OLD `.so` running in fppd's memory regardless of `restartFlag`, until an actual restart happened — `restartFlag` was the only thing making that visible to the user instead of silently stale. Added `scripts/fpp_upgrade.sh`, which FPP's own `scripts/upgrade_plugin` wrapper runs in preference to `fpp_install.sh` on every Update (it already did the git pull before calling us): it calls the `unload` endpoint, rebuilds the C++ plugin, calls `load`, restarts the Node audio daemon via the existing `restart-daemon.sh` and the PHP listener via the existing `commands/restart_listener.php` (neither is touched by the fppd load/unload calls — they're plain background processes started by `postStart.sh`, not something `PluginManager` dlopens), and only falls back to `setSetting restartFlag 1` if the `unload`/`load` calls don't both confirm `"Status":"OK"` or any step reports a problem — which is exactly what happens automatically on FPP 9 and older, where that endpoint doesn't exist. `fpp_install.sh` (fresh installs) and `fpp_uninstall.sh` are unchanged and still request a restart unconditionally: a fresh install has no running listener/daemon to preserve continuity for, and FPP's own uninstall flow already calls `unload` before deleting files. **This has only been verified by reading FPP's source, not by testing against a live fppd** — the `curl` calls to `/api/fppd/plugin/showpilot/unload` and `.../load` and their expected `{"Status":"OK"}` response need to be confirmed against real FPP 10 hardware before trusting the hot-reload path over the restart fallback in production. |
| 0.13.74 | **Regression fix, found on a live host.** `0.13.72`'s `repoName` change (`showpilot` → `ShowPilot-plugin`, made to satisfy `fpp-data#209`'s "repository name mismatch" best-practice item) broke fresh installs on FPP 10: FPP's `InstallPluginFromInfo()` reads `pluginInfo.json`'s `repoName` **verbatim** (only `escapeshellcmd()`'d, never case-normalized) and passes it straight to `scripts/install_plugin` as the literal on-disk clone directory name — confirmed by reading `www/api/controllers/plugin.php` on `FalconChristmas/fpp` master. Every script in this plugin had `PLUGIN_DIR="/home/fpp/media/plugins/showpilot"` hardcoded (lowercase), so once FPP cloned into `ShowPilot-plugin` instead, `fpp_install.sh`'s C++ build step, the `npm install ws` step, and fppd's own load attempt all failed against a path that no longer existed, and the "Open" menu link 404'd for the same reason. Real fix, not just a revert: every script (`fpp_install.sh`, `fpp_uninstall.sh`, `fpp_upgrade.sh`, `postStart.sh`, `postStop.sh`, `restart-daemon.sh`), `commands/restart_listener.php`, and the `Makefile`'s `.so` output target now **derive their own install directory at runtime instead of hardcoding it** (`PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"` in bash; `dirname(__DIR__)` in PHP; `PLUGIN_NAME := $(notdir $(CURDIR))` in the Makefile) — every one of these is always invoked by its own full, real path by FPP itself (`scripts/functions`, `scripts/install_plugin`/`upgrade_plugin`/`uninstall_plugin`), so this resolves correctly no matter what the directory is actually named, and can never drift from `repoName` again regardless of future renames. `pluginInfo.json`'s `repoName` is now `showpilot-plugin` (lowercase, hyphenated) — keeps "plugin" in the name to stay unambiguous alongside `ShowPilot-Lite`, while avoiding the mixed-case form that caused this. **Left deliberately untouched:** the settings-file key (`/home/fpp/media/config/plugin.showpilot`, and `$pluginName = "showpilot"` in `commands/restart_listener.php`/`showpilot_config.php`/`showpilot_listener.php`) is a fixed string tied to `FPP's WriteSettingToFile()`'s `configDirectory` convention, completely independent of the install directory name — changing it would have orphaned every existing user's saved ShowPilot server URL and show token, which would have been a far worse regression than this one. Also flagged, not yet fixed: the same live-host install log that surfaced this showed `WARN: FPP source not found at /opt/fpp/src — skipping C++ plugin build`, a separate issue (the MultiSync `.so` won't build without FPP's source tree present) that needs its own investigation independent of this directory-naming fix. |
| 0.13.75 | **Second and third places the same `repoName` regression broke, found live after v0.13.74 shipped.** After reinstalling with v0.13.74, the user still hit `Error with plugin, requesting a page that doesn't exist: showpilot/showpilot_ui.html` clicking the sidebar link — v0.13.74 only fixed scripts/filesystem paths, not the two places this plugin builds its own `plugin.php?plugin=...` URLs. **(1) `content_menu.inc`:** its menu link hardcoded `plugin=showpilot`. Confirmed by reading `www/menu.inc` (`list_plugin_entries()`) that FPP's real sidebar renders this file via `include_once()` with output buffering — the PHP in it genuinely executes on every page load, unlike the separate static-text scan the Plugin Manager's "Open" button uses (`_PluginGetBestPageUrl()` in `www/api/controllers/plugin.php`, which builds its own `plugin=` from the true directory and never reads it from this file — confirmed by reading that function too, so this fix can't collide with the existing v0.13.66 `nopage`-ordering fix, which only concerns `page=`). Changed to `plugin=<?php echo htmlspecialchars(basename(__DIR__)); ?>`, verified by simulating FPP's exact `include_once`+`ob_get_clean()`+`str_replace` sequence locally. **(2) `showpilot_ui.html`:** the admin config page itself — also `include_once`'d by `www/plugin.php`, confirmed the same way — makes five of its own `fetch()` calls back through `plugin.php?plugin=showpilot&page=...` (the proxy endpoint, loading/saving raw config twice, audio extraction, listener status), all hardcoded the same way. Added `$pluginDirName = basename(__DIR__);` to the page's existing top-of-file PHP block and injected it as `const SHOWPILOT_PLUGIN_DIR = <?php echo json_encode($pluginDirName); ?>;` right before the first script use; all five URLs now build `plugin=` from that constant. **Not touched, on purpose, in both files:** `$pluginName = "showpilot"` (the settings-file key, e.g. `/api/configfile/plugin.showpilot`) is a completely different, fixed identifier unrelated to the install directory — same reasoning as v0.13.74. This is now believed to be the complete set of `repoName`-dependent hardcodes; the checklist for verifying that going forward is: grep for `plugin=` followed by a literal name, and for any bare directory path under `plugins/`, not just for the old literal string. |
| 0.13.76 | **The actual cause of "ShowPilot isn't getting info when the show is running," found live.** Not a network problem, not a stale process (though a genuinely orphaned listener from a manual `rm -rf` of the old `ShowPilot-plugin` directory was also cleaned up during this investigation — see the operational note below). The real bug: `showpilot_listener.php` was the **one file in the entire plugin** that computed `$pluginName` dynamically — `basename(dirname(__FILE__))` — instead of hardcoding `"showpilot"` the way every other file does (`showpilot_config.php`, `showpilot_ui.html`, every `commands/*.php`, confirmed by grepping all of them). This was invisible for the plugin's whole history because the install directory was always literally named `showpilot` — the dynamic computation and the hardcoded literal produced the same value. As of v0.13.74, the real directory is `showpilot-plugin`, so this one file alone started reading and writing `/home/fpp/media/config/plugin.showpilot-plugin` (freshly empty — no serverUrl, no showToken) and logging to `plugin-showpilot-plugin.log`, while every other part of the plugin (the config UI, the scheduler commands, the config file a user actually edits) kept using `plugin.showpilot`. `ofHttp()`'s `if (empty($cfg['serverUrl']) || empty($cfg['showToken'])) return null;` guard then made every outbound report to ShowPilot's server a silent no-op — no error logged, because the request was never attempted. Confirmed via the ShowPilot server's own admin dashboard: Connection showed Offline, Last seen over an hour ago, and Plugin version stuck reporting 0.13.73 — the last point at which the directory (and thus `$pluginName`) still happened to match. Fix: hardcoded `$pluginName = "showpilot"` in `showpilot_listener.php` to match every other file; `$pluginPath` (the one place in that file that legitimately needs the real directory, though it turned out to be unused elsewhere in the file) still derives from `basename(dirname(__FILE__))` so it stays correct if it's ever used later. **Operational note, not a code fix:** during this investigation we also found a fully orphaned `showpilot_listener.php` process still running from the old, already-deleted `/home/fpp/media/plugins/ShowPilot-plugin/` directory — a manual `rm -rf` (rather than going through FPP's Plugin Manager Uninstall, which runs `fpp_uninstall.sh` and kills the listener first) leaves any already-running process alive, invisible to `ls`, still polling FPP and still POSTing to the configured server in parallel with the correct listener. Always kill matching processes (`pkill -f showpilot_listener`) before manually deleting a plugin directory by hand. |
| 0.14.0 | **Cooldown no longer edits FPP playlists.** Replaced on-disk playlist patching (remove cooled entry, re-insert from snapshot) with a native `query_next` playlist hook in `src/FPPShowPilotSync.cpp` that continues the running playlist past cooled items via FPP's in-playlist jump; listener now only writes `showpilot-cooldown-active.json`. One-time startup migration restores playlists left edited by 0.13.x and deletes the legacy state file; uninstall removes both state files. Prompted by an external security/standards review of the plugin. Built from FPP source reading (8.5/9.4/10.x headers compile-checked). **Live-tested:** a manually cooled mid-playlist sequence was skipped with no visible or audible start. Still to verify live: shuffled playlists, scheduler stop timing, and a cooled item directly after an inserted viewer request. See "Cooldown enforcement". |
| 0.14.1 | **(Community PR #12.) Fix wrong song playing for a winning vote/request (e.g. a Halloween pick playing a Christmas song).** Two causes, both fixed in `showpilot_listener.php`: **(1)** The listener inserted `Remote Playlist` at the server's `playlistIndex` (the position from the last Sync Now) without checking what sits there. New `resolveRemotePlaylistIndex()` looks the winning sequence up **by name** in the live Remote Playlist file at insert time (same name/position rules as `readFppPlaylistSequences`, so it can't disagree with Sync). Stale index → logs and uses the correct position; sequence not in the playlist → logs a WARN (throttled to once per song per 60s) and skips instead of playing the wrong slot. **(2)** `remotePlaylist` was only read at startup/restart, but the UI dropdown saves immediately and Sync Now uses the new value — so changing playlists without a Restart Listener synced positions from the new playlist while inserting from the old one. The main loop now re-reads it every iteration and logs `Remote Playlist changed: ...`. |

---

## Starting a new conversation

1. Read this primer.
2. Clone fresh: there is no `node_modules` or build step — the plugin is plain PHP + one Node file. `git clone https://github.com/ShowPilotFPP/ShowPilot-plugin.git /home/claude/showpilot-plugin`
3. Check `version.php` to confirm the starting version.
4. For audio daemon changes, read the "Critical invariants" section in the ShowPilot main PRIMER.md before touching `showpilot_audio.js`.
5. Changes to scheduler commands (adding/modifying) always need a corresponding `descriptions.json` update and a version bump.
