<?php
declare(strict_types=1);

namespace SiteMcp\Support;

use SiteMcp\Admin\AbilityLog;

final class AbilityRunner
{
    /**
     * Run an ability callback, centralising try/catch, logging, and error mapping.
     *
     * @param callable():mixed $fn
     * @return array<string, mixed>|\WP_Error
     */
    public static function run(string $ability, callable $fn)
    {
        $start = microtime(true);
        try {
            $result = $fn();
            if ($result instanceof \WP_Error) {
                $env = ErrorMapper::toMcp($result);
                AbilityLog::record($ability, 'error', (string) $env['code'], self::ms($start));
                return new \WP_Error('site_mcp_error', $env['message'], ['status' => $result->get_error_data()['status'] ?? 500] + (array) $env['data']);
            }
            AbilityLog::record($ability, 'ok', null, self::ms($start));
            return is_array($result) ? $result : ['result' => $result];
        } catch (\Throwable $e) {
            $env = ErrorMapper::toMcp($e);
            AbilityLog::record($ability, 'error', (string) $env['code'], self::ms($start));
            error_log(sprintf('[site-mcp] %s threw %s: %s', $ability, get_class($e), $e->getMessage()));
            return new \WP_Error('site_mcp_internal', $env['message'], ['status' => 500]);
        }
    }

    private static function ms(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
