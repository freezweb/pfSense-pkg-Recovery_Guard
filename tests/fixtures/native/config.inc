<?php
// Test double for config.inc. This file is never part of the install manifest.
function config_get_path($path, $default = null) {
    $value = $GLOBALS['fixture_config'];
    foreach (explode('/', $path) as $key) { if (!is_array($value) || !array_key_exists($key, $value)) return $default; $value = $value[$key]; }
    return $value;
}
function config_set_path($path, $value) {
    $node = &$GLOBALS['fixture_config'];
    foreach (explode('/', $path) as $key) { if (!isset($node[$key])) $node[$key] = []; $node = &$node[$key]; }
    $node = $value;
}
function config_del_path($path) {
    $keys = explode('/', $path); $last = array_pop($keys); $node = &$GLOBALS['fixture_config'];
    foreach ($keys as $key) $node = &$node[$key];
    unset($node[$last]);
}
function write_config($description) {
    $GLOBALS['fixture_writes']++;
    if ($GLOBALS['fixture_write_result'] === 'throw') throw new RuntimeException('Fixture disk failure');
    return $GLOBALS['fixture_write_result'] === 'success' ? $GLOBALS['fixture_config'] : $GLOBALS['fixture_write_result'];
}
