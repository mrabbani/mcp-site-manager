<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin;

defined('ABSPATH') || exit;

// All queries below hit the plugin's own log table ($wpdb->prefix . internal constant).
// Caching is intentionally bypassed: the table is the source of truth for fresh ability
// invocation telemetry and admin reads need real-time data.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

final class AbilityLog
{
    public const TABLE_SUFFIX = 'mcpsm_log';
    public const OPTION_ENABLED = 'mcpsm_log_enabled';
    public const TRIM_AT = 1000;

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ability VARCHAR(190) NOT NULL,
            status VARCHAR(8) NOT NULL,
            error_code VARCHAR(32) NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY ts (ts),
            KEY user_id (user_id),
            KEY ability (ability)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        if (get_option(self::OPTION_ENABLED, null) === null) {
            update_option(self::OPTION_ENABLED, 1);
        }
    }

    public static function enabled(): bool
    {
        return (bool) get_option(self::OPTION_ENABLED, 1);
    }

    public static function record(string $ability, string $status, ?string $error_code, int $duration_ms): void
    {
        if (!self::enabled()) return;
        global $wpdb;
        $wpdb->insert(self::table_name(), [
            'ts'          => current_time('mysql'),
            'user_id'     => get_current_user_id(),
            'ability'     => $ability,
            'status'      => $status,
            'error_code'  => $error_code,
            'duration_ms' => $duration_ms,
        ]);
        self::trim();
    }

    private static function trim(): void
    {
        global $wpdb;
        $table = self::table_name();
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($count <= self::TRIM_AT) return;
        $threshold_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET %d",
            self::TRIM_AT
        ));
        if ($threshold_id > 0) {
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id <= %d", $threshold_id));
        }
    }

    public static function recent(int $limit = 50): array
    {
        global $wpdb;
        $table = self::table_name();
        $limit = max(1, min(500, $limit));
        return (array) $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );
    }

    public static function clear(): void
    {
        global $wpdb;
        $table = self::table_name();
        // Table name is built from $wpdb->prefix and an internal constant; cannot be user input.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query("TRUNCATE TABLE `{$table}`");
    }
}
