<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin;

defined('ABSPATH') || exit;

use Mrabbani\McpSiteManager\Admin\Rest\AbilitiesController;

final class AbilitiesAssets
{
    public const HANDLE = 'mcpsm-abilities';

    public static function maybe_enqueue(string $hook_suffix): void
    {
        if ($hook_suffix !== 'tools_page_' . SettingsPage::SLUG) return;
        if (SettingsPage::current_tab() !== 'abilities') return;
        if (!current_user_can('manage_options')) return;

        $build = MCPSM_DIR . 'build/abilities.asset.php';
        if (!file_exists($build)) return;

        $asset = require $build;
        $deps    = $asset['dependencies'] ?? [];
        $version = $asset['version']      ?? MCPSM_VERSION;

        wp_register_script(
            self::HANDLE,
            MCPSM_URL . 'build/abilities.js',
            $deps,
            $version,
            true
        );
        wp_localize_script(self::HANDLE, 'mcpsmAbilities', [
            'restUrl' => esc_url_raw(rest_url(AbilitiesController::NAMESPACE)),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
        wp_enqueue_script(self::HANDLE);

        // DataViews stylesheet pitfall: DO NOT depend on `wp-dataviews` (script handle, not style handle).
        // Our SCSS imports the DataViews stylesheet directly — `wp-components` covers the rest.
        $css_candidates = [
            MCPSM_DIR . 'build/style-abilities.css',
            MCPSM_DIR . 'build/abilities.css',
        ];
        foreach ($css_candidates as $css_path) {
            if (file_exists($css_path)) {
                wp_enqueue_style(
                    self::HANDLE,
                    MCPSM_URL . 'build/' . basename($css_path),
                    ['wp-components'],
                    $version
                );
                break;
            }
        }
    }
}
