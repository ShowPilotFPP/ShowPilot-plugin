#!/bin/bash
# ShowPilot plugin postStop — gracefully terminate the listener when fppd
# stops. fppd would eventually clean up its child processes anyway, but doing
# it explicitly here ensures a clean shutdown sequence and gives the listener
# a chance to send any final state to the server before exiting.

pkill -f "php /home/fpp/media/plugins/showpilot/showpilot_listener.php" 2>/dev/null
pkill -f "node /home/fpp/media/plugins/showpilot/showpilot_audio.js" 2>/dev/null

#postStop
