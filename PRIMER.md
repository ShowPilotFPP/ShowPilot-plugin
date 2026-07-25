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
showpilot/                          (FPP plugin dir: /home/fpp/media/plugins/showpilot/)
├── showpilot_listener.php          — main polling loop
├── showpilot_audio.js              — audio sync daemon
├── showpilot_ui.html               — admin config UI embedded in FPP
├── version.php                     — single version source of truth ($PLUGIN_VERSION)
├── pluginInfo.json                 — FPP plugin manifest (name, version, homeURL)
├── callbacks.sh                    — FPP lifecycle hooks
├── scripts/
│   ├── postStart.sh                — starts audio daemon after FPP starts
│   ├── preStop.sh                  — stops daemon cleanly before FPP stops
│   └── restart-daemon.sh           — restarts daemon without a full fppd cycle
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

The plugin is installed via FPP's Plugin Manager (paste the GitHub URL). Updates are applied via `git pull` on the FPP host inside the plugin directory, then a listener restart:

```bash
# After updating plugin files:
cd /home/fpp/media/plugins/showpilot
git pull origin main

# If only listener/command PHP changed:
# (FPP picks up PHP changes on next poll cycle — no explicit restart needed
#  unless you want to be sure)

# If showpilot_audio.js changed:
sudo /home/fpp/media/plugins/showpilot/scripts/restart-daemon.sh
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
| 0.13.40 | Cooldown suppression: handle `playlistPatches` from ShowPilot `/state`, disable/re-enable sequences in FPP playlist JSON. |
| 0.13.41 | Fix PHP 8 crash in `applyPlaylistPatches`: stdClass objects require `->` not `[]`. |
| 0.13.63 | Race mode: `raceWinner` field handling; `effectiveInterrupt` for race winner playback. |
| 0.13.64 | `set_mode_race.php` scheduler command. Activates Race mode via `POST /api/plugin/viewer-mode`. |
| 0.13.66 | Fix "Open" button in FPP's new 10.x-beta (July 2026) Plugin Manager, which linked to a broken page (404: `requesting a page that doesn't exist: showpilot/1`). Cause: FPP's plugin-manager scanner extracts a plugin's page URL from `content_menu.inc` via a substring regex, not real query parsing — our href had `page=showpilot_ui.html&nopage=1`, and `"nopage=1"` itself contains the substring `"page=1"`, so the scanner's greedy match grabbed `"1"` as the page instead of `showpilot_ui.html`. Fix: reorder to `nopage=1&page=showpilot_ui.html` (nopage before page). Verified against FPP's actual scanner source (`www/api/controllers/plugin.php`, `FalconChristmas/fpp` master) before shipping. The same underlying bug hit `ShowPilot-Lite`'s `menu.inc`, fixed there in Lite v0.5.47 — see that repo's primer for the fuller writeup (Lite's fix also needed a new static redirect page since its Open target is an external, dynamically-computed URL). |
| 0.13.67 | FPP 10.0-beta compatibility audit (source-level, not live-tested): MultiSyncPlugin ABI, build system paths, and all REST endpoints used by the plugin confirmed unchanged. `pluginInfo.json` split the open-ended "9.0+" entry into 9.0–9.99 and an explicit 10.0+ entry. |
| 0.13.68 | `fpp_install.sh` was pinning Node.js 18 (EOL April 2025) via NodeSource; caught via the deprecation banner in a live FPP 10.0-beta install-log review. Node 20 is also now EOL (April 2026), so bumped the floor to Node 22 (Maintenance LTS through April 2027) rather than just chasing the previous version. |
| 0.13.69 | Added `icon.png` (256x256, repo root) plus `iconURL` in `pluginInfo.json` for FPP 10.x-beta's new Plugin Manager thumbnail grid. Reconstructed from the ShowPilot admin webapp's `svg.app-brand-mark` (`--logo-primary: #f59e0b`, `--logo-accent: #ef4444`, `--logo-dim: #f59e0b @ 45%`) — rendered directly from the rect geometry rather than from a rasterized screenshot, so it's crisp at both 128 and 256px. |

---

## Starting a new conversation

1. Read this primer.
2. Clone fresh: there is no `node_modules` or build step — the plugin is plain PHP + one Node file. `git clone https://github.com/ShowPilotFPP/ShowPilot-plugin.git /home/claude/showpilot-plugin`
3. Check `version.php` to confirm the starting version.
4. For audio daemon changes, read the "Critical invariants" section in the ShowPilot main PRIMER.md before touching `showpilot_audio.js`.
5. Changes to scheduler commands (adding/modifying) always need a corresponding `descriptions.json` update and a version bump.
