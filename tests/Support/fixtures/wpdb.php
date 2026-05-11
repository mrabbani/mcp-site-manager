<?php
/**
 * Minimal stand-in for WordPress's $wpdb global, just enough for Admin\Stats unit tests.
 *
 * Holds an in-memory array of "log rows" (associative arrays with the wp_mcpsm_log columns)
 * and a "users" table (id => login). Each query method inspects the SQL string and
 * dispatches to a hand-written PHP implementation that operates on the in-memory rows.
 *
 * This is a fixture, not a SQL engine. It supports only the exact queries Admin\Stats issues.
 */
final class FakeWpdb
{
    public string $prefix = 'wp_';
    public string $users = 'wp_users';

    /** @var list<array<string,mixed>> */
    public array $rows = [];

    /** @var array<int,string> user_id => user_login */
    public array $users_table = [];

    public function prepare(string $sql, ...$args): string
    {
        $i = 0;
        return preg_replace_callback('/%[ds]/', function () use ($args, &$i) {
            $v = $args[$i++] ?? '';
            return is_string($v) ? "'" . addslashes($v) . "'" : (string) (int) $v;
        }, $sql);
    }

    public function get_results(string $sql, $output = ARRAY_A): array
    {
        return $this->run($sql);
    }

    public function get_row(string $sql, $output = ARRAY_A): ?array
    {
        $r = $this->run($sql);
        return $r[0] ?? null;
    }

    public function get_var(string $sql)
    {
        $r = $this->run($sql);
        if (empty($r)) return null;
        $first = $r[0];
        return is_array($first) ? reset($first) : $first;
    }

    /** @return list<array<string,mixed>> */
    private function run(string $sql): array
    {
        if (preg_match('/SELECT\s+status,\s*COUNT\(\*\)\s+(?:AS\s+)?c\s+FROM/i', $sql)) {
            $out = [];
            foreach (['ok', 'error'] as $s) {
                $out[] = ['status' => $s, 'c' => count(array_filter($this->rows, fn($r) => $r['status'] === $s))];
            }
            return $out;
        }
        if (preg_match('/SELECT\s+AVG\(duration_ms\)\s+(?:AS\s+)?avg_ms,\s*COUNT\(\*\)\s+(?:AS\s+)?c\s+FROM/i', $sql)) {
            $n = count($this->rows);
            $avg = $n === 0 ? null : array_sum(array_column($this->rows, 'duration_ms')) / $n;
            return [['avg_ms' => $avg, 'c' => $n]];
        }
        if (preg_match('/SELECT\s+duration_ms\s+FROM[^O]*ORDER\s+BY\s+duration_ms\s+ASC\s+LIMIT\s+1\s+OFFSET\s+(\d+)/i', $sql, $m)) {
            $offset = (int) $m[1];
            $sorted = array_column($this->rows, 'duration_ms');
            sort($sorted);
            return isset($sorted[$offset]) ? [['duration_ms' => $sorted[$offset]]] : [];
        }
        if (preg_match('/SELECT\s+ability,\s*COUNT\(\*\)\s+(?:AS\s+)?calls/i', $sql) && preg_match('/LIMIT\s+(\d+)/i', $sql, $lim)) {
            $by = [];
            foreach ($this->rows as $r) {
                $a = $r['ability'];
                $by[$a] ??= ['ability' => $a, 'calls' => 0, 'ok' => 0, 'sum' => 0];
                $by[$a]['calls']++;
                $by[$a]['ok'] += $r['status'] === 'ok' ? 1 : 0;
                $by[$a]['sum'] += $r['duration_ms'];
            }
            $out = [];
            foreach ($by as $b) {
                $out[] = [
                    'ability' => $b['ability'],
                    'calls' => $b['calls'],
                    'success_rate' => $b['calls'] === 0 ? 0.0 : $b['ok'] / $b['calls'],
                    'avg_ms' => $b['calls'] === 0 ? 0 : $b['sum'] / $b['calls'],
                ];
            }
            usort($out, fn($a, $b) => $b['calls'] <=> $a['calls']);
            return array_slice($out, 0, (int) $lim[1]);
        }
        if (preg_match("/WHERE\s+l\.status\s*=\s*'error'/i", $sql) && preg_match('/LIMIT\s+(\d+)/i', $sql, $lim)) {
            $rows = array_filter($this->rows, fn($r) => $r['status'] === 'error');
            usort($rows, fn($a, $b) => $b['id'] <=> $a['id']);
            $rows = array_slice($rows, 0, (int) $lim[1]);
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'ts' => $r['ts'],
                    'ability' => $r['ability'],
                    'error_code' => $r['error_code'] ?? null,
                    'user_id' => $r['user_id'],
                    'user_login' => $this->users_table[$r['user_id']] ?? null,
                ];
            }
            return $out;
        }
        if (preg_match('/SELECT\s+MIN\(ts\)/i', $sql)) {
            if (empty($this->rows)) return [['f' => null, 't' => null, 'c' => 0]];
            $ts = array_column($this->rows, 'ts');
            sort($ts);
            return [['f' => $ts[0], 't' => end($ts), 'c' => count($this->rows)]];
        }
        return [];
    }
}
