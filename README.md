# ShowPilot FPP Plugin

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

- FPP 5.0 or newer (tested on FPP 9.5)
- A running [ShowPilot](https://github.com/ShowPilotFPP/ShowPilot) server reachable from the FPP

## Install

### Via FPP Plugin Manager (recommended once it's in the master plugin list)

**Pending** — once this plugin is added to the [FalconChristmas plugin list](https://github.com/FalconChristmas/fpp-data) you'll be able to install with one click. For now, install manually.

### Manual install

```bash
# On the FPP, in a terminal:
cd /home/fpp/media/plugins
git clone https://github.com/ShowPilotFPP/ShowPilot-plugin.git showpilot
sudo chmod +x showpilot/scripts/*.sh showpilot/commands/*.php
sudo chown -R fpp:fpp showpilot
sudo reboot
```

After reboot, the plugin should appear in **Content Setup → Plugin Manager**. Open the plugin's config page and fill in:

- **Server URL**: `http://your-showpilot-server:3100` (no trailing slash)
- **Show Token**: copy from your ShowPilot admin page → "Show Token (for ShowPilot Plugin)" section
- **Remote Playlist**: select the FPP playlist that contains your viewer-controllable sequences

Then click **Sync Playlist to ShowPilot**. Sequences should appear in the ShowPilot admin.

## Updating

```bash
cd /home/fpp/media/plugins/showpilot
git pull
sudo chmod +x scripts/*.sh commands/*.php
sudo chown -R fpp:fpp .
# Restart the listener via FPP's Command Scheduler or:
sudo pkill -f showpilot_listener
nohup php /home/fpp/media/plugins/showpilot/showpilot_listener.php > /dev/null 2>&1 &
disown
```

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
   └── Reads /home/fpp/media/playlists/<remote-playlist>.json
       to determine "next up" and to sync sequence list
```

The listener is a long-running PHP process (started by FPP's plugin system at boot via `scripts/postStart.sh`). It polls every second and is gentle on FPP's CPU.

All browser-to-ShowPilot API calls (Sync, Test Connectivity, audio upload) are routed through `showpilot_proxy.php` on FPP rather than going directly to the ShowPilot server. This keeps all requests same-origin, preventing ad blockers and browser extensions from interfering.

## Troubleshooting

**Plugin UI page won't save settings**
FPP 9+ moved its plugin JS helpers. The plugin uses FPP's REST API directly to save settings. If your FPP is older than 5.0 this won't work — upgrade FPP.

**Sync or Test Connectivity fails / does nothing**
Most likely a browser extension (ad blocker, privacy extension) blocking the request. All ShowPilot API calls are routed through `showpilot_proxy.php` on FPP itself and should be same-origin and extension-safe — but if you're still seeing issues, check your browser console for `ERR_BLOCKED_BY_CLIENT` errors. Temporarily disabling extensions or using an Incognito window (which disables extensions by default) will confirm if that's the cause.

**CSP errors in browser console**
The listener automatically registers your ShowPilot server URL with FPP's Apache Content Security Policy whitelist on startup. If you see CSP errors after a fresh install, restart the listener once (registration runs at listener startup). If errors persist on an older FPP version that lacks the registration script, add the URL manually:

```bash
sudo /opt/fpp/scripts/ManageApacheContentPolicy.sh add connect-src http://192.168.1.230:3100
sudo systemctl restart apache2
```

**Listener log location**
`/home/fpp/media/logs/showpilot-listener.log`

```bash
tail -f /home/fpp/media/logs/showpilot-listener.log
```

**Plugin queues wrong song**
Make sure you've clicked **Sync Playlist** in the plugin UI after any changes to your FPP playlist contents/order.

## License

MIT — see [LICENSE](./LICENSE).