<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Abilities;

use Mrabbani\McpSiteManager\Support\AbilityRunner;
use Mrabbani\McpSiteManager\Support\RestInvoker;

abstract class AbilityBundle
{
    /**
     * Map of local ability name (without "mcpsm/" prefix) to spec:
     *  [
     *    'label'              => string,
     *    'description'        => string,
     *    'input_schema'       => array,
     *    'output_schema'      => ?array,
     *    'permission_callback'=> callable,
     *    'execute'            => callable(array $args): mixed,
     *  ]
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public function abilities(): array;

    public function register(): void
    {
        // Note: we used to skip wp_register_ability() for disabled abilities,
        // but that excluded them from the Abilities API entirely. The
        // wp_register_ability_args filter in Plugin::maybe_hide_from_mcp now
        // flips meta.mcp.public=false instead, which hides disabled abilities
        // from the MCP default server while keeping them visible to any other
        // Abilities API consumer.
        foreach ($this->abilities() as $local => $spec) {
            $name = "mcpsm/$local";
            wp_register_ability($name, [
                'label'               => $spec['label'],
                'description'         => $spec['description'],
                'category'            => 'mcpsm',
                'input_schema'        => $spec['input_schema']  ?? ['type' => 'object', 'properties' => new \stdClass()],
                'output_schema'       => $spec['output_schema'] ?? ['type' => 'object'],
                'permission_callback' => $spec['permission_callback'] ?? '__return_true',
                'execute_callback'    => function ($args) use ($name, $spec) {
                    return AbilityRunner::run($name, fn() => ($spec['execute'])((array) $args));
                },
                'meta'                => array_merge(
                    [
                        'show_in_rest' => true,
                        'mcp'          => [
                            'public' => true,
                            'type'   => $spec['mcp_type'] ?? 'tool',
                        ],
                    ],
                    (array) ($spec['meta'] ?? [])
                ),
            ]);
        }
    }

    /**
     * Helper for REST-wrapping abilities: dispatches and unwraps WP_Error.
     */
    protected function rest(string $method, string $route, array $body = [], array $query = [])
    {
        return RestInvoker::dispatch($method, $route, $body, $query);
    }

    /** Permission callback for REST-wrapping abilities — REST enforces the real cap. */
    protected static function logged_in(): callable
    {
        return fn() => is_user_logged_in();
    }

    /** Permission callback for direct-PHP abilities. */
    protected static function require_cap(string ...$caps): callable
    {
        return function () use ($caps) {
            foreach ($caps as $cap) {
                if (!current_user_can($cap)) return false;
            }
            return true;
        };
    }
}
