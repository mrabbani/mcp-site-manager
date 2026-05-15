<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Abilities\Themes;

defined('ABSPATH') || exit;

use Mrabbani\McpSiteManager\Abilities\AbilityBundle;
use Mrabbani\McpSiteManager\Support\SchemaBuilder as S;
use Mrabbani\McpSiteManager\Support\UrlGuard;

final class ThemesBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'themes-list' => [
                'label'       => __('List themes', 'mcp-site-manager'),
                'description' => __('All installed themes with active flag, version, parent.', 'mcp-site-manager'),
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
            'themes-active' => [
                'label'       => __('Get active theme', 'mcp-site-manager'),
                'description' => __('Return the currently active theme with stylesheet, name, version, parent, supports, theme.json path, and FSE template/parts directories.', 'mcp-site-manager'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::require_cap('switch_themes'),
                'execute' => function () {
                    $theme    = wp_get_theme();
                    $parent   = $theme->parent();
                    $dir      = $theme->get_stylesheet_directory();
                    $tjson    = file_exists($dir . '/theme.json') ? $dir . '/theme.json' : null;
                    $is_block = function_exists('wp_is_block_theme') ? wp_is_block_theme() : false;

                    return [
                        'stylesheet'        => $theme->get_stylesheet(),
                        'template'          => $theme->get_template(),
                        'name'              => $theme->get('Name'),
                        'version'           => $theme->get('Version'),
                        'description'       => wp_strip_all_tags((string) $theme->get('Description')),
                        'author'            => wp_strip_all_tags((string) $theme->get('Author')),
                        'theme_uri'         => (string) $theme->get('ThemeURI'),
                        'text_domain'       => (string) $theme->get('TextDomain'),
                        'requires_wp'       => (string) $theme->get('RequiresWP'),
                        'requires_php'      => (string) $theme->get('RequiresPHP'),
                        'parent'            => $parent ? $parent->get_stylesheet() : null,
                        'is_block_theme'    => (bool) $is_block,
                        'theme_supports'    => array_values(array_filter([
                            'post-thumbnails', 'title-tag', 'wp-block-styles', 'editor-styles',
                            'responsive-embeds', 'html5', 'align-wide', 'block-templates',
                            'block-template-parts', 'appearance-tools',
                        ], 'current_theme_supports')),
                        'paths'             => [
                            'stylesheet_dir'  => $dir,
                            'template_dir'    => $theme->get_template_directory(),
                            'theme_json'      => $tjson,
                            'templates_dir'   => is_dir($dir . '/templates') ? $dir . '/templates' : null,
                            'parts_dir'       => is_dir($dir . '/parts') ? $dir . '/parts' : null,
                            'patterns_dir'    => is_dir($dir . '/patterns') ? $dir . '/patterns' : null,
                            'styles_dir'      => is_dir($dir . '/styles') ? $dir . '/styles' : null,
                        ],
                        'urls'              => [
                            'stylesheet_uri'  => $theme->get_stylesheet_directory_uri(),
                            'template_uri'    => $theme->get_template_directory_uri(),
                        ],
                    ];
                },
            ],
            'themes-switch' => [
                'label'       => __('Switch theme', 'mcp-site-manager'),
                'description' => __('Activate a theme by its stylesheet directory name.', 'mcp-site-manager'),
                'input_schema'=> S::object(['stylesheet' => S::str('Stylesheet directory', true)]),
                'permission_callback' => self::require_cap('switch_themes'),
                'execute' => function (array $a) {
                    $stylesheet = (string) $a['stylesheet'];
                    $theme = wp_get_theme($stylesheet);
                    if (!$theme->exists()) return new \WP_Error('mcpsm_theme_missing', 'Theme not installed', ['status' => 404]);
                    switch_theme($stylesheet);
                    return ['stylesheet' => $stylesheet, 'active' => true];
                },
            ],
            'themes-install' => [
                'label'       => __('Install theme', 'mcp-site-manager'),
                'description' => __('Install a theme from a wp.org slug or zip URL.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'slug'    => S::str('wp.org theme slug'),
                    'zip_url' => S::str('Public zip URL'),
                    'activate'=> S::bool('Activate after install'),
                ]),
                'permission_callback' => self::require_cap('install_themes'),
                'execute' => fn(array $a) => $this->install($a),
            ],
            'themes-update' => [
                'label'       => __('Update theme', 'mcp-site-manager'),
                'description' => __('Run the theme updater for one theme.', 'mcp-site-manager'),
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
                'label'       => __('Delete theme', 'mcp-site-manager'),
                'description' => __('Delete an installed theme (must not be active).', 'mcp-site-manager'),
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
            return new \WP_Error('mcpsm_theme_input', 'Provide slug or zip_url', ['status' => 400]);
        }
        $source = $a['zip_url'] ?? null;
        if (!$source) {
            $api = themes_api('theme_information', ['slug' => $a['slug'], 'fields' => ['sections' => false]]);
            if (is_wp_error($api)) return $api;
            $source = $api->download_link;
        } else {
            $guard = self::guard_install_url((string) $source);
            if (is_wp_error($guard)) return $guard;
        }
        $upgrader = new \Theme_Upgrader(new \WP_Ajax_Upgrader_Skin());
        $r = $upgrader->install($source);
        if (is_wp_error($r) || $r === false) {
            return is_wp_error($r) ? $r : new \WP_Error('mcpsm_theme_install_failed', 'Install failed', ['status' => 500]);
        }
        $stylesheet = $upgrader->theme_info() ? $upgrader->theme_info()->get_stylesheet() : null;
        $out = ['stylesheet' => $stylesheet, 'installed' => true, 'active' => false];
        if (!empty($a['activate']) && $stylesheet) {
            switch_theme($stylesheet);
            $out['active'] = true;
        }
        return $out;
    }

    /**
     * SSRF + host allowlist for caller-supplied zip_url. Mirrors PluginsBundle:
     * https-only, default trusted hosts, filterable via
     * `mcpsm_theme_install_allowed_hosts`. Plain-http downloads of executable
     * code are never accepted.
     */
    private static function guard_install_url(string $url)
    {
        /**
         * Filter the host allowlist for caller-supplied theme install zip_url.
         *
         * @param string[] $hosts Default trusted hosts.
         */
        $hosts = apply_filters('mcpsm_theme_install_allowed_hosts', [
            'downloads.wordpress.org',
            'github.com',
        ]);
        return UrlGuard::validate($url, [
            'https_only'    => true,
            'allowed_hosts' => is_array($hosts) ? $hosts : [],
            'error_code'    => 'mcpsm_theme_install_blocked',
        ]);
    }
}
