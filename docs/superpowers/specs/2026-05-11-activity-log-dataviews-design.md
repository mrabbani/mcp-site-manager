# Activity Log — DataViews Revision (v1.3)

**Status:** Draft, ready for implementation
**Date:** 2026-05-11
**Plugin:** mcp-site-manager
**Pattern:** Mirror of `2026-05-11-abilities-dataviews-design.md` applied to the Activity Log tab.

## 1. Purpose

Replace the static PHP `<table class="widefat striped">` on the Activity Log tab with a React + `@wordpress/dataviews` table. Same data (`wp_mcpsm_log`), but with built-in column sorting, filtering, pagination, and search across ability/user/status/error_code/timestamp. No new schema, no new data — just a UI swap.

## 2. Scope

### In scope

- New REST endpoint: `GET /mcp-site-manager/v1/log?per_page=N&page=N` — paginated rows from `wp_mcpsm_log` with one `LEFT JOIN` to `wp_users` for `user_login`. Default `per_page=50`, max 200.
- Response shape:
  ```json
  {
    "items": [
      { "id": 123, "ts": "2026-05-11 11:25:25", "user_id": 1, "user_login": "admin", "ability": "mcpsm/options-update", "status": "error", "error_code": "-32001", "duration_ms": 3 },
      ...
    ],
    "total": 90,
    "page": 1,
    "per_page": 50
  }
  ```
- New `Admin\Rest\LogController` with the route + permission check (`manage_options`).
- New `Admin\LogAssets` enqueue (mirrors `AbilitiesAssets`).
- New third webpack entry `log: src/log/index.js`.
- New React component `src/log/Log.js` rendering `<DataViews>` with fields: `ts` (formatted), `user_login`, `ability` (code), `status` (badge), `error_code`, `duration_ms` (numeric).
- `SettingsPage::render_log()` becomes a 1-line React mount `<div id="mcpsm-log-root">`.
- Reuse `src/abilities/style.scss`'s DataViews stylesheet — extract to a shared file `src/shared/dataviews.scss` and import it from both `src/abilities/style.scss` and `src/log/style.scss` (smaller bundles, no duplicated DataViews CSS).

### Out of scope

- Persisting per-user view preferences (default DataViews behavior is in-memory only).
- Streaming / live updates (single GET on mount + manual refresh button only).
- CSV export.
- Filtering by date range UI (DataViews built-in filter is enough for status/ability).

## 3. Decisions

| Choice | Decision |
|---|---|
| UI framework | DataViews `table` layout, same as Abilities tab. |
| Pagination | REST-side. Client sends `?page=N&per_page=N`. Default 50, max 200. |
| Sorting | Client-side via `filterSortAndPaginate` on the current page. (For 1000-row cap this is fine; full server-side sort can come later if the cap grows.) |
| Status field | Rendered as a colored badge (`ok` green, `error` red). DataViews `elements` for filter. |
| Time field | Stored as MySQL DATETIME; rendered client-side via `new Date(ts.replace(' ','T')+'Z').toLocaleString()` — same helper as the Dashboard. |
| Refresh | Manual "Refresh now" button (no polling). |
| Shared DataViews SCSS | Extract to `src/shared/dataviews.scss` so future React tabs reuse it. |

## 4. File layout

```
includes/Admin/
├── LogAssets.php           # NEW: enqueue build on Activity Log tab
├── Rest/LogController.php  # NEW: GET /log
├── SettingsPage.php        # MODIFY: render_log() becomes 1-line mount
└── AbilityLog.php          # UNCHANGED

src/
├── shared/
│   └── dataviews.scss      # NEW: shared DataViews stylesheet import
├── abilities/
│   └── style.scss          # MODIFY: @import '../shared/dataviews.scss'; (drop the duplicated relative path)
└── log/                    # NEW
    ├── index.js
    ├── Log.js
    └── style.scss

webpack.config.js           # MODIFY: add third entry 'log'
includes/Plugin.php         # MODIFY: register LogController + LogAssets
```

## 5. REST API

`GET /mcp-site-manager/v1/log`
Query params: `page` (int, default 1, min 1), `per_page` (int, default 50, min 1, max 200).
Permission: `current_user_can('manage_options')`.

Response: see §2. Implementation: one paginated `SELECT` + one `SELECT COUNT(*)` against `wp_mcpsm_log`, with LEFT JOIN to `wp_users` for `user_login`.

## 6. React component

```
<Log>
├── <RefreshHeader lastUpdated onRefresh /> — same pattern as Dashboard
└── <DataViews data fields view onChangeView paginationInfo />
    Fields: ts | user_login | ability | status | error_code | duration_ms
```

Status badge: tiny inline div with color background, white text, padding. Matches the colored stat tile pattern from the Dashboard.

## 7. Acceptance criteria

1. Visiting `?tab=log` renders the DataViews table within 1 second.
2. All log rows for the current page show real data.
3. Sorting by ts/duration_ms/ability works.
4. Pagination controls move between pages; total reflects the table count.
5. Filter by status (ok/error) works.
6. Search filters across ability/user_login/error_code.
7. "Refresh now" re-fetches.
8. The old PHP `<table class="widefat striped">` is gone from `render_log()`.
9. Unit tests still pass.
10. Integration suite gains 2 new tests: auth 401, list returns items with the correct shape. Final count: 19 integration tests.
11. No `wp-dataviews/build-style/style.css` "not registered" notices.

## 8. Implementation order (sketch)

1. `LogController` + register in `Plugin::register_hooks()` — 1 commit.
2. `RestLogTest` integration tests — 1 commit.
3. Extract `src/shared/dataviews.scss` and re-route `src/abilities/style.scss` to it — 1 commit.
4. Add `log` entry to webpack.config.js + `src/log/index.js` + `src/log/style.scss` + `src/log/Log.js` stub + `npm run build` — 1 commit.
5. Implement real `src/log/Log.js` with DataViews + refresh header — 1 commit.
6. `LogAssets` enqueue + register in `Plugin::register_hooks()` — 1 commit.
7. `SettingsPage::render_log()` becomes 1-line mount — 1 commit.
8. Final verify + push — 1 commit if any drift.

8 tasks, each independently committable. Pattern is identical to v1.2.

## 9. Notes for the implementing session

- Copy the v1.2 plan structure verbatim — `2026-05-11-abilities-dataviews.md`. Substitute "abilities" → "log" throughout.
- The DataViews stylesheet pitfall (no `wp-dataviews` style handle, relative `node_modules` path import) is solved; reuse the shared SCSS.
- `Plugin.php` no longer wires the `mcp_adapter_default_server_config` filter — abilities are auto-discovered. README and architecture diagram already updated. No change needed for this work.
- Build artifacts (`build/`) are gitignored. Each task commits source only.
- Local dev URLs and the app-password generation command match v1.2.
