<?php
declare(strict_types=1);

namespace SiteMcp\Abilities\Themes;

use SiteMcp\Abilities\AbilityBundle;
use SiteMcp\Support\SchemaBuilder as S;

final class ThemesBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'themes-list' => [
                'label'       => __('List themes', 'site-mcp'),
                'description' => __('All installed themes with active flag, version, parent.', 'site-mcp'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::require_cap('switch_themes'),
                'execute' => function () {
                    $items = [];
                    $current = wp_get_theme();
                    foreach (wp_get_themes() as $stylesheet => $theme) {
                        $items[] = [
                            'stylesheet' => $stylesheet,
                            'name'       => $theme->get('Name'),
                            'version'    => $theme->get('Version'),
                            'parent'     => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
                            'active'     => $stylesheet === $current->get_stylesheet(),
                        ];
                    }
                    return ['items' => $items, 'total' => count($items)];
                },
            ],
            'themes-switch' => [
                'label'       => __('Switch theme', 'site-mcp'),
                'description' => __('Activate a theme by its stylesheet directory name.', 'site-mcp'),
                'input_schema'=> S::object(['stylesheet' => S::str('Stylesheet directory', true)]),
                'permission_callback' => self::require_cap('switch_themes'),
                'execute' => function (array $a) {
                    $stylesheet = (string) $a['stylesheet'];
                    $theme = wp_get_theme($stylesheet);
                    if (!$theme->exists()) return new \WP_Error('site_mcp_theme_missing', 'Theme not installed', ['status' => 404]);
                    switch_theme($stylesheet);
                    return ['stylesheet' => $stylesheet, 'active' => true];
                },
            ],
            'themes-install' => [
                'label'       => __('Install theme', 'site-mcp'),
                'description' => __('Install a theme from a wp.org slug or zip URL.', 'site-mcp'),
                'input_schema'=> S::object([
                    'slug'    => S::str('wp.org theme slug'),
                    'zip_url' => S::str('Public zip URL'),
                    'activate'=> S::bool('Activate after install'),
                ]),
                'permission_callback' => self::require_cap('install_themes'),
                'execute' => fn(array $a) => $this->install($a),
            ],
            'themes-update' => [
                'label'       => __('Update theme', 'site-mcp'),
                'description' => __('Run the theme updater for one theme.', 'site-mcp'),
                'input_schema'=> S::object(['stylesheet' => S::str('Stylesheet directory', true)]),
                'permission_callback' => self::require_cap('update_themes'),
                'execute' => function (array $a) {
                    $this->load();
                    wp_update_themes();
                    $upgrader = new \Theme_Upgrader(new \WP_Ajax_Upgrader_Skin());
                    $r = $upgrader->upgrade((string) $a['stylesheet']);
                    if (is_wp_error($r)) return $r;
                    return ['stylesheet' => $a['stylesheet'], 'updated' => (bool) $r];
                },
            ],
            'themes-delete' => [
                'label'       => __('Delete theme', 'site-mcp'),
                'description' => __('Delete an installed theme (must not be active).', 'site-mcp'),
                'input_schema'=> S::object(['stylesheet' => S::str('Stylesheet directory', true)]),
                'permission_callback' => self::require_cap('delete_themes'),
                'execute' => function (array $a) {
                    $this->load();
                    $r = delete_theme((string) $a['stylesheet']);
                    if (is_wp_error($r)) return $r;
                    return ['stylesheet' => $a['stylesheet'], 'deleted' => (bool) $r];
                },
            ],
        ];
    }

    private function load(): void
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }

    private function install(array $a)
    {
        $this->load();
        if (empty($a['slug']) && empty($a['zip_url'])) {
            return new \WP_Error('site_mcp_theme_input', 'Provide slug or zip_url', ['status' => 400]);
        }
        $source = $a['zip_url'] ?? null;
        if (!$source) {
            $api = themes_api('theme_information', ['slug' => $a['slug'], 'fields' => ['sections' => false]]);
            if (is_wp_error($api)) return $api;
            $source = $api->download_link;
        }
        $upgrader = new \Theme_Upgrader(new \WP_Ajax_Upgrader_Skin());
        $r = $upgrader->install($source);
        if (is_wp_error($r) || $r === false) {
            return is_wp_error($r) ? $r : new \WP_Error('site_mcp_theme_install_failed', 'Install failed', ['status' => 500]);
        }
        $stylesheet = $upgrader->theme_info() ? $upgrader->theme_info()->get_stylesheet() : null;
        $out = ['stylesheet' => $stylesheet, 'installed' => true, 'active' => false];
        if (!empty($a['activate']) && $stylesheet) {
            switch_theme($stylesheet);
            $out['active'] = true;
        }
        return $out;
    }
}
