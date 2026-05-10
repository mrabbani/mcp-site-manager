<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Abilities\Plugins;

use Mrabbani\McpSiteManager\Abilities\AbilityBundle;
use Mrabbani\McpSiteManager\Support\SchemaBuilder as S;

final class PluginsBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'plugins-list' => [
                'label'       => __('List installed plugins', 'mcp-site-manager'),
                'description' => __('All installed plugins with status, version, description.', 'mcp-site-manager'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::require_cap('activate_plugins'),
                'execute' => fn() => $this->list_installed(),
            ],
            'plugins-activate' => [
                'label'       => __('Activate plugin', 'mcp-site-manager'),
                'description' => __('Activate by plugin file path (e.g. akismet/akismet.php).', 'mcp-site-manager'),
                'input_schema'=> S::object(['plugin' => S::str('Plugin file path', true)]),
                'permission_callback' => self::require_cap('activate_plugins'),
                'execute' => function (array $a) {
                    $this->load_plugin_php();
                    $r = activate_plugin((string) $a['plugin']);
                    if (is_wp_error($r)) return $r;
                    return ['plugin' => $a['plugin'], 'active' => true];
                },
            ],
            'plugins-deactivate' => [
                'label'       => __('Deactivate plugin', 'mcp-site-manager'),
                'description' => __('Deactivate one plugin by file path.', 'mcp-site-manager'),
                'input_schema'=> S::object(['plugin' => S::str('Plugin file path', true)]),
                'permission_callback' => self::require_cap('activate_plugins'),
                'execute' => function (array $a) {
                    $this->load_plugin_php();
                    deactivate_plugins([(string) $a['plugin']]);
                    return ['plugin' => $a['plugin'], 'active' => false];
                },
            ],
            'plugins-install' => [
                'label'       => __('Install plugin', 'mcp-site-manager'),
                'description' => __('Install from WordPress.org slug or a zip URL.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'slug'    => S::str('WordPress.org plugin slug'),
                    'zip_url' => S::str('Public zip URL (alternative to slug)'),
                    'activate'=> S::bool('Activate after install'),
                ]),
                'permission_callback' => self::require_cap('install_plugins'),
                'execute' => fn(array $a) => $this->install($a),
            ],
            'plugins-update' => [
                'label'       => __('Update plugin', 'mcp-site-manager'),
                'description' => __('Run the plugin updater for one plugin.', 'mcp-site-manager'),
                'input_schema'=> S::object(['plugin' => S::str('Plugin file path', true)]),
                'permission_callback' => self::require_cap('update_plugins'),
                'execute' => fn(array $a) => $this->update((string) $a['plugin']),
            ],
            'plugins-delete' => [
                'label'       => __('Delete plugin', 'mcp-site-manager'),
                'description' => __('Delete an installed plugin (must be inactive).', 'mcp-site-manager'),
                'input_schema'=> S::object(['plugin' => S::str('Plugin file path', true)]),
                'permission_callback' => self::require_cap('delete_plugins'),
                'execute' => function (array $a) {
                    $this->load_plugin_php();
                    $ok = delete_plugins([(string) $a['plugin']]);
                    if (is_wp_error($ok)) return $ok;
                    return ['plugin' => $a['plugin'], 'deleted' => (bool) $ok];
                },
            ],
            'plugins-search' => [
                'label'       => __('Search WordPress.org plugins', 'mcp-site-manager'),
                'description' => __('Search the WP.org plugin directory.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'query'    => S::str('Search query', true),
                    'per_page' => S::int('Results per page', false, 1, 50),
                ]),
                'permission_callback' => self::require_cap('install_plugins'),
                'execute' => fn(array $a) => $this->search($a),
            ],
        ];
    }

    private function load_plugin_php(): void
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }

    private function list_installed(): array
    {
        $this->load_plugin_php();
        $items = [];
        foreach (get_plugins() as $file => $data) {
            $items[] = [
                'plugin'      => $file,
                'name'        => $data['Name'],
                'version'     => $data['Version'],
                'description' => wp_strip_all_tags($data['Description']),
                'author'      => wp_strip_all_tags($data['Author']),
                'active'      => is_plugin_active($file),
                'network'     => is_plugin_active_for_network($file),
            ];
        }
        return ['items' => $items, 'total' => count($items)];
    }

    private function install(array $a)
    {
        $this->load_plugin_php();
        if (empty($a['slug']) && empty($a['zip_url'])) {
            return new \WP_Error('mcpsm_plugin_input', 'Provide slug or zip_url', ['status' => 400]);
        }
        $source = $a['zip_url'] ?? null;
        if (!$source) {
            $api = plugins_api('plugin_information', ['slug' => $a['slug'], 'fields' => ['sections' => false]]);
            if (is_wp_error($api)) return $api;
            $source = $api->download_link;
        }
        $upgrader = new \Plugin_Upgrader(new \WP_Ajax_Upgrader_Skin());
        $r = $upgrader->install($source);
        if (is_wp_error($r) || $r === false) {
            return is_wp_error($r) ? $r : new \WP_Error('mcpsm_plugin_install_failed', 'Install failed', ['status' => 500]);
        }
        $installed_file = $upgrader->plugin_info();
        $out = ['plugin' => $installed_file, 'installed' => true, 'active' => false];
        if (!empty($a['activate']) && $installed_file) {
            $r = activate_plugin($installed_file);
            if (!is_wp_error($r)) $out['active'] = true;
        }
        return $out;
    }

    private function update(string $plugin)
    {
        $this->load_plugin_php();
        wp_update_plugins();
        $upgrader = new \Plugin_Upgrader(new \WP_Ajax_Upgrader_Skin());
        $r = $upgrader->upgrade($plugin);
        if (is_wp_error($r)) return $r;
        return ['plugin' => $plugin, 'updated' => (bool) $r];
    }

    private function search(array $a)
    {
        $this->load_plugin_php();
        $api = plugins_api('query_plugins', [
            'search'   => (string) $a['query'],
            'per_page' => (int) ($a['per_page'] ?? 10),
            'fields'   => ['short_description' => true, 'icons' => false, 'sections' => false],
        ]);
        if (is_wp_error($api)) return $api;
        $items = [];
        foreach (($api->plugins ?? []) as $p) {
            $items[] = [
                'slug'    => $p->slug ?? null,
                'name'    => $p->name ?? null,
                'version' => $p->version ?? null,
                'rating'  => $p->rating ?? null,
                'short_description' => $p->short_description ?? null,
            ];
        }
        return ['items' => $items, 'total' => (int) ($api->info['results'] ?? count($items))];
    }
}
