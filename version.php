<?php
// ============================================================
// ShowPilot — version constant
// ============================================================
// Single source of truth for the plugin version. Both showpilot_listener.php
// and showpilot_ui.html include this file rather than hardcoding the value
// independently. Without this, the two files would drift — that's exactly
// what happened in 0.8.x → 0.9.x: the listener bumped through betas while
// the UI was stuck at 0.8.6, making it look to users like the plugin was
// never updated.
//
// When releasing a new version: edit ONLY this file. Do not search for
// "0.10.0" in other files and update by hand — by design, no other file
// has it.
// ============================================================
$PLUGIN_VERSION = "0.13.26";
