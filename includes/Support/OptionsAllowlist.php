<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Support;

defined('ABSPATH') || exit;

final class OptionsAllowlist
{
    public const ALLOWED = [
        'blogname',
        'blogdescription',
        'permalink_structure',
        'default_category',
        'posts_per_page',
        'timezone_string',
        'date_format',
        'time_format',
        'start_of_week',
        'WPLANG',
        'default_comment_status',
        'default_ping_status',
        'comment_registration',
        'show_on_front',
        'page_on_front',
        'page_for_posts',
    ];

    public const DEFAULT_DENY_PREFIXES = ['mcpsm_'];

    public static function contains(string $key): bool
    {
        if (self::is_denied($key)) {
            return false;
        }
        return in_array($key, self::filtered_allowed(), true);
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_values(array_filter(
            self::filtered_allowed(),
            static fn(string $k): bool => !self::is_denied($k)
        ));
    }

    /**
     * Hard denylist: plugin-internal options (mcpsm_*) must never be writable via MCP,
     * regardless of what the filtered allowlist contains. Otherwise an MCP client with
     * manage_options could re-enable abilities the admin disabled, or flip logging off, etc.
     *
     * Third parties can extend the denylist via the `mcpsm_options_denylist_prefixes`
     * filter (e.g. to protect their own plugin's internal options).
     */
    private static function is_denied(string $key): bool
    {
        /**
         * Filter the list of option-key prefixes that may never be exposed via MCP.
         *
         * @param string[] $prefixes Default plugin-internal prefixes.
         */
        $prefixes = apply_filters('mcpsm_options_denylist_prefixes', self::DEFAULT_DENY_PREFIXES);
        if (!is_array($prefixes)) $prefixes = self::DEFAULT_DENY_PREFIXES;

        foreach ($prefixes as $prefix) {
            if (!is_string($prefix) || $prefix === '') continue;
            if (str_starts_with($key, $prefix)) return true;
        }
        // The default prefix is non-negotiable — re-check in case a filter removed it.
        return str_starts_with($key, 'mcpsm_');
    }

    /** @return string[] */
    private static function filtered_allowed(): array
    {
        /**
         * Filter the option-key allowlist exposed via the MCP options abilities.
         *
         * Keys matching a denylist prefix are still rejected at the boundary, so adding
         * an `mcpsm_*` key here will have no effect.
         *
         * @param string[] $keys Default allowlist.
         */
        $keys = apply_filters('mcpsm_options_allowlist', self::ALLOWED);
        if (!is_array($keys)) return self::ALLOWED;
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), static fn($k) => $k !== '')));
        return $keys;
    }
}
