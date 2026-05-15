<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Plugin source files start with `defined('ABSPATH') || exit;` for Plugin Check
// compliance. In the unit test process there is no WordPress, so we satisfy
// that guard with a sentinel define before any plugin class autoloads.
if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

if (!function_exists('__')) {
    function __($text, $domain = null) { return $text; }
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
