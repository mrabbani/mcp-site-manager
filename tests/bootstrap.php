<?php
require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('__')) {
    function __($text, $domain = null) { return $text; }
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
