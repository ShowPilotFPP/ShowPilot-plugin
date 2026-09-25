<?php
// ShowPilot's entry in FPP's menu. 'wrap' => 1 renders the config page inside
// FPP's own header, navigation and theme, the same way Remote Falcon does.
//
// FPP's Plugin Manager "Open" button finds the page by scanning this file
// for the literal 'page' value, so keep it a static string.
$menuEntries = array(
    array(
        'text' => 'ShowPilot',
        'type' => 'content',
        'page' => 'showpilot_ui.html',
        'wrap' => 1,
    ),
);

// FPP sets $plugin (this plugin's directory) and $menu (the menu area).
foreach ($menuEntries as $entry) {
    if ($entry['type'] != $menu) continue;
    $nopage = (isset($entry['wrap']) && $entry['wrap'] == 0) ? '&nopage=1' : '';
    printf("<li><a href='plugin.php?plugin=%s&page=%s%s'>%s</a></li>\n",
        htmlspecialchars($plugin, ENT_QUOTES), $entry['page'], $nopage, htmlspecialchars($entry['text']));
}
