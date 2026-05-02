#!/bin/bash

PLUGIN_DIR="/home/fpp/media/plugins/showpilot"

# Remove all plugin-generated PHP files. The repo itself is deleted by
# FPP's plugin manager after this script runs — we only need to clean up
# files that might otherwise persist.
rm -f "$PLUGIN_DIR/showpilot_listener.php"
rm -f "$PLUGIN_DIR/showpilot_proxy.php"
rm -f "$PLUGIN_DIR/extract_audio.php"
rm -f "$PLUGIN_DIR/listener_status.php"

#fpp_uninstall
