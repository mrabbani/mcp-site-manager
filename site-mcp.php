<?php
/**
 * Plugin Name:       Site MCP
 * Description:       Exposes WordPress site management as MCP tools for AI clients (Claude Desktop, ChatGPT, Cursor).
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.0
 * Author:            site-mcp contributors
 * License:           GPL-2.0-or-later
 * Text Domain:       site-mcp
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('SITE_MCP_VERSION', '0.1.0');
define('SITE_MCP_FILE', __FILE__);
define('SITE_MCP_DIR', plugin_dir_path(__FILE__));
define('SITE_MCP_URL', plugin_dir_url(__FILE__));

$autoload = SITE_MCP_DIR . 'vendor/autoload.php';
if (!file_exists($autoload)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Site MCP: composer dependencies missing. Run `composer install` in the plugin directory.', 'site-mcp');
        echo '</p></div>';
    });
    return;
}
require_once $autoload;

register_activation_hook(__FILE__, [\SiteMcp\Plugin::class, 'on_activate']);
register_deactivation_hook(__FILE__, [\SiteMcp\Plugin::class, 'on_deactivate']);

add_action('plugins_loaded', [\SiteMcp\Plugin::class, 'boot'], 5);


add_action( 'abilities_api_init', function () {
    wp_register_ability( 'my-ai/get-site-info', [
        'label'               => 'Get site info',
        'description'         => 'Returns basic WordPress site information.',
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'fields' => [
                    'type'  => 'array',
                    'items' => [ 'type' => 'string' ],
                ],
            ],
        ],
        'output_schema'       => [
            'type'       => 'object',
            'properties' => [
                'name'        => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string' ],
                'url'         => [ 'type' => 'string' ],
            ],
        ],
        'execute_callback'    => function ( $input ) {
            return [
                'name'        => get_bloginfo( 'name' ),
                'description' => get_bloginfo( 'description' ),
                'url'         => get_bloginfo( 'url' ),
            ];
        },
        'permission_callback' => function () {
            return current_user_can( 'read' );
        },
    ] );
} );