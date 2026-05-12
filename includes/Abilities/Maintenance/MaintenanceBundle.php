<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Abilities\Maintenance;

defined('ABSPATH') || exit;

use Mrabbani\McpSiteManager\Abilities\AbilityBundle;
use Mrabbani\McpSiteManager\Support\SchemaBuilder as S;

final class MaintenanceBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'cache-flush-rewrite' => [
                'label'       => __('Flush rewrite rules', 'mcp-site-manager'),
                'description' => __('Re-generate URL rewrite rules.', 'mcp-site-manager'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::require_cap('manage_options'),
                'execute' => function () { flush_rewrite_rules(false); return ['flushed' => true]; },
            ],
            'cache-flush-object' => [
                'label'       => __('Flush object cache', 'mcp-site-manager'),
                'description' => __('Clear the WordPress object cache.', 'mcp-site-manager'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::require_cap('manage_options'),
                'execute' => function () { wp_cache_flush(); return ['flushed' => true]; },
            ],
            'cron-list' => [
                'label'       => __('List scheduled cron events', 'mcp-site-manager'),
                'description' => __('All scheduled WP-cron events.', 'mcp-site-manager'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::require_cap('manage_options'),
                'execute' => function () {
                    $cron = _get_cron_array() ?: [];
                    $items = [];
                    foreach ($cron as $ts => $hooks) {
                        foreach ($hooks as $hook => $events) {
                            foreach ($events as $event) {
                                $items[] = [
                                    'timestamp' => (int) $ts,
                                    'time'      => gmdate('c', (int) $ts),
                                    'hook'      => $hook,
                                    'schedule'  => $event['schedule'] ?? null,
                                    'args'      => $event['args'] ?? [],
                                ];
                            }
                        }
                    }
                    return ['items' => $items, 'total' => count($items)];
                },
            ],
            'cron-run' => [
                'label'       => __('Spawn cron', 'mcp-site-manager'),
                'description' => __('Trigger an immediate cron run.', 'mcp-site-manager'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::require_cap('manage_options'),
                'execute' => function () { spawn_cron(); return ['spawned' => true]; },
            ],
            'cron-unschedule' => [
                'label'       => __('Unschedule cron event', 'mcp-site-manager'),
                'description' => __('Remove a scheduled event.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'timestamp' => S::int('Event timestamp', true),
                    'hook'      => S::str('Hook name', true),
                    'args'      => S::arr(['type' => 'string'], 'Event args (must match exactly)'),
                ]),
                'permission_callback' => self::require_cap('manage_options'),
                'execute' => function (array $a) {
                    $r = wp_unschedule_event((int) $a['timestamp'], (string) $a['hook'], (array) ($a['args'] ?? []));
                    if (is_wp_error($r)) return $r;
                    return ['unscheduled' => (bool) $r];
                },
            ],
        ];
    }
}
