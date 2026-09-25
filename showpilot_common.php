<?php
// ============================================================
// ShowPilot — shared PHP helpers
// ============================================================
// Included by every PHP entry point (config page, proxy, FPP commands, and
// the CLI listener) so config parsing, secret storage, logging, and the
// ShowPilot HTTP client exist in exactly one place.
//
// Callers set `$skipJSsettings = true;` before including this file when they
// don't want FPP's config.php to emit its JS settings block.
// ============================================================

include_once "/opt/fpp/www/config.php";
include_once "/opt/fpp/www/common.php";

// Settings-file key: config/plugin.showpilot. Fixed forever — every existing
// install's saved settings live under this name, independent of repoName.
const SP_SETTINGS_KEY = 'showpilot';

// The on-disk install directory, which FPP names after pluginInfo.json's
// repoName. Drives the log file name and the plugindata directory, both of
// which FPP's guidelines key on repoName.
define('SP_REPO_NAME', basename(__DIR__));

// ------------------------------------------------------------
// Logging — one file, <logdir>/plugin-<repoName>.log, rotated by FPP.
// ------------------------------------------------------------

function sp_log_file() {
    global $settings;
    return $settings['logDirectory'] . '/plugin-' . SP_REPO_NAME . '.log';
}

function sp_log($message, $tag = '') {
    $prefix = '[' . date('Y-m-d H:i:s') . '] ' . ($tag !== '' ? "[$tag] " : '');
    @file_put_contents(sp_log_file(), $prefix . $message . "\n", FILE_APPEND | LOCK_EX);
}

// ------------------------------------------------------------
// Plugin config (config/plugin.showpilot) — non-sensitive settings only.
// ------------------------------------------------------------

// Values reach the config file through several writers: FPP's settings API
// and WriteSettingToFile() store them URL-encoded, the Developer raw editor
// stores them plain. Only decode when a %XX escape is present so plain values
// containing '+' aren't turned into spaces.
function sp_smart_decode($value) {
    if ($value === null || $value === '') return $value;
    return preg_match('/%[0-9a-fA-F]{2}/', $value) ? urldecode($value) : $value;
}

function sp_config_file() {
    global $settings;
    return $settings['configDirectory'] . '/plugin.' . SP_SETTINGS_KEY;
}

function sp_read_config() {
    $s = @parse_ini_file(sp_config_file());
    return is_array($s) ? $s : array();
}

function sp_setting(array $config, $key, $default = '') {
    if (!isset($config[$key]) || $config[$key] === '') return $default;
    return sp_smart_decode($config[$key]);
}

// FPP's own WriteSettingToFile() handles locking and quoting for writes; it
// has no delete, so removing a key (used to move the token out of config/)
// is done here under the same flock.
function sp_remove_config_key($key) {
    $path = sp_config_file();
    $fd = @fopen($path, 'c+');
    if ($fd === false) return false;
    flock($fd, LOCK_EX);
    $lines = array();
    while (($line = fgets($fd)) !== false) $lines[] = rtrim($line, "\r\n");
    $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=/';
    $kept = array_filter($lines, function ($l) use ($pattern) {
        return !preg_match($pattern, $l);
    });
    $ok = true;
    if (count($kept) !== count($lines)) {
        $ok = ftruncate($fd, 0) && rewind($fd)
            && fwrite($fd, rtrim(implode("\n", $kept)) . "\n") !== false;
    }
    flock($fd, LOCK_UN);
    fclose($fd);
    return $ok;
}

// ------------------------------------------------------------
// Private data — <mediadir>/plugindata/<repoName>/, mode 0700/0600.
// Holds the show token and the cooldown state. FPP's crash-report bundle and
// JSON backup both copy config/ but never plugindata/ (guideline §14.11).
// ------------------------------------------------------------

// Install scripts and the fppd-launched listener run as root; the web UI runs
// as fpp. Anything root creates here has to be handed back to fpp or the UI
// can no longer read it.
function sp_fix_owner($path) {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        @chown($path, 'fpp');
        @chgrp($path, 'fpp');
    }
}

function sp_data_dir() {
    global $settings;
    $parent = $settings['mediaDirectory'] . '/plugindata';
    if (!is_dir($parent)) {
        @mkdir($parent, 0775);
        sp_fix_owner($parent);
    }
    $dir = $parent . '/' . SP_REPO_NAME;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700);
        sp_fix_owner($dir);
    }
    return $dir;
}

// Write a file only its owner can read, atomically.
function sp_write_private_file($path, $content) {
    $tmp = $path . '.tmp';
    $oldUmask = umask(0077);
    $ok = @file_put_contents($tmp, $content, LOCK_EX) !== false && @rename($tmp, $path);
    umask($oldUmask);
    if (!$ok) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0600);
    sp_fix_owner($path);
    return true;
}

function sp_token_file() {
    return sp_data_dir() . '/showToken';
}

// Returns the show token, moving it out of config/ if an older version (or
// FPP's generic settings API) left it there.
function sp_get_show_token() {
    $legacy = sp_setting(sp_read_config(), 'showToken');
    if ($legacy !== '' && sp_set_show_token($legacy)) {
        sp_remove_config_key('showToken');
    }
    $file = sp_token_file();
    return is_readable($file) ? trim((string)@file_get_contents($file)) : '';
}

function sp_set_show_token($token) {
    return sp_write_private_file(sp_token_file(), trim((string)$token));
}

// ------------------------------------------------------------
// ShowPilot server client
// ------------------------------------------------------------

// Only plain http(s) origins with no embedded credentials. This value is
// concatenated straight into file_get_contents(), so anything else (file://,
// php://, user:pass@) must never get that far.
function sp_is_valid_server_url($url) {
    $p = @parse_url($url);
    return is_array($p)
        && isset($p['scheme'], $p['host'])
        && in_array(strtolower($p['scheme']), array('http', 'https'), true)
        && !isset($p['user']) && !isset($p['pass'])
        && !isset($p['query']) && !isset($p['fragment']);
}

function sp_server_url($config = null) {
    $url = rtrim(sp_setting($config ?? sp_read_config(), 'serverUrl'), '/');
    return sp_is_valid_server_url($url) ? $url : '';
}

// Returns array('status' => int, 'body' => string|null), or null when the
// server URL or token isn't configured.
function sp_http($serverUrl, $token, $method, $path, $body = null, $contentType = 'application/json', $timeout = 10) {
    if ($serverUrl === '' || $token === '') return null;

    $headers = array('Authorization: Bearer ' . $token, 'Accept: application/json');
    if ($body !== null) $headers[] = 'Content-Type: ' . $contentType;

    $options = array('http' => array(
        'method'          => $method,
        'timeout'         => $timeout,
        'header'          => implode("\r\n", $headers),
        'ignore_errors'   => true,
        // PHP re-sends custom headers — our bearer token — to wherever a
        // redirect points. Never follow one; the operator should enter the
        // server's final URL instead.
        'follow_location' => 0,
    ));
    if ($body !== null) $options['http']['content'] = $body;

    $result = @file_get_contents($serverUrl . $path, false, stream_context_create($options));

    $status = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
        }
    }
    return array('status' => $status, 'body' => $result === false ? null : $result);
}

// Used by the viewer-mode FPP commands.
function sp_set_viewer_mode($mode) {
    $r = sp_http(sp_server_url(), sp_get_show_token(), 'POST', '/api/plugin/viewer-mode',
        json_encode(array('mode' => $mode)));
    if ($r === null) {
        sp_log("Viewer mode $mode not sent — Server URL or Show Token not configured", 'command');
        return false;
    }
    $ok = $r['status'] >= 200 && $r['status'] < 300;
    sp_log("Viewer mode $mode: " . ($ok ? 'OK' : 'failed (HTTP ' . $r['status'] . ')'), 'command');
    return $ok;
}

// ------------------------------------------------------------
// FPP playlists
// ------------------------------------------------------------

// Playlist names arrive from the plugin config, FPP status, a local state
// file, and the ShowPilot server's cooldown patches. None of them may name a
// path outside FPP's playlist directory.
function sp_playlist_path($name) {
    global $settings;
    if (!is_string($name) || $name === '' || strlen($name) > 255) return null;
    if (strpbrk($name, "/\\\0") !== false || $name === '.' || $name === '..') return null;
    return $settings['playlistDirectory'] . '/' . $name . '.json';
}

function sp_read_playlist($name) {
    $path = sp_playlist_path($name);
    if ($path === null || !is_readable($path)) return null;
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function sp_write_playlist($name, array $data) {
    $path = sp_playlist_path($name);
    if ($path === null) return false;
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    sp_fix_owner($path);
    return true;
}
