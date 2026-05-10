<?php
declare(strict_types=1);

namespace SiteMcp\Admin;

use SiteMcp\Plugin;

final class SettingsPage
{
    public const SLUG = 'site-mcp';

    public static function register(): void
    {
        add_options_page(
            __('Site MCP', 'site-mcp'),
            __('Site MCP', 'site-mcp'),
            'manage_options',
            self::SLUG,
            [self::class, 'render']
        );
        add_action('admin_post_site_mcp_clear_log', [self::class, 'handle_clear_log']);
        add_action('admin_post_site_mcp_toggle_log', [self::class, 'handle_toggle_log']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) wp_die();

        $endpoint = rest_url('mcp/mcp-adapter-default-server');
        $deps_ok  = Plugin::dependencies_met();
        $apppw_ok = function_exists('wp_is_application_passwords_available') ? wp_is_application_passwords_available() : true;

        $abilities = self::collect_abilities();
        $log_rows  = AbilityLog::recent(50);
        $log_on    = AbilityLog::enabled();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Site MCP', 'site-mcp'); ?></h1>

            <h2><?php esc_html_e('Status', 'site-mcp'); ?></h2>
            <ul>
                <li><?php echo self::dot($deps_ok); ?> <?php esc_html_e('MCP Adapter & Abilities API available', 'site-mcp'); ?></li>
                <li><?php echo self::dot($apppw_ok); ?> <?php esc_html_e('Application Passwords enabled', 'site-mcp'); ?></li>
                <li><?php echo self::dot(true); ?> <?php printf(esc_html__('Abilities exposed via %s', 'site-mcp'), '<code>mcp-adapter-default-server</code>'); ?></li>
            </ul>

            <h2><?php esc_html_e('Connection', 'site-mcp'); ?></h2>
            <p><strong><?php esc_html_e('MCP Endpoint:', 'site-mcp'); ?></strong>
                <code id="site-mcp-url"><?php echo esc_html($endpoint); ?></code>
                <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('site-mcp-url').innerText)"><?php esc_html_e('Copy', 'site-mcp'); ?></button>
            </p>
            <p><?php
                printf(
                    esc_html__('Generate an Application Password from %s, then add this snippet to your MCP client config:', 'site-mcp'),
                    '<a href="' . esc_url(admin_url('profile.php#application-passwords-section')) . '">' . esc_html__('your profile', 'site-mcp') . '</a>'
                );
            ?></p>
            <pre><?php echo esc_html(self::client_config_snippet($endpoint)); ?></pre>

            <h2><?php esc_html_e('Registered abilities', 'site-mcp'); ?></h2>
            <table class="widefat striped"><thead><tr>
                <th><?php esc_html_e('Name', 'site-mcp'); ?></th>
                <th><?php esc_html_e('Description', 'site-mcp'); ?></th>
            </tr></thead><tbody>
            <?php foreach ($abilities as $name => $desc): ?>
                <tr><td><code><?php echo esc_html($name); ?></code></td><td><?php echo esc_html($desc); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>

            <h2><?php esc_html_e('Activity log', 'site-mcp'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <?php wp_nonce_field('site_mcp_toggle_log'); ?>
                <input type="hidden" name="action" value="site_mcp_toggle_log">
                <button class="button"><?php echo $log_on ? esc_html__('Disable logging', 'site-mcp') : esc_html__('Enable logging', 'site-mcp'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <?php wp_nonce_field('site_mcp_clear_log'); ?>
                <input type="hidden" name="action" value="site_mcp_clear_log">
                <button class="button"><?php esc_html_e('Clear log', 'site-mcp'); ?></button>
            </form>
            <table class="widefat striped" style="margin-top:1em;"><thead><tr>
                <th><?php esc_html_e('Time', 'site-mcp'); ?></th>
                <th><?php esc_html_e('User', 'site-mcp'); ?></th>
                <th><?php esc_html_e('Ability', 'site-mcp'); ?></th>
                <th><?php esc_html_e('Status', 'site-mcp'); ?></th>
                <th><?php esc_html_e('Code', 'site-mcp'); ?></th>
                <th><?php esc_html_e('Duration (ms)', 'site-mcp'); ?></th>
            </tr></thead><tbody>
            <?php foreach ($log_rows as $row): ?>
                <tr>
                    <td><?php echo esc_html($row['ts']); ?></td>
                    <td><?php echo esc_html((string) $row['user_id']); ?></td>
                    <td><code><?php echo esc_html($row['ability']); ?></code></td>
                    <td><?php echo esc_html($row['status']); ?></td>
                    <td><?php echo esc_html((string) ($row['error_code'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) $row['duration_ms']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    public static function handle_clear_log(): void
    {
        if (!current_user_can('manage_options')) wp_die();
        check_admin_referer('site_mcp_clear_log');
        AbilityLog::clear();
        wp_safe_redirect(admin_url('options-general.php?page=' . self::SLUG));
        exit;
    }

    public static function handle_toggle_log(): void
    {
        if (!current_user_can('manage_options')) wp_die();
        check_admin_referer('site_mcp_toggle_log');
        update_option(AbilityLog::OPTION_ENABLED, AbilityLog::enabled() ? 0 : 1);
        wp_safe_redirect(admin_url('options-general.php?page=' . self::SLUG));
        exit;
    }

    /** @return array<string,string> */
    private static function collect_abilities(): array
    {
        $out = [];
        if (!function_exists('wp_get_abilities')) return $out;
        foreach (wp_get_abilities() as $name => $ability) {
            if (str_starts_with((string) $name, 'site-mcp/')) {
                $desc = method_exists($ability, 'get_description') ? $ability->get_description() : '';
                $out[$name] = $desc;
            }
        }
        ksort($out);
        return $out;
    }

    private static function client_config_snippet(string $endpoint): string
    {
        return json_encode([
            'mcpServers' => [
                'site-mcp' => [
                    'transport' => 'http',
                    'url'       => $endpoint,
                    'headers'   => ['Authorization' => 'Basic ' . base64_encode('USERNAME:APP_PASSWORD')],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function dot(bool $ok): string
    {
        return $ok ? '<span style="color:#00a32a;">●</span>' : '<span style="color:#d63638;">●</span>';
    }
}
