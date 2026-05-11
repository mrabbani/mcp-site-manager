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

    /**
     * @return array{avg_ms:int, p95_ms:int}
     */
    public static function latency(): array
    {
        global $wpdb;
        $table = AbilityLog::table_name();

        $row = $wpdb->get_row("SELECT AVG(duration_ms) AS avg_ms, COUNT(*) AS c FROM $table", ARRAY_A);
        $avg = isset($row['avg_ms']) && $row['avg_ms'] !== null ? (int) round((float) $row['avg_ms']) : 0;
        $count = (int) ($row['c'] ?? 0);

        $p95 = 0;
        if ($count > 0) {
            $offset = (int) floor($count * 0.95);
            if ($offset >= $count) $offset = $count - 1;
            $val = $wpdb->get_var($wpdb->prepare(
                "SELECT duration_ms FROM $table ORDER BY duration_ms ASC LIMIT 1 OFFSET %d",
                $offset
            ));
            $p95 = $val === null ? 0 : (int) $val;
        }

        return ['avg_ms' => $avg, 'p95_ms' => $p95];
    }
}
