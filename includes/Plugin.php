<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager;

defined('ABSPATH') || exit;

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
        // Dependency on MCP Adapter is enforced softly: if it is missing on
        // activation, the AdapterDependency admin notice offers a one-click
        // install. We deliberately do not deactivate here so that flow is
        // reachable. WordPress core (>= 6.5) also surfaces the
        // "Requires Plugins: mcp-adapter" header as a separate hint.
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
            Admin\AdapterDependency::register();
            return;
        }

        // The MCP Adapter class may be loadable without anyone having called
        // McpAdapter::instance() yet — notably when WooCommerce vendors the
        // library but its mcp_integration feature flag is off. The class is
        // there, but no REST routes are registered and the default server is
        // 404. Trip the singleton ourselves so the default server (which
        // surfaces every ability with meta.mcp.public = true) comes online
        // without requiring the standalone MCP Adapter plugin. The adapter
        // registers its own rest_api_init handler (priority 20000), which
        // still fires for the current request.
        if (class_exists('\\WP\\MCP\\Core\\McpAdapter')) {
            \WP\MCP\Core\McpAdapter::instance();
        }

        add_action('wp_abilities_api_categories_init', [$this, 'register_category']);
        add_action('wp_abilities_api_init', [$this, 'register_abilities']);
        add_action('admin_menu', [Admin\SettingsPage::class, 'register']);
        add_action('rest_api_init', [\Mrabbani\McpSiteManager\Admin\Rest\StatsController::class, 'register_routes']);
        add_action('rest_api_init', [\Mrabbani\McpSiteManager\Admin\Rest\AbilitiesController::class, 'register_routes']);
        add_action('rest_api_init', [\Mrabbani\McpSiteManager\Admin\Rest\LogController::class, 'register_routes']);
        add_action('admin_enqueue_scripts', [\Mrabbani\McpSiteManager\Admin\DashboardAssets::class, 'maybe_enqueue']);
        add_action('admin_enqueue_scripts', [\Mrabbani\McpSiteManager\Admin\AbilitiesAssets::class, 'maybe_enqueue']);
        add_action('admin_enqueue_scripts', [\Mrabbani\McpSiteManager\Admin\LogAssets::class, 'maybe_enqueue']);
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

}
