#!/bin/bash
# OpenFalcon plugin postStop — gracefully terminate the listener.

# Listener (PHP)
pkill -f "php /home/fpp/media/plugins/openfalcon/openfalcon_listener.php" 2>/dev/null

#postStop
