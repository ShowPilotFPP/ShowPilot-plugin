<!--
  Param order matters here: nopage=1 MUST come before page=... .

  FPP's plugin-manager "Open" button (10.x beta+, July 2026) resolves a
  plugin's page URL by statically scanning menu/content_menu.inc with a
  substring regex, not a real query-string parser. "nopage=1" itself
  contains the substring "page=1", so if it appears AFTER our real
  page=showpilot_ui.html, the scanner's greedy match latches onto that
  and reports the page as literal "1" instead of showpilot_ui.html —
  plugin.php then 404s with "requesting a page that doesn't exist:
  showpilot/1". Putting nopage first avoids the collision. Confirmed
  against FPP's _PluginExtractPageFromHtml regex before shipping.
-->
<li><a href="plugin.php?plugin=showpilot&nopage=1&page=showpilot_ui.html">ShowPilot Configuration</a></li>
