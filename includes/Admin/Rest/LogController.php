<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin\Rest;

use Mrabbani\McpSiteManager\Admin\AbilityLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class LogController
{
    public const NAMESPACE = 'mcp-site-manager/v1';

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/log', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_list'],
            'permission_callback' => [self::class, 'permission_check'],
            'args' => [
                'page'     => ['type' => 'integer', 'default' => 1,  'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 200],
                'orderby'  => [
                    'type'    => 'string',
                    'default' => 'ts',
                    'enum'    => ['id', 'ts', 'ability', 'status', 'error_code', 'duration_ms', 'user_login'],
                ],
                'order'    => [
                    'type'    => 'string',
                    'default' => 'desc',
                    'enum'    => ['asc', 'desc', 'ASC', 'DESC'],
                ],
                'search'   => ['type' => 'string', 'default' => ''],
                'status'   => [
                    'type'    => 'string',
                    'default' => '',
                    'enum'    => ['', 'ok', 'error'],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/log/bulk-delete', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'bulk_delete'],
            'permission_callback' => [self::class, 'permission_check'],
            'args' => [
                'ids' => [
                    'type'     => 'array',
                    'required' => true,
                    'items'    => ['type' => 'integer'],
                ],
            ],
        ]);
    }

    public static function permission_check()
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', __('You need manage_options.', 'mcp-site-manager'), ['status' => rest_authorization_required_code()]);
        }
        return true;
    }

    public static function get_list(WP_REST_Request $r): WP_REST_Response
    {
        global $wpdb;
        $page     = max(1, (int) $r->get_param('page'));
        $per_page = max(1, min(200, (int) $r->get_param('per_page')));
        $offset   = ($page - 1) * $per_page;

        $orderby_param = (string) $r->get_param('orderby');
        $order         = strtoupper((string) $r->get_param('order')) === 'ASC' ? 'ASC' : 'DESC';
        $search        = trim((string) $r->get_param('search'));
        $status        = (string) $r->get_param('status');

        // Whitelist orderby → real SQL expression (table-qualified).
        $orderby_map = [
            'id'          => 'l.id',
            'ts'          => 'l.ts',
            'ability'     => 'l.ability',
            'status'      => 'l.status',
            'error_code'  => 'l.error_code',
            'duration_ms' => 'l.duration_ms',
            'user_login'  => 'u.user_login',
        ];
        $orderby_sql = $orderby_map[$orderby_param] ?? 'l.ts';

        $log_table   = AbilityLog::table_name();
        $users_table = $wpdb->users;

        $where  = '1=1';
        $params = [];

        if ($status === 'ok' || $status === 'error') {
            $where   .= ' AND l.status = %s';
            $params[] = $status;
        }

        if ($search !== '') {
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $where   .= ' AND (l.ability LIKE %s OR l.error_code LIKE %s OR u.user_login LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $count_sql = "SELECT COUNT(*) FROM $log_table l LEFT JOIN $users_table u ON u.ID = l.user_id WHERE $where";
        $total = empty($params)
            ? (int) $wpdb->get_var($count_sql)
            : (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));

        $list_sql = "SELECT l.id, l.ts, l.user_id, u.user_login, l.ability, l.status, l.error_code, l.duration_ms
             FROM $log_table l
             LEFT JOIN $users_table u ON u.ID = l.user_id
             WHERE $where
             ORDER BY $orderby_sql $order, l.id DESC
             LIMIT %d OFFSET %d";

        $rows = $wpdb->get_results(
            $wpdb->prepare($list_sql, ...array_merge($params, [$per_page, $offset])),
            ARRAY_A
        );

        $items = array_map(static function ($row) {
            return [
                'id'          => (int) $row['id'],
                'ts'          => (string) $row['ts'],
                'user_id'     => (int) $row['user_id'],
                'user_login'  => $row['user_login'] !== null ? (string) $row['user_login'] : '',
                'ability'     => (string) $row['ability'],
                'status'      => (string) $row['status'],
                'error_code'  => $row['error_code'] !== null ? (string) $row['error_code'] : '',
                'duration_ms' => (int) $row['duration_ms'],
            ];
        }, $rows ?: []);

        return new WP_REST_Response([
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ]);
    }

    public static function bulk_delete(WP_REST_Request $r): WP_REST_Response
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', (array) $r->get_param('ids')), static fn($i) => $i > 0));
        if (empty($ids)) {
            return new WP_REST_Response(['deleted' => 0]);
        }

        $table       = AbilityLog::table_name();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $deleted = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE id IN ($placeholders)",
            ...$ids
        ));

        return new WP_REST_Response(['deleted' => $deleted]);
    }
}
