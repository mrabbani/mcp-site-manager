<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Support;

final class DisabledAbilities
{
    public const OPTION = 'mcpsm_disabled_abilities';

    /**
     * @return string[] Fully-qualified ability names (e.g. "mcpsm/posts-list",
     * "woocommerce/foo"). Legacy entries from earlier versions stored bare
     * local names without a namespace; those are normalized to "mcpsm/<name>"
     * on read so existing user choices survive the cross-namespace refactor.
     */
    public static function all(): array
    {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) return [];
        $items = array_filter($raw, fn($v) => $v !== '' && $v !== null);
        $items = array_map([self::class, 'normalize'], $items);
        return array_values(array_unique($items));
    }

    public static function contains(string $name): bool
    {
        return in_array(self::normalize($name), self::all(), true);
    }

    /** @param array<int|string, mixed> $names */
    public static function set(array $names): void
    {
        $items = array_filter($names, fn($v) => $v !== '' && $v !== null);
        $items = array_map([self::class, 'normalize'], $items);
        $items = array_values(array_unique($items));
        update_option(self::OPTION, $items, false);
    }

    /**
     * Normalize a stored or incoming ability name to fully-qualified form.
     * Bare names (no slash) are assumed to be our own and get the "mcpsm/"
     * prefix — preserves disable state for users upgrading from a version
     * that stored just the local name.
     */
    private static function normalize($value): string
    {
        $s = (string) $value;
        return strpos($s, '/') === false ? "mcpsm/$s" : $s;
    }

    public static function clear(): void
    {
        update_option(self::OPTION, [], false);
    }
}
