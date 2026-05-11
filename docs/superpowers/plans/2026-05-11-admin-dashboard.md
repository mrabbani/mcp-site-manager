# Admin Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Dashboard tab to `Settings → MCP Site Manager` that shows aggregated stats (Numbers, Latency, Top 10 abilities, Recent 20 errors + window footer) computed live from the existing `wp_mcpsm_log` table, plus restructure the existing single-page admin into 5 tabs.

**Architecture:** TDD a pure-data `Admin\Stats` class (5 static methods, one SQL query each, with one LEFT JOIN to `wp_users`). Then refactor `SettingsPage::render()` into a `?tab=`-based dispatcher with one render method per tab. No JS, no transient cache, no schema changes.

**Tech Stack:** PHP 8.0+, WordPress 6.8+, PHPUnit via wp-env, `$wpdb`. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-05-11-admin-dashboard-design.md`

**Working dir for every task:** `/Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager`

---

## File map

| File | Action | Purpose |
|---|---|---|
| `includes/Admin/Stats.php` | **Create** | Pure-data aggregations: `counts`, `latency`, `top_abilities`, `recent_errors`, `window`. |
| `tests/Support/StatsTest.php` | **Create** | Unit tests using a fixture wpdb stub. |
| `tests/Support/fixtures/wpdb.php` | **Create** | Minimal `$wpdb` + `WPDB` stand-in for unit tests (doesn't need a real database). |
| `includes/Admin/SettingsPage.php` | **Modify** | `render()` becomes tab dispatcher; existing UI moves into `render_connection`, `render_abilities`, `render_log`, `render_settings`; new `render_dashboard`. |

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
 * and an "users" array (id => login). Each query method inspects the SQL string and
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
        // Trivial substitution: replace each %d/%s in order with the next arg.
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
        // status COUNT(*) GROUP BY status
        if (preg_match('/SELECT\s+status,\s*COUNT\(\*\)\s+(?:AS\s+)?c\s+FROM/i', $sql)) {
            $out = [];
            foreach (['ok', 'error'] as $s) {
                $out[] = ['status' => $s, 'c' => count(array_filter($this->rows, fn($r) => $r['status'] === $s))];
            }
            return $out;
        }
        // AVG + COUNT
        if (preg_match('/SELECT\s+AVG\(duration_ms\)\s+(?:AS\s+)?avg_ms,\s*COUNT\(\*\)\s+(?:AS\s+)?c\s+FROM/i', $sql)) {
            $n = count($this->rows);
            $avg = $n === 0 ? null : array_sum(array_column($this->rows, 'duration_ms')) / $n;
            return [['avg_ms' => $avg, 'c' => $n]];
        }
        // ORDER BY duration_ms ASC LIMIT 1 OFFSET N (p95)
        if (preg_match('/SELECT\s+duration_ms\s+FROM[^O]*ORDER\s+BY\s+duration_ms\s+ASC\s+LIMIT\s+1\s+OFFSET\s+(\d+)/i', $sql, $m)) {
            $offset = (int) $m[1];
            $sorted = array_column($this->rows, 'duration_ms');
            sort($sorted);
            return isset($sorted[$offset]) ? [['duration_ms' => $sorted[$offset]]] : [];
        }
        // top abilities
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
        // recent errors with users JOIN
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
        // window
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

- [ ] **Step 2: Write the failing test for `counts()`**

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
        // Stub AbilityLog::table_name() so Stats can resolve the table name.
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

- [ ] **Step 3: Run the test, verify it fails**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
./vendor/bin/phpunit tests/Support/StatsTest.php 2>&1 | tail -10
```
Expected: errors mentioning `Class "Mrabbani\McpSiteManager\Admin\Stats" not found` (2 errors).

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

- [ ] **Step 5: Run the test, verify it passes**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php 2>&1 | tail -5
```
Expected: `OK (2 tests, 8 assertions)`.

- [ ] **Step 6: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/Stats.php tests/Support/StatsTest.php tests/Support/fixtures/wpdb.php
git commit -m "feat(stats): add Admin\\\\Stats::counts() with unit test fixture"
```

---

## Task 2: Stats — `latency()` (TDD)

**Files:**
- Modify: `includes/Admin/Stats.php`
- Modify: `tests/Support/StatsTest.php`

- [ ] **Step 1: Add failing tests**

Add these methods inside the existing `StatsTest` class:

```php
    public function test_latency_with_rows(): void
    {
        $this->wpdb->rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $this->wpdb->rows[] = $this->row(['duration_ms' => $i * 10]); // 10..1000
        }
        $r = Stats::latency();
        // avg of 10..1000 step 10 = 505
        $this->assertSame(505, $r['avg_ms']);
        // p95 of 100 values: OFFSET = floor(100 * 0.95) = 95 → 0-indexed value at position 95 → 960
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

- [ ] **Step 2: Run failing test**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php --filter latency 2>&1 | tail -5
```
Expected: errors `Stats::latency does not exist`.

- [ ] **Step 3: Implement `latency()`**

Append inside the `Stats` class in `includes/Admin/Stats.php`:

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
            // Clamp so we never read past the last row.
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

- [ ] **Step 4: Run, verify pass**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php 2>&1 | tail -5
```
Expected: `OK (4 tests, …)`.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/Stats.php tests/Support/StatsTest.php
git commit -m "feat(stats): add Admin\\\\Stats::latency() (avg + p95)"
```

---

## Task 3: Stats — `top_abilities()` (TDD)

**Files:**
- Modify: `includes/Admin/Stats.php`
- Modify: `tests/Support/StatsTest.php`

- [ ] **Step 1: Add failing test**

Append to `StatsTest`:

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
        $r = Stats::top_abilities(3);
        $this->assertCount(3, $r);
    }
```

- [ ] **Step 2: Run, verify fail**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php --filter top_abilities 2>&1 | tail -5
```
Expected: errors `Stats::top_abilities does not exist`.

- [ ] **Step 3: Implement `top_abilities()`**

Append inside `Stats`:

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

- [ ] **Step 4: Run, verify pass**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php 2>&1 | tail -5
```
Expected: `OK (6 tests, …)`.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/Stats.php tests/Support/StatsTest.php
git commit -m "feat(stats): add Admin\\\\Stats::top_abilities()"
```

---

## Task 4: Stats — `recent_errors()` (TDD)

**Files:**
- Modify: `includes/Admin/Stats.php`
- Modify: `tests/Support/StatsTest.php`

- [ ] **Step 1: Add failing test**

Append to `StatsTest`:

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
        $this->assertSame('mcpsm/users-create', $r[0]['ability']); // newest id first
        $this->assertSame('-32603', $r[0]['error_code']);
        $this->assertSame(999, $r[0]['user_id']);
        $this->assertNull($r[0]['user_login']); // user not in users_table

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

- [ ] **Step 2: Run, verify fail**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php --filter recent_errors 2>&1 | tail -5
```
Expected: errors `Stats::recent_errors does not exist`.

- [ ] **Step 3: Implement `recent_errors()`**

Append inside `Stats`:

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

- [ ] **Step 4: Run, verify pass**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php 2>&1 | tail -5
```
Expected: `OK (8 tests, …)`.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/Stats.php tests/Support/StatsTest.php
git commit -m "feat(stats): add Admin\\\\Stats::recent_errors() with user JOIN"
```

---

## Task 5: Stats — `window()` (TDD)

**Files:**
- Modify: `includes/Admin/Stats.php`
- Modify: `tests/Support/StatsTest.php`

- [ ] **Step 1: Add failing test**

Append to `StatsTest`:

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
```

- [ ] **Step 2: Run, verify fail**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php --filter window 2>&1 | tail -5
```
Expected: errors `Stats::window does not exist`.

- [ ] **Step 3: Implement `window()`**

Append inside `Stats`:

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
```

- [ ] **Step 4: Run, verify pass**

```bash
./vendor/bin/phpunit tests/Support/StatsTest.php 2>&1 | tail -5
```
Expected: `OK (10 tests, …)`.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/Stats.php tests/Support/StatsTest.php
git commit -m "feat(stats): add Admin\\\\Stats::window()"
```

---

## Task 6: SettingsPage — refactor into tab dispatcher

**Files:**
- Modify: `includes/Admin/SettingsPage.php` (full rewrite — preserves all existing behaviour, just relocates code)

This task is a structural refactor with **no UI changes yet** (Dashboard tab will be filled in by Task 7). It moves existing rendering into per-tab methods and adds the dispatcher + nav-tab UI.

- [ ] **Step 1: Replace `includes/Admin/SettingsPage.php` with this exact content**

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin;

use Mrabbani\McpSiteManager\Plugin;

final class SettingsPage
{
    public const SLUG = 'mcp-site-manager';
    private const TABS = [
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

    private static function current_tab(): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'dashboard';
        return array_key_exists($tab, self::TABS) ? $tab : 'dashboard';
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
        // Filled in Task 7.
        echo '<p>' . esc_html__('Dashboard coming.', 'mcp-site-manager') . '</p>';
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

If wp-env is not running:
```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npx wp-env start
```

Then in a browser, visit each URL (replace `8890` with your actual port if different):

- http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager (default → Dashboard placeholder)
- http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager&tab=connection
- http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager&tab=abilities
- http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager&tab=log
- http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager&tab=settings

Each tab must render without PHP errors. The Dashboard tab shows the temporary "Dashboard coming." line. The other tabs show their existing content.

- [ ] **Step 4: Verify the post-actions still work**

In the Settings tab, click "Disable logging" then "Enable logging". Each click should redirect back to `?tab=settings` and the button label flips. Then click "Clear log" — page redirects back to Settings tab, no error. Re-visit Activity log tab — table is empty.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/SettingsPage.php
git commit -m "refactor(admin): split SettingsPage into 5 tabs via ?tab= dispatcher"
```

---

## Task 7: Dashboard — fill `render_dashboard()`

**Files:**
- Modify: `includes/Admin/SettingsPage.php` (replace just the `render_dashboard()` method body)

- [ ] **Step 1: Replace the placeholder `render_dashboard()` with this implementation**

Find the method `private static function render_dashboard(): void` in `includes/Admin/SettingsPage.php`. Replace its entire body (between the `{` and `}`) with:

```php
        $counts  = Stats::counts();
        $latency = Stats::latency();
        $top     = Stats::top_abilities(10);
        $errors  = Stats::recent_errors(20);
        $window  = Stats::window();

        if ($counts['total'] === 0) {
            $conn_url = add_query_arg(['page' => self::SLUG, 'tab' => 'connection'], admin_url('options-general.php'));
            ?>
            <div style="margin-top:2em;padding:2em;border:1px solid #ddd;background:#fff;text-align:center;">
                <h2><?php esc_html_e("You haven't run anything yet.", 'mcp-site-manager'); ?></h2>
                <p><?php esc_html_e('Once your MCP client invokes a tool, stats will show up here.', 'mcp-site-manager'); ?></p>
                <p><a class="button button-primary" href="<?php echo esc_url($conn_url); ?>"><?php esc_html_e('See connection details →', 'mcp-site-manager'); ?></a></p>
            </div>
            <?php
            return;
        }

        $rate_pct = round($counts['success_rate'] * 100, 1);
        $rate_bg  = $counts['error'] === 0 ? '#00a32a' : ($rate_pct >= 95 ? '#00a32a' : '#646970');
        $err_bg   = $counts['error'] > 0 ? '#d63638' : '#646970';
        ?>

        <h2><?php esc_html_e('Numbers', 'mcp-site-manager'); ?></h2>
        <div style="display:flex;gap:1em;flex-wrap:wrap;margin-bottom:1.5em;">
            <?php
            self::tile(esc_html__('Total', 'mcp-site-manager'), number_format_i18n($counts['total']), '#646970');
            self::tile(esc_html__('Success', 'mcp-site-manager'), number_format_i18n($counts['success']), '#00a32a');
            self::tile(esc_html__('Errors', 'mcp-site-manager'), number_format_i18n($counts['error']), $err_bg);
            self::tile(esc_html__('Success rate', 'mcp-site-manager'), $rate_pct . '%', $rate_bg);
            ?>
        </div>

        <h2><?php esc_html_e('Latency', 'mcp-site-manager'); ?></h2>
        <div style="display:flex;gap:1em;flex-wrap:wrap;margin-bottom:1.5em;">
            <?php
            self::tile(esc_html__('Average', 'mcp-site-manager'), number_format_i18n($latency['avg_ms']) . ' ms', '#646970');
            self::tile(esc_html__('p95', 'mcp-site-manager'), number_format_i18n($latency['p95_ms']) . ' ms', '#646970');
            ?>
        </div>

        <h2><?php esc_html_e('Top abilities', 'mcp-site-manager'); ?></h2>
        <table class="widefat striped" style="max-width:900px;"><thead><tr>
            <th><?php esc_html_e('Ability', 'mcp-site-manager'); ?></th>
            <th><?php esc_html_e('Calls', 'mcp-site-manager'); ?></th>
            <th><?php esc_html_e('Success rate', 'mcp-site-manager'); ?></th>
            <th><?php esc_html_e('Avg ms', 'mcp-site-manager'); ?></th>
        </tr></thead><tbody>
        <?php foreach ($top as $row): ?>
            <tr>
                <td><code><?php echo esc_html($row['ability']); ?></code></td>
                <td><?php echo esc_html(number_format_i18n($row['calls'])); ?></td>
                <td><?php echo esc_html(round($row['success_rate'] * 100, 1) . '%'); ?></td>
                <td><?php echo esc_html(number_format_i18n($row['avg_ms'])); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>

        <h2 style="margin-top:1.5em;"><?php esc_html_e('Recent errors', 'mcp-site-manager'); ?></h2>
        <?php if (empty($errors)): ?>
            <p><em><?php esc_html_e('No errors recorded in the current window.', 'mcp-site-manager'); ?></em></p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:900px;"><thead><tr>
                <th><?php esc_html_e('Time', 'mcp-site-manager'); ?></th>
                <th><?php esc_html_e('Ability', 'mcp-site-manager'); ?></th>
                <th><?php esc_html_e('Code', 'mcp-site-manager'); ?></th>
                <th><?php esc_html_e('User', 'mcp-site-manager'); ?></th>
            </tr></thead><tbody>
            <?php foreach ($errors as $row): ?>
                <tr>
                    <td><?php echo esc_html(self::format_ts($row['ts'])); ?></td>
                    <td><code><?php echo esc_html($row['ability']); ?></code></td>
                    <td><?php echo esc_html((string) ($row['error_code'] ?? '')); ?></td>
                    <td><?php echo esc_html($row['user_login'] ?? __('(unknown)', 'mcp-site-manager')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>

        <p style="margin-top:1.5em;"><em><?php
            printf(
                esc_html__('Stats based on the last %1$s invocations between %2$s and %3$s.', 'mcp-site-manager'),
                '<strong>' . esc_html(number_format_i18n($window['count'])) . '</strong>',
                '<strong>' . esc_html(self::format_ts((string) $window['from'])) . '</strong>',
                '<strong>' . esc_html(self::format_ts((string) $window['to'])) . '</strong>'
            );
        ?></em></p>
        <?php
```

- [ ] **Step 2: Add the two helper methods**

In the same file, append these two private static helpers inside the `SettingsPage` class (just before the closing `}` of the class):

```php
    private static function tile(string $label, string $value, string $color): void
    {
        printf(
            '<div style="flex:1;min-width:140px;padding:1em;border:1px solid #ddd;background:#fff;border-radius:6px;">'
            . '<div style="font-size:1.8em;font-weight:600;color:%s;">%s</div>'
            . '<div style="color:#646970;text-transform:uppercase;font-size:0.8em;letter-spacing:0.05em;margin-top:0.3em;">%s</div>'
            . '</div>',
            esc_attr($color),
            esc_html($value),
            $label
        );
    }

    private static function format_ts(string $mysql_dt): string
    {
        if ($mysql_dt === '') return '';
        $ts = strtotime($mysql_dt . ' UTC');
        if ($ts === false) return $mysql_dt;
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts);
    }
```

- [ ] **Step 3: Add `Stats` import at top of the file**

Find the existing `use` line near the top:

```php
use Mrabbani\McpSiteManager\Plugin;
```

Add the Stats import directly underneath:

```php
use Mrabbani\McpSiteManager\Plugin;
use Mrabbani\McpSiteManager\Admin\Stats;
```

(The `Stats` class is in the same `Admin\` namespace, so the `use` is technically optional — same-namespace classes resolve without it. The explicit `use` makes the dependency visible at the top of the file.)

- [ ] **Step 4: Lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l includes/Admin/SettingsPage.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 5: Run unit tests (sanity)**

```bash
./vendor/bin/phpunit --testsuite=unit 2>&1 | tail -5
```
Expected: `OK (N tests, …)` — N is the previous count plus 10 new Stats tests.

- [ ] **Step 6: Visual check in wp-admin**

If wp-env isn't running:
```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npx wp-env start
```

Trigger some ability calls so the log table has data:

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
APP_PW=$(npx wp-env run cli wp user application-password create admin "dashboard-check" --porcelain 2>&1 | grep -E '^[a-zA-Z0-9]{20,}' | head -1)
MCPSM_APP_PW="$APP_PW" \
MCPSM_URL="http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
MCPSM_USER="admin" \
  ./vendor/bin/phpunit --testsuite=integration 2>&1 | tail -3
```

This populates the log with ~30+ rows.

Then visit:
http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager

Verify:
- Numbers row shows 4 tiles with real values
- Latency row shows 2 tiles
- Top abilities table populated, sorted by Calls desc
- Recent errors table populated (or "No errors recorded…" message)
- Footer line shows the actual from/to timestamps

Check `wp-content/debug.log` (inside wp-env) for any new PHP notices/warnings:
```bash
npx wp-env run cli bash -c 'tail -50 /var/www/html/wp-content/debug.log' 2>&1 | grep -E "Notice|Warning|Fatal" | grep -v "Function WP_Abilities_Registry::get_registered" | tail -10
```
Expected: empty (the `get_registered` notices are pre-existing mcp-adapter noise, not us).

- [ ] **Step 7: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/SettingsPage.php
git commit -m "feat(admin): dashboard tab with stats tiles, top abilities, recent errors"
```

---

## Task 8: Final verification

**Files:** none modified.

- [ ] **Step 1: Full unit suite**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
./vendor/bin/phpunit --testsuite=unit 2>&1 | tail -5
```
Expected: `OK (N tests, …)`. N should be the prior baseline (9 from rename) plus 10 new Stats tests = 19. Adjust expectations if more tests were added in interim work.

- [ ] **Step 2: Full integration suite**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
APP_PW=$(npx wp-env run cli wp user application-password create admin "dashboard-final" --porcelain 2>&1 | grep -E '^[a-zA-Z0-9]{20,}' | head -1)
MCPSM_APP_PW="$APP_PW" \
MCPSM_URL="http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
MCPSM_USER="admin" \
  ./vendor/bin/phpunit --testsuite=integration 2>&1 | tail -5
```
Expected: `OK (9 tests, 70 assertions)` (the integration suite is unchanged by the dashboard work).

- [ ] **Step 3: Page-render benchmark**

A quick sanity check that the dashboard renders quickly. From inside wp-env:

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npx wp-env run cli wp eval '
$start = microtime(true);
$counts  = \Mrabbani\McpSiteManager\Admin\Stats::counts();
$latency = \Mrabbani\McpSiteManager\Admin\Stats::latency();
$top     = \Mrabbani\McpSiteManager\Admin\Stats::top_abilities(10);
$errors  = \Mrabbani\McpSiteManager\Admin\Stats::recent_errors(20);
$window  = \Mrabbani\McpSiteManager\Admin\Stats::window();
$ms = (int) round((microtime(true) - $start) * 1000);
echo "stats query budget: {$ms} ms (rows: {$counts[\"total\"]})\n";
'
```
Expected: under 100 ms. (If it's much higher with a 1000-row log, investigate index usage on `wp_mcpsm_log`.)

- [ ] **Step 4: Manual UI walk-through**

In a browser:

1. Visit `http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager` — Dashboard renders with widgets.
2. Click each tab in the nav — each renders without errors.
3. On the Settings tab, toggle logging off then on; click Clear log; verify Activity Log tab is now empty.
4. Re-trigger one ability via the Activity Log emptiness, then visit Dashboard — empty-state UI shows.
5. Re-run integration tests to repopulate, refresh Dashboard — widgets reappear.

- [ ] **Step 5: No-commit summary**

If everything passes, no commit needed. The feature is in.

If you find a bug, fix it, run unit + integration again, and commit with a `fix(admin): …` message before declaring done.

---

## Self-Review

### Spec coverage

| Spec section | Covered by |
|---|---|
| §2 In scope (5 tabs, default Dashboard, 4 widgets + footer, empty state, Stats class, unit tests) | Tasks 1–7 |
| §5 Tab layout (`?tab=` query param, 5 tabs, default fallback) | Task 6 (`current_tab()`, `TABS` const, `render_nav()`) |
| §5 Existing-content relocation table | Task 6 (each `render_*` method matches the spec table) |
| §6.1–§6.5 Numbers / Latency / Top 10 / Recent 20 / Footer + empty state | Task 7 |
| §7 `Admin\Stats` API (5 methods with documented signatures) | Tasks 1–5 (one task per method) |
| §8 File layout (Stats.php new, SettingsPage.php modified, StatsTest.php new) | Tasks 1, 6 |
| §9 Permissions, escaping, prepare(), nonce preservation | Task 6 (existing handlers preserved with their nonces; nav uses `add_query_arg`+`esc_url`; SQL uses `$wpdb->prepare()`) |
| §10 Acceptance criteria | Task 8 (all 10 criteria explicitly verified) |
| §11 Risks (boolean expression, p95 query, division by zero, p95 floor) | Task 1 (CASE WHEN), Task 2 (clamp + zero check), Task 3 (calls > 0 guard) |

No gaps.

### Placeholder scan

- All TDD test bodies are concrete code, no "// add tests for X" stubs.
- All implementation steps show the actual PHP code.
- Step 6 of Task 6 + Step 6 of Task 7 are visual verification steps with explicit URLs and expected behaviour, not "test it manually".
- The fixture wpdb is a real working class, not a stub interface.
- Tab-rendering placeholder method body in Task 6 is intentional — Task 7 fills it with full code, not "TBD". Each task is independently complete.

### Type / signature consistency

- `Stats::counts()` → `array{total:int, success:int, error:int, success_rate:float}` — consumed in Task 7 by `$counts['total']`, `$counts['success']`, `$counts['error']`, `$counts['success_rate']`. Match.
- `Stats::latency()` → `array{avg_ms:int, p95_ms:int}` — consumed via `$latency['avg_ms']`, `$latency['p95_ms']`. Match.
- `Stats::top_abilities(int): array<int, array{ability:string, calls:int, success_rate:float, avg_ms:int}>` — consumed via `foreach ($top as $row)` then `$row['ability']`, `$row['calls']`, `$row['success_rate']`, `$row['avg_ms']`. Match.
- `Stats::recent_errors(int): array<int, array{ts:string, ability:string, error_code:?string, user_id:int, user_login:?string}>` — consumed via `$row['ts']`, `$row['ability']`, `$row['error_code']`, `$row['user_login']`. Match.
- `Stats::window()` → `array{from:?string, to:?string, count:int}` — consumed via `$window['count']`, `$window['from']`, `$window['to']`. Match.
- Tab slugs (`dashboard|connection|abilities|log|settings`) used identically in `TABS` const, `render()` switch, `current_tab()` allowlist, and `render_nav()` link generation.

No inconsistencies.

---

## Execution Handoff

Plan complete and saved to `wp-content/plugins/mcp-site-manager/docs/superpowers/plans/2026-05-11-admin-dashboard.md`.

Two execution options:

1. **Subagent-Driven (recommended)** — fresh subagent per task with two-stage review.
2. **Inline Execution** — `superpowers:executing-plans` with batch checkpoints.

Which approach?
