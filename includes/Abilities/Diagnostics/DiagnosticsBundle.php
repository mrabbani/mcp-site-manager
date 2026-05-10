<?php
declare(strict_types=1);

namespace SiteMcp\Abilities\Diagnostics;

use SiteMcp\Abilities\AbilityBundle;
use SiteMcp\Support\SchemaBuilder as S;

final class DiagnosticsBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'health-overview' => [
                'label'       => __('Site health overview', 'site-mcp'),
                'description' => __('WP/PHP/MySQL versions, active theme, plugin counts, debug flags.', 'site-mcp'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::require_cap('manage_options'),
                'execute' => function () {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                    global $wpdb;
                    $plugins = get_plugins();
                    $active  = (array) get_option('active_plugins', []);
                    return [
                        'wp_version'        => get_bloginfo('version'),
                        'php_version'       => PHP_VERSION,
                        'mysql_version'     => $wpdb->db_version(),
                        'is_multisite'      => is_multisite(),
                        'active_theme'      => wp_get_theme()->get_stylesheet(),
                        'active_theme_name' => wp_get_theme()->get('Name'),
                        'plugin_total'      => count($plugins),
                        'plugin_active'     => count($active),
                        'wp_debug'          => defined('WP_DEBUG') && WP_DEBUG,
                        'wp_debug_log'      => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
                        'app_passwords_enabled' => function_exists('wp_is_application_passwords_available') ? wp_is_application_passwords_available() : true,
                        'rest_url'          => rest_url(),
                    ];
                },
            ],
            'health-debug-log-tail' => [
                'label'       => __('Tail debug log', 'site-mcp'),
                'description' => __('Return the last N lines of wp-content/debug.log if WP_DEBUG_LOG is enabled.', 'site-mcp'),
                'input_schema'=> S::object([
                    'lines' => S::int('Lines from the end (1-500)', false, 1, 500),
                ]),
                'permission_callback' => self::require_cap('manage_options'),
                'execute' => function (array $a) {
                    if (!(defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)) {
                        return new \WP_Error('site_mcp_debug_log_off', 'WP_DEBUG_LOG is not enabled', ['status' => 400]);
                    }
                    $path = is_string(WP_DEBUG_LOG) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
                    if (!is_readable($path)) {
                        return new \WP_Error('site_mcp_debug_log_missing', 'Debug log not found or unreadable', ['status' => 404]);
                    }
                    $n = max(1, min(500, (int) ($a['lines'] ?? 100)));
                    $lines = self::tail($path, $n);
                    return ['path' => $path, 'lines' => $lines];
                },
            ],
            'health-rest-status' => [
                'label'       => __('REST API status', 'site-mcp'),
                'description' => __('Whether the REST API namespace and app-password auth are available.', 'site-mcp'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::require_cap('manage_options'),
                'execute' => function () {
                    return [
                        'rest_url'              => rest_url(),
                        'rest_prefix'           => rest_get_url_prefix(),
                        'app_passwords_enabled' => function_exists('wp_is_application_passwords_available') ? wp_is_application_passwords_available() : true,
                        'site_mcp_endpoint'     => rest_url('mcp/mcp-adapter-default-server'),
                    ];
                },
            ],
        ];
    }

    private static function tail(string $path, int $lines): array
    {
        $f = @fopen($path, 'rb');
        if (!$f) return [];
        $buffer = '';
        $chunk = 4096;
        fseek($f, 0, SEEK_END);
        $pos = ftell($f);
        while ($pos > 0 && substr_count($buffer, "\n") <= $lines) {
            $read = min($chunk, $pos);
            $pos -= $read;
            fseek($f, $pos);
            $buffer = fread($f, $read) . $buffer;
        }
        fclose($f);
        $arr = explode("\n", trim($buffer));
        return array_slice($arr, -$lines);
    }
}
