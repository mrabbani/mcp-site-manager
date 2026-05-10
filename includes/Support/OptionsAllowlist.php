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
        return in_array($key, self::ALLOWED, true);
    }

    /** @return string[] */
    public static function keys(): array
    {
        return self::ALLOWED;
    }
}
