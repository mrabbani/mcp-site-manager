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

    /**
     * @param int $limit  Max rows returned (1-100).
     * @return array<int, array{ability:string, calls:int, success_rate:float, avg_ms:int}>
     */
    public static function top_abilities(int $limit = 10): array
    {
        global $wpdb;
        $table = AbilityLog::table_name();
        $limit = max(1, min(100, $limit));
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ability,
                    COUNT(*) AS calls,
                    SUM(CASE WHEN status='ok' THEN 1 ELSE 0 END) AS ok,
                    AVG(duration_ms) AS avg_ms
             FROM $table
             GROUP BY ability
             ORDER BY calls DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        $out = [];
        foreach ($rows as $r) {
            $calls = (int) ($r['calls'] ?? 0);
            $ok    = (int) ($r['ok'] ?? 0);
            $out[] = [
                'ability'      => (string) ($r['ability'] ?? ''),
                'calls'        => $calls,
                'success_rate' => $calls > 0 ? (float) ($ok / $calls) : 0.0,
                'avg_ms'       => isset($r['avg_ms']) && $r['avg_ms'] !== null ? (int) round((float) $r['avg_ms']) : 0,
            ];
        }
        return $out;
    }

    /**
     * @param int $limit  Max rows returned (1-100).
     * @return array<int, array{ts:string, ability:string, error_code:?string, user_id:int, user_login:?string}>
     */
    public static function recent_errors(int $limit = 20): array
    {
        global $wpdb;
        $table = AbilityLog::table_name();
        $users = $wpdb->users;
        $limit = max(1, min(100, $limit));
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT l.ts, l.ability, l.error_code, l.user_id, u.user_login
             FROM $table l
             LEFT JOIN $users u ON u.ID = l.user_id
             WHERE l.status = 'error'
             ORDER BY l.id DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'ts'         => (string) ($r['ts'] ?? ''),
                'ability'    => (string) ($r['ability'] ?? ''),
                'error_code' => isset($r['error_code']) ? (string) $r['error_code'] : null,
                'user_id'    => (int) ($r['user_id'] ?? 0),
                'user_login' => isset($r['user_login']) && $r['user_login'] !== null ? (string) $r['user_login'] : null,
            ];
        }
        return $out;
    }

    /**
     * @return array{from:?string, to:?string, count:int}
     */
    public static function window(): array
    {
        global $wpdb;
        $table = AbilityLog::table_name();
        $row = $wpdb->get_row("SELECT MIN(ts) AS f, MAX(ts) AS t, COUNT(*) AS c FROM $table", ARRAY_A);
        $count = (int) ($row['c'] ?? 0);
        return [
            'from'  => $count > 0 && !empty($row['f']) ? (string) $row['f'] : null,
            'to'    => $count > 0 && !empty($row['t']) ? (string) $row['t'] : null,
            'count' => $count,
        ];
    }

    /**
     * Combined payload for the dashboard's single-round-trip /stats/all endpoint.
     *
     * @return array{
     *   counts: array{total:int, success:int, error:int, success_rate:float},
     *   latency: array{avg_ms:int, p95_ms:int},
     *   top_abilities: array<int, array{ability:string, calls:int, success_rate:float, avg_ms:int}>,
     *   recent_errors: array<int, array{ts:string, ability:string, error_code:?string, user_id:int, user_login:?string}>,
     *   window: array{from:?string, to:?string, count:int},
     * }
     */
    public static function all(): array
    {
        return [
            'counts'        => self::counts(),
            'latency'       => self::latency(),
            'top_abilities' => self::top_abilities(10),
            'recent_errors' => self::recent_errors(20),
            'window'        => self::window(),
        ];
    }
}
