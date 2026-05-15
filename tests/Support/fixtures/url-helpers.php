<?php
/**
 * Minimal stand-ins for WordPress URL/filter helpers used by Support\UrlGuard.
 * The guard never reaches DNS resolution in tests because we feed it
 * dotted-quad / IPv6 hostnames that filter_var(FILTER_VALIDATE_IP) accepts
 * directly — that branch short-circuits the resolver.
 */

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return $component === -1 ? \parse_url($url) : \parse_url($url, $component);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        return $value;
    }
}
