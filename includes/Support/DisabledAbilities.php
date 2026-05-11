<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Support;

final class DisabledAbilities
{
    public const OPTION = 'mcpsm_disabled_abilities';

    /** @return string[] */
    public static function all(): array
    {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) return [];
        return array_values(array_unique(array_map('strval', array_filter($raw, fn($v) => $v !== '' && $v !== null))));
    }

    public static function contains(string $local_name): bool
    {
        return in_array($local_name, self::all(), true);
    }

    /** @param array<int|string, mixed> $names */
    public static function set(array $names): void
    {
        $clean = array_values(array_unique(array_map('strval', array_filter($names, fn($v) => $v !== '' && $v !== null))));
        update_option(self::OPTION, $clean, false);
    }

    public static function clear(): void
    {
        update_option(self::OPTION, [], false);
    }
}
