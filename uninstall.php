<?php
/**
 * Uninstall handler for MCP Site Manager.
 *
 * Runs when the plugin is deleted (not on deactivation). Drops plugin-owned
 * options and the ability log table so no orphaned data is left behind.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$options = [
    'mcpsm_log_enabled',
    'mcpsm_disabled_abilities',
];

if (is_multisite()) {
    $site_ids = get_sites(['fields' => 'ids']);
    foreach ($site_ids as $site_id) {
        switch_to_blog((int) $site_id);
        foreach ($options as $option) {
            delete_option($option);
        }
        $table = $wpdb->prefix . 'mcpsm_log';
        $wpdb->query("DROP TABLE IF EXISTS `$table`");
        restore_current_blog();
    }
} else {
    foreach ($options as $option) {
        delete_option($option);
    }
    $table = $wpdb->prefix . 'mcpsm_log';
    $wpdb->query("DROP TABLE IF EXISTS `$table`");
}
