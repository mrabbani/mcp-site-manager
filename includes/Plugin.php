<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager;

final class Plugin
{
    private static ?self $instance = null;

    public static function boot(): void
    {
        if (self::$instance !== null) {
            return;
        }
        self::$instance = new self();
        self::$instance->register_hooks();
    }

    public static function on_activate(): void
    {
        Admin\AbilityLog::install_table();
        if (!self::dependencies_met()) {
            deactivate_plugins(plugin_basename(MCPSM_FILE));
            wp_die(
                esc_html__('MCP Site Manager requires the MCP Adapter plugin and the Abilities API (WordPress 6.8+).', 'mcp-site-manager'),
                esc_html__('Plugin activation failed', 'mcp-site-manager'),
                ['back_link' => true]
            );
        }
    }

    public static function on_deactivate(): void
    {
        // Intentionally non-destructive: keep log table and options.
    }

    public static function dependencies_met(): bool
    {
        return function_exists('wp_register_ability')
            && class_exists('\\WP\\MCP\\Core\\McpAdapter');
    }

    private function register_hooks(): void
    {
        if (!self::dependencies_met()) {
            add_action('admin_notices', [$this, 'render_missing_deps_notice']);
            return;
        }

        add_action('wp_abilities_api_categories_init', [$this, 'register_category']);
        add_action('wp_abilities_api_init', [$this, 'register_abilities']);
        add_filter('mcp_adapter_default_server_config', [$this, 'extend_default_server']);
        add_action('admin_menu', [Admin\SettingsPage::class, 'register']);
        add_action('rest_api_init', [\Mrabbani\McpSiteManager\Admin\Rest\StatsController::class, 'register_routes']);
        add_action('admin_enqueue_scripts', [\Mrabbani\McpSiteManager\Admin\DashboardAssets::class, 'maybe_enqueue']);
    }

    public function register_abilities(): void
    {
        foreach ($this->bundles() as $bundle) {
            $bundle->register();
        }
    }

    public function register_category(): void
    {
        wp_register_ability_category('mcpsm', [
            'label'       => __('MCP Site Manager', 'mcp-site-manager'),
            'description' => __('WordPress site management abilities exposed to MCP clients.', 'mcp-site-manager'),
        ]);
    }

    /**
     * @param mixed $config
     */
    public function extend_default_server($config): array
    {
        if (!is_array($config)) {
            $config = [];
        }
        $existing = isset($config['tools']) && is_array($config['tools']) ? $config['tools'] : [];
        $config['tools'] = array_values(array_unique(array_merge($existing, $this->ability_names())));
        return $config;
    }

    public function render_missing_deps_notice(): void
    {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('MCP Site Manager is inactive: install and activate the MCP Adapter plugin (requires WordPress 6.8+).', 'mcp-site-manager');
        echo '</p></div>';
    }

    /** @return Abilities\AbilityBundle[] */
    private function bundles(): array
    {
        return [
            new Abilities\Content\PostsBundle(),
            new Abilities\Content\PagesBundle(),
            new Abilities\Content\CptBundle(),
            new Abilities\Taxonomy\TaxonomyBundle(),
            new Abilities\Media\MediaBundle(),
            new Abilities\Comments\CommentsBundle(),
            new Abilities\Users\UsersBundle(),
            new Abilities\Plugins\PluginsBundle(),
            new Abilities\Themes\ThemesBundle(),
            new Abilities\Options\OptionsBundle(),
            new Abilities\Menus\MenusBundle(),
            new Abilities\Diagnostics\DiagnosticsBundle(),
            new Abilities\Maintenance\MaintenanceBundle(),
        ];
    }

    /**
     * Public accessor for the bundle list. Used by the Abilities admin tab to
     * enumerate every potential ability (including disabled ones) so the
     * "save" handler can compute the disabled set.
     *
     * @return Abilities\AbilityBundle[]
     */
    public static function instance_bundles(): array
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->bundles();
    }

    /** @return string[] */
    public function ability_names(): array
    {
        $disabled = \Mrabbani\McpSiteManager\Support\DisabledAbilities::all();
        $names = [];
        foreach ($this->bundles() as $bundle) {
            foreach (array_keys($bundle->abilities()) as $local) {
                if (in_array($local, $disabled, true)) continue;
                $names[] = "mcpsm/$local";
            }
        }
        return $names;
    }
}
