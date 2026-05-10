<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Abilities\Options;

use Mrabbani\McpSiteManager\Abilities\AbilityBundle;
use Mrabbani\McpSiteManager\Support\OptionsAllowlist;
use Mrabbani\McpSiteManager\Support\SchemaBuilder as S;

final class OptionsBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'options-list' => [
                'label'       => __('List allowed options', 'mcp-site-manager'),
                'description' => __('Returns the allowlisted site options and current values.', 'mcp-site-manager'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::require_cap('manage_options'),
                'execute' => function () {
                    $items = [];
                    foreach (OptionsAllowlist::keys() as $k) {
                        $items[$k] = get_option($k);
                    }
                    return ['items' => $items, 'allowed_keys' => OptionsAllowlist::keys()];
                },
            ],
            'options-get' => [
                'label'       => __('Get an option', 'mcp-site-manager'),
                'description' => __('Get one allowlisted option.', 'mcp-site-manager'),
                'input_schema'=> S::object(['key' => S::str('Option key', true)]),
                'permission_callback' => self::require_cap('manage_options'),
                'execute' => function (array $a) {
                    if (!OptionsAllowlist::contains($a['key'])) {
                        return new \WP_Error('mcpsm_option_denied', 'Option not in allowlist', ['status' => 403, 'allowed_keys' => OptionsAllowlist::keys()]);
                    }
                    return ['key' => $a['key'], 'value' => get_option($a['key'])];
                },
            ],
            'options-update' => [
                'label'       => __('Update an option', 'mcp-site-manager'),
                'description' => __('Update one allowlisted option.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'key'   => S::str('Option key', true),
                    'value' => ['description' => 'New value (string|number|bool depending on option)', '__required' => true],
                ]),
                'permission_callback' => self::require_cap('manage_options'),
                'execute' => function (array $a) {
                    if (!OptionsAllowlist::contains($a['key'])) {
                        return new \WP_Error('mcpsm_option_denied', 'Option not in allowlist', ['status' => 403, 'allowed_keys' => OptionsAllowlist::keys()]);
                    }
                    $ok = update_option($a['key'], $a['value']);
                    return ['key' => $a['key'], 'updated' => (bool) $ok, 'value' => get_option($a['key'])];
                },
            ],
        ];
    }
}
