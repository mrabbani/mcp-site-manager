<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin;

defined('ABSPATH') || exit;

use Mrabbani\McpSiteManager\Support\UrlGuard;

/**
 * Guided install/activate flow for the MCP Adapter plugin.
 *
 * MCP Adapter is not on WordPress.org (yet), so users can't install it through
 * the standard plugin search. This class renders an admin notice with one-click
 * Install and Activate buttons that fetch the adapter from its GitHub repo via
 * WordPress's own Plugin_Upgrader.
 *
 * The download URL is filterable (`mcpsm_adapter_download_url`) so site owners
 * can pin a specific release tag instead of always tracking the latest.
 */
final class AdapterDependency
{
    private const INSTALL_ACTION  = 'mcpsm_install_adapter';
    private const ACTIVATE_ACTION = 'mcpsm_activate_adapter';
    private const NONCE_INSTALL   = 'mcpsm_install_adapter_nonce';
    private const NONCE_ACTIVATE  = 'mcpsm_activate_adapter_nonce';
    private const NOTICE_TRANSIENT = 'mcpsm_adapter_install_notice';

    // Per the MCP Adapter docs ("Installing the MCP Adapter"), the canonical
    // download is the latest GitHub Release asset, which is a clean zip whose
    // top-level folder is already `mcp-adapter/`.
    private const DEFAULT_DOWNLOAD_URL = 'https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip';

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render_notice']);
        add_action('admin_post_' . self::INSTALL_ACTION, [self::class, 'handle_install']);
        add_action('admin_post_' . self::ACTIVATE_ACTION, [self::class, 'handle_activate']);
    }

    public static function adapter_active(): bool
    {
        return class_exists('\\WP\\MCP\\Core\\McpAdapter');
    }

    /**
     * Locate the MCP Adapter plugin file (e.g. "mcp-adapter/mcp-adapter.php") among
     * installed plugins. Returns null if not installed.
     */
    public static function adapter_plugin_file(): ?string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (get_plugins() as $file => $data) {
            $folder = strtok($file, '/');
            if ($folder === 'mcp-adapter' || $folder === 'mcp-adapter-trunk') {
                return $file;
            }
            if (isset($data['TextDomain']) && $data['TextDomain'] === 'mcp-adapter') {
                return $file;
            }
        }
        return null;
    }

    public static function render_notice(): void
    {
        if (self::adapter_active()) {
            self::render_flash();
            return;
        }
        if (!current_user_can('install_plugins') && !current_user_can('activate_plugins')) {
            return;
        }

        $installed_file = self::adapter_plugin_file();
        $admin_post = admin_url('admin-post.php');

        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . esc_html__('MCP Site Manager', 'mcp-site-manager') . ':</strong> ';

        if ($installed_file === null) {
            echo esc_html__('The MCP Adapter plugin is required. Click below to install it from the official WordPress/mcp-adapter repository.', 'mcp-site-manager');
            echo '</p><p>';
            if (current_user_can('install_plugins')) {
                $url = wp_nonce_url(
                    add_query_arg('action', self::INSTALL_ACTION, $admin_post),
                    self::NONCE_INSTALL,
                    '_wpnonce'
                );
                printf(
                    '<a href="%s" class="button button-primary">%s</a> ',
                    esc_url($url),
                    esc_html__('Install MCP Adapter', 'mcp-site-manager')
                );
            } else {
                echo esc_html__('Ask an administrator with the install_plugins capability to complete this step.', 'mcp-site-manager');
            }
        } else {
            echo esc_html__('The MCP Adapter plugin is installed but not active.', 'mcp-site-manager');
            echo '</p><p>';
            if (current_user_can('activate_plugins')) {
                $url = wp_nonce_url(
                    add_query_arg(
                        ['action' => self::ACTIVATE_ACTION, 'plugin' => rawurlencode($installed_file)],
                        $admin_post
                    ),
                    self::NONCE_ACTIVATE,
                    '_wpnonce'
                );
                printf(
                    '<a href="%s" class="button button-primary">%s</a>',
                    esc_url($url),
                    esc_html__('Activate MCP Adapter', 'mcp-site-manager')
                );
            }
        }

        echo ' <a href="https://github.com/WordPress/mcp-adapter" target="_blank" rel="noopener noreferrer">' . esc_html__('Learn more', 'mcp-site-manager') . '</a>';
        echo '</p></div>';
    }

    public static function handle_install(): void
    {
        if (!current_user_can('install_plugins')) {
            wp_die(esc_html__('You do not have permission to install plugins.', 'mcp-site-manager'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_INSTALL);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $url = (string) apply_filters('mcpsm_adapter_download_url', self::DEFAULT_DOWNLOAD_URL);

        // Lock the dependency-install path to the two trusted source-of-truth hosts
        // for the upstream adapter (WordPress.org's directory CDN and the official
        // WordPress GitHub org's release assets). A site owner can broaden this via
        // `mcpsm_adapter_download_allowed_hosts` if they need to mirror the zip
        // internally, but the default protects them from a misconfigured filter
        // pointing the installer at an arbitrary host.
        $hosts = apply_filters('mcpsm_adapter_download_allowed_hosts', [
            'github.com',
            'downloads.wordpress.org',
        ]);
        $guard = UrlGuard::validate($url, [
            'https_only'    => true,
            'allowed_hosts' => is_array($hosts) ? $hosts : [],
            'error_code'    => 'mcpsm_adapter_download_blocked',
        ]);
        if (is_wp_error($guard)) {
            self::flash('error', $guard->get_error_message());
            self::redirect_back();
        }

        add_filter('upgrader_source_selection', [self::class, 'rename_source_folder'], 10, 3);

        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $result = $upgrader->install($url);

        remove_filter('upgrader_source_selection', [self::class, 'rename_source_folder'], 10);

        if (is_wp_error($result)) {
            self::flash('error', $result->get_error_message());
            self::redirect_back();
        }
        if ($result !== true) {
            self::flash('error', __('Installer returned an unexpected result.', 'mcp-site-manager'));
            self::redirect_back();
        }

        $installed_file = self::adapter_plugin_file();
        if ($installed_file === null) {
            self::flash('error', __('Installation finished but the adapter plugin file could not be located.', 'mcp-site-manager'));
            self::redirect_back();
        }

        if (current_user_can('activate_plugins')) {
            $activated = activate_plugin($installed_file);
            if (is_wp_error($activated)) {
                self::flash('error', sprintf(
                    /* translators: %s: WordPress activation error message. */
                    __('Adapter installed but activation failed: %s', 'mcp-site-manager'),
                    $activated->get_error_message()
                ));
                self::redirect_back();
            }
            self::flash('success', __('MCP Adapter installed and activated.', 'mcp-site-manager'));
        } else {
            self::flash('success', __('MCP Adapter installed. Ask an administrator to activate it.', 'mcp-site-manager'));
        }

        self::redirect_back();
    }

    public static function handle_activate(): void
    {
        if (!current_user_can('activate_plugins')) {
            wp_die(esc_html__('You do not have permission to activate plugins.', 'mcp-site-manager'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ACTIVATE);

        // Sanitized via wp_unslash → rawurldecode → sanitize_text_field; nonce verified above.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $plugin = isset($_GET['plugin']) ? sanitize_text_field(rawurldecode((string) wp_unslash($_GET['plugin']))) : '';
        if ($plugin === '' || strpos($plugin, '..') !== false) {
            wp_die(esc_html__('Invalid plugin path.', 'mcp-site-manager'), '', ['response' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $result = activate_plugin($plugin);
        if (is_wp_error($result)) {
            self::flash('error', $result->get_error_message());
        } else {
            self::flash('success', __('MCP Adapter activated.', 'mcp-site-manager'));
        }
        self::redirect_back();
    }

    /**
     * Renames the unzipped folder so trunk archives land at `mcp-adapter/`
     * instead of `mcp-adapter-trunk/`. Hooked only during our install call.
     *
     * @param string|\WP_Error $source
     * @param string           $remote_source
     * @param \WP_Upgrader     $upgrader
     * @return string|\WP_Error
     */
    public static function rename_source_folder($source, $remote_source, $upgrader)
    {
        if (is_wp_error($source)) {
            return $source;
        }
        $basename = basename(untrailingslashit($source));
        if ($basename === 'mcp-adapter') {
            return $source;
        }
        if (strpos($basename, 'mcp-adapter') !== 0) {
            return $source;
        }
        $target = trailingslashit(dirname($source)) . 'mcp-adapter/';
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        global $wp_filesystem;
        if (!$wp_filesystem) {
            WP_Filesystem();
        }
        if ($wp_filesystem->move($source, $target, true)) {
            return $target;
        }
        return $source;
    }

    private static function flash(string $type, string $message): void
    {
        set_transient(self::NOTICE_TRANSIENT, ['type' => $type, 'message' => $message], 60);
    }

    private static function render_flash(): void
    {
        $flash = get_transient(self::NOTICE_TRANSIENT);
        if (!is_array($flash) || empty($flash['message'])) {
            return;
        }
        delete_transient(self::NOTICE_TRANSIENT);
        $class = ($flash['type'] === 'success') ? 'notice-success' : 'notice-error';
        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($class),
            esc_html((string) $flash['message'])
        );
    }

    private static function redirect_back(): void
    {
        $ref = wp_get_referer();
        if (!$ref) {
            $ref = admin_url('plugins.php');
        }
        wp_safe_redirect($ref);
        exit;
    }
}
