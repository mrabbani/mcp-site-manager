# Admin Dashboard — Design Spec

**Status:** Draft, awaiting user approval
**Date:** 2026-05-11
**Author:** brainstormed via superpowers:brainstorming
**Plugin:** mcp-site-manager

## 1. Purpose

Add a Dashboard tab to the existing `Settings → MCP Site Manager` admin page that shows aggregated statistics about MCP activity: total/success/error counts, latency, top-used abilities, and recent errors. Surfaces what's happening on the MCP layer at a glance, without leaving the WP admin.

## 2. Scope

### In scope

- Convert the existing single-page admin into a 5-tab page (`Dashboard`, `Connection`, `Abilities`, `Activity Log`, `Settings`).
- Default tab is `Dashboard`.
- Render four data widgets on the Dashboard tab (Numbers, Latency, Top 10 abilities, Recent 20 errors) plus a footer line describing the data window.
- Render an empty-state message when no activity is recorded yet.
- Add a pure-data collaborator class `Admin\Stats` that owns all aggregation SQL.
- Add unit tests for `Stats` against fixture rows.

### Out of scope (deferred)

- Time-series charts (would require a JS chart library).
- Daily-rollup table for historical analytics beyond the current 1000-row log.
- Filters (date range picker, ability filter).
- CSV export.
- Per-user dashboard tile (multi-user analytics).

## 3. Non-goals

- Not a real-time dashboard. Stats refresh on page load.
- Not a long-term analytics product. Data window is bounded by the existing 1000-row log table — explicitly disclosed in the UI footer.
- Not a settings interface. Logging toggle and clear-log moved to a dedicated `Settings` tab; the Dashboard tab is read-only.

## 4. Decisions

| Choice | Decision |
|---|---|
| Placement | Tab on existing Settings page (no new menu). |
| Tab mechanism | `?tab=<slug>` query parameter; one URL per tab; bookmarkable; no JS. |
| Caching | Live SQL on every page load. No transients. (Sub-5ms against indexed 1000-row table.) |
| Retention | Existing 1000-row cap unchanged. Dashboard discloses window in footer. |
| Charts | None in v1. Numbers + tables only. |
| Auth | Page-level `current_user_can('manage_options')` (unchanged). |

## 5. Tab layout

The existing `add_options_page(...)` registration stays the same. `SettingsPage::render()` becomes a tab dispatcher:

```
Settings → MCP Site Manager
┌──────────────────────────────────────────────────────────────┐
│ [Dashboard] [Connection] [Abilities] [Activity Log] [Settings] │
└──────────────────────────────────────────────────────────────┘
```

URLs:
- `?page=mcp-site-manager` (no tab) → Dashboard (default).
- `?page=mcp-site-manager&tab=dashboard`
- `?page=mcp-site-manager&tab=connection`
- `?page=mcp-site-manager&tab=abilities`
- `?page=mcp-site-manager&tab=log`
- `?page=mcp-site-manager&tab=settings`

The active tab gets WP core's `nav-tab-active` CSS class. Tab values are validated against a hard-coded allowlist; unknown values fall back to Dashboard.

The existing content moves as follows:

| Existing section | New tab |
|---|---|
| Status (deps + connection dots) | `connection` (paired with Connection panel) |
| Connection (URL, copy, snippet) | `connection` |
| Registered abilities table | `abilities` |
| Activity log table (last 50 rows) | `log` |
| Logging toggle + Clear log buttons | `settings` |

## 6. Dashboard widgets

Render order (top to bottom):

### 6.1 Numbers
Four side-by-side stat tiles using a flex container. Each tile has a large number and a small label.

| Tile | Source |
|---|---|
| **Total** | `Stats::counts()['total']` |
| **Success** | `Stats::counts()['success']` |
| **Errors** | `Stats::counts()['error']` |
| **Success rate** | `Stats::counts()['success_rate']` formatted as `94.2%` |

Tiles use simple inline CSS — flex row, white background, border, 6px border-radius, 16px padding. Error tile is colored `#d63638` (WP red) when count > 0; success tile is `#00a32a` (WP green) when rate ≥ 95%; otherwise neutral grey.

### 6.2 Latency
Two tiles side-by-side:

| Tile | Source |
|---|---|
| **Average** | `Stats::latency()['avg_ms']` formatted as `47 ms` |
| **p95** | `Stats::latency()['p95_ms']` formatted as `312 ms` |

### 6.3 Top 10 abilities
WP-admin `widefat striped` table, columns: Ability | Calls | Success rate | Avg ms. Sorted by Calls desc. Source: `Stats::top_abilities(10)`.

### 6.4 Recent 20 errors
WP-admin `widefat striped` table, columns: Time | Ability | Error code | User. Sorted by `id` desc. Source: `Stats::recent_errors(20)`. The Time column shows local date/time (via `date_i18n()` with the site's timezone). The User column shows `user_login` (joined via `Stats::recent_errors`); if `user_id = 0` or join misses, show `(unknown)`.

### 6.5 Footer
Single italic line under the last table:

> *Stats based on the last N invocations between {from} and {to}.*

Where N, from, to come from `Stats::window()`. If N=0, this line is replaced by the empty-state UI below.

### Empty state

When `Stats::counts()['total'] === 0`, the entire body of the Dashboard tab is replaced with:

```
You haven't run anything yet.

Once your MCP client invokes a tool, stats will show up here.
[See connection details →]   ← link to ?tab=connection
```

## 7. `Admin\Stats` API

```php
namespace Mrabbani\McpSiteManager\Admin;

final class Stats
{
    /**
     * @return array{total:int, success:int, error:int, success_rate:float}
     *   success_rate is a 0.0–1.0 float; UI formats as percent. 0.0 when total=0.
     */
    public static function counts(): array;

    /**
     * @return array{avg_ms:int, p95_ms:int}
     *   Both 0 when no rows.
     */
    public static function latency(): array;

    /**
     * @param int $limit  Max rows returned (1-100). Caller guarantees sane.
     * @return array<int, array{ability:string, calls:int, success_rate:float, avg_ms:int}>
     */
    public static function top_abilities(int $limit = 10): array;

    /**
     * @param int $limit  Max rows returned (1-100).
     * @return array<int, array{ts:string, ability:string, error_code:?string, user_id:int, user_login:?string}>
     *   ts is the raw DATETIME string from the log; UI formats it.
     *   user_login is null when user_id=0 or the user no longer exists.
     */
    public static function recent_errors(int $limit = 20): array;

    /**
     * @return array{from:?string, to:?string, count:int}
     *   from/to are null when count=0.
     */
    public static function window(): array;
}
```

All five methods are pure reads from `wp_mcpsm_log` (with one LEFT JOIN to `wp_users` in `recent_errors`). No state, no side effects, no caching. Each method runs at most one SQL query.

### SQL approach

- **counts** — `SELECT status, COUNT(*) FROM {table} GROUP BY status`. Reshape to `total`, `success`, `error`, `success_rate` in PHP.
- **latency** — `SELECT AVG(duration_ms) avg_ms, COUNT(*) c FROM {table}` for the average and count, then a second indexed read `SELECT duration_ms FROM {table} ORDER BY duration_ms ASC LIMIT 1 OFFSET FLOOR(c × 0.95)` for p95. Two queries acceptable for read-side simplicity. (Cap of 1000 rows means the window query is fast.)
- **top_abilities** — `SELECT ability, COUNT(*) calls, SUM(status='ok')/COUNT(*) success_rate, AVG(duration_ms) avg_ms FROM {table} GROUP BY ability ORDER BY calls DESC LIMIT %d`.
- **recent_errors** — `SELECT l.ts, l.ability, l.error_code, l.user_id, u.user_login FROM {table} l LEFT JOIN {users} u ON u.ID=l.user_id WHERE l.status='error' ORDER BY l.id DESC LIMIT %d`.
- **window** — `SELECT MIN(ts) f, MAX(ts) t, COUNT(*) c FROM {table}`.

All queries use `$wpdb->prepare()` for the integer LIMIT placeholders. Table names come from `AbilityLog::table_name()` and `$wpdb->users` (validated, never user-supplied).

## 8. File layout

```
includes/Admin/
├── SettingsPage.php   # rewritten as tab dispatcher
├── Stats.php          # NEW — aggregation collaborator
└── AbilityLog.php     # unchanged
tests/Support/
└── StatsTest.php      # NEW — unit tests against fixture rows
```

`SettingsPage.php` grows new private methods: `render_dashboard()`, `render_connection()`, `render_abilities()`, `render_log()`, `render_settings()`, plus a `current_tab()` helper that reads/sanitises `$_GET['tab']` against the allowlist.

## 9. Permissions and security

- The page is already gated at registration (`add_options_page(..., 'manage_options', ...)`). No additional check needed in tab dispatch beyond the existing `current_user_can('manage_options')` guard at the top of `render()`.
- Tab slugs are validated against `['dashboard','connection','abilities','log','settings']`. Anything else falls back to `dashboard`.
- All output is escaped via `esc_html()`, `esc_attr()`, `esc_url()`. SQL uses `$wpdb->prepare()`.
- The `Settings` tab's existing form actions (`admin-post.php`) keep their nonces (`wp_nonce_field`) and `check_admin_referer`. Unchanged.

## 10. Acceptance criteria

The feature is complete when:

1. Visiting `Settings → MCP Site Manager` shows the Dashboard tab by default with five visible widgets (Numbers, Latency, Top abilities, Recent errors, Footer).
2. Clicking each tab navigates via `?tab=` and renders the corresponding section without error.
3. With at least one row in `wp_mcpsm_log`, all four data widgets show real numbers (no `Notice` or `Warning` in `wp-content/debug.log`).
4. With zero rows, the empty-state UI renders in place of widgets.
5. `php -l` passes on every changed file.
6. `./vendor/bin/phpunit --testsuite=unit` passes — including the new `StatsTest`.
7. The Activity log tab still shows the existing last-50-rows table.
8. The Settings tab still shows the logging toggle and clear-log button, both functional.
9. No JavaScript is added (verify in source).
10. The dashboard renders in under 100ms with a 1000-row log table (single page-load query budget).

## 11. Risks

- **MySQL strict mode + `SUM(status='ok')`.** Some MySQL configs reject the boolean expression. Mitigation: use `SUM(CASE WHEN status='ok' THEN 1 ELSE 0 END)`. Spec'd that way.
- **p95 query cost.** Two queries instead of one is a tiny extra round trip. Acceptable at 1000-row scale; would revisit if log cap grows.
- **Empty-table division by zero.** All ratio calculations check `total > 0` first.
- **Floor() rounding for p95 OFFSET.** With 1000 rows, p95 = OFFSET 950, no edge case. With <20 rows, OFFSET could land on 0; correct behaviour (returns the smallest), not a bug.

## 12. Open questions

None. All design dimensions are resolved.
