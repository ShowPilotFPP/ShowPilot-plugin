#!/usr/bin/env php
<?php
// ShowPilot — Clear Queue (needs ShowPilot v0.33.249+). Empties the request
// queue, e.g. right before turning viewer control on or off.
$skipJSsettings = true;
require_once dirname(__DIR__) . '/showpilot_common.php';

exit(sp_clear_queue() ? 0 : 1);
