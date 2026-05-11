# Activity Log — DataViews Implementation Plan (v1.3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the static PHP `<table class="widefat striped">` on the Activity Log tab with a React + `@wordpress/dataviews` table backed by a new paginated REST endpoint. No schema changes — same `wp_mcpsm_log` data, new UI.

**Architecture:** New `Admin\Rest\LogController` (`GET /log`) returns paginated rows joined to `wp_users` for `user_login`. New `src/log/` React entry renders a `<DataViews>` table. New `Admin\LogAssets` enqueues the build on the Activity Log tab. `SettingsPage::render_log()` becomes a one-line mount. DataViews stylesheet is shared with the Abilities tab via `src/shared/dataviews.scss`.

**Tech Stack:** PHP 8.0+, WP 6.8+, React via `@wordpress/element`, `@wordpress/dataviews`, `@wordpress/components`, `@wordpress/api-fetch`, `@wordpress/scripts` build.

**Spec:** `docs/superpowers/specs/2026-05-11-activity-log-dataviews-design.md`

**Reference plan (template):** `docs/superpowers/plans/2026-05-11-abilities-dataviews.md`

**Working dir for every task:** `/Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager`

---

## File map

| File | Action | Purpose |
|---|---|---|
| `includes/Admin/Rest/LogController.php` | Create | `GET /mcp-site-manager/v1/log?page=N&per_page=N` paginated rows + LEFT JOIN to wp_users. |
| `tests/Integration/RestLogTest.php` | Create | wp-env tests: auth + list shape. |
| `includes/Admin/LogAssets.php` | Create | Enqueue `build/log.js` + stylesheet on the Activity Log tab only. |
| `includes/Plugin.php` | Modify | Register `LogController` on `rest_api_init` + `LogAssets` on `admin_enqueue_scripts`. |
| `includes/Admin/SettingsPage.php` | Modify | `render_log()` becomes 1-line React mount. |
| `webpack.config.js` | Modify | Add third entry `log`. |
| `src/shared/dataviews.scss` | Create | Shared DataViews stylesheet import. |
| `src/abilities/style.scss` | Modify | Replace direct import with `@import '../shared/dataviews.scss';`. |
| `src/log/index.js` | Create | createRoot mount. |
| `src/log/Log.js` | Create | DataViews component. |
| `src/log/style.scss` | Create | `@import '../shared/dataviews.scss';` + scoping. |

---

## Task 1: LogController (REST)

**Files:**
- Create: `includes/Admin/Rest/LogController.php`
- Modify: `includes/Plugin.php`

- [ ] **Step 1: Implement the controller**

`includes/Admin/Rest/LogController.php`:

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin\Rest;

use Mrabbani\McpSiteManager\Admin\AbilityLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class LogController
{
    public const NAMESPACE = 'mcp-site-manager/v1';

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/log', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_list'],
            'permission_callback' => [self::class, 'permission_check'],
            'args' => [
                'page'     => ['type' => 'integer', 'default' => 1,  'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200],
            ],
        ]);
    }

    public static function permission_check()
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', __('You need manage_options.', 'mcp-site-manager'), ['status' => rest_authorization_required_code()]);
        }
        return true;
    }

    public static function get_list(WP_REST_Request $r): WP_REST_Response
    {
        global $wpdb;
        $page     = max(1, (int) $r->get_param('page'));
        $per_page = max(1, min(200, (int) $r->get_param('per_page')));
        $offset   = ($page - 1) * $per_page;

        $log_table   = AbilityLog::table_name();
        $users_table = $wpdb->users;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $log_table");

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id, l.ts, l.user_id, u.user_login, l.ability, l.status, l.error_code, l.duration_ms
             FROM $log_table l
             LEFT JOIN $users_table u ON u.ID = l.user_id
             ORDER BY l.id DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ), ARRAY_A);

        $items = array_map(static function ($row) {
            return [
                'id'          => (int) $row['id'],
                'ts'          => (string) $row['ts'],
                'user_id'     => (int) $row['user_id'],
                'user_login'  => $row['user_login'] !== null ? (string) $row['user_login'] : '',
                'ability'     => (string) $row['ability'],
                'status'      => (string) $row['status'],
                'error_code'  => $row['error_code'] !== null ? (string) $row['error_code'] : '',
                'duration_ms' => (int) $row['duration_ms'],
            ];
        }, $rows ?: []);

        return new WP_REST_Response([
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ]);
    }
}
```

- [ ] **Step 2: Register on `rest_api_init`**

In `includes/Plugin.php`, in `register_hooks()` alongside the existing AbilitiesController line, add:

```php
        add_action('rest_api_init', [\Mrabbani\McpSiteManager\Admin\Rest\LogController::class, 'register_routes']);
```

- [ ] **Step 3: Lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l includes/Admin/Rest/LogController.php
php -l includes/Plugin.php
```
Expected: both clean.

- [ ] **Step 4: Manual REST probe**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
PW=$(npx wp-env run cli wp user application-password create admin "log-rest-check" --porcelain 2>&1 | grep -E "^[a-zA-Z0-9]{20,}$" | head -1)
echo "PW=$PW"
curl -sS -u admin:$PW "http://localhost:8890/wp-json/mcp-site-manager/v1/log?per_page=3" | python3 -c "import sys,json; d=json.load(sys.stdin); print('total:', d['total'], 'page:', d['page'], 'per_page:', d['per_page'], 'returned:', len(d['items']))"
```
Expected: prints total/page/per_page/returned. If the log is empty, `returned: 0` and `total: 0` — still a pass.

Capture `PW=...` for Task 2.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/Rest/LogController.php includes/Plugin.php
git commit -m "feat(rest): LogController paginated GET /log with user_login join"
```

---

## Task 2: REST integration tests

**Files:**
- Create: `tests/Integration/RestLogTest.php`

- [ ] **Step 1: Write the test**

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class RestLogTest extends TestCase
{
    private function call(string $method, string $path, ?array $body = null, bool $auth = true): array
    {
        $url = rtrim((string) getenv('MCPSM_BASE_URL'), '/') . $path;
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($auth) $headers[] = 'Authorization: Basic ' . base64_encode(getenv('MCPSM_USER') . ':' . getenv('MCPSM_APP_PW'));
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'json' => json_decode((string) $resp, true)];
    }

    public function test_unauthenticated_get_returns_401(): void
    {
        $r = $this->call('GET', '/wp-json/mcp-site-manager/v1/log', null, false);
        $this->assertSame(401, $r['code']);
    }

    public function test_get_list_returns_paginated_shape(): void
    {
        $r = $this->call('GET', '/wp-json/mcp-site-manager/v1/log?per_page=5');
        $this->assertSame(200, $r['code']);
        $this->assertArrayHasKey('items', $r['json']);
        $this->assertArrayHasKey('total', $r['json']);
        $this->assertSame(1, $r['json']['page']);
        $this->assertSame(5, $r['json']['per_page']);
        $this->assertIsArray($r['json']['items']);
        if (!empty($r['json']['items'])) {
            $first = $r['json']['items'][0];
            foreach (['id','ts','user_id','user_login','ability','status','error_code','duration_ms'] as $k) {
                $this->assertArrayHasKey($k, $first, "missing key: $k");
            }
        }
    }
}
```

- [ ] **Step 2: Run integration suite**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
APP_PW=<paste from Task 1 step 4>
MCPSM_APP_PW="$APP_PW" \
MCPSM_URL="http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
MCPSM_BASE_URL="http://localhost:8890" \
MCPSM_USER="admin" \
  ./vendor/bin/phpunit --testsuite=integration 2>&1 | tail -10
```
Expected: `OK (19 tests, …)` — 17 baseline + 2 new log REST tests.

- [ ] **Step 3: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add tests/Integration/RestLogTest.php
git commit -m "test(int): REST log endpoint (auth, paginated shape)"
```

---

## Task 3: Shared DataViews stylesheet

**Files:**
- Create: `src/shared/dataviews.scss`
- Modify: `src/abilities/style.scss`

- [ ] **Step 1: Create the shared file**

`src/shared/dataviews.scss`:

```scss
/* Shared @wordpress/dataviews stylesheet import.
 * Imported via a relative node_modules path to bypass
 * dependency-extraction-webpack-plugin externalization
 * (otherwise it becomes a bogus `wp-dataviews/build-style/style.css` script handle).
 */
@import '../../node_modules/@wordpress/dataviews/build-style/style.css';
```

- [ ] **Step 2: Replace `src/abilities/style.scss`**

Current content:
```scss
/* MCP Site Manager — Abilities tab styles. */
@import '../../node_modules/@wordpress/dataviews/build-style/style.css';

#mcpsm-abilities-root {
    margin-top: 1em;
}
```

Replace with:
```scss
/* MCP Site Manager — Abilities tab styles. */
@import '../shared/dataviews.scss';

#mcpsm-abilities-root {
    margin-top: 1em;
}
```

- [ ] **Step 3: Build + sanity check**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npm run build 2>&1 | tail -5
ls -la build/ | grep -E "style-abilities|abilities"
```
Expected: build succeeds; `build/style-abilities.css` still emitted at non-trivial size (>50 KB — proves DataViews CSS is still bundled).

- [ ] **Step 4: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add src/shared/dataviews.scss src/abilities/style.scss
git commit -m "refactor(build): extract shared DataViews stylesheet to src/shared/dataviews.scss"
```

---

## Task 4: webpack entry + log src stubs

**Files:**
- Modify: `webpack.config.js`
- Create: `src/log/index.js`
- Create: `src/log/style.scss`
- Create: `src/log/Log.js` (placeholder — real impl in Task 5)

- [ ] **Step 1: Add `log` entry to `webpack.config.js`**

Current content:
```js
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        dashboard: path.resolve(__dirname, 'src/dashboard/index.js'),
        abilities: path.resolve(__dirname, 'src/abilities/index.js'),
    },
};
```

Replace with:
```js
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        dashboard: path.resolve(__dirname, 'src/dashboard/index.js'),
        abilities: path.resolve(__dirname, 'src/abilities/index.js'),
        log:       path.resolve(__dirname, 'src/log/index.js'),
    },
};
```

- [ ] **Step 2: Create `src/log/style.scss`**

```scss
/* MCP Site Manager — Activity Log tab styles. */
@import '../shared/dataviews.scss';

#mcpsm-log-root {
    margin-top: 1em;
}

.mcpsm-log-status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    color: #fff;
    font-size: 12px;
    font-weight: 500;
}
.mcpsm-log-status-badge--ok    { background: #00a32a; }
.mcpsm-log-status-badge--error { background: #d63638; }
```

- [ ] **Step 3: Create `src/log/Log.js` (placeholder)**

```js
/* Replaced in Task 5. Stub lets the build pipeline compile in Task 4. */
import { __ } from '@wordpress/i18n';
export default function Log() {
    return <p>{ __( 'Activity log loading…', 'mcp-site-manager' ) }</p>;
}
```

- [ ] **Step 4: Create `src/log/index.js`**

```js
/**
 * MCP Site Manager — Activity Log React app entry.
 */
import { createRoot, StrictMode } from '@wordpress/element';
import Log from './Log';
import './style.scss';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('mcpsm-log-root');
    if (!root) return;
    createRoot(root).render(
        <StrictMode>
            <Log />
        </StrictMode>
    );
});
```

- [ ] **Step 5: Build**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npm run build 2>&1 | tail -10
ls build/ | grep -E "^log\.|^style-log"
```
Expected:
- `build/log.js`, `build/log.asset.php`, `build/style-log.css` (NEW).
- `abilities` and `dashboard` outputs still present.

- [ ] **Step 6: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add webpack.config.js src/log
git commit -m "build: add log webpack entry + src/log scaffolding"
```

---

## Task 5: Real Log React component

**Files:**
- Replace: `src/log/Log.js`

- [ ] **Step 1: Replace `src/log/Log.js`** with the full DataViews implementation

```js
import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { Button, Notice, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const PER_PAGE = 200;

const DEFAULT_VIEW = {
    type: 'table',
    page: 1,
    perPage: 25,
    search: '',
    sort: { field: 'ts', direction: 'desc' },
    fields: [ 'ts', 'user_login', 'ability', 'status', 'error_code', 'duration_ms' ],
    layout: {},
};

function formatTs( ts ) {
    if ( ! ts ) return '';
    try {
        const d = new Date( ts.replace( ' ', 'T' ) + 'Z' );
        return d.toLocaleString();
    } catch ( e ) {
        return ts;
    }
}

export default function Log() {
    const [ items, setItems ] = useState( null );
    const [ total, setTotal ] = useState( 0 );
    const [ lastUpdated, setLastUpdated ] = useState( null );
    const [ error, setError ] = useState( null );
    const [ loading, setLoading ] = useState( false );
    const [ view, setView ] = useState( DEFAULT_VIEW );

    const load = useCallback( () => {
        setLoading( true );
        setError( null );
        apiFetch( { path: `/mcp-site-manager/v1/log?per_page=${ PER_PAGE }&page=1` } )
            .then( ( r ) => {
                setItems( r.items );
                setTotal( r.total );
                setLastUpdated( new Date() );
            } )
            .catch( ( e ) => setError( e.message || String( e ) ) )
            .finally( () => setLoading( false ) );
    }, [] );

    useEffect( () => {
        load();
    }, [ load ] );

    const fields = useMemo( () => [
        {
            id: 'ts',
            label: __( 'Time', 'mcp-site-manager' ),
            enableGlobalSearch: true,
            render: ( { item } ) => formatTs( item.ts ),
        },
        {
            id: 'user_login',
            label: __( 'User', 'mcp-site-manager' ),
            enableGlobalSearch: true,
            render: ( { item } ) => item.user_login || <em>{ __( '(unknown)', 'mcp-site-manager' ) }</em>,
        },
        {
            id: 'ability',
            label: __( 'Ability', 'mcp-site-manager' ),
            enableGlobalSearch: true,
            render: ( { item } ) => <code>{ item.ability }</code>,
        },
        {
            id: 'status',
            label: __( 'Status', 'mcp-site-manager' ),
            render: ( { item } ) => (
                <span className={ `mcpsm-log-status-badge mcpsm-log-status-badge--${ item.status === 'ok' ? 'ok' : 'error' }` }>
                    { item.status }
                </span>
            ),
            elements: [
                { value: 'ok',    label: __( 'OK', 'mcp-site-manager' ) },
                { value: 'error', label: __( 'Error', 'mcp-site-manager' ) },
            ],
        },
        {
            id: 'error_code',
            label: __( 'Code', 'mcp-site-manager' ),
            enableGlobalSearch: true,
        },
        {
            id: 'duration_ms',
            label: __( 'Duration (ms)', 'mcp-site-manager' ),
            type: 'integer',
            render: ( { item } ) => String( item.duration_ms ),
        },
    ], [] );

    const { data, paginationInfo } = useMemo(
        () =>
            items
                ? filterSortAndPaginate( items, view, fields )
                : { data: [], paginationInfo: { totalItems: 0, totalPages: 0 } },
        [ items, view, fields ]
    );

    if ( items === null && !error ) return <Spinner />;
    if ( error && items === null ) {
        return (
            <Notice status="error" isDismissible={ false }>
                { error }
            </Notice>
        );
    }
    if ( !items ) return null;

    return (
        <>
            <div style={ { display: 'flex', gap: '1em', alignItems: 'center', marginBottom: '1em' } }>
                <Button variant="secondary" onClick={ load } disabled={ loading }>
                    { loading ? __( 'Refreshing…', 'mcp-site-manager' ) : __( 'Refresh now', 'mcp-site-manager' ) }
                </Button>
                <span style={ { color: '#646970' } }>
                    { items.length } { __( 'shown', 'mcp-site-manager' ) } / { total } { __( 'total', 'mcp-site-manager' ) }
                    { lastUpdated && (
                        <> · { __( 'Updated', 'mcp-site-manager' ) } { lastUpdated.toLocaleTimeString() }</>
                    ) }
                </span>
            </div>
            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>
                    { error }
                </Notice>
            ) }
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

- [ ] **Step 2: Build**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npm run build 2>&1 | tail -10
cat build/log.asset.php
```
Expected: `log.asset.php` `dependencies` array now includes `wp-dataviews`, `wp-components`, `wp-element`, `wp-i18n`, `wp-api-fetch`.

- [ ] **Step 3: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add src/log/Log.js
git commit -m "feat(admin): React Activity Log tab with DataViews + refresh button"
```

---

## Task 6: LogAssets PHP enqueue

**Files:**
- Create: `includes/Admin/LogAssets.php`
- Modify: `includes/Plugin.php`

- [ ] **Step 1: Implement `LogAssets`**

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin;

use Mrabbani\McpSiteManager\Admin\Rest\LogController;

final class LogAssets
{
    public const HANDLE = 'mcpsm-log';

    public static function maybe_enqueue(string $hook_suffix): void
    {
        if ($hook_suffix !== 'settings_page_' . SettingsPage::SLUG) return;
        if (SettingsPage::current_tab() !== 'log') return;
        if (!current_user_can('manage_options')) return;

        $build = MCPSM_DIR . 'build/log.asset.php';
        if (!file_exists($build)) return;

        $asset = require $build;
        $deps    = $asset['dependencies'] ?? [];
        $version = $asset['version']      ?? MCPSM_VERSION;

        wp_register_script(
            self::HANDLE,
            MCPSM_URL . 'build/log.js',
            $deps,
            $version,
            true
        );
        wp_localize_script(self::HANDLE, 'mcpsmLog', [
            'restUrl' => esc_url_raw(rest_url(LogController::NAMESPACE)),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
        wp_enqueue_script(self::HANDLE);

        // DataViews stylesheet pitfall: DO NOT depend on `wp-dataviews` (script handle, not style handle).
        // Our SCSS imports the DataViews stylesheet directly — `wp-components` covers the rest.
        $css_candidates = [
            MCPSM_DIR . 'build/style-log.css',
            MCPSM_DIR . 'build/log.css',
        ];
        foreach ($css_candidates as $css_path) {
            if (file_exists($css_path)) {
                wp_enqueue_style(
                    self::HANDLE,
                    MCPSM_URL . 'build/' . basename($css_path),
                    ['wp-components'],
                    $version
                );
                break;
            }
        }
    }
}
```

- [ ] **Step 2: Wire enqueue into Plugin**

In `includes/Plugin.php`, alongside the existing AbilitiesAssets `add_action`, add:

```php
        add_action('admin_enqueue_scripts', [\Mrabbani\McpSiteManager\Admin\LogAssets::class, 'maybe_enqueue']);
```

- [ ] **Step 3: Lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l includes/Admin/LogAssets.php
php -l includes/Plugin.php
```
Expected: both clean.

- [ ] **Step 4: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/LogAssets.php includes/Plugin.php
git commit -m "feat(admin): LogAssets enqueues React build on Activity Log tab"
```

---

## Task 7: Replace `render_log()` with React mount

**Files:**
- Modify: `includes/Admin/SettingsPage.php`

- [ ] **Step 1: Replace `render_log()` body**

Find `private static function render_log(): void { … }`. Replace its entire body with:

```php
        ?>
        <h2><?php esc_html_e('Activity log', 'mcp-site-manager'); ?></h2>
        <p><?php esc_html_e('Recent ability invocations. Use the Settings tab to disable logging or clear the log.', 'mcp-site-manager'); ?></p>
        <div id="mcpsm-log-root"><p><em><?php esc_html_e('Loading activity log…', 'mcp-site-manager'); ?></em></p></div>
        <?php
```

- [ ] **Step 2: Lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l includes/Admin/SettingsPage.php
```
Expected: clean.

- [ ] **Step 3: Verify the React app loads in the page**

```bash
PW=<from Task 1 / regenerate if expired>
curl -sS -u admin:$PW "http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager&tab=log" \
  | grep -E "mcpsm-log-root|mcpsm-log-js|mcpsmLog" | head -5
```
Expected:
- `<div id="mcpsm-log-root">…</div>` present
- `<script id="mcpsm-log-js" src=".../build/log.js…"` present
- `var mcpsmLog = {"restUrl":"…/mcp-site-manager/v1","nonce":"…"}` present

Verify the old PHP table is gone:
```bash
curl -sS -u admin:$PW "http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager&tab=log" \
  | grep -E "widefat striped|Duration \(ms\)<\/th>"
```
Expected: empty (no `widefat striped` table on the log tab).

- [ ] **Step 4: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/SettingsPage.php
git commit -m "refactor(admin): Activity Log tab is now a React mount; remove PHP table"
```

---

## Task 8: Final verification + push

**Files:** none modified.

- [ ] **Step 1: Unit suite**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
./vendor/bin/phpunit --testsuite=unit 2>&1 | tail -5
```
Expected: 26 tests (unchanged; data layer untouched).

- [ ] **Step 2: Integration suite**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
APP_PW=$(npx wp-env run cli wp user application-password create admin "log-dv-final" --porcelain 2>&1 | grep -E "^[a-zA-Z0-9]{20,}$" | head -1)
MCPSM_APP_PW="$APP_PW" \
MCPSM_URL="http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
MCPSM_BASE_URL="http://localhost:8890" \
MCPSM_USER="admin" \
  ./vendor/bin/phpunit --testsuite=integration 2>&1 | tail -5
```
Expected: `OK (19 tests, …)`.

- [ ] **Step 3: Confirm no wp-dataviews stylesheet notices**

```bash
npx wp-env run cli bash -c 'tail -200 /var/www/html/wp-content/debug.log' 2>&1 \
  | grep -E "wp-dataviews|build-style" | tail -5
```
Expected: empty.

- [ ] **Step 4: Push**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git push origin main 2>&1 | tail -3
```

---

## Self-Review

### Spec coverage

| Spec section | Covered by |
|---|---|
| §2 In scope (REST endpoint, response shape, controller, LogAssets, third entry, React component, 1-line mount, shared SCSS) | T1–T7 |
| §3 Decisions (DataViews table, REST pagination, client sort, status badge, time format, manual refresh, shared SCSS) | T1, T3, T5 |
| §4 File layout | All tasks |
| §5 REST API | T1 |
| §6 React component | T5 |
| §7 Acceptance criteria | T8 + manual browser check |
| §8 Implementation order (8 tasks) | T1–T8 |

No gaps.

### Placeholder scan

Every code step shows complete code. The `Log.js` placeholder in T4 is intentional (lets the build pipeline compile before T5 lands the real component) and is explicitly replaced in T5.

### Type / signature consistency

- REST URL `/log` consistent across T1 register, T2 tests, T5 apiFetch.
- Response shape (`items / total / page / per_page`) identical in T1, T2 assertions, T5 consumer.
- Item shape (`id / ts / user_id / user_login / ability / status / error_code / duration_ms`) identical in T1 controller, T2 assertions, T5 fields.
- React mount id (`mcpsm-log-root`) consistent across T4 entry, T7 PHP mount.
- Asset handle (`mcpsm-log`) consistent across T6 register/enqueue/localize.
- Localised JS object name (`mcpsmLog`) consistent across T6 PHP and T7 verification grep.

No drift.

---

## Execution Handoff

The user has chosen subagent-driven execution. Proceeding directly.
