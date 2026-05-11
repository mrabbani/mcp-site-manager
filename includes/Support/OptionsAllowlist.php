<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Support;

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

    public static function contains(string $key): bool
    {
        // Hard denylist: plugin-internal options (mcpsm_*) must never be writable via MCP,
        // regardless of what ALLOWED contains. Otherwise an MCP client with manage_options
        // could re-enable abilities the admin disabled, or flip logging off, etc.
        if (str_starts_with($key, 'mcpsm_')) {
            return false;
        }
        return in_array($key, self::ALLOWED, true);
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_values(array_filter(
            self::ALLOWED,
            static fn(string $k): bool => !str_starts_with($k, 'mcpsm_')
        ));
    }
}
