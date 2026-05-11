# Abilities Toggle + Search Implementation Plan (v1.1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins enable/disable individual MCP abilities and search them on the existing **Abilities** tab. Disabled abilities are filtered out at registration time so they never appear in `wp_get_abilities()`, the MCP tool list, or anywhere else.

**Architecture:** Tiny new `Support\DisabledAbilities` helper wraps a single WP option. Two existing methods (`AbilityBundle::register()`, `Plugin::ability_names()`) gain a one-line skip when the local ability name is in the disabled list. The Abilities tab is rewritten as a single PHP `<form>` with a checkbox per row, a search input above (filters rows via inline vanilla JS), and a Save/Reset action posted to a new admin-post handler. No JS framework added; no React touched.

**Tech Stack:** PHP 8.0+, WordPress 6.8+, PHPUnit. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-05-11-abilities-toggle-design.md`

**Working dir for every task:** `/Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager`

---

## File map

| File | Action | Purpose |
|---|---|---|
| `includes/Support/DisabledAbilities.php` | Create | Single-option store: `all() / contains() / set() / clear()`. |
| `tests/Support/DisabledAbilitiesTest.php` | Create | Unit tests via in-memory option stub. |
| `tests/Support/fixtures/options.php` | Create | `get_option`/`update_option` shims for unit tests. |
| `includes/Abilities/AbilityBundle.php` | Modify | Skip disabled in `register()`. |
| `includes/Plugin.php` | Modify | Skip disabled in `ability_names()`; expose `instance_bundles()`. |
| `includes/Admin/SettingsPage.php` | Modify | Rewrite `render_abilities()`, add `handle_save_abilities()`, register the new admin-post action, add `all_local_ability_names()` helper. |

---

## Task 1: `Support\DisabledAbilities` (TDD)

**Files:**
- Create: `tests/Support/fixtures/options.php`
- Create: `tests/Support/DisabledAbilitiesTest.php`
- Create: `includes/Support/DisabledAbilities.php`

- [ ] **Step 1: Create the options fixture**

`tests/Support/fixtures/options.php`:

```php
<?php
/**
 * Minimal stand-in for WordPress's option API for unit tests.
 * Only what Support\DisabledAbilities calls: get_option() + update_option().
 */
$GLOBALS['__opts'] = [];

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['__opts'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['__opts'][$name] = $value;
        return true;
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Support/DisabledAbilitiesTest.php`:

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Support;

use PHPUnit\Framework\TestCase;
use Mrabbani\McpSiteManager\Support\DisabledAbilities;

require_once __DIR__ . '/fixtures/options.php';

final class DisabledAbilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__opts'] = [];
    }

    public function test_default_is_empty_array(): void
    {
        $this->assertSame([], DisabledAbilities::all());
        $this->assertFalse(DisabledAbilities::contains('themes-delete'));
    }

    public function test_set_then_all_round_trips(): void
    {
        DisabledAbilities::set(['themes-delete', 'plugins-delete']);
        $this->assertSame(['themes-delete', 'plugins-delete'], DisabledAbilities::all());
    }

    public function test_set_dedupes_and_stringifies(): void
    {
        DisabledAbilities::set(['themes-delete', 'themes-delete', 123]);
        $this->assertSame(['themes-delete', '123'], DisabledAbilities::all());
    }

    public function test_contains_returns_true_for_listed(): void
    {
        DisabledAbilities::set(['plugins-install']);
        $this->assertTrue(DisabledAbilities::contains('plugins-install'));
        $this->assertFalse(DisabledAbilities::contains('plugins-list'));
    }

    public function test_clear_resets_to_empty(): void
    {
        DisabledAbilities::set(['x', 'y']);
        DisabledAbilities::clear();
        $this->assertSame([], DisabledAbilities::all());
    }

    public function test_all_handles_corrupt_option_gracefully(): void
    {
        $GLOBALS['__opts'][DisabledAbilities::OPTION] = 'not-an-array';
        $this->assertSame([], DisabledAbilities::all());
    }
}
```

- [ ] **Step 3: Run, verify FAIL**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
./vendor/bin/phpunit tests/Support/DisabledAbilitiesTest.php 2>&1 | tail -10
```
Expected: errors `Class "Mrabbani\McpSiteManager\Support\DisabledAbilities" not found` (6 of them).

- [ ] **Step 4: Implement `DisabledAbilities`**

`includes/Support/DisabledAbilities.php`:

```php
<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Support;

final class DisabledAbilities
{
    public const OPTION = 'mcpsm_disabled_abilities';

    /** @return string[] */
    public static function all(): array
    {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) return [];
        return array_values(array_unique(array_map('strval', array_filter($raw, fn($v) => $v !== '' && $v !== null))));
    }

    public static function contains(string $local_name): bool
    {
        return in_array($local_name, self::all(), true);
    }

    /** @param array<int|string, mixed> $names */
    public static function set(array $names): void
    {
        $clean = array_values(array_unique(array_map('strval', array_filter($names, fn($v) => $v !== '' && $v !== null))));
        update_option(self::OPTION, $clean, false);
    }

    public static function clear(): void
    {
        update_option(self::OPTION, [], false);
    }
}
```

- [ ] **Step 5: Run, verify PASS**

```bash
./vendor/bin/phpunit tests/Support/DisabledAbilitiesTest.php 2>&1 | tail -5
```
Expected: `OK (6 tests, 8 assertions)`.

- [ ] **Step 6: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Support/DisabledAbilities.php tests/Support/DisabledAbilitiesTest.php tests/Support/fixtures/options.php
git commit -m "feat(support): DisabledAbilities option store with unit tests"
```

---

## Task 2: Skip disabled in `AbilityBundle::register()`

**Files:**
- Modify: `includes/Abilities/AbilityBundle.php`

- [ ] **Step 1: Add the skip check in `register()`**

Open `includes/Abilities/AbilityBundle.php`. Find the `register()` method. The current `foreach` loop body starts with `$name = "mcpsm/$local";`. Insert this preamble at the very top of the `register()` method body (before the foreach), and an early-continue at the top of the loop body:

```php
public function register(): void
{
    $disabled = \Mrabbani\McpSiteManager\Support\DisabledAbilities::all();
    foreach ($this->abilities() as $local => $spec) {
        if (in_array($local, $disabled, true)) {
            continue;
        }
        $name = "mcpsm/$local";
        // …existing wp_register_ability() call unchanged…
    }
}
```

The exact patch for the `foreach` opening lines (replace the line `foreach ($this->abilities() as $local => $spec) {` with the four lines above ending in the `continue` block, keeping everything after `$name = "mcpsm/$local";` exactly the same).

- [ ] **Step 2: Lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l includes/Abilities/AbilityBundle.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Run unit tests (sanity)**

```bash
./vendor/bin/phpunit --testsuite=unit 2>&1 | tail -5
```
Expected: previous count + 6 new tests, all passing.

- [ ] **Step 4: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Abilities/AbilityBundle.php
git commit -m "feat(abilities): AbilityBundle::register() skips disabled abilities"
```

---

## Task 3: Skip disabled in `Plugin::ability_names()` + expose `instance_bundles()`

**Files:**
- Modify: `includes/Plugin.php`

- [ ] **Step 1: Add `instance_bundles()` and skip in `ability_names()`**

Open `includes/Plugin.php`.

(a) Find the existing `private function bundles(): array { … }` method around line 94. Add a new public method right after it:

```php
    /**
     * Public accessor for the bundle list. Used by the Abilities admin tab to
     * enumerate every potential ability (including disabled ones) so the
     * "save" handler can compute the disabled set.
     *
     * @return Abilities\AbilityBundle[]
     */
    public static function instance_bundles(): array
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->bundles();
    }
```

(b) Find `public function ability_names(): array { … }` around line 114. Replace its body with:

```php
    public function ability_names(): array
    {
        $disabled = \Mrabbani\McpSiteManager\Support\DisabledAbilities::all();
        $names = [];
        foreach ($this->bundles() as $bundle) {
            foreach (array_keys($bundle->abilities()) as $local) {
                if (in_array($local, $disabled, true)) continue;
                $names[] = "mcpsm/$local";
            }
        }
        return $names;
    }
```

- [ ] **Step 2: Lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l includes/Plugin.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verify abilities still register live (wp-env)**

wp-env should already be running. Nothing is disabled yet, so the count must be unchanged:

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npx wp-env run cli wp eval 'echo "mcpsm count: " . count(array_filter(array_keys((array) wp_get_abilities()), fn($n)=>str_starts_with($n,"mcpsm/"))) . "\n";' 2>&1 | tail -2
```
Expected: `mcpsm count: 74`.

Then disable one and verify it disappears:

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npx wp-env run cli wp eval 'update_option("mcpsm_disabled_abilities", ["themes-delete"], false); echo "mcpsm count: " . count(array_filter(array_keys((array) wp_get_abilities()), fn($n)=>str_starts_with($n,"mcpsm/"))) . " | has themes-delete: " . (in_array("mcpsm/themes-delete", array_keys((array) wp_get_abilities()), true) ? "yes" : "no") . "\n";' 2>&1 | tail -2
```
Expected: `mcpsm count: 73 | has themes-delete: no`.

Then re-enable:

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npx wp-env run cli wp eval 'update_option("mcpsm_disabled_abilities", [], false); echo "mcpsm count: " . count(array_filter(array_keys((array) wp_get_abilities()), fn($n)=>str_starts_with($n,"mcpsm/"))) . "\n";' 2>&1 | tail -2
```
Expected: `mcpsm count: 74`.

- [ ] **Step 4: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Plugin.php
git commit -m "feat: Plugin::ability_names() respects DisabledAbilities + instance_bundles() accessor"
```

---

## Task 4: Rewrite `SettingsPage::render_abilities()` + add save handler

**Files:**
- Modify: `includes/Admin/SettingsPage.php`

- [ ] **Step 1: Add the save handler registration**

In `SettingsPage::register()`, find the existing two `add_action('admin_post_*', ...)` lines. Add a third one immediately below them:

```php
        add_action('admin_post_mcpsm_save_abilities', [self::class, 'handle_save_abilities']);
```

- [ ] **Step 2: Replace `render_abilities()` body**

Find `private static function render_abilities(): void { … }`. Replace its entire body with:

```php
        $disabled  = \Mrabbani\McpSiteManager\Support\DisabledAbilities::all();
        $bundles   = \Mrabbani\McpSiteManager\Plugin::instance_bundles();
        $abilities = self::collect_abilities(); // existing — name => description for currently registered

        // Build full inventory: local name => [label, description, bundle_label].
        $rows = [];
        foreach ($bundles as $bundle) {
            $bundle_label = self::bundle_label($bundle);
            foreach ($bundle->abilities() as $local => $spec) {
                $rows[$local] = [
                    'name'        => "mcpsm/$local",
                    'tool_name'   => 'mcpsm-' . $local,
                    'label'       => isset($spec['label']) ? (string) $spec['label'] : $local,
                    'description' => isset($spec['description']) ? (string) $spec['description'] : '',
                    'bundle'      => $bundle_label,
                    'enabled'     => !in_array($local, $disabled, true),
                ];
            }
        }
        ksort($rows);
        $total = count($rows);
        ?>
        <h2><?php esc_html_e('Registered abilities', 'mcp-site-manager'); ?></h2>
        <p><?php esc_html_e('Disable individual abilities to hide them from MCP clients. Disabled abilities are not registered with WordPress and cannot be invoked. Changes take effect on the next page load and on the next MCP client reconnect.', 'mcp-site-manager'); ?></p>

        <?php if (!empty($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Abilities saved.', 'mcp-site-manager'); ?></p></div>
        <?php endif; ?>

        <p>
            <input type="search" id="mcpsm-ability-filter"
                   placeholder="<?php esc_attr_e('Search abilities…', 'mcp-site-manager'); ?>"
                   style="width:300px;">
            <span id="mcpsm-ability-count" style="margin-left:1em;color:#646970;"></span>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('mcpsm_save_abilities'); ?>
            <input type="hidden" name="action" value="mcpsm_save_abilities">

            <table class="widefat striped">
                <thead><tr>
                    <th style="width:60px;"><?php esc_html_e('Enabled', 'mcp-site-manager'); ?></th>
                    <th><?php esc_html_e('Name', 'mcp-site-manager'); ?></th>
                    <th><?php esc_html_e('Description', 'mcp-site-manager'); ?></th>
                    <th style="width:140px;"><?php esc_html_e('Bundle', 'mcp-site-manager'); ?></th>
                    <th style="width:200px;"><?php esc_html_e('Tool name', 'mcp-site-manager'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $local => $r):
                    $haystack = strtolower($r['name'] . ' ' . $r['label'] . ' ' . $r['description'] . ' ' . $r['bundle']);
                ?>
                    <tr class="mcpsm-ability-row" data-haystack="<?php echo esc_attr($haystack); ?>">
                        <td>
                            <input type="checkbox"
                                   name="enabled[]"
                                   value="<?php echo esc_attr($local); ?>"
                                   <?php checked($r['enabled']); ?>>
                        </td>
                        <td><code><?php echo esc_html($r['name']); ?></code><br><small><?php echo esc_html($r['label']); ?></small></td>
                        <td><?php echo esc_html($r['description']); ?></td>
                        <td><?php echo esc_html($r['bundle']); ?></td>
                        <td><code><?php echo esc_html($r['tool_name']); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:1em;">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save changes', 'mcp-site-manager'); ?></button>
                <button type="submit" name="reset" value="1" class="button" onclick="return confirm('<?php echo esc_js(__('Re-enable every ability?', 'mcp-site-manager')); ?>');"><?php esc_html_e('Reset to all-enabled', 'mcp-site-manager'); ?></button>
            </p>
        </form>

        <script>
        (function() {
            var input = document.getElementById('mcpsm-ability-filter');
            var counter = document.getElementById('mcpsm-ability-count');
            var rows = document.querySelectorAll('tr.mcpsm-ability-row');
            var total = rows.length;
            function update() {
                var q = input.value.trim().toLowerCase();
                var shown = 0;
                rows.forEach(function(row) {
                    var haystack = row.dataset.haystack || '';
                    var match = q === '' || haystack.indexOf(q) !== -1;
                    row.style.display = match ? '' : 'none';
                    if (match) shown++;
                });
                counter.textContent = (shown === total)
                    ? (<?php echo wp_json_encode(__('Showing %d of %d', 'mcp-site-manager')); ?>).replace('%d', total).replace('%d', total)
                    : (<?php echo wp_json_encode(__('Showing %d of %d', 'mcp-site-manager')); ?>).replace('%d', shown).replace('%d', total);
            }
            input.addEventListener('input', update);
            update();
        })();
        </script>
        <?php
```

- [ ] **Step 3: Add `bundle_label()` and `handle_save_abilities()` and `all_local_ability_names()` private helpers**

Anywhere inside the `SettingsPage` class (e.g. just before `dot()` at the bottom):

```php
    public static function handle_save_abilities(): void
    {
        if (!current_user_can('manage_options')) wp_die();
        check_admin_referer('mcpsm_save_abilities');

        if (!empty($_POST['reset'])) {
            \Mrabbani\McpSiteManager\Support\DisabledAbilities::clear();
        } else {
            $enabled_raw = (array) ($_POST['enabled'] ?? []);
            $enabled = array_map('sanitize_text_field', array_map('strval', $enabled_raw));
            $all_local = self::all_local_ability_names();
            $disabled  = array_values(array_diff($all_local, $enabled));
            \Mrabbani\McpSiteManager\Support\DisabledAbilities::set($disabled);
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, 'tab' => 'abilities', 'updated' => 1],
            admin_url('options-general.php')
        ));
        exit;
    }

    /** @return string[] All local ability names known to the plugin (regardless of enabled state). */
    private static function all_local_ability_names(): array
    {
        $names = [];
        foreach (\Mrabbani\McpSiteManager\Plugin::instance_bundles() as $bundle) {
            foreach (array_keys($bundle->abilities()) as $local) $names[] = $local;
        }
        return $names;
    }

    /** Derive a friendly bundle label from the bundle's class basename. */
    private static function bundle_label($bundle): string
    {
        $cls = get_class($bundle);
        $base = substr($cls, strrpos($cls, '\\') + 1);
        // "PostsBundle" -> "Posts"; "MaintenanceBundle" -> "Maintenance"
        return preg_replace('/Bundle$/', '', $base);
    }
```

- [ ] **Step 4: Lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l includes/Admin/SettingsPage.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 5: Visual verification (curl probe)**

```bash
PW=$(npx wp-env run cli wp user application-password create admin "abilities-toggle-check" --porcelain 2>&1 | grep -E "^[a-zA-Z0-9]{20,}$" | head -1)
echo "PW=$PW"
curl -sS -u admin:$PW "http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager&tab=abilities" \
  | grep -E "mcpsm-ability-filter|mcpsm-ability-row|enabled\[\]|mcpsm_save_abilities" | head -10
```
Expected: at least the filter input, several rows, the bulk save action, and many `enabled[]` checkboxes.

- [ ] **Step 6: End-to-end save verification (curl POST)**

Get the nonce from the page, POST to disable `themes-delete`, then verify it's gone:

```bash
COOKIE_JAR=/tmp/mcpsm-cookies.txt
rm -f $COOKIE_JAR
curl -sS -c $COOKIE_JAR -b $COOKIE_JAR -L -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=http%3A%2F%2Flocalhost%3A8890%2Fwp-admin%2F&testcookie=1" "http://localhost:8890/wp-login.php" -o /dev/null

# Get the nonce from the abilities tab
NONCE=$(curl -sS -b $COOKIE_JAR "http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager&tab=abilities" \
  | grep -oE 'name="_wpnonce" value="[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{8,}')
echo "NONCE=$NONCE"

# POST: enabled list excludes themes-delete (we list a single dummy enabled value to exercise the diff path)
# In practice the form sends the full enabled[] list; for the probe we simulate "enable only posts-list" (everything else becomes disabled).
# This is just to exercise the round-trip; we'll restore at the end.
curl -sS -b $COOKIE_JAR -d "_wpnonce=$NONCE&action=mcpsm_save_abilities&enabled[]=posts-list" \
  "http://localhost:8890/wp-admin/admin-post.php" -o /dev/null

# Verify
npx wp-env run cli wp eval 'echo "mcpsm count: " . count(array_filter(array_keys((array) wp_get_abilities()), fn($n)=>str_starts_with($n,"mcpsm/"))) . "\n";' 2>&1 | tail -2
```
Expected: `mcpsm count: 1`.

Now restore by clicking Reset (simulated via curl):

```bash
NONCE=$(curl -sS -b $COOKIE_JAR "http://localhost:8890/wp-admin/options-general.php?page=mcp-site-manager&tab=abilities" \
  | grep -oE 'name="_wpnonce" value="[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{8,}')
curl -sS -b $COOKIE_JAR -d "_wpnonce=$NONCE&action=mcpsm_save_abilities&reset=1" \
  "http://localhost:8890/wp-admin/admin-post.php" -o /dev/null

npx wp-env run cli wp eval 'echo "mcpsm count: " . count(array_filter(array_keys((array) wp_get_abilities()), fn($n)=>str_starts_with($n,"mcpsm/"))) . "\n";' 2>&1 | tail -2
```
Expected: `mcpsm count: 74`.

- [ ] **Step 7: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/SettingsPage.php
git commit -m "feat(admin): Abilities tab with per-ability toggles + search + reset"
```

---

## Task 5: Final verification

**Files:** none modified.

- [ ] **Step 1: Unit suite**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
./vendor/bin/phpunit --testsuite=unit 2>&1 | tail -5
```
Expected: prior count + 6 new tests, all green.

- [ ] **Step 2: Integration suite (sanity — should be unchanged)**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
APP_PW=$(npx wp-env run cli wp user application-password create admin "abilities-final" --porcelain 2>&1 | grep -E "^[a-zA-Z0-9]{20,}$" | head -1)
MCPSM_APP_PW="$APP_PW" \
MCPSM_URL="http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
MCPSM_BASE_URL="http://localhost:8890" \
MCPSM_USER="admin" \
  ./vendor/bin/phpunit --testsuite=integration 2>&1 | tail -5
```
Expected: `OK (12 tests, …)` (unchanged).

- [ ] **Step 3: Sanity — option is empty after reset**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npx wp-env run cli wp eval 'var_export(get_option("mcpsm_disabled_abilities", []));' 2>&1 | tail -3
```
Expected: an empty array `array(0) { }` (or `array (\n)` — both indicate empty).

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
| §2 In scope (option, registration filter, rewrite Abilities tab w/ checkboxes + search + Save + Reset) | Tasks 1–4 |
| §3 Decisions (single option, per-ability granularity, default empty, bulk save, filter at registration) | T1 (option, default), T2 (registration filter), T4 (bulk save) |
| §4 UX flow (intro copy, search input, table layout, counter, save/reset buttons) | T4 step 2 |
| §5.1 `Support\DisabledAbilities` class | T1 |
| §5.2 Skip in `register()` and `ability_names()` | T2, T3 |
| §5.3 New admin-post handler | T4 step 3 |
| §5.4 Refactor `render_abilities()` | T4 step 2 |
| §5.5 Empty-enabled edge case (everything disabled is acceptable) | T4 step 6 (probe) |
| §5.6 Search input HTML/JS | T4 step 2 (inline `<script>`) |
| §6 File layout | T1, T2, T3, T4 |
| §7 Permissions/security (cap check, nonce, sanitize, intersect with known inventory, esc_*) | T4 step 3 (handler), T4 step 2 (escaping) |
| §8 Acceptance criteria | T5 + T4's curl probes (steps 5, 6) |

No gaps.

### Placeholder scan

Every step shows the actual code. The form `<script>` block is fully written, not "add filter logic later". The handler is fully written including the diff math. No "similar to" cross-references.

One legitimate "elision" to flag: in T2 step 1, the existing `wp_register_ability(...)` body is described as "unchanged" and not repeated. That's safe because the file is being modified by addition only — the engineer is keeping all existing code in that method untouched. The patch is purely additive (add 4 lines at the top of the loop body). Repeating the entire `register()` method body would invite copy-paste error.

### Type / signature consistency

- `DisabledAbilities::OPTION` constant value `'mcpsm_disabled_abilities'` used in T1, T3 step 3 (wp eval), T5 step 3 (wp eval).
- `DisabledAbilities::all() / contains() / set() / clear()` signatures from T1 used identically in T2 (`all()`), T3 (`all()`), T4 (`all()`, `set()`, `clear()`).
- `Plugin::instance_bundles()` defined in T3 → consumed in T4's `render_abilities()` and `all_local_ability_names()`.
- Form field name `enabled[]` consistent in T4 step 2 (rendering) and T4 step 3 (handler reading).
- Admin-post action name `mcpsm_save_abilities` consistent across T4 step 1 (registration), step 2 (form `<input type="hidden" name="action">`), step 3 (handler).
- Nonce action `mcpsm_save_abilities` consistent across T4 step 2 (`wp_nonce_field`), step 3 (`check_admin_referer`).
- Redirect target `?page=mcp-site-manager&tab=abilities&updated=1` consistent with the `?updated=1` notice gate in `render_abilities()`.

No drift.

---

## Execution Handoff

Plan complete and saved to `wp-content/plugins/mcp-site-manager/docs/superpowers/plans/2026-05-11-abilities-toggle.md`.

Two execution options:

1. **Subagent-Driven (recommended)** — fresh subagent per task with two-stage review.
2. **Inline Execution** — `superpowers:executing-plans` with batch checkpoints.

Which approach?
