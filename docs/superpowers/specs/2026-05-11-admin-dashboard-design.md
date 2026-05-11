# Admin Dashboard — Design Spec

**Status:** Draft, awaiting user approval
**Date:** 2026-05-11 (revised: React frontend)
**Author:** brainstormed via superpowers:brainstorming
**Plugin:** mcp-site-manager

## 1. Purpose

Add a Dashboard tab to the existing `Settings → MCP Site Manager` admin page that shows aggregated statistics about MCP activity: total/success/error counts, latency, top-used abilities, and recent errors. The Dashboard tab is a React app served from a `<div>` mount point; the four other tabs stay server-rendered PHP. Surfaces what's happening on the MCP layer at a glance, with auto-refresh and no full page reloads.

## 2. Scope

### In scope

- Convert the existing single-page admin into a 5-tab page (`Dashboard`, `Connection`, `Abilities`, `Activity Log`, `Settings`).
- Default tab is `Dashboard`.
- Build a React app for the Dashboard tab using WordPress's first-party React stack: `@wordpress/scripts`, `@wordpress/element`, `@wordpress/components`, `@wordpress/api-fetch`. No third-party React/Vite chain.
- 5 REST endpoints under `mcp-site-manager/v1` namespace expose the Stats data as JSON.
- React Dashboard renders four data widgets (Numbers, Latency, Top 10 abilities, Recent 20 errors) plus a footer line describing the data window.
- Single fetch on mount; manual "Refresh now" button to re-fetch on demand. No auto-polling.
- Render an empty-state message when no activity is recorded yet.
- Pure-data PHP class `Admin\Stats` owns all aggregation SQL; both the REST controller and (potentially future) PHP code consume it.
- Unit tests for `Stats`. REST endpoints integration-tested via wp-env.
- The other four tabs (Connection, Abilities, Activity Log, Settings) are PHP, unchanged behaviour, just relocated into per-tab render methods.

### Out of scope (deferred)

- Time-series charts (would require chart library — Chart.js, Recharts, etc.).
- Daily-rollup table for historical analytics beyond the current 1000-row log.
- Filters (date range picker, ability filter).
- CSV export.
- Per-user dashboard tile (multi-user analytics).
- Auto-refresh / polling / real-time push. Single load + manual refresh only.
- Server-side rendering of the React app.
- React on the other four tabs.

## 3. Non-goals

- Not a long-term analytics product. Data window is bounded by the existing 1000-row log table — explicitly disclosed in the UI footer.
- Not a settings interface. Logging toggle and clear-log moved to a dedicated `Settings` tab; the Dashboard tab is read-only.
- Not a SPA. Each non-Dashboard tab is a normal full-page-load WP admin page.

## 4. Decisions

| Choice | Decision |
|---|---|
| Placement | Tab on existing Settings page (no new menu). |
| Tab mechanism | `?tab=<slug>` query parameter; one URL per tab; bookmarkable; full-page reload on tab change. |
| Dashboard rendering | React, mounted at `<div id="mcpsm-dashboard-root"></div>`. |
| Other tabs rendering | PHP (`render_connection`, `render_abilities`, `render_log`, `render_settings`). |
| React stack | `@wordpress/scripts` build + `@wordpress/element` runtime + `@wordpress/components` UI primitives + `@wordpress/api-fetch` data layer. No React Router (single mount point). No Redux (component-local `useState`). |
| Data layer | New REST namespace `mcp-site-manager/v1` with 5 GET endpoints (`/stats/counts`, `/stats/latency`, `/stats/top-abilities`, `/stats/recent-errors`, `/stats/window`). Permission callback: `current_user_can('manage_options')`. |
| Combined endpoint | One additional `/stats/all` endpoint returns all five payloads in one request. The React app uses `/stats/all` to minimise round-trips. The individual endpoints exist for completeness and possible future per-widget refresh. |
| Refresh | Single fetch on component mount. Manual "Refresh now" button. "Last updated: hh:mm:ss" timestamp visible. No polling. |
| Caching | Live SQL on every REST call. No transients. (Sub-5ms against indexed 1000-row table.) |
| Retention | Existing 1000-row cap unchanged. Dashboard discloses window in footer. |
| Auth | REST nonce (`X-WP-Nonce` from `wpApiSettings` localised in PHP) + page-level `current_user_can('manage_options')`. |

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

| Existing section | New tab | Renderer |
|---|---|---|
| Status (deps + connection dots) | `connection` (paired with Connection panel) | PHP |
| Connection (URL, copy, snippet) | `connection` | PHP |
| Registered abilities table | `abilities` | PHP |
| Activity log table (last 50 rows) | `log` | PHP |
| Logging toggle + Clear log buttons | `settings` | PHP |
| (new) Aggregated stats | `dashboard` | React |

## 6. Dashboard widgets (React component tree)

```
<Dashboard>
├── <RefreshHeader lastUpdated={…} loading={…} onManualRefresh={…} />
│   └── shows "Last updated 12:34:56  ⟳"
├── <EmptyState />            ← if data.counts.total === 0
└── <DashboardContent data={…}>
    ├── <NumbersRow counts={…} />
    │   └── 4 <StatCard /> (Total, Success, Errors, Success rate)
    ├── <LatencyRow latency={…} />
    │   └── 2 <StatCard /> (Average, p95)
    ├── <TopAbilitiesTable rows={…} />
    │   └── @wordpress/components <TableRow>, sorted by calls desc
    ├── <RecentErrorsTable rows={…} />
    │   └── empty-table message if no errors
    └── <WindowFooter window={…} />
```

`<StatCard>` is a small reusable component: `{ label, value, color }`. Renders a colored box with the value and label using @wordpress/components `Card`.

Tables use @wordpress/components if the right primitive exists, otherwise plain `<table className="widefat striped">` to match the rest of wp-admin.

### 6.1 Numbers
Four side-by-side stat cards using flex layout.

| Card | Source |
|---|---|
| **Total** | `data.counts.total` |
| **Success** | `data.counts.success` |
| **Errors** | `data.counts.error` |
| **Success rate** | `data.counts.success_rate` formatted as `94.2%` |

Errors card is `#d63638` (WP red) when count > 0; success rate card is `#00a32a` (WP green) when rate ≥ 95%; otherwise neutral grey.

### 6.2 Latency
Two cards side-by-side: `Average 47 ms` | `p95 312 ms`.

### 6.3 Top 10 abilities
Table: Ability | Calls | Success rate | Avg ms. Sorted by Calls desc. Source: `data.top_abilities`.

### 6.4 Recent 20 errors
Table: Time | Ability | Error code | User. Sorted by id desc. Source: `data.recent_errors`. The Time column shows local date/time formatted client-side. The User column shows `user_login`; if null, show `(unknown)`.

### 6.5 Footer
Single italic line under the last table:

> *Stats based on the last N invocations between {from} and {to}. Last updated: {hh:mm:ss}.*

### Empty state

When `data.counts.total === 0`, the entire Dashboard body is replaced with:

```
You haven't run anything yet.
Once your MCP client invokes a tool, stats will show up here.
[See connection details →]   ← link to ?tab=connection
```

### Loading & error states

- **Initial load:** spinner from `@wordpress/components` `<Spinner />`.
- **Refresh load:** keep current data visible; show small spinner in `<RefreshHeader>` next to the timestamp; overlay opacity if you want.
- **Fetch error:** `<Notice status="error">` from @wordpress/components with the error message and a retry button. Doesn't blank the screen.

## 7. `Admin\Stats` API

Same as the original spec — 5 pure-data static methods. (Unchanged from the PHP-only design; React just consumes them via REST.)

```php
namespace Mrabbani\McpSiteManager\Admin;

final class Stats
{
    /** @return array{total:int, success:int, error:int, success_rate:float} */
    public static function counts(): array;

    /** @return array{avg_ms:int, p95_ms:int} */
    public static function latency(): array;

    /** @return array<int, array{ability:string, calls:int, success_rate:float, avg_ms:int}> */
    public static function top_abilities(int $limit = 10): array;

    /** @return array<int, array{ts:string, ability:string, error_code:?string, user_id:int, user_login:?string}> */
    public static function recent_errors(int $limit = 20): array;

    /** @return array{from:?string, to:?string, count:int} */
    public static function window(): array;
}
```

## 8. REST API

New REST controller `Admin\Rest\StatsController` registers under namespace `mcp-site-manager/v1`. All routes:

- Method: `GET`
- Permission callback: `current_user_can('manage_options')` (returns `WP_Error` 401 otherwise)
- Authentication: cookie + REST nonce (default WP REST behaviour for admin contexts)

| Route | Returns |
|---|---|
| `/stats/counts` | `{ total, success, error, success_rate }` |
| `/stats/latency` | `{ avg_ms, p95_ms }` |
| `/stats/top-abilities?limit=10` | `[{ ability, calls, success_rate, avg_ms }, …]` |
| `/stats/recent-errors?limit=20` | `[{ ts, ability, error_code, user_id, user_login }, …]` |
| `/stats/window` | `{ from, to, count }` |
| `/stats/all` | `{ counts, latency, top_abilities, recent_errors, window }` — single round-trip for the dashboard |

The React app uses `/stats/all` exclusively in v1; the per-widget endpoints exist for future composability.

## 9. File layout

```
includes/Admin/
├── SettingsPage.php       # rewritten as tab dispatcher (tabs: dashboard, connection, abilities, log, settings)
├── DashboardAssets.php    # NEW — enqueues the React build, localizes nonces + REST URL
├── Stats.php              # NEW — aggregation collaborator (PHP-only)
└── Rest/
    └── StatsController.php # NEW — REST routes that delegate to Stats

src/dashboard/
├── index.js               # NEW — entry: createRoot + render <Dashboard />
├── Dashboard.js           # NEW — top-level component
├── components/
│   ├── StatCard.js
│   ├── NumbersRow.js
│   ├── LatencyRow.js
│   ├── TopAbilitiesTable.js
│   ├── RecentErrorsTable.js
│   ├── WindowFooter.js
│   ├── RefreshHeader.js
│   └── EmptyState.js
├── hooks/
│   └── useStats.js        # NEW — data hook: fetch /stats/all, polling, error state
└── style.scss             # NEW — minimal layout CSS

build/                     # @wordpress/scripts output (gitignored)

tests/
├── Support/StatsTest.php       # NEW — unit tests for Stats
└── Integration/RestStatsTest.php # NEW — wp-env integration tests for REST endpoints
```

`DashboardAssets.php` registers and enqueues the build assets only when on the Dashboard tab (cap-checked), and uses `wp_localize_script` to expose:

```js
window.mcpsmDashboard = {
  restUrl: 'http://site.test/wp-json/mcp-site-manager/v1',
  nonce: '...',
  tabUrls: { connection: '...' },   // for "See connection details →" link
};
```

`@wordpress/scripts` handles the build (`webpack` underneath). Build entry: `src/dashboard/index.js` → `build/dashboard.js` + `build/dashboard.css` + `build/dashboard.asset.php` (for dependency declarations).

## 10. Build & dev workflow

### Dev dependencies (added to `package.json`)

```json
{
  "devDependencies": {
    "@wordpress/env": "^10.0.0",
    "@wordpress/scripts": "^30.0.0"
  }
}
```

### npm scripts

```json
{
  "scripts": {
    "wp-env": "wp-env",
    "build": "wp-scripts build src/dashboard/index.js",
    "start": "wp-scripts start src/dashboard/index.js",
    "test:int": "..."
  }
}
```

`npm run start` watches and rebuilds; `npm run build` produces the production bundle. The build output (`build/`) is gitignored; for wp.org distribution we'll commit a built copy when shipping a release.

### `.gitignore` additions

```
/build/
```

(Already present from earlier work.)

## 11. Permissions and security

- Page-level: `current_user_can('manage_options')` (already enforced at `add_options_page` registration).
- REST endpoints: each route's `permission_callback` returns `current_user_can('manage_options')`.
- Tab parameter validated against the hard-coded allowlist.
- All output (PHP and React JSX) is auto-escaped — React escapes by default; PHP uses `esc_html`/`esc_attr`/`esc_url`.
- SQL: all `Stats` queries use `$wpdb->prepare()` for any non-constant integer LIMIT.
- The existing nonce-protected admin-post handlers (clear-log, toggle-log) keep their existing nonce protection — moved into the Settings tab unchanged.

## 12. Acceptance criteria

The feature is complete when:

1. Visiting `Settings → MCP Site Manager` shows the Dashboard tab (React app) by default with the five widgets (Numbers, Latency, Top abilities, Recent errors, Footer).
2. Clicking "Refresh now" re-fetches; "Last updated" timestamp updates.
3. Clicking each tab navigates via `?tab=` and renders the corresponding section without error.
4. With at least one row in `wp_mcpsm_log`, all four data widgets show real numbers (no JS console errors, no PHP notices in `wp-content/debug.log`).
5. With zero rows, the empty-state UI renders.
6. REST: a logged-in admin can `GET /wp-json/mcp-site-manager/v1/stats/all` with a valid nonce and receive 200 + the combined payload.
7. REST: a logged-out request returns 401.
8. `php -l` passes on every changed PHP file; `npm run build` completes without errors.
9. `./vendor/bin/phpunit --testsuite=unit` passes — including the new `StatsTest`.
10. `./vendor/bin/phpunit --testsuite=integration` passes — including the new `RestStatsTest`.
11. The Activity log tab still shows the existing last-50-rows table.
12. The Settings tab still shows the logging toggle and clear-log button, both functional with their nonces.
13. `/stats/all` round-trips in under 100ms with a 1000-row log.

## 13. Risks

- **MySQL boolean expression**: `SUM(status='ok')` rejected by some configs. Spec uses `SUM(CASE WHEN status='ok' THEN 1 ELSE 0 END)`.
- **REST nonce expiry**: WP REST nonces last ~12 hours. After expiry the dashboard will get 403s. Mitigation: detect 403 in the React fetch error handler and show a "Refresh the page to renew session" notice.
- **`@wordpress/scripts` version drift**: pin in `package.json` and document supported version range in README.
- **`build/` not committed**: dev workflow requires `npm install && npm run build` before activation. Documented in README. wp.org release builds will commit `build/`.
- **Browser cache of `build/dashboard.js`**: `wp-scripts` emits a version hash in `build/dashboard.asset.php` so cache-busting is automatic.

## 14. Open questions

None. All design dimensions are resolved.
