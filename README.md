# ShowPilot FPP Plugin

[![Discord](https://img.shields.io/badge/Discord-Join%20the%20chat-5865F2?logo=discord&logoColor=white)](https://discord.gg/UpmcXmWfN9) [![Facebook](https://img.shields.io/badge/Facebook-Join%20the%20group-1877F2?logo=facebook&logoColor=white)](https://www.facebook.com/groups/showpilot)

The Falcon Player (FPP) plugin for [ShowPilot](https://github.com/ShowPilotFPP/ShowPilot) — a self-hosted replacement for Remote Falcon.

This plugin connects an FPP instance to your ShowPilot server. It reports playback state to ShowPilot, queues sequences when viewers vote or make jukebox requests, and provides the precise playback position that keeps viewers' phone audio in sync. In FPP it appears as **ShowPilot Blackbox**, with its settings under **Content Setup → ShowPilot**.

## What this plugin does

- Polls FPP's status API (`/api/system/status`) every second by default
- Reports the currently playing sequence and what's coming up next to ShowPilot
- Asks ShowPilot what to play next (vote winner or jukebox request)
- Queues that sequence in FPP via `Insert Playlist Immediate` or `Insert Playlist After Current`
- Pushes the playlist contents to ShowPilot so the viewer page knows what songs exist
- Heartbeats back to ShowPilot so the admin page can show plugin connectivity
- Runs a small audio daemon (Node.js) that follows FPP's playback position in real time and relays it to ShowPilot, so phones listening to the show stay in sync, and serves your show audio to ShowPilot when you sync with audio upload
- Includes a small FPP component (C++, built at install) that can skip songs in cooldown during FPP's normal playlist rotation, without editing your playlists (only when that option is on in ShowPilot)

## Requirements

- **FPP 10.0 or newer.** Older FPP versions aren't supported: the settings page shows a warning there and the plugin may not work reliably. Upgrade FPP first.
- A running [ShowPilot](https://github.com/ShowPilotFPP/ShowPilot) server reachable from the FPP
- Node.js, which the installer adds from Debian's packages if it's missing (only `nodejs`; the daemon's one library is bundled)

## Install

### Via FPP Plugin Manager's list

Once this plugin appears in the [FalconChristmas plugin list](https://github.com/FalconChristmas/fpp-data), you can install it with one click. Until then, install it by URL as below.

### Install by URL

In FPP, open **Content Setup → Plugin Manager**, paste
`https://raw.githubusercontent.com/ShowPilotFPP/ShowPilot-plugin/main/pluginInfo.json`
into the plugin URL box, and click **Get Plugin Info**, then **Install**. FPP clones the
plugin and runs its install script. FPP 10 loads the plugin right away; if FPP still shows a
restart prompt, restarting is harmless.

Then open **Content Setup → ShowPilot** and fill in:

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
| ShowPilot - Turn Viewer Control On | Restores the last active mode (Voting, Jukebox or Race) |
| ShowPilot - Turn Viewer Control Off | Disables viewer control |
| ShowPilot - Switch to Voting Mode | Forces voting mode |
| ShowPilot - Switch to Jukebox Mode | Forces jukebox mode |
| ShowPilot - Switch to Race Mode | Forces race mode |
| ShowPilot - Restart Listener | Reloads plugin config |
| ShowPilot - Stop Listener | Stops the listener (turns plugin off) |
| ShowPilot - Turn Interrupt Schedule On | Force-on the "interrupt schedule" plugin setting |
| ShowPilot - Turn Interrupt Schedule Off | Force-off the same |

A typical setup: schedule "Turn Viewer Control On" 30 minutes before showtime, "Turn Viewer Control Off" at end-of-night.

## Architecture

```
[FPP] ──┬─▶ /api/system/status            (polled by the listener)
        ├─▶ /api/command/Insert Playlist … (queues viewer picks)
        └─▶ playback events ──▶ [FPP component (C++)] ──FIFO──▶ [Audio daemon (Node.js)]
                                  (cooldown skip)                  │ position, in real time
[Plugin Listener (PHP)] ──HTTP──▶ [ShowPilot Server] ◀──WebSocket──┘
   └── reads <mediadir>/playlists/<remote-playlist>.json
       to determine "next up" and to sync the sequence list
```

The listener and audio daemon are long-running processes started by FPP at boot (and by **Restart FPPD**) via `scripts/postStart.sh`, running as the `fpp` user. The listener polls every second and is gentle on FPP's CPU; the daemon reacts to FPP's playback events as they happen.

All browser-to-ShowPilot API calls (Sync, Test Connectivity, audio upload) are routed through `showpilot_proxy.php` on FPP rather than going directly to the ShowPilot server. This keeps all requests same-origin, preventing ad blockers and browser extensions from interfering.

## Troubleshooting

**Settings page shows "not supported" or doesn't work properly**
The plugin requires FPP 10.0 or newer. Upgrade FPP, then update the plugin.

**Sync or Test Connectivity fails / does nothing**
Most likely a browser extension (ad blocker, privacy extension) blocking the request. All ShowPilot API calls are routed through `showpilot_proxy.php` on FPP itself and should be same-origin and extension-safe — but if you're still seeing issues, check your browser console for `ERR_BLOCKED_BY_CLIENT` errors. Temporarily disabling extensions or using an Incognito window (which disables extensions by default) will confirm if that's the cause.

**Log location**
Everything the plugin does — listener, audio daemon, commands, install steps — goes to one
log, `plugin-showpilot-plugin.log`, viewable under **Status/Control → Logs** or in the
config page's Diagnostics tab.

**Plugin queues wrong song**
Make sure you've clicked **Sync Now** in the plugin UI after any changes to your FPP playlist contents/order.

## License

MIT — see [LICENSE](./LICENSE).