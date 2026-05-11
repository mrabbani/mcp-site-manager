# Abilities — DataViews + Switch Revision (v1.2)

**Status:** Draft
**Date:** 2026-05-11
**Plugin:** mcp-site-manager
**Supersedes:** `2026-05-11-abilities-toggle-design.md` for the UI layer; the data layer (`Support\DisabledAbilities`, registration filter) is unchanged.

## 1. Why

Two issues with v1.1:
1. **WP convention**: row-leading checkboxes are reserved for **bulk actions**. Per-row state changes belong on a `ToggleControl` (Switch). Mixing both in one table is confusing.
2. **Reinventing search/sort/pagination** in PHP+JS when DataViews already ships in WP core (via `@wordpress/dataviews`) and we already have a React build pipeline from the Dashboard.

v1.2 replaces the PHP form with a React + DataViews table. Same data layer; new UI; new REST endpoints to drive per-row updates without page reloads.

## 2. Scope

### In scope

- Replace `render_abilities()` body with a 1-line React mount: `<div id="mcpsm-abilities-root">`.
- Build a second React entry `src/abilities/` (sibling of `src/dashboard/`).
- Render `<DataViews>` with `view.type = 'table'`, fields: `name`, `bundle`, `description`, `tool_name`, `enabled`. Built-in search via `enableGlobalSearch: true`.
- The `enabled` field's `render` callback returns a `<ToggleControl>` (Switch) bound to a per-row update handler.
- Remove the v1.1 `mcpsm_save_abilities` admin-post handler and the inline JS search filter — replaced by REST + DataViews.
- Three new REST routes under `mcp-site-manager/v1`:
  - `GET /abilities` — list every potential ability with `enabled` state and metadata.
  - `PUT /abilities/{name}/enabled` body `{enabled: bool}` — set per-ability state.
  - `DELETE /abilities/disabled` — clear the disabled list (re-enable all).
- New `Admin\AbilitiesAssets` enqueues the build only when on the Abilities tab.
- New `Admin\Rest\AbilitiesController` registers the 3 routes.

### Out of scope (deferred)

- DataViews "actions" toolbar items beyond Re-enable all (e.g. bulk disable a bundle). v2.
- Optimistic UI rollback animations / undo. v2.
- Bundle-level toggle. v2.

## 3. Decisions

| Choice | Decision |
|---|---|
| UI framework | DataViews (`@wordpress/dataviews`) for the table, `@wordpress/components` `ToggleControl` for the per-row Switch. |
| Search | DataViews built-in (set `enableGlobalSearch: true` on `name`/`description`/`bundle` fields). Drop the inline JS. |
| Save UX | Per-row toggle change → immediate `apiFetch` PUT. Optimistic local state update; revert on REST error. No bulk save. |
| Reset | DataViews `actions` toolbar item "Re-enable all" → `apiFetch` DELETE. |
| Data fetch | One `GET /abilities` call on mount. The PUT/DELETE responses return the updated full list so the React state can replace its `items` array on each save (cheap; ≤74 rows). |
| Build | Second entry `abilities` in `webpack.config.js`. Output: `build/abilities.js`, `build/abilities.asset.php`, `build/style-abilities.css`. |
| DataViews stylesheet | SCSS import via relative node_modules path (`@import '../../../node_modules/@wordpress/dataviews/build-style/style.css';`) — bypasses dependency-extraction-webpack-plugin externalization. |
| PHP style deps | `['wp-components']` only. Do NOT list `wp-dataviews` (it's a script handle, not a style handle). |
| Permission | All 3 REST routes require `current_user_can('manage_options')`. Identical pattern to existing StatsController. |

## 4. UX flow

```
Settings → MCP Site Manager → Abilities
┌──────────────────────────────────────────────────────────────────────┐
│ Registered abilities                                                  │
│ Disable individual abilities to hide them from MCP clients.          │
│                                                                       │
│ ┌────────────────────────────────────────────────────────────────┐   │
│ │ DataViews toolbar: [Search…] [Filters] [Re-enable all]         │   │
│ ├────────────────────────────────────────────────────────────────┤   │
│ │ Name              │ Bundle    │ Description     │ Tool   │ ☑   │   │
│ ├────────────────────────────────────────────────────────────────┤   │
│ │ mcpsm/posts-list  │ Posts     │ List blog posts │ mcpsm- │ ●━○ │   │
│ │ mcpsm/posts-create│ Posts     │ Create a post   │ mcpsm- │ ●━○ │   │
│ │ mcpsm/themes-del  │ Themes    │ Delete a theme  │ mcpsm- │ ○━● │  ← disabled
│ │ ...                                                                │
│ │ [pagination: 1 2 3 ...] [20 per page ▼]                            │
│ └────────────────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────────────┘
```

Click Switch → instant local toggle + PUT request → on success, refresh items from the response. On failure: revert toggle + show `<Notice status="error">` at the top.

## 5. File layout

```
includes/
├── Support/DisabledAbilities.php     # UNCHANGED (from v1.1)
├── Abilities/AbilityBundle.php       # UNCHANGED (skip-disabled already in place)
├── Plugin.php                        # MODIFY: register Rest\AbilitiesController + AbilitiesAssets
└── Admin/
    ├── SettingsPage.php              # MODIFY: render_abilities() becomes 1-line mount; remove handle_save_abilities + admin-post action; remove all_local_ability_names + bundle_label helpers (move to controller)
    ├── AbilitiesAssets.php           # NEW: enqueue build only on Abilities tab
    └── Rest/AbilitiesController.php  # NEW: GET list / PUT enabled / DELETE disabled

src/abilities/
├── index.js                          # NEW: createRoot mount
├── Abilities.js                      # NEW: top-level component, fetches, holds state
├── style.scss                        # NEW: imports DataViews stylesheet via relative path
└── (no extra components needed — DataViews handles the chrome)
```

The v1.1 `mcpsm_save_abilities` admin-post handler is removed in this revision.

## 6. REST API

All routes in `mcp-site-manager/v1` namespace. Permission: `current_user_can('manage_options')`.

### GET /abilities

Returns the full ability inventory with metadata and current enabled state.

```json
{
  "items": [
    {
      "id": "posts-list",
      "name": "mcpsm/posts-list",
      "tool_name": "mcpsm-posts-list",
      "label": "List posts",
      "description": "List blog posts...",
      "bundle": "Posts",
      "enabled": true
    },
    ...
  ],
  "disabled_count": 2,
  "total": 74
}
```

The `id` field equals the local ability name (without `mcpsm/`) — used as DataViews item id and as the URL path segment for the PUT route.

### PUT /abilities/{name}/enabled

Body: `{"enabled": true}` or `{"enabled": false}`.
`{name}` is the local ability name (e.g. `posts-list`). Validated against the known inventory; unknown names return 404.

Response: same shape as `GET /abilities` (the updated full list). Lets the React app replace its items array in one shot.

### DELETE /abilities/disabled

Clears the disabled-list option. Response: same shape as `GET /abilities` with `disabled_count: 0` and every `enabled: true`.

## 7. AbilitiesController PHP

```php
namespace Mrabbani\McpSiteManager\Admin\Rest;

final class AbilitiesController
{
    public const NAMESPACE = 'mcp-site-manager/v1';

    public static function register_routes(): void { /* 3 register_rest_route calls */ }

    public static function permission_check() { /* identical to StatsController */ }

    /** Build the inventory snapshot consumed by all 3 endpoints. */
    public static function snapshot(): array { /* loops bundles, marries with DisabledAbilities::all() */ }

    public static function get_list(): WP_REST_Response { return new WP_REST_Response(self::snapshot()); }
    public static function update_enabled(WP_REST_Request $r): WP_REST_Response|WP_Error { /* validate name, DisabledAbilities::set */ }
    public static function reset(): WP_REST_Response { DisabledAbilities::clear(); return new WP_REST_Response(self::snapshot()); }
}
```

## 8. AbilitiesAssets PHP

Identical pattern to `DashboardAssets`. Enqueues `build/abilities.js` + `build/style-abilities.css` only when on the Abilities tab. Localizes `window.mcpsmAbilities = { restUrl, nonce }`.

## 9. webpack.config.js change

Add a second entry:

```js
module.exports = {
    ...defaultConfig,
    entry: {
        dashboard: path.resolve(__dirname, 'src/dashboard/index.js'),
        abilities: path.resolve(__dirname, 'src/abilities/index.js'),
    },
};
```

`@wordpress/scripts` produces matching `build/abilities.js`, `build/abilities.asset.php`, and `build/style-abilities.css` for each entry.

## 10. React `Abilities.js` component

```jsx
import { useState, useEffect, useMemo } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { ToggleControl, Notice, Button, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const DEFAULT_VIEW = {
  type: 'table',
  page: 1,
  perPage: 20,
  search: '',
  sort: { field: 'name', direction: 'asc' },
  fields: [ 'name', 'bundle', 'description', 'tool_name', 'enabled' ],
  layout: {},
};

export default function Abilities() {
  const [ items, setItems ] = useState( null );
  const [ error, setError ] = useState( null );
  const [ view, setView ] = useState( DEFAULT_VIEW );

  // Initial load
  useEffect( () => {
    apiFetch({ path: '/mcp-site-manager/v1/abilities' })
      .then( r => setItems( r.items ) )
      .catch( e => setError( e.message ) );
  }, [] );

  const fields = useMemo(() => ([
    { id: 'name', label: __('Name', 'mcp-site-manager'), enableGlobalSearch: true,
      render: ({ item }) => <code>{ item.name }</code> },
    { id: 'bundle', label: __('Bundle', 'mcp-site-manager'), enableGlobalSearch: true,
      elements: [...new Set((items||[]).map(i=>i.bundle))].sort().map(b=>({ value:b, label:b })) },
    { id: 'description', label: __('Description', 'mcp-site-manager'), enableGlobalSearch: true },
    { id: 'tool_name', label: __('Tool name', 'mcp-site-manager'),
      render: ({ item }) => <code>{ item.tool_name }</code> },
    { id: 'enabled', label: __('Enabled', 'mcp-site-manager'),
      render: ({ item }) => (
        <ToggleControl
          checked={ item.enabled }
          onChange={ (next) => toggle( item.id, next ) }
          __nextHasNoMarginBottom
        />
      ),
      elements: [{ value: true, label: __('Enabled','mcp-site-manager') },
                 { value: false, label: __('Disabled','mcp-site-manager') }] },
  ]), [ items ] );

  const { data, paginationInfo } = useMemo(
    () => items ? filterSortAndPaginate( items, view, fields ) : { data: [], paginationInfo: { totalItems: 0, totalPages: 0 } },
    [ items, view, fields ]
  );

  const toggle = ( id, next ) => {
    // Optimistic update
    setItems( prev => prev.map( i => i.id === id ? { ...i, enabled: next } : i ) );
    apiFetch({
      path: `/mcp-site-manager/v1/abilities/${id}/enabled`,
      method: 'PUT',
      data: { enabled: next },
    })
      .then( r => setItems( r.items ) )
      .catch( e => {
        setError( e.message );
        // Revert
        setItems( prev => prev.map( i => i.id === id ? { ...i, enabled: !next } : i ) );
      });
  };

  const reset = () => {
    apiFetch({ path: '/mcp-site-manager/v1/abilities/disabled', method: 'DELETE' })
      .then( r => setItems( r.items ) )
      .catch( e => setError( e.message ) );
  };

  if ( items === null && !error ) return <Spinner />;
  if ( error && items === null ) return <Notice status="error" isDismissible={false}>{ error }</Notice>;

  const disabledCount = items.filter( i => !i.enabled ).length;

  return (
    <>
      <div style={{ display:'flex', gap:'1em', alignItems:'center', marginBottom:'1em' }}>
        <Button variant="secondary" onClick={ reset } disabled={ disabledCount === 0 }>
          { __( 'Re-enable all', 'mcp-site-manager' ) }
        </Button>
        <span style={{ color:'#646970' }}>
          { disabledCount } { __( 'disabled', 'mcp-site-manager' ) } / { items.length } { __( 'total', 'mcp-site-manager' ) }
        </span>
      </div>
      { error && <Notice status="error" onRemove={ () => setError(null) }>{ error }</Notice> }
      <DataViews
        data={ data }
        fields={ fields }
        view={ view }
        onChangeView={ setView }
        paginationInfo={ paginationInfo }
        defaultLayouts={ { table: {} } }
        getItemId={ ( item ) => String( item.id ) }
      />
    </>
  );
}
```

## 11. Acceptance criteria

1. Abilities tab loads. After ~half-second, DataViews table renders with 74 rows.
2. Search bar filters live across name/bundle/description.
3. Toggling a Switch immediately reflects in the UI; the option is updated; the next page-load (or a `tools/list` MCP call) confirms the ability is gone/back.
4. Re-enable all clears every disabled state in one click.
5. The v1.1 inline JS filter and the bulk Save/Reset form are gone (removed, not commented out).
6. The v1.1 admin-post handler `mcpsm_save_abilities` is gone (no longer registered).
7. Unit tests still pass (the data-layer tests are unchanged).
8. Integration tests still pass (existing 12).
9. New: `GET /abilities`, `PUT /abilities/{name}/enabled`, `DELETE /abilities/disabled` all return 200 for an authenticated admin and 401 for an unauthenticated request.
10. No JS console errors. No PHP notices. No `wp-dataviews` style-handle warnings (Approach 1 is followed).
11. The Dashboard tab still works (the existing build entry is untouched).

## 12. Risks

- **Stylesheet pitfall** — easy to make the DataViews unstyled. The plan codifies the SCSS-via-relative-path approach.
- **DataViews version pinning** — match the WP core version to avoid duplicate React tree warnings. We're on WP 6.8+ which ships a recent DataViews; pin to `^4.0.0` (or whatever current major matches), tested against installed wp-env.
- **Optimistic toggle race** — if the user toggles fast, multiple in-flight requests could land out of order. Acceptable at v1.2 (admin UI, single user); add request-id sequencing in v2 if it becomes an issue.
- **Bulk action ambiguity removed** — by removing the v1.1 form, no one can confuse per-row toggles with bulk selection. Win.
