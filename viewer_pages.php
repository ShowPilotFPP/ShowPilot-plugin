<?php
// ShowPilot — the list of viewer page names, for the "Set Viewer Page"
// command's dropdown in FPP's command editor (contentListUrl). Always answers
// with a JSON array (empty if ShowPilot can't be reached), so the field still
// takes a typed name. The show token stays on FPP.
header('Content-Type: application/json');
header('Cache-Control: no-store');
$skipJSsettings = true;
require_once __DIR__ . '/showpilot_common.php';

$names = array();
$serverUrl = sp_server_url();
$token = sp_get_show_token();
if ($serverUrl !== '' && $token !== '') {
    $r = sp_http($serverUrl, $token, 'GET', '/api/plugin/viewer-pages', null, 'application/json', 5);
    if ($r !== null && $r['status'] >= 200 && $r['status'] < 300) {
        $data = json_decode((string)$r['body'], true);
        if (is_array($data) && isset($data['pages']) && is_array($data['pages'])) {
            foreach ($data['pages'] as $p) {
                if (is_array($p) && isset($p['name']) && is_string($p['name'])) $names[] = $p['name'];
            }
        }
    }
}
echo json_encode($names);
