<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin;

final class Stats
{
    /**
     * @return array{total:int, success:int, error:int, success_rate:float}
     */
    public static function counts(): array
    {
        global $wpdb;
        $table = AbilityLog::table_name();
        $rows = (array) $wpdb->get_results(
            "SELECT status, COUNT(*) AS c FROM $table GROUP BY status",
            ARRAY_A
        );
        $by = ['ok' => 0, 'error' => 0];
        foreach ($rows as $r) {
            $s = (string) ($r['status'] ?? '');
            $c = (int) ($r['c'] ?? 0);
            if (isset($by[$s])) $by[$s] = $c;
        }
        $total = $by['ok'] + $by['error'];
        return [
            'total'        => $total,
            'success'      => $by['ok'],
            'error'        => $by['error'],
            'success_rate' => $total > 0 ? $by['ok'] / $total : 0.0,
        ];
    }
}
