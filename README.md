# ShowPilot FPP Plugin

[![Discord](https://img.shields.io/badge/Discord-Join%20the%20chat-5865F2?logo=discord&logoColor=white)](https://discord.gg/UpmcXmWfN9) [![Facebook](https://img.shields.io/badge/Facebook-Join%20the%20group-1877F2?logo=facebook&logoColor=white)](https://www.facebook.com/groups/showpilot)

The Falcon Player (FPP) plugin for [ShowPilot](https://github.com/ShowPilotFPP/ShowPilot) — a self-hosted replacement for Remote Falcon.

This plugin connects an FPP instance to your ShowPilot server. It reports playback state to ShowPilot and queues sequences when viewers vote or make jukebox requests.

## What this plugin does

- Polls FPP's status API (`/api/system/status`) every second by default
- Reports the currently playing sequence and what's coming up next to ShowPilot
- Asks ShowPilot what to play next (vote winner or jukebox request)
- Queues that sequence in FPP via `Insert Playlist Immediate` or `Insert Playlist After Current`
- Pushes the playlist contents to ShowPilot so the viewer page knows what songs exist
- Heartbeats back to ShowPilot so the admin page can show plugin connectivity

## Requirements

- FPP 5.0 or newer (tested on FPP 9.5; FPP 10.0-beta compatibility verified via source-level API/build audit, not yet run on live 10.x hardware)
- A running [ShowPilot](https://github.com/ShowPilotFPP/ShowPilot) server reachable from the FPP

## Install

### Via FPP Plugin Manager (recommended once it's in the master plugin list)

**Pending** — once this plugin is added to the [FalconChristmas plugin list](https://github.com/FalconChristmas/fpp-data) you'll be able to install with one click. For now, install manually.

### Manual install

In FPP, open **Content Setup → Plugin Manager**, paste
`https://raw.githubusercontent.com/ShowPilotFPP/ShowPilot-plugin/main/pluginInfo.json`
into the plugin URL box, and click **Get Plugin Info**, then **Install**. FPP clones the
plugin, runs its install script, and asks for an fppd restart.

Then open the plugin's config page and fill in:

- **Server URL**: `http://your-showpilot-server:3100` (no trailing slash; use `https://` for a server outside your home network)
- **Show Token**: copy from your ShowPilot admin page → "Show Token (for ShowPilot Plugin)" section
- **Remote Playlist**: select the FPP playlist that contains your viewer-controllable sequences

Then click **Sync Now**. Sequences should appear in the ShowPilot admin.

## Updating

Use the **Update** button in FPP's Plugin Manager. It pulls the new code, rebuilds the
MultiSync component, and restarts the listener and audio daemon — on FPP 10+ without an
fppd restart.

## Privacy & security

- **What leaves this FPP:** only traffic to the ShowPilot server you configure — what is
  playing and its position, the plugin version, your Remote Playlist's song list, and (only
  when you click Sync Now with audio upload checked) those songs' audio. Nothing is sent until
  a Server URL and Show Token are saved.
- **What listens on your network:** the audio daemon on port 8090 (configurable). It serves
  audio files from FPP's music folder and the current playback position, without a login —
  don't forward that port to the internet. It only starts once a Server URL is configured.
- **What it changes on FPP:** it keeps its own `ShowPilot Queue` playlist for queued requests.
  It never edits your playlists. If you turn on *Also skip cooled-down songs in FPP's normal
  playlist rotation* in ShowPilot, the plugin's FPP component continues your playlist past a
  song in cooldown at the song change (this needs the component built at install; see
  PRIMER.md, "Cooldown enforcement"). The cooldown list is kept in
  `config/showpilot-cooldown-active.json` and removed on uninstall.
- **Where your token is kept:** `/home/fpp/media/plugindata/showpilot-plugin/showToken`,
  readable only by the plugin — not in FPP's `config/` folder, so it isn't included in FPP
  backups or crash reports. Uninstalling keeps it (with your settings) so a reinstall
  picks up where it left off; delete that file to remove it.
- Visitor votes and requests are received and stored by your ShowPilot server, not on FPP.

## FPP Commands

The plugin exposes several commands you can schedule via FPP's command preset/scheduler:

| Command | Effect |
|---|---|
| ShowPilot - Turn Viewer Control On | Restores last active mode (Voting or Jukebox) |
| ShowPilot - Turn Viewer Control Off | Disables viewer control |
| ShowPilot - Switch to Voting Mode | Forces voting mode |
| ShowPilot - Switch to Jukebox Mode | Forces jukebox mode |
| ShowPilot - Restart Listener | Reloads plugin config |
| ShowPilot - Stop Listener | Stops the listener (turns plugin off) |
| ShowPilot - Turn Interrupt Schedule On | Force-on the "interrupt schedule" plugin setting |
| ShowPilot - Turn Interrupt Schedule Off | Force-off the same |

A typical setup: schedule "Turn Viewer Control On" 30 minutes before showtime, "Turn Viewer Control Off" at end-of-night.

## Architecture

```
[FPP]  ──┬─▶ /api/system/status  (polled by listener)
         └─▶ /api/command/Insert Playlist Immediate (queues viewer picks)
              ▲
              │
[Plugin Listener (PHP)] ──HTTP──▶  [ShowPilot Server]
   |
   └── Reads <mediadir>/playlists/<remote-playlist>.json
       to determine "next up" and to sync sequence list
```

The listener is a long-running PHP process (started by FPP's plugin system at boot via `scripts/postStart.sh`, running as the `fpp` user). It polls every second and is gentle on FPP's CPU.

All browser-to-ShowPilot API calls (Sync, Test Connectivity, audio upload) are routed through `showpilot_proxy.php` on FPP rather than going directly to the ShowPilot server. This keeps all requests same-origin, preventing ad blockers and browser extensions from interfering.

## Troubleshooting

**Plugin UI page won't save settings**
FPP 9+ moved its plugin JS helpers. The plugin uses FPP's REST API directly to save settings. If your FPP is older than 5.0 this won't work — upgrade FPP.

**Sync or Test Connectivity fails / does nothing**
Most likely a browser extension (ad blocker, privacy extension) blocking the request. All ShowPilot API calls are routed through `showpilot_proxy.php` on FPP itself and should be same-origin and extension-safe — but if you're still seeing issues, check your browser console for `ERR_BLOCKED_BY_CLIENT` errors. Temporarily disabling extensions or using an Incognito window (which disables extensions by default) will confirm if that's the cause.

**Log location**
Everything the plugin does — listener, audio daemon, commands, install steps — goes to one
log, `plugin-showpilot-plugin.log`, viewable under **Status/Control → Logs** or in the
config page's Diagnostics tab.

**Plugin queues wrong song**
Make sure you've clicked **Sync Playlist** in the plugin UI after any changes to your FPP playlist contents/order.

## License

MIT — see [LICENSE](./LICENSE).