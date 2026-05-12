<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin;

defined('ABSPATH') || exit;

use Mrabbani\McpSiteManager\Plugin;

final class SettingsPage
{
    public const SLUG = 'mcp-site-manager';
    public const TABS = [
        'dashboard'  => 'Dashboard',
        'connection' => 'Connection',
        'abilities'  => 'Abilities',
        'log'        => 'Activity Log',
        'settings'   => 'Settings',
    ];

    /** @return array<string,string> Localized tab labels. */
    private static function tab_labels(): array
    {
        return [
            'dashboard'  => __('Dashboard', 'mcp-site-manager'),
            'connection' => __('Connection', 'mcp-site-manager'),
            'abilities'  => __('Abilities', 'mcp-site-manager'),
            'log'        => __('Activity Log', 'mcp-site-manager'),
            'settings'   => __('Settings', 'mcp-site-manager'),
        ];
    }

    public static function register(): void
    {
        add_management_page(
            __('MCP Site Manager', 'mcp-site-manager'),
            __('MCP Site Manager', 'mcp-site-manager'),
            'manage_options',
            self::SLUG,
            [self::class, 'render']
        );
        add_action('admin_post_mcpsm_clear_log', [self::class, 'handle_clear_log']);
        add_action('admin_post_mcpsm_toggle_log', [self::class, 'handle_toggle_log']);
        add_action('admin_init', [self::class, 'maybe_redirect_legacy_url']);
    }

    /**
     * Back-compat: the page used to live under Settings (options-general.php).
     * Redirect any bookmarked legacy URLs to the new Tools location.
     */
    public static function maybe_redirect_legacy_url(): void
    {
        if (!is_admin() || wp_doing_ajax()) return;
        // Read-only routing check on an admin page URL; not a form submission so no nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $script = isset($_SERVER['SCRIPT_NAME']) ? basename(sanitize_text_field(wp_unslash($_SERVER['SCRIPT_NAME']))) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($script !== 'options-general.php' || $page !== self::SLUG) return;
        $args = ['page' => self::SLUG];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['tab'])) $args['tab'] = sanitize_key(wp_unslash($_GET['tab']));
        wp_safe_redirect(add_query_arg($args, admin_url('tools.php')));
        exit;
    }

    public static function current_tab(): string
    {
        // Read-only tab routing for an admin screen; not a form submission so no nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'dashboard';
        return array_key_exists($tab, self::TABS) ? $tab : 'dashboard';
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) wp_die();

        $tab = self::current_tab();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('MCP Site Manager', 'mcp-site-manager') . '</h1>';
        self::render_nav($tab);

        switch ($tab) {
            case 'connection': self::render_connection(); break;
            case 'abilities':  self::render_abilities();  break;
            case 'log':        self::render_log();        break;
            case 'settings':   self::render_settings();   break;
            case 'dashboard':
            default:           self::render_dashboard();  break;
        }

        echo '</div>';
    }

    private static function render_nav(string $active): void
    {
        $labels = self::tab_labels();
        echo '<h2 class="nav-tab-wrapper">';
        foreach (self::TABS as $slug => $_default_label) {
            $url   = add_query_arg(['page' => self::SLUG, 'tab' => $slug], admin_url('tools.php'));
            $class = 'nav-tab' . ($active === $slug ? ' nav-tab-active' : '');
            $label = $labels[$slug] ?? $slug;
            printf(
                '<a href="%s" class="%s">%s</a>',
                esc_url($url),
                esc_attr($class),
                esc_html($label)
            );
        }
        echo '</h2>';
    }

    private static function render_dashboard(): void
    {
        // React mount point. The actual UI is rendered by build/dashboard.js, enqueued by DashboardAssets.
        echo '<div id="mcpsm-dashboard-root"><p><em>' . esc_html__('Loading dashboard…', 'mcp-site-manager') . '</em></p></div>';
    }

    private static function render_connection(): void
    {
        $endpoint = rest_url('mcp/mcp-adapter-default-server');
        $deps_ok  = Plugin::dependencies_met();
        $apppw_ok = function_exists('wp_is_application_passwords_available') ? wp_is_application_passwords_available() : true;

        // The default server's REST routes register on rest_api_init priority 15
        // (inside McpAdapter::init()). On a plain admin page load that hook
        // hasn't fired yet, so the third dot reflects bootstrap state, not
        // whether routes are currently in $wp_rest_server. After our self-
        // bootstrap (Plugin::register_hooks calls McpAdapter::instance()),
        // init() is guaranteed to run when rest_api_init fires.
        $init_fired = did_action('mcp_adapter_init') > 0;
        ?>
        <?php $dot_allowed = ['span' => ['style' => true]]; ?>
        <h2><?php esc_html_e('Status', 'mcp-site-manager'); ?></h2>
        <ul>
            <li><?php echo wp_kses(self::dot($deps_ok), $dot_allowed); ?> <?php esc_html_e('MCP Adapter library reachable', 'mcp-site-manager'); ?></li>
            <li><?php echo wp_kses(self::dot($apppw_ok), $dot_allowed); ?> <?php esc_html_e('Application Passwords enabled', 'mcp-site-manager'); ?></li>
            <li><?php echo wp_kses(self::dot($deps_ok), $dot_allowed); ?> <?php
                if ($init_fired) {
                    /* translators: %s: REST route slug for the default MCP server, wrapped in <code>. */
                    printf(esc_html__('Default server live at %s', 'mcp-site-manager'), '<code>mcp-adapter-default-server</code>');
                } else {
                    /* translators: %s: REST route slug for the default MCP server, wrapped in <code>. */
                    printf(esc_html__('Default server ready at %s (initializes on the next REST request)', 'mcp-site-manager'), '<code>mcp-adapter-default-server</code>');
                }
            ?></li>
        </ul>

        <h2><?php esc_html_e('Connection', 'mcp-site-manager'); ?></h2>
        <p><strong><?php esc_html_e('MCP Endpoint:', 'mcp-site-manager'); ?></strong>
            <code id="mcpsm-url"><?php echo esc_html($endpoint); ?></code>
            <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('mcpsm-url').innerText)"><?php esc_html_e('Copy', 'mcp-site-manager'); ?></button>
        </p>
        <p><?php
            printf(
                /* translators: %s: link to the user's WordPress profile page (Application Passwords section). */
                esc_html__('Generate an Application Password from %s, then add this snippet to your MCP client config:', 'mcp-site-manager'),
                '<a href="' . esc_url(admin_url('profile.php#application-passwords-section')) . '">' . esc_html__('your profile', 'mcp-site-manager') . '</a>'
            );
        ?></p>
        <pre><?php echo esc_html(self::client_config_snippet($endpoint)); ?></pre>
        <p style="margin-top:1em;"><em><?php
            printf(
                /* translators: %s: link to the WordPress Developer Blog walkthrough */
                esc_html__('Need help wiring up a specific client (Claude Desktop, Claude Code, Cursor, VS Code) or want STDIO transport for local dev? See the upstream walkthrough: %s.', 'mcp-site-manager'),
                '<a href="https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/#connecting-ai-applications" target="_blank" rel="noopener noreferrer">' . esc_html__('Connecting AI applications', 'mcp-site-manager') . ' ↗</a>'
            );
        ?></em></p>
        <?php
    }

    private static function render_abilities(): void
    {
        ?>
        <h2><?php esc_html_e('Registered abilities', 'mcp-site-manager'); ?></h2>
        <p><?php esc_html_e('Disable individual abilities to hide them from MCP clients. Disabled abilities are not registered with WordPress and cannot be invoked. Changes take effect on the next page load and on the next MCP client reconnect.', 'mcp-site-manager'); ?></p>
        <div id="mcpsm-abilities-root"><p><em><?php esc_html_e('Loading abilities…', 'mcp-site-manager'); ?></em></p></div>
        <?php
    }

    private static function render_log(): void
    {
        ?>
        <h2><?php esc_html_e('Activity log', 'mcp-site-manager'); ?></h2>
        <p><?php esc_html_e('Recent ability invocations. Use the Settings tab to disable logging or clear the log.', 'mcp-site-manager'); ?></p>
        <div id="mcpsm-log-root"><p><em><?php esc_html_e('Loading activity log…', 'mcp-site-manager'); ?></em></p></div>
        <?php
    }

    private static function render_settings(): void
    {
        $log_on = AbilityLog::enabled();
        ?>
        <h2><?php esc_html_e('Settings', 'mcp-site-manager'); ?></h2>
        <h3><?php esc_html_e('Activity logging', 'mcp-site-manager'); ?></h3>
        <p><?php esc_html_e('Logging records each ability invocation in a custom table (capped at 1000 rows).', 'mcp-site-manager'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <?php wp_nonce_field('mcpsm_toggle_log'); ?>
            <input type="hidden" name="action" value="mcpsm_toggle_log">
            <button class="button"><?php echo $log_on ? esc_html__('Disable logging', 'mcp-site-manager') : esc_html__('Enable logging', 'mcp-site-manager'); ?></button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <?php wp_nonce_field('mcpsm_clear_log'); ?>
            <input type="hidden" name="action" value="mcpsm_clear_log">
            <button class="button"><?php esc_html_e('Clear log', 'mcp-site-manager'); ?></button>
        </form>
        <p style="margin-top:1em;"><em><?php
            printf(
                /* translators: %s: bolded current logging state — "on" or "off". */
                esc_html__('Logging is currently %s.', 'mcp-site-manager'),
                $log_on ? '<strong>' . esc_html__('on', 'mcp-site-manager') . '</strong>' : '<strong>' . esc_html__('off', 'mcp-site-manager') . '</strong>'
            );
        ?></em></p>
        <?php
    }

    public static function handle_clear_log(): void
    {
        if (!current_user_can('manage_options')) wp_die();
        check_admin_referer('mcpsm_clear_log');
        AbilityLog::clear();
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'tab' => 'settings'], admin_url('tools.php')));
        exit;
    }

    public static function handle_toggle_log(): void
    {
        if (!current_user_can('manage_options')) wp_die();
        check_admin_referer('mcpsm_toggle_log');
        update_option(AbilityLog::OPTION_ENABLED, AbilityLog::enabled() ? 0 : 1);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'tab' => 'settings'], admin_url('tools.php')));
        exit;
    }

    /** @return array<string,string> */
    private static function collect_abilities(): array
    {
        $out = [];
        if (!function_exists('wp_get_abilities')) return $out;
        foreach (wp_get_abilities() as $name => $ability) {
            if (str_starts_with((string) $name, 'mcpsm/')) {
                $desc = method_exists($ability, 'get_description') ? $ability->get_description() : '';
                $out[$name] = $desc;
            }
        }
        ksort($out);
        return $out;
    }

    private static function client_config_snippet(string $endpoint): string
    {
        $user     = wp_get_current_user();
        $username = ($user && $user->exists()) ? $user->user_login : 'your-username';

        // Matches the article's HTTP transport shape:
        // developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/#connecting-ai-applications
        // The @automattic/mcp-wordpress-remote proxy bridges JSON-RPC stdio to
        // HTTPS with Application Password basic auth. Works for Claude Desktop,
        // Claude Code, and Cursor as-is. VS Code uses the `servers` key instead
        // of `mcpServers` (see README).
        return json_encode([
            'mcpServers' => [
                'mcp-site-manager' => [
                    'command' => 'npx',
                    'args'    => ['-y', '@automattic/mcp-wordpress-remote@latest'],
                    'env'     => [
                        'WP_API_URL'      => $endpoint,
                        'WP_API_USERNAME' => $username,
                        'WP_API_PASSWORD' => 'your application password',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function dot(bool $ok): string
    {
        return $ok ? '<span style="color:#00a32a;">●</span>' : '<span style="color:#d63638;">●</span>';
    }
}
