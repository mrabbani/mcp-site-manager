<?php
/**
 * Plugin Name:       MCP Site Manager
 * Plugin URI:        https://github.com/mrabbani/mcp-site-manager
 * Description:       Manage WordPress from Claude, ChatGPT, Cursor and other MCP clients. Exposes posts, pages, taxonomies, media, plugins, themes, options, menus, diagnostics and maintenance as MCP tools via the MCP Adapter.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Author:            mrabbani
 * Author URI:        https://profiles.wordpress.org/mrabbani/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mcp-site-manager
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('MCPSM_VERSION', '0.1.0');
define('MCPSM_FILE', __FILE__);
define('MCPSM_DIR', plugin_dir_path(__FILE__));
define('MCPSM_URL', plugin_dir_url(__FILE__));

$mcpsm_autoload = MCPSM_DIR . 'vendor/autoload.php';
if (!file_exists($mcpsm_autoload)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('MCP Site Manager: composer dependencies missing. Run `composer install` in the plugin directory.', 'mcp-site-manager');
        echo '</p></div>';
    });
    return;
}
require_once $mcpsm_autoload;

register_activation_hook(__FILE__, [\Mrabbani\McpSiteManager\Plugin::class, 'on_activate']);
register_deactivation_hook(__FILE__, [\Mrabbani\McpSiteManager\Plugin::class, 'on_deactivate']);

add_action('plugins_loaded', [\Mrabbani\McpSiteManager\Plugin::class, 'boot'], 5);
