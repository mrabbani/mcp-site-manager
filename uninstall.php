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

// One-time cleanup. Table name is $wpdb->prefix . internal constant; SchemaChange and DirectQuery are required for uninstall.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

global $wpdb;

$mcpsm_options = [
    'mcpsm_log_enabled',
    'mcpsm_disabled_abilities',
];

if (is_multisite()) {
    $mcpsm_site_ids = get_sites(['fields' => 'ids']);
    foreach ($mcpsm_site_ids as $mcpsm_site_id) {
        switch_to_blog((int) $mcpsm_site_id);
        foreach ($mcpsm_options as $mcpsm_option) {
            delete_option($mcpsm_option);
        }
        $mcpsm_table = $wpdb->prefix . 'mcpsm_log';
        $wpdb->query("DROP TABLE IF EXISTS `{$mcpsm_table}`");
        restore_current_blog();
    }
} else {
    foreach ($mcpsm_options as $mcpsm_option) {
        delete_option($mcpsm_option);
    }
    $mcpsm_table = $wpdb->prefix . 'mcpsm_log';
    $wpdb->query("DROP TABLE IF EXISTS `{$mcpsm_table}`");
}

// phpcs:enable
