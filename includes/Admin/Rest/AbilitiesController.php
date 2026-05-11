<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin\Rest;

use Mrabbani\McpSiteManager\Plugin;
use Mrabbani\McpSiteManager\Support\DisabledAbilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AbilitiesController
{
    public const NAMESPACE = 'mcp-site-manager/v1';

    public static function register_routes(): void
    {
        $perm = [self::class, 'permission_check'];

        register_rest_route(self::NAMESPACE, '/abilities', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_list'],
            'permission_callback' => $perm,
        ]);
        register_rest_route(self::NAMESPACE, '/abilities/(?P<name>[a-z0-9-]+)/enabled', [
            'methods'             => 'PUT',
            'callback'            => [self::class, 'update_enabled'],
            'permission_callback' => $perm,
            'args' => [
                'enabled' => ['type' => 'boolean', 'required' => true],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/abilities/disabled', [
            'methods'             => 'DELETE',
            'callback'            => [self::class, 'reset'],
            'permission_callback' => $perm,
        ]);
    }

    public static function permission_check()
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', __('You need manage_options.', 'mcp-site-manager'), ['status' => rest_authorization_required_code()]);
        }
        return true;
    }

    public static function get_list(): WP_REST_Response
    {
        return new WP_REST_Response(self::snapshot());
    }

    public static function update_enabled(WP_REST_Request $r)
    {
        $name = (string) $r['name'];
        $enabled = (bool) $r->get_param('enabled');
        $known = self::all_local_ability_names();
        if (!in_array($name, $known, true)) {
            return new WP_Error('rest_unknown_ability', __('Unknown ability.', 'mcp-site-manager'), ['status' => 404]);
        }
        $disabled = DisabledAbilities::all();
        if ($enabled) {
            $disabled = array_values(array_diff($disabled, [$name]));
        } else {
            if (!in_array($name, $disabled, true)) $disabled[] = $name;
        }
        DisabledAbilities::set($disabled);
        return new WP_REST_Response(self::snapshot());
    }

    public static function reset(): WP_REST_Response
    {
        DisabledAbilities::clear();
        return new WP_REST_Response(self::snapshot());
    }

    /** Build the inventory snapshot consumed by all 3 endpoints. */
    public static function snapshot(): array
    {
        $disabled = DisabledAbilities::all();
        $items = [];
        foreach (Plugin::instance_bundles() as $bundle) {
            $bundle_label = self::bundle_label($bundle);
            foreach ($bundle->abilities() as $local => $spec) {
                $items[] = [
                    'id'          => $local,
                    'name'        => "mcpsm/$local",
                    'tool_name'   => 'mcpsm-' . $local,
                    'label'       => isset($spec['label']) ? (string) $spec['label'] : $local,
                    'description' => isset($spec['description']) ? (string) $spec['description'] : '',
                    'bundle'      => $bundle_label,
                    'enabled'     => !in_array($local, $disabled, true),
                ];
            }
        }
        usort($items, fn($a, $b) => strcmp($a['name'], $b['name']));
        return [
            'items'          => $items,
            'disabled_count' => count($disabled),
            'total'          => count($items),
        ];
    }

    /** @return string[] */
    private static function all_local_ability_names(): array
    {
        $names = [];
        foreach (Plugin::instance_bundles() as $bundle) {
            foreach (array_keys($bundle->abilities()) as $local) $names[] = $local;
        }
        return $names;
    }

    private static function bundle_label($bundle): string
    {
        $cls = get_class($bundle);
        $base = substr($cls, strrpos($cls, '\\') + 1);
        return preg_replace('/Bundle$/', '', $base);
    }
}
