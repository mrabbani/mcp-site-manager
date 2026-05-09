<?php
declare(strict_types=1);

namespace SiteMcp\Support;

final class SchemaBuilder
{
    public static function object(array $properties, array $required = []): array
    {
        $auto_required = [];
        foreach ($properties as $name => $schema) {
            if (!empty($schema['__required'])) {
                $auto_required[] = $name;
                unset($properties[$name]['__required']);
            }
        }
        return [
            'type'       => 'object',
            'properties' => $properties,
            'required'   => array_values(array_unique(array_merge($required, $auto_required))),
        ];
    }

    public static function int(string $description = '', bool $required = false, ?int $min = null, ?int $max = null): array
    {
        $s = ['type' => 'integer', 'description' => $description];
        if ($min !== null) $s['minimum'] = $min;
        if ($max !== null) $s['maximum'] = $max;
        if ($required) $s['__required'] = true;
        return $s;
    }

    public static function str(string $description = '', bool $required = false, ?array $enum = null): array
    {
        $s = ['type' => 'string', 'description' => $description];
        if ($enum) $s['enum'] = $enum;
        if ($required) $s['__required'] = true;
        return $s;
    }

    public static function bool(string $description = '', bool $required = false): array
    {
        $s = ['type' => 'boolean', 'description' => $description];
        if ($required) $s['__required'] = true;
        return $s;
    }

    public static function arr(array $items, string $description = ''): array
    {
        return ['type' => 'array', 'description' => $description, 'items' => $items];
    }

    /** Common pagination params merged into list-ability input schemas. */
    public static function paging(): array
    {
        return [
            'per_page' => self::int('Items per page (default 10, max 100)', false, 1, 100),
            'page'     => self::int('Page number (1-indexed)', false, 1),
            'search'   => self::str('Search string'),
        ];
    }
}
