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
        // Allow slashes in ability names (e.g. mcpsm/posts-list, woocommerce/foo).
        register_rest_route(self::NAMESPACE, '/abilities/(?P<name>[a-z0-9/_-]+)/enabled', [
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
        register_rest_route(self::NAMESPACE, '/abilities/bulk-enabled', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'bulk_update_enabled'],
            'permission_callback' => $perm,
            'args' => [
                'ids'     => ['type' => 'array', 'required' => true, 'items' => ['type' => 'string']],
                'enabled' => ['type' => 'boolean', 'required' => true],
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

    public static function get_list(): WP_REST_Response
    {
        return new WP_REST_Response(self::snapshot());
    }

    public static function update_enabled(WP_REST_Request $r)
    {
        $name    = self::normalize_name((string) $r['name']);
        $enabled = (bool) $r->get_param('enabled');
        $known   = self::all_registered_ability_names();
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

    public static function bulk_update_enabled(WP_REST_Request $r)
    {
        $ids = array_values(array_map([self::class, 'normalize_name'], array_filter((array) $r->get_param('ids'), 'is_string')));
        $enabled = (bool) $r->get_param('enabled');
        $known = self::all_registered_ability_names();
        $unknown = array_values(array_diff($ids, $known));
        if (!empty($unknown)) {
            return new WP_Error('rest_unknown_ability', __('Unknown ability.', 'mcp-site-manager'), ['status' => 404, 'unknown' => $unknown]);
        }
        $disabled = DisabledAbilities::all();
        if ($enabled) {
            $disabled = array_values(array_diff($disabled, $ids));
        } else {
            $disabled = array_values(array_unique(array_merge($disabled, $ids)));
        }
        DisabledAbilities::set($disabled);
        return new WP_REST_Response(self::snapshot());
    }

    /**
     * Accept both legacy local names (e.g. "posts-list") and fully-qualified
     * names (e.g. "mcpsm/posts-list"). Bare names are treated as mcpsm/<name>
     * for backward compatibility with prior DisabledAbilities storage.
     */
    private static function normalize_name(string $name): string
    {
        return strpos($name, '/') === false ? "mcpsm/$name" : $name;
    }

    public static function reset(): WP_REST_Response
    {
        DisabledAbilities::clear();
        return new WP_REST_Response(self::snapshot());
    }

    /**
     * Build the inventory snapshot consumed by all endpoints.
     *
     * Enumerates every registered ability via wp_get_abilities() so third-party
     * abilities (e.g. woocommerce/*) are surfaced alongside our own mcpsm/*.
     * Falls back to bundle iteration for our abilities when wp_get_abilities
     * isn't available (older WP without Abilities API).
     */
    public static function snapshot(): array
    {
        $disabled  = DisabledAbilities::all();
        $bundle_of = self::mcpsm_bundle_map();
        $items     = [];

        if (function_exists('wp_get_abilities')) {
            foreach (wp_get_abilities() as $name => $ability) {
                $name      = (string) $name;
                $namespace = (string) strstr($name, '/', true) ?: '';
                $label     = method_exists($ability, 'get_label')       ? (string) $ability->get_label()       : $name;
                $desc      = method_exists($ability, 'get_description') ? (string) $ability->get_description() : '';
                $items[] = [
                    'id'          => $name,
                    'name'        => $name,
                    'tool_name'   => str_replace('/', '-', $name),
                    'label'       => $label,
                    'description' => $desc,
                    'namespace'   => $namespace ?: 'unknown',
                    'bundle'      => $bundle_of[$name] ?? null,
                    'enabled'     => !in_array($name, $disabled, true),
                ];
            }
        } else {
            // Fallback: only our abilities, treated as enabled (the filter never runs).
            foreach (Plugin::instance_bundles() as $bundle) {
                $bundle_label = self::bundle_label($bundle);
                foreach ($bundle->abilities() as $local => $spec) {
                    $name = "mcpsm/$local";
                    $items[] = [
                        'id'          => $name,
                        'name'        => $name,
                        'tool_name'   => 'mcpsm-' . $local,
                        'label'       => isset($spec['label']) ? (string) $spec['label'] : $local,
                        'description' => isset($spec['description']) ? (string) $spec['description'] : '',
                        'namespace'   => 'mcpsm',
                        'bundle'      => $bundle_label,
                        'enabled'     => !in_array($name, $disabled, true),
                    ];
                }
            }
        }

        usort($items, fn($a, $b) => strcmp($a['name'], $b['name']));
        return [
            'items'          => $items,
            'disabled_count' => count($disabled),
            'total'          => count($items),
        ];
    }

    /** @return string[] Fully-qualified names of every registered ability. */
    private static function all_registered_ability_names(): array
    {
        if (function_exists('wp_get_abilities')) {
            return array_map('strval', array_keys(wp_get_abilities()));
        }
        // Fallback: our bundles.
        $names = [];
        foreach (Plugin::instance_bundles() as $bundle) {
            foreach (array_keys($bundle->abilities()) as $local) $names[] = "mcpsm/$local";
        }
        return $names;
    }

    /** @return array<string,string> name => bundle label, for our abilities only. */
    private static function mcpsm_bundle_map(): array
    {
        $map = [];
        foreach (Plugin::instance_bundles() as $bundle) {
            $label = self::bundle_label($bundle);
            foreach (array_keys($bundle->abilities()) as $local) {
                $map["mcpsm/$local"] = $label;
            }
        }
        return $map;
    }

    private static function bundle_label($bundle): string
    {
        $cls = get_class($bundle);
        $base = substr($cls, strrpos($cls, '\\') + 1);
        return preg_replace('/Bundle$/', '', $base);
    }
}
