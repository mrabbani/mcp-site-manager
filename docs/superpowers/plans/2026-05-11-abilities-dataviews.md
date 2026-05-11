# Abilities — DataViews + Switch Implementation Plan (v1.2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the v1.1 PHP-checkbox Abilities tab with a React + `@wordpress/dataviews` table where each row has a `<ToggleControl>` Switch wired to a per-row REST PUT. Adds a 3-route REST controller, a second build entry, and a small enqueue class. Removes the v1.1 admin-post handler.

**Architecture:** Three layers reused: `Support\DisabledAbilities` (unchanged), new `Admin\Rest\AbilitiesController` (3 routes), new `src/abilities/` React entry rendering `<DataViews>`. The bridge: `Admin\AbilitiesAssets` enqueues the build only when on the Abilities tab. `SettingsPage::render_abilities()` becomes a one-line `<div>` mount; the v1.1 form + admin-post handler are deleted.

**Tech Stack:** PHP 8.0+, WP 6.8+, React via `@wordpress/element`, `@wordpress/dataviews`, `@wordpress/components`, `@wordpress/api-fetch`, `@wordpress/scripts` build.

**Spec:** `docs/superpowers/specs/2026-05-11-abilities-dataviews-design.md`

**Working dir for every task:** `/Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager`

---

## File map

| File | Action | Purpose |
|---|---|---|
| `includes/Admin/Rest/AbilitiesController.php` | Create | 3 REST routes: list, update enabled, reset. |
| `tests/Integration/RestAbilitiesTest.php` | Create | wp-env tests for the 3 routes. |
| `includes/Admin/AbilitiesAssets.php` | Create | Enqueue build/abilities.js + style-abilities.css on the Abilities tab only. |
| `includes/Plugin.php` | Modify | Register AbilitiesController on `rest_api_init` + AbilitiesAssets on `admin_enqueue_scripts`. |
| `includes/Admin/SettingsPage.php` | Modify | `render_abilities()` becomes a 1-line mount. Delete `handle_save_abilities`, `all_local_ability_names`, `bundle_label` (move to controller). Remove the 3rd `add_action('admin_post_mcpsm_save_abilities', ...)` line. |
| `package.json` | Modify | Add `@wordpress/dataviews` to devDependencies. |
| `webpack.config.js` | Modify | Add second entry `abilities`. |
| `src/abilities/index.js` | Create | createRoot mount. |
| `src/abilities/Abilities.js` | Create | DataViews component + toggle handler. |
| `src/abilities/style.scss` | Create | `@import` DataViews stylesheet via relative node_modules path. |

---

## Task 1: AbilitiesController (REST)

**Files:**
- Create: `includes/Admin/Rest/AbilitiesController.php`
- Modify: `includes/Plugin.php`

- [ ] **Step 1: Implement the controller**

`includes/Admin/Rest/AbilitiesController.php`:

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin\Rest;

use Mrabbani\McpSiteManager\Plugin;
use Mrabbani\McpSiteManager\Support\DisabledAbilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AbilitiesController
{
    public const NAMESPACE = 'mcp-site-manager/v1';

    public static function register_routes(): void
    {
        $perm = [self::class, 'permission_check'];

        register_rest_route(self::NAMESPACE, '/abilities', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_list'],
            'permission_callback' => $perm,
        ]);
        register_rest_route(self::NAMESPACE, '/abilities/(?P<name>[a-z0-9-]+)/enabled', [
            'methods'             => 'PUT',
            'callback'            => [self::class, 'update_enabled'],
            'permission_callback' => $perm,
            'args' => [
                'enabled' => ['type' => 'boolean', 'required' => true],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/abilities/disabled', [
            'methods'             => 'DELETE',
            'callback'            => [self::class, 'reset'],
            'permission_callback' => $perm,
        ]);
    }

    public static function permission_check()
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', __('You need manage_options.', 'mcp-site-manager'), ['status' => rest_authorization_required_code()]);
        }
        return true;
    }

    public static function get_list(): WP_REST_Response
    {
        return new WP_REST_Response(self::snapshot());
    }

    public static function update_enabled(WP_REST_Request $r)
    {
        $name = (string) $r['name'];
        $enabled = (bool) $r->get_param('enabled');
        $known = self::all_local_ability_names();
        if (!in_array($name, $known, true)) {
            return new WP_Error('rest_unknown_ability', __('Unknown ability.', 'mcp-site-manager'), ['status' => 404]);
        }
        $disabled = DisabledAbilities::all();
        if ($enabled) {
            $disabled = array_values(array_diff($disabled, [$name]));
        } else {
            if (!in_array($name, $disabled, true)) $disabled[] = $name;
        }
        DisabledAbilities::set($disabled);
        return new WP_REST_Response(self::snapshot());
    }

    public static function reset(): WP_REST_Response
    {
        DisabledAbilities::clear();
        return new WP_REST_Response(self::snapshot());
    }

    /** Build the inventory snapshot consumed by all 3 endpoints. */
    public static function snapshot(): array
    {
        $disabled = DisabledAbilities::all();
        $items = [];
        foreach (Plugin::instance_bundles() as $bundle) {
            $bundle_label = self::bundle_label($bundle);
            foreach ($bundle->abilities() as $local => $spec) {
                $items[] = [
                    'id'          => $local,
                    'name'        => "mcpsm/$local",
                    'tool_name'   => 'mcpsm-' . $local,
                    'label'       => isset($spec['label']) ? (string) $spec['label'] : $local,
                    'description' => isset($spec['description']) ? (string) $spec['description'] : '',
                    'bundle'      => $bundle_label,
                    'enabled'     => !in_array($local, $disabled, true),
                ];
            }
        }
        usort($items, fn($a, $b) => strcmp($a['name'], $b['name']));
        return [
            'items'          => $items,
            'disabled_count' => count($disabled),
            'total'          => count($items),
        ];
    }

    /** @return string[] */
    private static function all_local_ability_names(): array
    {
        $names = [];
        foreach (Plugin::instance_bundles() as $bundle) {
            foreach (array_keys($bundle->abilities()) as $local) $names[] = $local;
        }
        return $names;
    }

    private static function bundle_label($bundle): string
    {
        $cls = get_class($bundle);
        $base = substr($cls, strrpos($cls, '\\') + 1);
        return preg_replace('/Bundle$/', '', $base);
    }
}
```

- [ ] **Step 2: Register on `rest_api_init`**

Open `includes/Plugin.php`. In `register_hooks()`, alongside the existing StatsController line, add:

```php
        add_action('rest_api_init', [\Mrabbani\McpSiteManager\Admin\Rest\AbilitiesController::class, 'register_routes']);
```

- [ ] **Step 3: Lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l includes/Admin/Rest/AbilitiesController.php
php -l includes/Plugin.php
```
Expected: both clean.

- [ ] **Step 4: Manual REST probe**

```bash
PW=$(npx wp-env run cli wp user application-password create admin "abilities-rest-check" --porcelain 2>&1 | grep -E "^[a-zA-Z0-9]{20,}$" | head -1)
echo "PW=$PW"
curl -sS -u admin:$PW "http://localhost:8890/wp-json/mcp-site-manager/v1/abilities" | python3 -c "import sys,json; d=json.load(sys.stdin); print('total:', d['total'], 'disabled:', d['disabled_count'], 'first:', d['items'][0]['id'])"

# Disable themes-delete
curl -sS -u admin:$PW -X PUT -H 'Content-Type: application/json' "http://localhost:8890/wp-json/mcp-site-manager/v1/abilities/themes-delete/enabled" -d '{"enabled":false}' | python3 -c "import sys,json; d=json.load(sys.stdin); print('disabled:', d['disabled_count'])"

# Reset
curl -sS -u admin:$PW -X DELETE "http://localhost:8890/wp-json/mcp-site-manager/v1/abilities/disabled" | python3 -c "import sys,json; d=json.load(sys.stdin); print('after reset disabled:', d['disabled_count'])"
```
Expected:
- `total: 74 disabled: 0 first: cache-flush-object` (or similar alphabetical first)
- `disabled: 1`
- `after reset disabled: 0`

Capture `PW=...` for Task 2.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/Rest/AbilitiesController.php includes/Plugin.php
git commit -m "feat(rest): AbilitiesController (list, update enabled, reset)"
```

---

## Task 2: REST integration tests

**Files:**
- Create: `tests/Integration/RestAbilitiesTest.php`

- [ ] **Step 1: Write the test**

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class RestAbilitiesTest extends TestCase
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

    protected function tearDown(): void
    {
        // Always restore to all-enabled at the end of each test.
        $this->call('DELETE', '/wp-json/mcp-site-manager/v1/abilities/disabled');
    }

    public function test_unauthenticated_get_returns_401(): void
    {
        $r = $this->call('GET', '/wp-json/mcp-site-manager/v1/abilities', null, false);
        $this->assertSame(401, $r['code']);
    }

    public function test_get_list_returns_inventory(): void
    {
        $r = $this->call('GET', '/wp-json/mcp-site-manager/v1/abilities');
        $this->assertSame(200, $r['code']);
        $this->assertGreaterThanOrEqual(70, $r['json']['total']);
        $this->assertSame(0, $r['json']['disabled_count']);
        $this->assertArrayHasKey('items', $r['json']);
        $first = $r['json']['items'][0];
        foreach (['id','name','tool_name','label','description','bundle','enabled'] as $k) {
            $this->assertArrayHasKey($k, $first);
        }
    }

    public function test_put_disable_then_enable_round_trips(): void
    {
        $r = $this->call('PUT', '/wp-json/mcp-site-manager/v1/abilities/themes-delete/enabled', ['enabled' => false]);
        $this->assertSame(200, $r['code']);
        $this->assertSame(1, $r['json']['disabled_count']);
        $themes_delete = current(array_filter($r['json']['items'], fn($i) => $i['id'] === 'themes-delete'));
        $this->assertFalse($themes_delete['enabled']);

        $r = $this->call('PUT', '/wp-json/mcp-site-manager/v1/abilities/themes-delete/enabled', ['enabled' => true]);
        $this->assertSame(200, $r['code']);
        $this->assertSame(0, $r['json']['disabled_count']);
    }

    public function test_put_unknown_ability_returns_404(): void
    {
        $r = $this->call('PUT', '/wp-json/mcp-site-manager/v1/abilities/does-not-exist/enabled', ['enabled' => false]);
        $this->assertSame(404, $r['code']);
    }

    public function test_delete_disabled_clears_all(): void
    {
        $this->call('PUT', '/wp-json/mcp-site-manager/v1/abilities/themes-delete/enabled', ['enabled' => false]);
        $this->call('PUT', '/wp-json/mcp-site-manager/v1/abilities/plugins-delete/enabled', ['enabled' => false]);
        $r = $this->call('DELETE', '/wp-json/mcp-site-manager/v1/abilities/disabled');
        $this->assertSame(200, $r['code']);
        $this->assertSame(0, $r['json']['disabled_count']);
    }
}
```

- [ ] **Step 2: Run integration suite**

Use the same env vars as before:
```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
APP_PW=<paste from Task 1 step 4>
MCPSM_APP_PW="$APP_PW" \
MCPSM_URL="http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
MCPSM_BASE_URL="http://localhost:8890" \
MCPSM_USER="admin" \
  ./vendor/bin/phpunit --testsuite=integration 2>&1 | tail -10
```
Expected: `OK (17 tests, …)` — 12 baseline + 5 new abilities REST tests.

- [ ] **Step 3: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add tests/Integration/RestAbilitiesTest.php
git commit -m "test(int): REST abilities endpoints (auth, list, put round-trip, 404, reset)"
```

---

## Task 3: webpack config + npm install + style entry

**Files:**
- Modify: `package.json`
- Modify: `webpack.config.js`
- Create: `src/abilities/index.js`
- Create: `src/abilities/style.scss`
- Create: `src/abilities/Abilities.js` (placeholder — full impl in Task 4)

- [ ] **Step 1: Add `@wordpress/dataviews` to package.json devDependencies**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npm install --save-dev @wordpress/dataviews 2>&1 | tail -5
```
Expected: install completes; `package.json` `devDependencies` now contains `@wordpress/dataviews`.

- [ ] **Step 2: Replace `webpack.config.js`**

Current content:
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

Replace with:
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

- [ ] **Step 3: Create `src/abilities/style.scss`**

```scss
/* MCP Site Manager — Abilities tab styles. */
@import '../../node_modules/@wordpress/dataviews/build-style/style.css';

#mcpsm-abilities-root {
    margin-top: 1em;
}
```

(Approach 1 from the wp-dataviews skill: relative `node_modules` path bypasses the dependency-extraction-webpack-plugin externalization. From `src/abilities/`, `../../` reaches the plugin root.)

- [ ] **Step 4: Create `src/abilities/Abilities.js` (placeholder)**

```js
/* Replaced in Task 4. Stub here lets the build pipeline compile in Task 3. */
import { __ } from '@wordpress/i18n';
export default function Abilities() {
    return <p>{ __( 'Abilities loading…', 'mcp-site-manager' ) }</p>;
}
```

- [ ] **Step 5: Create `src/abilities/index.js`**

```js
/**
 * MCP Site Manager — Abilities React app entry.
 */
import { createRoot, StrictMode } from '@wordpress/element';
import Abilities from './Abilities';
import './style.scss';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('mcpsm-abilities-root');
    if (!root) return;
    createRoot(root).render(
        <StrictMode>
            <Abilities />
        </StrictMode>
    );
});
```

- [ ] **Step 6: Build**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npm run build 2>&1 | tail -10
ls build/
```
Expected:
- `build/dashboard.js`, `build/dashboard.asset.php`, `build/style-dashboard.css` (existing)
- `build/abilities.js`, `build/abilities.asset.php`, `build/style-abilities.css` (NEW)

The abilities.asset.php should declare `wp-element` and `wp-i18n` as dependencies (no DataViews yet — that lands in Task 4).

- [ ] **Step 7: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add package.json package-lock.json webpack.config.js src/abilities
git commit -m "build: add abilities entry + @wordpress/dataviews + DataViews stylesheet import"
```

---

## Task 4: Real Abilities React component

**Files:**
- Replace: `src/abilities/Abilities.js`

- [ ] **Step 1: Replace `src/abilities/Abilities.js`** with the full DataViews implementation

```js
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

    useEffect( () => {
        apiFetch( { path: '/mcp-site-manager/v1/abilities' } )
            .then( ( r ) => setItems( r.items ) )
            .catch( ( e ) => setError( e.message || String( e ) ) );
    }, [] );

    const toggle = ( id, next ) => {
        setItems( ( prev ) => prev.map( ( i ) => ( i.id === id ? { ...i, enabled: next } : i ) ) );
        apiFetch( {
            path: `/mcp-site-manager/v1/abilities/${ id }/enabled`,
            method: 'PUT',
            data: { enabled: next },
        } )
            .then( ( r ) => setItems( r.items ) )
            .catch( ( e ) => {
                setError( e.message || String( e ) );
                setItems( ( prev ) => prev.map( ( i ) => ( i.id === id ? { ...i, enabled: !next } : i ) ) );
            } );
    };

    const reset = () => {
        apiFetch( { path: '/mcp-site-manager/v1/abilities/disabled', method: 'DELETE' } )
            .then( ( r ) => setItems( r.items ) )
            .catch( ( e ) => setError( e.message || String( e ) ) );
    };

    const fields = useMemo( () => {
        const bundleOptions = items
            ? Array.from( new Set( items.map( ( i ) => i.bundle ) ) ).sort().map( ( b ) => ( { value: b, label: b } ) )
            : [];
        return [
            {
                id: 'name',
                label: __( 'Name', 'mcp-site-manager' ),
                enableGlobalSearch: true,
                render: ( { item } ) => <code>{ item.name }</code>,
            },
            {
                id: 'bundle',
                label: __( 'Bundle', 'mcp-site-manager' ),
                enableGlobalSearch: true,
                elements: bundleOptions,
            },
            {
                id: 'description',
                label: __( 'Description', 'mcp-site-manager' ),
                enableGlobalSearch: true,
            },
            {
                id: 'tool_name',
                label: __( 'Tool name', 'mcp-site-manager' ),
                render: ( { item } ) => <code>{ item.tool_name }</code>,
            },
            {
                id: 'enabled',
                label: __( 'Enabled', 'mcp-site-manager' ),
                render: ( { item } ) => (
                    <ToggleControl
                        checked={ item.enabled }
                        onChange={ ( next ) => toggle( item.id, next ) }
                        __nextHasNoMarginBottom
                    />
                ),
                elements: [
                    { value: true, label: __( 'Enabled', 'mcp-site-manager' ) },
                    { value: false, label: __( 'Disabled', 'mcp-site-manager' ) },
                ],
            },
        ];
    }, [ items ] );

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

    const disabledCount = items.filter( ( i ) => !i.enabled ).length;

    return (
        <>
            <div style={ { display: 'flex', gap: '1em', alignItems: 'center', marginBottom: '1em' } }>
                <Button variant="secondary" onClick={ reset } disabled={ disabledCount === 0 }>
                    { __( 'Re-enable all', 'mcp-site-manager' ) }
                </Button>
                <span style={ { color: '#646970' } }>
                    { disabledCount } { __( 'disabled', 'mcp-site-manager' ) } / { items.length } { __( 'total', 'mcp-site-manager' ) }
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
cat build/abilities.asset.php
```
Expected: `abilities.asset.php` `dependencies` array now includes `wp-dataviews`, `wp-components`, `wp-element`, `wp-i18n`, `wp-api-fetch`.

- [ ] **Step 3: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add src/abilities/Abilities.js
git commit -m "feat(admin): React Abilities tab with DataViews + per-row Switch"
```

---

## Task 5: AbilitiesAssets PHP enqueue

**Files:**
- Create: `includes/Admin/AbilitiesAssets.php`
- Modify: `includes/Plugin.php`

- [ ] **Step 1: Implement `AbilitiesAssets`**

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Admin;

use Mrabbani\McpSiteManager\Admin\Rest\AbilitiesController;

final class AbilitiesAssets
{
    public const HANDLE = 'mcpsm-abilities';

    public static function maybe_enqueue(string $hook_suffix): void
    {
        if ($hook_suffix !== 'settings_page_' . SettingsPage::SLUG) return;
        if (SettingsPage::current_tab() !== 'abilities') return;
        if (!current_user_can('manage_options')) return;

        $build = MCPSM_DIR . 'build/abilities.asset.php';
        if (!file_exists($build)) return;

        $asset = require $build;
        $deps    = $asset['dependencies'] ?? [];
        $version = $asset['version']      ?? MCPSM_VERSION;

        wp_register_script(
            self::HANDLE,
            MCPSM_URL . 'build/abilities.js',
            $deps,
            $version,
            true
        );
        wp_localize_script(self::HANDLE, 'mcpsmAbilities', [
            'restUrl' => esc_url_raw(rest_url(AbilitiesController::NAMESPACE)),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
        wp_enqueue_script(self::HANDLE);

        // DataViews stylesheet pitfall: DO NOT depend on `wp-dataviews` (script handle, not style handle).
        // Our SCSS imports the DataViews stylesheet directly — `wp-components` covers the rest.
        $css_candidates = [
            MCPSM_DIR . 'build/style-abilities.css',
            MCPSM_DIR . 'build/abilities.css',
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

In `includes/Plugin.php`, alongside the existing DashboardAssets `add_action`, add:

```php
        add_action('admin_enqueue_scripts', [\Mrabbani\McpSiteManager\Admin\AbilitiesAssets::class, 'maybe_enqueue']);
```

- [ ] **Step 3: Lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l includes/Admin/AbilitiesAssets.php
php -l includes/Plugin.php
```
Expected: both clean.

- [ ] **Step 4: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/AbilitiesAssets.php includes/Plugin.php
git commit -m "feat(admin): AbilitiesAssets enqueues React build on Abilities tab"
```

---

## Task 6: Replace `render_abilities()` + remove v1.1 form/handler

**Files:**
- Modify: `includes/Admin/SettingsPage.php`

- [ ] **Step 1: Replace `render_abilities()` body**

Find `private static function render_abilities(): void { … }`. Replace its entire body with:

```php
        ?>
        <h2><?php esc_html_e('Registered abilities', 'mcp-site-manager'); ?></h2>
        <p><?php esc_html_e('Disable individual abilities to hide them from MCP clients. Disabled abilities are not registered with WordPress and cannot be invoked. Changes take effect on the next page load and on the next MCP client reconnect.', 'mcp-site-manager'); ?></p>
        <div id="mcpsm-abilities-root"><p><em><?php esc_html_e('Loading abilities…', 'mcp-site-manager'); ?></em></p></div>
        <?php
```

- [ ] **Step 2: Remove the v1.1 admin-post action registration**

In `register()`, find and DELETE this line:

```php
        add_action('admin_post_mcpsm_save_abilities', [self::class, 'handle_save_abilities']);
```

- [ ] **Step 3: Delete the three v1.1 helper methods**

Delete these methods entirely from `SettingsPage`:
- `public static function handle_save_abilities(): void`
- `private static function all_local_ability_names(): array`
- `private static function bundle_label($bundle): string`

(Their functionality moved to `AbilitiesController` in Task 1.)

- [ ] **Step 4: Lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l includes/Admin/SettingsPage.php
```
Expected: clean.

- [ ] **Step 5: Verify the React app loads in the page**

```bash
PW=<from Task 1 / regenerate if expired>
curl -sS -u admin:$PW "http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager&tab=abilities" \
  | grep -E "mcpsm-abilities-root|mcpsm-abilities-js|mcpsmAbilities" | head -5
```
Expected:
- `<div id="mcpsm-abilities-root">…</div>` present
- `<script id="mcpsm-abilities-js" src=".../build/abilities.js…"` present
- `var mcpsmAbilities = {"restUrl":"…/mcp-site-manager/v1","nonce":"…"}` present

Verify the v1.1 form is GONE:
```bash
curl -sS -u admin:$PW "http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager&tab=abilities" \
  | grep -E "mcpsm-ability-filter|mcpsm-ability-row|enabled\[\]|mcpsm_save_abilities"
```
Expected: empty (none of the v1.1 markers remain).

- [ ] **Step 6: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/SettingsPage.php
git commit -m "refactor(admin): Abilities tab is now a React mount; remove v1.1 form + handler"
```

---

## Task 7: Final verification + push

**Files:** none modified.

- [ ] **Step 1: Unit suite**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
./vendor/bin/phpunit --testsuite=unit 2>&1 | tail -5
```
Expected: 26 tests (unchanged from v1.1; data layer untouched).

- [ ] **Step 2: Integration suite**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
APP_PW=$(npx wp-env run cli wp user application-password create admin "abilities-dv-final" --porcelain 2>&1 | grep -E "^[a-zA-Z0-9]{20,}$" | head -1)
MCPSM_APP_PW="$APP_PW" \
MCPSM_URL="http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
MCPSM_BASE_URL="http://localhost:8890" \
MCPSM_USER="admin" \
  ./vendor/bin/phpunit --testsuite=integration 2>&1 | tail -5
```
Expected: `OK (17 tests, …)` (12 baseline + 5 new abilities REST tests).

- [ ] **Step 3: Confirm wp-dataviews style notice did NOT appear**

```bash
npx wp-env run cli bash -c 'tail -100 /var/www/html/wp-content/debug.log' 2>&1 \
  | grep -E "wp-dataviews|build-style" | tail -5
```
Expected: empty (no notices about `wp-dataviews/build-style/style.css` not registered).

- [ ] **Step 4: Visit the page in browser**

```bash
open "http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager&tab=abilities"
```

Manual checks:
- DataViews table renders with toolbar (search, view options, pagination).
- 74 rows, sorted by name asc.
- Each row has a Switch in the Enabled column.
- Toggling a Switch is instant; refreshing the page persists the change.
- "Re-enable all" button restores all rows to enabled.

- [ ] **Step 5: Confirm option is empty after testing**

```bash
npx wp-env run cli wp eval 'update_option("mcpsm_disabled_abilities", [], false); var_export(get_option("mcpsm_disabled_abilities", []));'
```

- [ ] **Step 6: Push**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git push origin main 2>&1 | tail -3
```

---

## Self-Review

### Spec coverage

| Spec section | Covered by |
|---|---|
| §2 In scope (mount, second build entry, DataViews, ToggleControl, REST 3 routes, AbilitiesAssets, controller) | T1–T6 |
| §3 Decisions (DataViews, search built-in, optimistic toggle, single GET on mount, second entry, SCSS pitfall) | T1, T3, T4, T5 |
| §4 UX flow | T4 |
| §5 File layout | All 7 |
| §6 REST API (3 routes + response shape) | T1 |
| §7 AbilitiesController | T1 |
| §8 AbilitiesAssets | T5 |
| §9 webpack.config.js | T3 |
| §10 React component code | T4 |
| §11 Acceptance criteria | T7 |
| §12 Risks (stylesheet, version pin, optimistic race) | T3 (SCSS pitfall), T1 (controller is canonical), T5 (DataViews stylesheet via SCSS) |

No gaps.

### Placeholder scan

Every code step shows complete code. The placeholder `Abilities.js` in T3 is intentional (lets the build pipeline compile before T4 lands the real component) and is explicitly replaced in T4. No "similar to" cross-references.

### Type / signature consistency

- REST URLs (`/abilities`, `/abilities/{name}/enabled`, `/abilities/disabled`) used identically in T1 (registration), T2 (tests), T4 (apiFetch).
- Snapshot response shape (`items / disabled_count / total`) identical in T1, T2 assertions, T4 consumers.
- Item shape (`id / name / tool_name / label / description / bundle / enabled`) identical in T1 (controller snapshot), T2 (test assertions), T4 (DataViews fields).
- React entry mount id (`mcpsm-abilities-root`) consistent across T3 (mount), T5 (PHP gate doesn't reference id directly but does reference asset handles), T6 (`render_abilities()` placeholder).
- Asset handle (`mcpsm-abilities`) consistent across T5 register/enqueue/localize.
- Localised JS object name (`mcpsmAbilities`) consistent across T5 PHP and T6 verification grep.

No drift.

---

## Execution Handoff

The user has chosen subagent-driven execution. Proceeding directly.
