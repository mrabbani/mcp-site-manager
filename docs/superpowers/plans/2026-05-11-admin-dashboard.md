# Admin Dashboard Implementation Plan (React + REST)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Dashboard tab to `Settings → MCP Site Manager` rendered as a React app (using WordPress's `@wordpress/scripts` stack) that consumes a new REST API exposing live stats from `wp_mcpsm_log`. Restructure the existing single-page admin into 5 tabs; the Dashboard tab is React, the four others stay PHP.

**Architecture:** Three layers, each in its own task group:
1. **Data (PHP)** — TDD `Admin\Stats` (5 pure-data static methods, one query each).
2. **REST (PHP)** — `Admin\Rest\StatsController` exposes Stats via 6 GET routes under `mcp-site-manager/v1`.
3. **UI (React)** — `@wordpress/scripts` build, `<Dashboard />` component tree consuming `/stats/all`, 30s polling, mounted into a `<div>` rendered by the PHP tab dispatcher.

The five other UI sections (Status/Connection, Abilities, Activity Log, Settings) move into per-tab PHP render methods on `SettingsPage`.

**Tech Stack:** PHP 8.0+, WordPress 6.8+, PHPUnit, `$wpdb`, WP REST API. JS: `@wordpress/scripts` (webpack), `@wordpress/element` (React), `@wordpress/components`, `@wordpress/api-fetch`. No third-party React/build/Redux/Router dependencies.

**Spec:** `docs/superpowers/specs/2026-05-11-admin-dashboard-design.md`

**Working dir for every task:** `/Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager`

**Note on TDD scope:** Stats methods get full TDD (Tasks 1–5). REST controller gets integration tests (Task 7). React components are visually verified — no JS test framework added in v1.

---

## File map

| File | Action | Purpose |
|---|---|---|
| `includes/Admin/Stats.php` | Create | Pure-data aggregations. |
| `includes/Admin/Rest/StatsController.php` | Create | 6 REST routes that delegate to `Stats`. |
| `includes/Admin/DashboardAssets.php` | Create | Enqueues React build + localizes nonce/REST URL on Dashboard tab. |
| `includes/Admin/SettingsPage.php` | Modify | `render()` becomes tab dispatcher; per-tab render methods. Mounts `<div id="mcpsm-dashboard-root"></div>` on Dashboard tab. |
| `includes/Plugin.php` | Modify | Register the REST controller on `rest_api_init`. Register `DashboardAssets` enqueue. |
| `tests/Support/Stats*.php` | Create | Unit tests + fake `$wpdb` fixture. |
| `tests/Integration/RestStatsTest.php` | Create | wp-env REST integration tests. |
| `package.json` | Modify | Add `@wordpress/scripts`, `build`/`start` scripts. |
| `.gitignore` | Verify | `/build/` already excluded. |
| `src/dashboard/` | Create | React entry, components, hook, styles. |

---

## Task 1: Stats — `counts()` (TDD)

**Files:**
- Create: `includes/Admin/Stats.php`
- Create: `tests/Support/fixtures/wpdb.php`
- Create: `tests/Support/StatsTest.php`

- [ ] **Step 1: Create the wpdb fixture**

`tests/Support/fixtures/wpdb.php`:

```php
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
```

- [ ] **Step 2: Write failing test for `counts()`**

`tests/Support/StatsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Support;

use PHPUnit\Framework\TestCase;
use Mrabbani\McpSiteManager\Admin\Stats;

require_once __DIR__ . '/fixtures/wpdb.php';

final class StatsTest extends TestCase
{
    private \FakeWpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new \FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        if (!class_exists('Mrabbani\\McpSiteManager\\Admin\\AbilityLog')) {
            require_once __DIR__ . '/../../includes/Admin/AbilityLog.php';
        }
    }

    private function row(array $overrides = []): array
    {
        static $id = 0;
        $id++;
        return array_merge([
            'id' => $id,
            'ts' => '2026-05-11 12:00:00',
            'user_id' => 1,
            'ability' => 'mcpsm/posts-list',
            'status' => 'ok',
            'error_code' => null,
            'duration_ms' => 50,
        ], $overrides);
    }

    public function test_counts_with_mixed_rows(): void
    {
        $this->wpdb->rows = [
            $this->row(['status' => 'ok']),
            $this->row(['status' => 'ok']),
            $this->row(['status' => 'ok']),
            $this->row(['status' => 'error', 'error_code' => '-32602']),
        ];
        $r = Stats::counts();
        $this->assertSame(4, $r['total']);
        $this->assertSame(3, $r['success']);
        $this->assertSame(1, $r['error']);
        $this->assertSame(0.75, $r['success_rate']);
    }

    public function test_counts_empty(): void
    {
        $this->wpdb->rows = [];
        $r = Stats::counts();
        $this->assertSame(0, $r['total']);
        $this->assertSame(0, $r['success']);
        $this->assertSame(0, $r['error']);
        $this->assertSame(0.0, $r['success_rate']);
    }
}
```

- [ ] **Step 3: Run, verify FAIL**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
./vendor/bin/phpunit tests/Support/StatsTest.php 2>&1 | tail -10
```
Expected: 2 errors `Class "Mrabbani\McpSiteManager\Admin\Stats" not found`.

- [ ] **Step 4: Implement `Stats::counts()`**

`includes/Admin/Stats.php`:

```php
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
```

- [ ] **Step 5: Run, verify PASS**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php 2>&1 | tail -5
```
Expected: `OK (2 tests, 8 assertions)`.

- [ ] **Step 6: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/Stats.php tests/Support/StatsTest.php tests/Support/fixtures/wpdb.php
git commit -m "feat(stats): Admin\\\\Stats::counts() with unit test fixture"
```

---

## Task 2: Stats — `latency()` (TDD)

**Files:**
- Modify: `includes/Admin/Stats.php`
- Modify: `tests/Support/StatsTest.php`

- [ ] **Step 1: Add failing tests** (append inside `StatsTest`)

```php
    public function test_latency_with_rows(): void
    {
        $this->wpdb->rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $this->wpdb->rows[] = $this->row(['duration_ms' => $i * 10]);
        }
        $r = Stats::latency();
        $this->assertSame(505, $r['avg_ms']);
        $this->assertSame(960, $r['p95_ms']);
    }

    public function test_latency_empty(): void
    {
        $this->wpdb->rows = [];
        $r = Stats::latency();
        $this->assertSame(0, $r['avg_ms']);
        $this->assertSame(0, $r['p95_ms']);
    }
```

- [ ] **Step 2: Run, verify FAIL**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php --filter latency 2>&1 | tail -5
```
Expected: errors `Stats::latency does not exist`.

- [ ] **Step 3: Implement `latency()`** (append inside `Stats`)

```php
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
```

- [ ] **Step 4: Run, verify PASS**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php 2>&1 | tail -5
```
Expected: `OK (4 tests, …)`.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/Stats.php tests/Support/StatsTest.php
git commit -m "feat(stats): Admin\\\\Stats::latency() (avg + p95)"
```

---

## Task 3: Stats — `top_abilities()` (TDD)

**Files:**
- Modify: `includes/Admin/Stats.php`
- Modify: `tests/Support/StatsTest.php`

- [ ] **Step 1: Add failing tests**

```php
    public function test_top_abilities_orders_by_calls_desc(): void
    {
        $this->wpdb->rows = [];
        for ($i = 0; $i < 5; $i++) $this->wpdb->rows[] = $this->row(['ability' => 'mcpsm/themes-active', 'duration_ms' => 100, 'status' => 'ok']);
        for ($i = 0; $i < 3; $i++) $this->wpdb->rows[] = $this->row(['ability' => 'mcpsm/posts-list',   'duration_ms' => 50,  'status' => 'ok']);
        $this->wpdb->rows[] = $this->row(['ability' => 'mcpsm/posts-list', 'duration_ms' => 50, 'status' => 'error']);
        for ($i = 0; $i < 2; $i++) $this->wpdb->rows[] = $this->row(['ability' => 'mcpsm/health-overview', 'duration_ms' => 10, 'status' => 'ok']);

        $r = Stats::top_abilities(10);

        $this->assertCount(3, $r);
        $this->assertSame('mcpsm/themes-active', $r[0]['ability']);
        $this->assertSame(5, $r[0]['calls']);
        $this->assertSame(1.0, $r[0]['success_rate']);
        $this->assertSame(100, $r[0]['avg_ms']);

        $this->assertSame('mcpsm/posts-list', $r[1]['ability']);
        $this->assertSame(4, $r[1]['calls']);
        $this->assertSame(0.75, $r[1]['success_rate']);
    }

    public function test_top_abilities_respects_limit(): void
    {
        $this->wpdb->rows = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $this->wpdb->rows[] = $this->row(['ability' => "mcpsm/$name"]);
        }
        $this->assertCount(3, Stats::top_abilities(3));
    }
```

- [ ] **Step 2: Run, verify FAIL**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php --filter top_abilities 2>&1 | tail -5
```

- [ ] **Step 3: Implement `top_abilities()`** (append inside `Stats`)

```php
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
                'success_rate' => $calls > 0 ? $ok / $calls : 0.0,
                'avg_ms'       => isset($r['avg_ms']) && $r['avg_ms'] !== null ? (int) round((float) $r['avg_ms']) : 0,
            ];
        }
        return $out;
    }
```

- [ ] **Step 4: Run, verify PASS**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php 2>&1 | tail -5
```
Expected: `OK (6 tests, …)`.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/Stats.php tests/Support/StatsTest.php
git commit -m "feat(stats): Admin\\\\Stats::top_abilities()"
```

---

## Task 4: Stats — `recent_errors()` (TDD)

**Files:**
- Modify: `includes/Admin/Stats.php`
- Modify: `tests/Support/StatsTest.php`

- [ ] **Step 1: Add failing tests**

```php
    public function test_recent_errors_filters_and_joins_user(): void
    {
        $this->wpdb->users_table = [1 => 'admin', 2 => 'editor'];
        $this->wpdb->rows = [
            $this->row(['ability' => 'mcpsm/posts-list', 'status' => 'ok']),
            $this->row(['ability' => 'mcpsm/options-update', 'status' => 'error', 'error_code' => '-32001', 'user_id' => 1]),
            $this->row(['ability' => 'mcpsm/posts-create', 'status' => 'error', 'error_code' => '-32602', 'user_id' => 2]),
            $this->row(['ability' => 'mcpsm/users-create', 'status' => 'error', 'error_code' => '-32603', 'user_id' => 999]),
        ];
        $r = Stats::recent_errors(10);

        $this->assertCount(3, $r);
        $this->assertSame('mcpsm/users-create', $r[0]['ability']);
        $this->assertSame('-32603', $r[0]['error_code']);
        $this->assertSame(999, $r[0]['user_id']);
        $this->assertNull($r[0]['user_login']);

        $this->assertSame('mcpsm/options-update', $r[2]['ability']);
        $this->assertSame('admin', $r[2]['user_login']);
    }

    public function test_recent_errors_respects_limit(): void
    {
        $this->wpdb->rows = [];
        for ($i = 0; $i < 25; $i++) {
            $this->wpdb->rows[] = $this->row(['status' => 'error', 'ability' => "mcpsm/x-$i"]);
        }
        $this->assertCount(5, Stats::recent_errors(5));
    }
```

- [ ] **Step 2: Run, verify FAIL**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php --filter recent_errors 2>&1 | tail -5
```

- [ ] **Step 3: Implement `recent_errors()`**

```php
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
```

- [ ] **Step 4: Run, verify PASS**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php 2>&1 | tail -5
```
Expected: `OK (8 tests, …)`.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/Stats.php tests/Support/StatsTest.php
git commit -m "feat(stats): Admin\\\\Stats::recent_errors() with user JOIN"
```

---

## Task 5: Stats — `window()` + `all()` aggregator (TDD)

**Files:**
- Modify: `includes/Admin/Stats.php`
- Modify: `tests/Support/StatsTest.php`

- [ ] **Step 1: Add failing tests**

```php
    public function test_window_returns_min_max(): void
    {
        $this->wpdb->rows = [
            $this->row(['ts' => '2026-05-09 14:32:00']),
            $this->row(['ts' => '2026-05-10 09:15:00']),
            $this->row(['ts' => '2026-05-11 11:44:00']),
        ];
        $r = Stats::window();
        $this->assertSame('2026-05-09 14:32:00', $r['from']);
        $this->assertSame('2026-05-11 11:44:00', $r['to']);
        $this->assertSame(3, $r['count']);
    }

    public function test_window_empty(): void
    {
        $this->wpdb->rows = [];
        $r = Stats::window();
        $this->assertNull($r['from']);
        $this->assertNull($r['to']);
        $this->assertSame(0, $r['count']);
    }

    public function test_all_combines_every_section(): void
    {
        $this->wpdb->rows = [$this->row(['status' => 'ok'])];
        $r = Stats::all();
        $this->assertArrayHasKey('counts', $r);
        $this->assertArrayHasKey('latency', $r);
        $this->assertArrayHasKey('top_abilities', $r);
        $this->assertArrayHasKey('recent_errors', $r);
        $this->assertArrayHasKey('window', $r);
        $this->assertSame(1, $r['counts']['total']);
    }
```

- [ ] **Step 2: Run, verify FAIL**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php --filter "window|all" 2>&1 | tail -5
```

- [ ] **Step 3: Implement `window()` and `all()`**

```php
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
```

- [ ] **Step 4: Run, verify PASS**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php 2>&1 | tail -5
```
Expected: `OK (11 tests, …)`.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/Stats.php tests/Support/StatsTest.php
git commit -m "feat(stats): Admin\\\\Stats::window() + all() combiner"
```

---

## Task 6: REST controller — `Admin\Rest\StatsController`

**Files:**
- Create: `includes/Admin/Rest/StatsController.php`
- Modify: `includes/Plugin.php`

- [ ] **Step 1: Implement the controller**

`includes/Admin/Rest/StatsController.php`:

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin\Rest;

use Mrabbani\McpSiteManager\Admin\Stats;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

final class StatsController
{
    public const NAMESPACE = 'mcp-site-manager/v1';

    public static function register_routes(): void
    {
        $perm = [self::class, 'permission_check'];

        register_rest_route(self::NAMESPACE, '/stats/counts', [
            'methods'  => 'GET',
            'callback' => fn() => new WP_REST_Response(Stats::counts()),
            'permission_callback' => $perm,
        ]);
        register_rest_route(self::NAMESPACE, '/stats/latency', [
            'methods'  => 'GET',
            'callback' => fn() => new WP_REST_Response(Stats::latency()),
            'permission_callback' => $perm,
        ]);
        register_rest_route(self::NAMESPACE, '/stats/top-abilities', [
            'methods'  => 'GET',
            'callback' => function (WP_REST_Request $r) {
                $limit = max(1, min(100, (int) ($r->get_param('limit') ?? 10)));
                return new WP_REST_Response(Stats::top_abilities($limit));
            },
            'permission_callback' => $perm,
            'args' => [
                'limit' => [
                    'type'              => 'integer',
                    'default'           => 10,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/stats/recent-errors', [
            'methods'  => 'GET',
            'callback' => function (WP_REST_Request $r) {
                $limit = max(1, min(100, (int) ($r->get_param('limit') ?? 20)));
                return new WP_REST_Response(Stats::recent_errors($limit));
            },
            'permission_callback' => $perm,
            'args' => [
                'limit' => [
                    'type'              => 'integer',
                    'default'           => 20,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/stats/window', [
            'methods'  => 'GET',
            'callback' => fn() => new WP_REST_Response(Stats::window()),
            'permission_callback' => $perm,
        ]);
        register_rest_route(self::NAMESPACE, '/stats/all', [
            'methods'  => 'GET',
            'callback' => fn() => new WP_REST_Response(Stats::all()),
            'permission_callback' => $perm,
        ]);
    }

    public static function permission_check()
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('You need manage_options to view MCP Site Manager stats.', 'mcp-site-manager'),
                ['status' => rest_authorization_required_code()]
            );
        }
        return true;
    }
}
```

- [ ] **Step 2: Wire it into `Plugin::register_hooks()`**

Open `includes/Plugin.php`. Find `register_hooks()` and add the REST registration alongside the existing actions:

```php
        add_action('rest_api_init', [\Mrabbani\McpSiteManager\Admin\Rest\StatsController::class, 'register_routes']);
```

Insert it after the existing `add_action('admin_menu', ...)` line.

- [ ] **Step 3: Lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l includes/Admin/Rest/StatsController.php
php -l includes/Plugin.php
```
Expected: both `No syntax errors detected`.

- [ ] **Step 4: Manual verification (wp-env)**

If wp-env isn't running, start it (`npx wp-env start`).

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
APP_PW=$(npx wp-env run cli wp user application-password create admin "stats-rest-check" --porcelain 2>&1 | grep -E '^[a-zA-Z0-9]{20,}' | head -1)
echo "APP_PW=$APP_PW"
curl -sS -u admin:$APP_PW http://localhost:8890/wp-json/mcp-site-manager/v1/stats/all | python3 -m json.tool | head -30
```
Expected: JSON object with keys `counts`, `latency`, `top_abilities`, `recent_errors`, `window`.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/Rest/StatsController.php includes/Plugin.php
git commit -m "feat(rest): mcp-site-manager/v1 stats endpoints (counts, latency, top, errors, window, all)"
```

---

## Task 7: REST integration test

**Files:**
- Create: `tests/Integration/RestStatsTest.php`

- [ ] **Step 1: Write the test**

`tests/Integration/RestStatsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class RestStatsTest extends TestCase
{
    private function call_rest(string $path, ?string $auth_user = null, ?string $auth_pw = null): array
    {
        $url = rtrim((string) getenv('MCPSM_BASE_URL'), '/') . $path;
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($auth_user !== null) {
            $headers[] = 'Authorization: Basic ' . base64_encode("$auth_user:$auth_pw");
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $body, 'json' => json_decode((string) $body, true)];
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $r = $this->call_rest('/wp-json/mcp-site-manager/v1/stats/all');
        $this->assertSame(401, $r['code'], 'Body: ' . substr((string) $r['body'], 0, 300));
    }

    public function test_authenticated_all_returns_combined_payload(): void
    {
        $r = $this->call_rest(
            '/wp-json/mcp-site-manager/v1/stats/all',
            (string) getenv('MCPSM_USER'),
            (string) getenv('MCPSM_APP_PW')
        );
        $this->assertSame(200, $r['code'], 'Body: ' . substr((string) $r['body'], 0, 300));
        $this->assertIsArray($r['json']);
        foreach (['counts', 'latency', 'top_abilities', 'recent_errors', 'window'] as $k) {
            $this->assertArrayHasKey($k, $r['json'], "missing key: $k");
        }
        // counts shape
        foreach (['total', 'success', 'error', 'success_rate'] as $k) {
            $this->assertArrayHasKey($k, $r['json']['counts']);
        }
        // latency shape
        $this->assertArrayHasKey('avg_ms', $r['json']['latency']);
        $this->assertArrayHasKey('p95_ms', $r['json']['latency']);
        // window shape
        $this->assertArrayHasKey('count', $r['json']['window']);
    }

    public function test_top_abilities_respects_limit_query_param(): void
    {
        $r = $this->call_rest(
            '/wp-json/mcp-site-manager/v1/stats/top-abilities?limit=3',
            (string) getenv('MCPSM_USER'),
            (string) getenv('MCPSM_APP_PW')
        );
        $this->assertSame(200, $r['code']);
        $this->assertIsArray($r['json']);
        $this->assertLessThanOrEqual(3, count($r['json']));
    }
}
```

- [ ] **Step 2: Add the BASE_URL env var to the integration bootstrap**

Open `tests/Integration/bootstrap.php`. Add (or verify) the `MCPSM_BASE_URL` default near the existing env defaults:

```php
if (!getenv('MCPSM_BASE_URL')) putenv('MCPSM_BASE_URL=http://localhost:8890');
```

- [ ] **Step 3: Run integration suite**

Make sure wp-env is running and you have an app password.

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
APP_PW=$(npx wp-env run cli wp user application-password create admin "rest-stats-test" --porcelain 2>&1 | grep -E '^[a-zA-Z0-9]{20,}' | head -1)
MCPSM_APP_PW="$APP_PW" \
MCPSM_URL="http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
MCPSM_BASE_URL="http://localhost:8890" \
MCPSM_USER="admin" \
  ./vendor/bin/phpunit --testsuite=integration 2>&1 | tail -10
```
Expected: integration suite passes including 3 new tests.

- [ ] **Step 4: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add tests/Integration/RestStatsTest.php tests/Integration/bootstrap.php
git commit -m "test(int): REST stats endpoints (auth gate + combined payload + limit)"
```

---

## Task 8: SettingsPage — refactor into tab dispatcher (Dashboard tab placeholder)

**Files:**
- Modify: `includes/Admin/SettingsPage.php` (full rewrite — relocates existing UI; Dashboard tab gets a placeholder mount point that Task 10 fills with the React enqueue)

- [ ] **Step 1: Replace `includes/Admin/SettingsPage.php` with this exact content**

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin;

use Mrabbani\McpSiteManager\Plugin;

final class SettingsPage
{
    public const SLUG = 'mcp-site-manager';
    public const TABS = [
        'dashboard'  => 'Dashboard',
        'connection' => 'Connection',
        'abilities'  => 'Abilities',
        'log'        => 'Activity Log',
        'settings'   => 'Settings',
    ];

    public static function register(): void
    {
        add_options_page(
            __('MCP Site Manager', 'mcp-site-manager'),
            __('MCP Site Manager', 'mcp-site-manager'),
            'manage_options',
            self::SLUG,
            [self::class, 'render']
        );
        add_action('admin_post_mcpsm_clear_log', [self::class, 'handle_clear_log']);
        add_action('admin_post_mcpsm_toggle_log', [self::class, 'handle_toggle_log']);
    }

    public static function current_tab(): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'dashboard';
        return array_key_exists($tab, self::TABS) ? $tab : 'dashboard';
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) wp_die();

        $tab = self::current_tab();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('MCP Site Manager', 'mcp-site-manager') . '</h1>';
        self::render_nav($tab);

        switch ($tab) {
            case 'connection': self::render_connection(); break;
            case 'abilities':  self::render_abilities();  break;
            case 'log':        self::render_log();        break;
            case 'settings':   self::render_settings();   break;
            case 'dashboard':
            default:           self::render_dashboard();  break;
        }

        echo '</div>';
    }

    private static function render_nav(string $active): void
    {
        echo '<h2 class="nav-tab-wrapper">';
        foreach (self::TABS as $slug => $label) {
            $url = add_query_arg(['page' => self::SLUG, 'tab' => $slug], admin_url('options-general.php'));
            $class = 'nav-tab' . ($active === $slug ? ' nav-tab-active' : '');
            printf(
                '<a href="%s" class="%s">%s</a>',
                esc_url($url),
                esc_attr($class),
                esc_html__($label, 'mcp-site-manager')
            );
        }
        echo '</h2>';
    }

    private static function render_dashboard(): void
    {
        // React mount point. The actual UI is rendered by build/dashboard.js, enqueued by DashboardAssets.
        echo '<div id="mcpsm-dashboard-root"><p><em>' . esc_html__('Loading dashboard…', 'mcp-site-manager') . '</em></p></div>';
    }

    private static function render_connection(): void
    {
        $endpoint = rest_url('mcp/mcp-adapter-default-server');
        $deps_ok  = Plugin::dependencies_met();
        $apppw_ok = function_exists('wp_is_application_passwords_available') ? wp_is_application_passwords_available() : true;
        ?>
        <h2><?php esc_html_e('Status', 'mcp-site-manager'); ?></h2>
        <ul>
            <li><?php echo self::dot($deps_ok); ?> <?php esc_html_e('MCP Adapter & Abilities API available', 'mcp-site-manager'); ?></li>
            <li><?php echo self::dot($apppw_ok); ?> <?php esc_html_e('Application Passwords enabled', 'mcp-site-manager'); ?></li>
            <li><?php echo self::dot(true); ?> <?php printf(esc_html__('Abilities exposed via %s', 'mcp-site-manager'), '<code>mcp-adapter-default-server</code>'); ?></li>
        </ul>

        <h2><?php esc_html_e('Connection', 'mcp-site-manager'); ?></h2>
        <p><strong><?php esc_html_e('MCP Endpoint:', 'mcp-site-manager'); ?></strong>
            <code id="mcpsm-url"><?php echo esc_html($endpoint); ?></code>
            <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('mcpsm-url').innerText)"><?php esc_html_e('Copy', 'mcp-site-manager'); ?></button>
        </p>
        <p><?php
            printf(
                esc_html__('Generate an Application Password from %s, then add this snippet to your MCP client config:', 'mcp-site-manager'),
                '<a href="' . esc_url(admin_url('profile.php#application-passwords-section')) . '">' . esc_html__('your profile', 'mcp-site-manager') . '</a>'
            );
        ?></p>
        <pre><?php echo esc_html(self::client_config_snippet($endpoint)); ?></pre>
        <?php
    }

    private static function render_abilities(): void
    {
        $abilities = self::collect_abilities();
        ?>
        <h2><?php esc_html_e('Registered abilities', 'mcp-site-manager'); ?></h2>
        <table class="widefat striped"><thead><tr>
            <th><?php esc_html_e('Name', 'mcp-site-manager'); ?></th>
            <th><?php esc_html_e('Description', 'mcp-site-manager'); ?></th>
        </tr></thead><tbody>
        <?php foreach ($abilities as $name => $desc): ?>
            <tr><td><code><?php echo esc_html($name); ?></code></td><td><?php echo esc_html($desc); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    private static function render_log(): void
    {
        $log_rows = AbilityLog::recent(50);
        ?>
        <h2><?php esc_html_e('Activity log', 'mcp-site-manager'); ?></h2>
        <p><?php esc_html_e('Last 50 ability invocations. Use the Settings tab to disable logging or clear the log.', 'mcp-site-manager'); ?></p>
        <table class="widefat striped"><thead><tr>
            <th><?php esc_html_e('Time', 'mcp-site-manager'); ?></th>
            <th><?php esc_html_e('User', 'mcp-site-manager'); ?></th>
            <th><?php esc_html_e('Ability', 'mcp-site-manager'); ?></th>
            <th><?php esc_html_e('Status', 'mcp-site-manager'); ?></th>
            <th><?php esc_html_e('Code', 'mcp-site-manager'); ?></th>
            <th><?php esc_html_e('Duration (ms)', 'mcp-site-manager'); ?></th>
        </tr></thead><tbody>
        <?php foreach ($log_rows as $row): ?>
            <tr>
                <td><?php echo esc_html($row['ts']); ?></td>
                <td><?php echo esc_html((string) $row['user_id']); ?></td>
                <td><code><?php echo esc_html($row['ability']); ?></code></td>
                <td><?php echo esc_html($row['status']); ?></td>
                <td><?php echo esc_html((string) ($row['error_code'] ?? '')); ?></td>
                <td><?php echo esc_html((string) $row['duration_ms']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    private static function render_settings(): void
    {
        $log_on = AbilityLog::enabled();
        ?>
        <h2><?php esc_html_e('Settings', 'mcp-site-manager'); ?></h2>
        <h3><?php esc_html_e('Activity logging', 'mcp-site-manager'); ?></h3>
        <p><?php esc_html_e('Logging records each ability invocation in a custom table (capped at 1000 rows).', 'mcp-site-manager'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <?php wp_nonce_field('mcpsm_toggle_log'); ?>
            <input type="hidden" name="action" value="mcpsm_toggle_log">
            <button class="button"><?php echo $log_on ? esc_html__('Disable logging', 'mcp-site-manager') : esc_html__('Enable logging', 'mcp-site-manager'); ?></button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <?php wp_nonce_field('mcpsm_clear_log'); ?>
            <input type="hidden" name="action" value="mcpsm_clear_log">
            <button class="button"><?php esc_html_e('Clear log', 'mcp-site-manager'); ?></button>
        </form>
        <p style="margin-top:1em;"><em><?php
            printf(
                esc_html__('Logging is currently %s.', 'mcp-site-manager'),
                $log_on ? '<strong>' . esc_html__('on', 'mcp-site-manager') . '</strong>' : '<strong>' . esc_html__('off', 'mcp-site-manager') . '</strong>'
            );
        ?></em></p>
        <?php
    }

    public static function handle_clear_log(): void
    {
        if (!current_user_can('manage_options')) wp_die();
        check_admin_referer('mcpsm_clear_log');
        AbilityLog::clear();
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'tab' => 'settings'], admin_url('options-general.php')));
        exit;
    }

    public static function handle_toggle_log(): void
    {
        if (!current_user_can('manage_options')) wp_die();
        check_admin_referer('mcpsm_toggle_log');
        update_option(AbilityLog::OPTION_ENABLED, AbilityLog::enabled() ? 0 : 1);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'tab' => 'settings'], admin_url('options-general.php')));
        exit;
    }

    /** @return array<string,string> */
    private static function collect_abilities(): array
    {
        $out = [];
        if (!function_exists('wp_get_abilities')) return $out;
        foreach (wp_get_abilities() as $name => $ability) {
            if (str_starts_with((string) $name, 'mcpsm/')) {
                $desc = method_exists($ability, 'get_description') ? $ability->get_description() : '';
                $out[$name] = $desc;
            }
        }
        ksort($out);
        return $out;
    }

    private static function client_config_snippet(string $endpoint): string
    {
        return json_encode([
            'mcpServers' => [
                'mcp-site-manager' => [
                    'transport' => 'http',
                    'url'       => $endpoint,
                    'headers'   => ['Authorization' => 'Basic ' . base64_encode('USERNAME:APP_PASSWORD')],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function dot(bool $ok): string
    {
        return $ok ? '<span style="color:#00a32a;">●</span>' : '<span style="color:#d63638;">●</span>';
    }
}
```

- [ ] **Step 2: Lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l includes/Admin/SettingsPage.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Visually verify each tab loads**

Visit each tab in the browser:
- `http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager` → Dashboard placeholder ("Loading dashboard…")
- `…&tab=connection`, `&tab=abilities`, `&tab=log`, `&tab=settings`

Each must render without PHP errors. The Settings tab's toggle/clear buttons must still work.

- [ ] **Step 4: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/SettingsPage.php
git commit -m "refactor(admin): split SettingsPage into 5 tabs; Dashboard mount point"
```

---

## Task 9: Build tooling — `@wordpress/scripts` setup

**Files:**
- Modify: `package.json`
- Verify: `.gitignore` (ensure `/build/` ignored)
- Create: `src/dashboard/index.js` (minimal stub — full app in Task 10)
- Create: `src/dashboard/style.scss` (empty — to verify the CSS pipeline)

- [ ] **Step 1: Replace `package.json` with this exact content**

```json
{
    "name": "mcp-site-manager",
    "private": true,
    "scripts": {
        "wp-env": "wp-env",
        "build": "wp-scripts build",
        "start": "wp-scripts start",
        "test:int": "wp-env run tests-cli --env-cwd=wp-content/plugins/mcp-site-manager ./vendor/bin/phpunit --testsuite=integration"
    },
    "devDependencies": {
        "@wordpress/env": "^10.0.0",
        "@wordpress/scripts": "^30.0.0"
    }
}
```

- [ ] **Step 2: Verify `.gitignore` has `/build/`**

```bash
grep -E '^/?build/?$' /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager/.gitignore
```
Expected: `/build/` (already there from earlier work). If missing, append it.

- [ ] **Step 3: Create the entry stub**

`src/dashboard/index.js`:

```js
/**
 * MCP Site Manager — Dashboard React app.
 * Mounts into <div id="mcpsm-dashboard-root"> rendered by SettingsPage::render_dashboard().
 *
 * Full component tree is built in Task 10; this stub verifies the build pipeline.
 */

import { createRoot } from '@wordpress/element';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('mcpsm-dashboard-root');
    if (!root) return;
    createRoot(root).render('Dashboard build pipeline OK');
});
```

`src/dashboard/style.scss`:

```scss
/* MCP Site Manager dashboard — styles loaded with the build. */
#mcpsm-dashboard-root { font-family: inherit; }
```

- [ ] **Step 4: Install + build**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npm install 2>&1 | tail -5
npm run build 2>&1 | tail -10
```

Expected: `npm install` finishes without errors. `npm run build` produces:
- `build/index.js`
- `build/index.css` (or empty if scss has no rules)
- `build/index.asset.php` (with `dependencies` array including `wp-element`)

(`wp-scripts` defaults to entry `src/index.js`; we want `src/dashboard/index.js` instead. If the build doesn't pick up our entry, override it via webpack config in the next step.)

- [ ] **Step 5: Pick the right entry**

If `npm run build` produced `build/index.js` from `src/index.js` (which doesn't exist), or complained about missing entry, create a minimal webpack override at `webpack.config.js` in the plugin root:

```js
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        dashboard: path.resolve(__dirname, 'src/dashboard/index.js'),
    },
};
```

Re-run:

```bash
npm run build 2>&1 | tail -5
ls build/
```
Expected: `build/dashboard.js`, `build/dashboard.asset.php`, optionally `build/dashboard.css` and an `rtl` variant.

- [ ] **Step 6: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add package.json package-lock.json src/dashboard/index.js src/dashboard/style.scss webpack.config.js 2>/dev/null
git diff --cached --stat
git commit -m "build: @wordpress/scripts setup + dashboard entry stub"
```

---

## Task 10: DashboardAssets enqueue + React Dashboard component tree

**Files:**
- Create: `includes/Admin/DashboardAssets.php`
- Modify: `includes/Plugin.php` (register the enqueue)
- Replace: `src/dashboard/index.js`
- Create: `src/dashboard/Dashboard.js`
- Create: `src/dashboard/hooks/useStats.js`
- Create: `src/dashboard/components/StatCard.js`
- Create: `src/dashboard/components/NumbersRow.js`
- Create: `src/dashboard/components/LatencyRow.js`
- Create: `src/dashboard/components/TopAbilitiesTable.js`
- Create: `src/dashboard/components/RecentErrorsTable.js`
- Create: `src/dashboard/components/WindowFooter.js`
- Create: `src/dashboard/components/RefreshHeader.js`
- Create: `src/dashboard/components/EmptyState.js`
- Replace: `src/dashboard/style.scss`

- [ ] **Step 1: Implement `DashboardAssets`**

`includes/Admin/DashboardAssets.php`:

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin;

use Mrabbani\McpSiteManager\Admin\Rest\StatsController;

final class DashboardAssets
{
    public const HANDLE = 'mcpsm-dashboard';

    public static function maybe_enqueue(string $hook_suffix): void
    {
        // Only on our settings page.
        if ($hook_suffix !== 'settings_page_' . SettingsPage::SLUG) return;
        // Only when the Dashboard tab is active.
        if (SettingsPage::current_tab() !== 'dashboard') return;
        if (!current_user_can('manage_options')) return;

        $build = MCPSM_DIR . 'build/dashboard.asset.php';
        if (!file_exists($build)) return; // build artefact missing — admin is still functional, just no React.

        $asset = require $build;
        $deps    = $asset['dependencies'] ?? [];
        $version = $asset['version']      ?? MCPSM_VERSION;

        wp_register_script(
            self::HANDLE,
            MCPSM_URL . 'build/dashboard.js',
            $deps,
            $version,
            true
        );

        wp_localize_script(self::HANDLE, 'mcpsmDashboard', [
            'restUrl'  => esc_url_raw(rest_url(StatsController::NAMESPACE)),
            'nonce'    => wp_create_nonce('wp_rest'),
            'tabUrls'  => [
                'connection' => esc_url_raw(add_query_arg(
                    ['page' => SettingsPage::SLUG, 'tab' => 'connection'],
                    admin_url('options-general.php')
                )),
            ],
        ]);

        wp_enqueue_script(self::HANDLE);

        $css = MCPSM_DIR . 'build/dashboard.css';
        if (file_exists($css)) {
            wp_enqueue_style(
                self::HANDLE,
                MCPSM_URL . 'build/dashboard.css',
                ['wp-components'],
                $version
            );
        }
    }
}
```

- [ ] **Step 2: Wire enqueue into `Plugin::register_hooks()`**

Open `includes/Plugin.php`. Add this line alongside the other admin actions:

```php
        add_action('admin_enqueue_scripts', [\Mrabbani\McpSiteManager\Admin\DashboardAssets::class, 'maybe_enqueue']);
```

- [ ] **Step 3: Replace `src/dashboard/index.js`**

```js
/**
 * MCP Site Manager — Dashboard React app entry.
 */

import { createRoot, StrictMode } from '@wordpress/element';
import Dashboard from './Dashboard';
import './style.scss';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('mcpsm-dashboard-root');
    if (!root) return;
    createRoot(root).render(
        <StrictMode>
            <Dashboard />
        </StrictMode>
    );
});
```

- [ ] **Step 4: Create `src/dashboard/hooks/useStats.js`**

```js
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Fetch /stats/all from the MCP Site Manager REST namespace.
 * Loads once on mount; caller invokes `refresh()` to re-fetch on demand
 * (e.g. via the "Refresh now" button). No polling.
 *
 * @returns {{ data: object|null, loading: boolean, error: Error|null, lastUpdated: Date|null, refresh: function }}
 */
export default function useStats() {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);
    const [lastUpdated, setLastUpdated] = useState(null);
    const inflight = useRef(false);

    const refresh = useCallback(async () => {
        if (inflight.current) return;
        inflight.current = true;
        setLoading(true);
        try {
            const result = await apiFetch({ path: '/mcp-site-manager/v1/stats/all' });
            setData(result);
            setError(null);
            setLastUpdated(new Date());
        } catch (e) {
            setError(e instanceof Error ? e : new Error(String(e?.message ?? e)));
        } finally {
            setLoading(false);
            inflight.current = false;
        }
    }, []);

    useEffect(() => {
        refresh();
    }, [refresh]);

    return { data, loading, error, lastUpdated, refresh };
}
```

- [ ] **Step 5: Create `src/dashboard/components/StatCard.js`**

```js
import { Card, CardBody } from '@wordpress/components';

export default function StatCard({ label, value, color }) {
    return (
        <Card style={{ flex: 1, minWidth: 140 }}>
            <CardBody>
                <div style={{ fontSize: '1.8em', fontWeight: 600, color: color || '#646970' }}>
                    {value}
                </div>
                <div style={{
                    color: '#646970',
                    textTransform: 'uppercase',
                    fontSize: '0.8em',
                    letterSpacing: '0.05em',
                    marginTop: '0.3em'
                }}>
                    {label}
                </div>
            </CardBody>
        </Card>
    );
}
```

- [ ] **Step 6: Create `src/dashboard/components/NumbersRow.js`**

```js
import { __ } from '@wordpress/i18n';
import StatCard from './StatCard';

const fmt = (n) => Number(n).toLocaleString();

export default function NumbersRow({ counts }) {
    const ratePct = (counts.success_rate * 100).toFixed(1);
    const errBg   = counts.error > 0 ? '#d63638' : '#646970';
    const rateBg  = counts.error === 0 ? '#00a32a' : (counts.success_rate >= 0.95 ? '#00a32a' : '#646970');
    return (
        <div style={{ display: 'flex', gap: '1em', flexWrap: 'wrap', marginBottom: '1.5em' }}>
            <StatCard label={__('Total', 'mcp-site-manager')}        value={fmt(counts.total)}   color="#646970" />
            <StatCard label={__('Success', 'mcp-site-manager')}      value={fmt(counts.success)} color="#00a32a" />
            <StatCard label={__('Errors', 'mcp-site-manager')}       value={fmt(counts.error)}   color={errBg} />
            <StatCard label={__('Success rate', 'mcp-site-manager')} value={`${ratePct}%`}       color={rateBg} />
        </div>
    );
}
```

- [ ] **Step 7: Create `src/dashboard/components/LatencyRow.js`**

```js
import { __ } from '@wordpress/i18n';
import StatCard from './StatCard';

const fmt = (n) => Number(n).toLocaleString();

export default function LatencyRow({ latency }) {
    return (
        <div style={{ display: 'flex', gap: '1em', flexWrap: 'wrap', marginBottom: '1.5em' }}>
            <StatCard label={__('Average', 'mcp-site-manager')} value={`${fmt(latency.avg_ms)} ms`} color="#646970" />
            <StatCard label={__('p95', 'mcp-site-manager')}     value={`${fmt(latency.p95_ms)} ms`} color="#646970" />
        </div>
    );
}
```

- [ ] **Step 8: Create `src/dashboard/components/TopAbilitiesTable.js`**

```js
import { __ } from '@wordpress/i18n';

const fmt = (n) => Number(n).toLocaleString();

export default function TopAbilitiesTable({ rows }) {
    return (
        <table className="widefat striped" style={{ maxWidth: 900 }}>
            <thead><tr>
                <th>{__('Ability', 'mcp-site-manager')}</th>
                <th>{__('Calls', 'mcp-site-manager')}</th>
                <th>{__('Success rate', 'mcp-site-manager')}</th>
                <th>{__('Avg ms', 'mcp-site-manager')}</th>
            </tr></thead>
            <tbody>
                {rows.map((r) => (
                    <tr key={r.ability}>
                        <td><code>{r.ability}</code></td>
                        <td>{fmt(r.calls)}</td>
                        <td>{(r.success_rate * 100).toFixed(1)}%</td>
                        <td>{fmt(r.avg_ms)}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
```

- [ ] **Step 9: Create `src/dashboard/components/RecentErrorsTable.js`**

```js
import { __ } from '@wordpress/i18n';

function formatTs(mysqlDt) {
    if (!mysqlDt) return '';
    const d = new Date(mysqlDt.replace(' ', 'T') + 'Z');
    if (Number.isNaN(d.getTime())) return mysqlDt;
    return d.toLocaleString();
}

export default function RecentErrorsTable({ rows }) {
    if (!rows || rows.length === 0) {
        return <p><em>{__('No errors recorded in the current window.', 'mcp-site-manager')}</em></p>;
    }
    return (
        <table className="widefat striped" style={{ maxWidth: 900 }}>
            <thead><tr>
                <th>{__('Time', 'mcp-site-manager')}</th>
                <th>{__('Ability', 'mcp-site-manager')}</th>
                <th>{__('Code', 'mcp-site-manager')}</th>
                <th>{__('User', 'mcp-site-manager')}</th>
            </tr></thead>
            <tbody>
                {rows.map((r, i) => (
                    <tr key={`${r.ts}-${r.ability}-${i}`}>
                        <td>{formatTs(r.ts)}</td>
                        <td><code>{r.ability}</code></td>
                        <td>{r.error_code ?? ''}</td>
                        <td>{r.user_login ?? __('(unknown)', 'mcp-site-manager')}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
```

- [ ] **Step 10: Create `src/dashboard/components/WindowFooter.js`**

```js
import { __, sprintf } from '@wordpress/i18n';

function formatTs(mysqlDt) {
    if (!mysqlDt) return '';
    const d = new Date(mysqlDt.replace(' ', 'T') + 'Z');
    if (Number.isNaN(d.getTime())) return mysqlDt;
    return d.toLocaleString();
}

export default function WindowFooter({ window: w, lastUpdated }) {
    const updated = lastUpdated ? lastUpdated.toLocaleTimeString() : '—';
    return (
        <p style={{ marginTop: '1.5em' }}>
            <em>
                {sprintf(
                    __('Stats based on the last %1$s invocations between %2$s and %3$s. Last updated: %4$s.', 'mcp-site-manager'),
                    Number(w.count).toLocaleString(),
                    formatTs(w.from),
                    formatTs(w.to),
                    updated
                )}
            </em>
        </p>
    );
}
```

- [ ] **Step 11: Create `src/dashboard/components/RefreshHeader.js`**

```js
import { __ } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';

export default function RefreshHeader({ lastUpdated, loading, onManualRefresh }) {
    const updated = lastUpdated ? lastUpdated.toLocaleTimeString() : '—';
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75em', marginBottom: '1em' }}>
            <strong>{__('Stats', 'mcp-site-manager')}</strong>
            <span style={{ color: '#646970', fontSize: '0.9em' }}>
                {__('Last updated:', 'mcp-site-manager')} {updated}
            </span>
            {loading && <Spinner />}
            <Button variant="secondary" onClick={onManualRefresh} disabled={loading}>
                {__('Refresh now', 'mcp-site-manager')}
            </Button>
        </div>
    );
}
```

- [ ] **Step 12: Create `src/dashboard/components/EmptyState.js`**

```js
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

export default function EmptyState() {
    const connectionUrl = (window.mcpsmDashboard && window.mcpsmDashboard.tabUrls && window.mcpsmDashboard.tabUrls.connection) || '#';
    return (
        <div style={{
            marginTop: '2em',
            padding: '2em',
            border: '1px solid #ddd',
            background: '#fff',
            textAlign: 'center'
        }}>
            <h2>{__("You haven't run anything yet.", 'mcp-site-manager')}</h2>
            <p>{__('Once your MCP client invokes a tool, stats will show up here.', 'mcp-site-manager')}</p>
            <p>
                <Button variant="primary" href={connectionUrl}>
                    {__('See connection details →', 'mcp-site-manager')}
                </Button>
            </p>
        </div>
    );
}
```

- [ ] **Step 13: Create `src/dashboard/Dashboard.js`**

```js
import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import useStats from './hooks/useStats';
import RefreshHeader from './components/RefreshHeader';
import NumbersRow from './components/NumbersRow';
import LatencyRow from './components/LatencyRow';
import TopAbilitiesTable from './components/TopAbilitiesTable';
import RecentErrorsTable from './components/RecentErrorsTable';
import WindowFooter from './components/WindowFooter';
import EmptyState from './components/EmptyState';

export default function Dashboard() {
    const { data, loading, error, lastUpdated, refresh } = useStats();

    if (!data && loading) {
        return <div style={{ padding: '2em' }}><Spinner /></div>;
    }
    if (error && !data) {
        return (
            <Notice status="error" isDismissible={false}>
                {__('Could not load stats:', 'mcp-site-manager')} {error.message}
            </Notice>
        );
    }
    if (!data) return null;

    if (data.counts.total === 0) {
        return <EmptyState />;
    }

    return (
        <>
            <RefreshHeader lastUpdated={lastUpdated} loading={loading} onManualRefresh={refresh} />
            {error && (
                <Notice status="warning" isDismissible={false}>
                    {__('Last refresh failed:', 'mcp-site-manager')} {error.message}
                </Notice>
            )}
            <h2>{__('Numbers', 'mcp-site-manager')}</h2>
            <NumbersRow counts={data.counts} />
            <h2>{__('Latency', 'mcp-site-manager')}</h2>
            <LatencyRow latency={data.latency} />
            <h2>{__('Top abilities', 'mcp-site-manager')}</h2>
            <TopAbilitiesTable rows={data.top_abilities} />
            <h2 style={{ marginTop: '1.5em' }}>{__('Recent errors', 'mcp-site-manager')}</h2>
            <RecentErrorsTable rows={data.recent_errors} />
            <WindowFooter window={data.window} lastUpdated={lastUpdated} />
        </>
    );
}
```

- [ ] **Step 14: Replace `src/dashboard/style.scss`**

```scss
/* MCP Site Manager dashboard layout. */
#mcpsm-dashboard-root {
    margin-top: 1em;

    .components-card {
        background: #fff;
    }

    table.widefat {
        margin-top: 0;
    }
}
```

- [ ] **Step 15: Build**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npm run build 2>&1 | tail -10
ls build/
```
Expected: `build/dashboard.js`, `build/dashboard.asset.php` (likely also `build/dashboard.css`).

- [ ] **Step 16: Lint PHP**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l includes/Admin/DashboardAssets.php
php -l includes/Plugin.php
```
Expected: both `No syntax errors detected`.

- [ ] **Step 17: Visual verification**

Visit: `http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager`

Expected:
- Dashboard tab loads. After ~half a second, the React app replaces "Loading dashboard…" with the widgets (or the empty-state UI if log table is empty).
- Open browser dev console. No JS errors.
- Network tab shows a single `GET /wp-json/mcp-site-manager/v1/stats/all` returning 200 with the combined payload. No further fetches unless triggered.
- Clicking "Refresh now" triggers a fresh fetch and updates the "Last updated" timestamp.

If the log is empty, populate it by running the integration suite:

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
APP_PW=$(npx wp-env run cli wp user application-password create admin "viz" --porcelain 2>&1 | grep -E '^[a-zA-Z0-9]{20,}' | head -1)
MCPSM_APP_PW="$APP_PW" \
MCPSM_URL="http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
MCPSM_BASE_URL="http://localhost:8890" \
MCPSM_USER="admin" \
  ./vendor/bin/phpunit --testsuite=integration > /dev/null 2>&1
```

Then refresh the Dashboard tab — widgets populated.

- [ ] **Step 18: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/DashboardAssets.php includes/Plugin.php src/dashboard
git commit -m "feat(admin): React dashboard mounted via DashboardAssets, polling /stats/all"
```

(Note: `build/` is gitignored. We commit only the React source. wp.org release builds will commit the compiled artefact in a later release-prep task, out of scope here.)

---

## Task 11: Final verification

**Files:** none modified (commits only if step 1 finds drift to fix).

- [ ] **Step 1: Full unit + integration suites**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager

# unit
./vendor/bin/phpunit --testsuite=unit 2>&1 | tail -5

# integration
APP_PW=$(npx wp-env run cli wp user application-password create admin "dashboard-final" --porcelain 2>&1 | grep -E '^[a-zA-Z0-9]{20,}' | head -1)
MCPSM_APP_PW="$APP_PW" \
MCPSM_URL="http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
MCPSM_BASE_URL="http://localhost:8890" \
MCPSM_USER="admin" \
  ./vendor/bin/phpunit --testsuite=integration 2>&1 | tail -10
```
Expected:
- Unit: `OK (N tests, …)` where N = 19 (9 baseline + 10 new Stats methods + tests).
- Integration: `OK (12 tests, …)` (9 smoke + 3 RestStats).

- [ ] **Step 2: Stats query budget**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npx wp-env run cli wp eval '
$start = microtime(true);
$all = \Mrabbani\McpSiteManager\Admin\Stats::all();
$ms = (int) round((microtime(true) - $start) * 1000);
echo "stats budget: {$ms} ms (rows: {$all[\"counts\"][\"total\"]})\n";
'
```
Expected: under 100 ms.

- [ ] **Step 3: Manual UI walk-through**

1. Open `http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager` in a browser.
2. Dashboard renders with widgets within 1s.
3. Click "Refresh now" — observe a network call and the "Last updated" timestamp updates.
4. Click each non-Dashboard tab; each renders correctly.
5. On Settings tab, toggle logging off then on; click Clear log; visit Activity Log tab — empty.
6. Visit Dashboard tab — empty-state UI shows.
7. Run integration suite to repopulate the log.
8. Refresh Dashboard — widgets repopulate.

- [ ] **Step 4: Push**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git push origin main 2>&1 | tail -5
```

---

## Self-Review

### Spec coverage

| Spec section | Covered by |
|---|---|
| §2 In scope (5 tabs, default dashboard, React Dashboard, REST endpoints, polling, Stats) | T1–T10 |
| §4 Decisions (tab mechanism, React stack, /stats/all, 30s polling) | T8 (tabs), T9 (build), T10 (React + polling), T6 (REST) |
| §5 Tab layout | T8 |
| §6 Component tree (Dashboard, RefreshHeader, NumbersRow, LatencyRow, TopAbilitiesTable, RecentErrorsTable, WindowFooter, EmptyState, StatCard) | T10 (steps 5–13) |
| §7 Stats API | T1–T5 |
| §8 REST endpoints (5 individual + /stats/all) | T6 |
| §9 File layout | T1, T6, T8, T10 |
| §10 Build & dev workflow | T9 |
| §11 Permissions/security (cap check, REST nonce, escaping, prepare) | T6 (perm callback), T8 (existing nonces preserved), T10 (nonce localized) |
| §12 Acceptance criteria | T11 (all explicit) |
| §13 Risks (CASE WHEN, nonce expiry, scripts pin, build/ ignored, visibility pause, asset.php cache-bust) | T1 (CASE), T10 (visibilitychange + asset.php), T9 (gitignore + pin) |

No gaps.

### Placeholder scan

Every code step shows the actual code. Build verification steps show actual commands and expected output. No "TBD", no "similar to", no "add error handling".

The Dashboard component imports `useStats` from `./hooks/useStats` and the eight component files from `./components/*` — every imported file is created in the same task (T10 steps 4–13). No undefined references.

### Type / signature consistency

- `Stats::all()` payload keys (`counts`, `latency`, `top_abilities`, `recent_errors`, `window`) match REST `/stats/all` response, match `useStats` consumer, match every individual component's prop shape.
- `Stats::counts()` returns `{total, success, error, success_rate}` — consumed by `<NumbersRow counts={…}>` via `counts.total/.success/.error/.success_rate`. Match.
- `Stats::latency()` returns `{avg_ms, p95_ms}` — consumed by `<LatencyRow latency={…}>`. Match.
- `Stats::top_abilities()` rows have `{ability, calls, success_rate, avg_ms}` — consumed by `<TopAbilitiesTable>`. Match.
- `Stats::recent_errors()` rows have `{ts, ability, error_code, user_id, user_login}` — consumed by `<RecentErrorsTable>` (uses ts/ability/error_code/user_login). Match.
- `Stats::window()` returns `{from, to, count}` — consumed by `<WindowFooter window={…}>`. Match.
- Tab slugs (`dashboard|connection|abilities|log|settings`) consistent across `TABS` const, dispatcher switch, `current_tab()` allowlist, nav rendering, `DashboardAssets::maybe_enqueue` gate, and tab-bouncing redirects in handlers.
- REST namespace `mcp-site-manager/v1` consistent across `StatsController::NAMESPACE`, `DashboardAssets` localized URL, `useStats` apiFetch path, and integration tests.

No drift.

---

## Execution Handoff

Plan complete and saved to `wp-content/plugins/mcp-site-manager/docs/superpowers/plans/2026-05-11-admin-dashboard.md`.

The user has chosen subagent-driven execution. Proceeding directly.
