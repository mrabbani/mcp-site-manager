<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin;

use Mrabbani\McpSiteManager\Admin\Rest\StatsController;

final class DashboardAssets
{
    public const HANDLE = 'mcpsm-dashboard';

    public static function maybe_enqueue(string $hook_suffix): void
    {
        // Only on our settings page.
        if ($hook_suffix !== 'tools_page_' . SettingsPage::SLUG) return;
        // Only when the Dashboard tab is active.
        if (SettingsPage::current_tab() !== 'dashboard') return;
        if (!current_user_can('manage_options')) return;

        $build = MCPSM_DIR . 'build/dashboard.asset.php';
        if (!file_exists($build)) return; // build artefact missing — admin is still functional, just no React.

        $asset = require $build;
        $deps    = $asset['dependencies'] ?? [];
        $version = $asset['version']      ?? MCPSM_VERSION;

        wp_register_script(
            self::HANDLE,
            MCPSM_URL . 'build/dashboard.js',
            $deps,
            $version,
            true
        );

        wp_localize_script(self::HANDLE, 'mcpsmDashboard', [
            'restUrl'  => esc_url_raw(rest_url(StatsController::NAMESPACE)),
            'nonce'    => wp_create_nonce('wp_rest'),
            'tabUrls'  => [
                'connection' => esc_url_raw(add_query_arg(
                    ['page' => SettingsPage::SLUG, 'tab' => 'connection'],
                    admin_url('tools.php')
                )),
            ],
        ]);

        wp_enqueue_script(self::HANDLE);

        // wp-scripts outputs the CSS as style-{entry}.css (e.g. style-dashboard.css).
        $css_candidates = [
            MCPSM_DIR . 'build/style-dashboard.css',
            MCPSM_DIR . 'build/dashboard.css',
        ];
        $css_urls = [
            MCPSM_DIR . 'build/style-dashboard.css' => MCPSM_URL . 'build/style-dashboard.css',
            MCPSM_DIR . 'build/dashboard.css'       => MCPSM_URL . 'build/dashboard.css',
        ];
        foreach ($css_candidates as $css) {
            if (file_exists($css)) {
                wp_enqueue_style(
                    self::HANDLE,
                    $css_urls[$css],
                    ['wp-components'],
                    $version
                );
                break;
            }
        }
    }
}
