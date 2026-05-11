<?php
/**
 * Minimal stand-in for WordPress's option API for unit tests.
 * Only what Support\DisabledAbilities calls: get_option() + update_option().
 */
$GLOBALS['__opts'] = [];

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['__opts'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['__opts'][$name] = $value;
        return true;
    }
}
