<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin\Rest;

defined('ABSPATH') || exit;

use Mrabbani\McpSiteManager\Admin\Stats;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

final class StatsController
{
    public const NAMESPACE = 'mcp-site-manager/v1';

    public static function register_routes(): void
    {
        $perm = [self::class, 'permission_check'];

        register_rest_route(self::NAMESPACE, '/stats/counts', [
            'methods'  => 'GET',
            'callback' => fn() => new WP_REST_Response(Stats::counts()),
            'permission_callback' => $perm,
        ]);
        register_rest_route(self::NAMESPACE, '/stats/latency', [
            'methods'  => 'GET',
            'callback' => fn() => new WP_REST_Response(Stats::latency()),
            'permission_callback' => $perm,
        ]);
        register_rest_route(self::NAMESPACE, '/stats/top-abilities', [
            'methods'  => 'GET',
            'callback' => function (WP_REST_Request $r) {
                $limit = max(1, min(100, (int) ($r->get_param('limit') ?? 10)));
                return new WP_REST_Response(Stats::top_abilities($limit));
            },
            'permission_callback' => $perm,
            'args' => [
                'limit' => [
                    'type'              => 'integer',
                    'default'           => 10,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/stats/recent-errors', [
            'methods'  => 'GET',
            'callback' => function (WP_REST_Request $r) {
                $limit = max(1, min(100, (int) ($r->get_param('limit') ?? 20)));
                return new WP_REST_Response(Stats::recent_errors($limit));
            },
            'permission_callback' => $perm,
            'args' => [
                'limit' => [
                    'type'              => 'integer',
                    'default'           => 20,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/stats/window', [
            'methods'  => 'GET',
            'callback' => fn() => new WP_REST_Response(Stats::window()),
            'permission_callback' => $perm,
        ]);
        register_rest_route(self::NAMESPACE, '/stats/all', [
            'methods'  => 'GET',
            'callback' => fn() => new WP_REST_Response(Stats::all()),
            'permission_callback' => $perm,
        ]);
    }

    public static function permission_check()
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('You need manage_options to view MCP Site Manager stats.', 'mcp-site-manager'),
                ['status' => rest_authorization_required_code()]
            );
        }
        return true;
    }
}
