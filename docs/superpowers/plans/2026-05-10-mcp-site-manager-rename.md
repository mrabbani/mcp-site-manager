# MCP Site Manager Rename Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename the in-development `site-mcp` plugin to `mcp-site-manager` end-to-end (folder, namespace, constants, prefixes, ability namespace, text domain, DB table, admin labels, dev env, tests) so it can be submitted to the WordPress.org plugin directory under mrabbani's account.

**Architecture:** Mostly mechanical find-and-replace driven by a single set of mappings. Execution order matters: rename the directory first, regenerate the Composer autoload against the new namespace, then run bulk text replacements grouped by concern (namespace → constants → text-domain → option keys → ability namespace → admin strings → env config → tests). Each task is one atomic commit on the renamed repo.

**Tech Stack:** PHP 8.0+, WordPress 6.8+, Composer (PSR-4), PHPUnit via wp-env, mcp-adapter, GNU find + sed (BSD sed compatible commands provided).

**Spec:** `docs/superpowers/specs/2026-05-10-wp-org-naming-design.md`

**Notation in this plan:**
- `OLD_DIR` = `/Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/site-mcp`
- `NEW_DIR` = `/Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager`
- After Task 2, all subsequent commands run from `NEW_DIR`. `OLD_DIR` no longer exists.

---

## Task 1: Pre-flight — halt wp-env, capture baseline

**Purpose:** wp-env mounts `OLD_DIR` into the container; renaming the host dir while it's running corrupts the volume. Stop it cleanly first. Also snapshot the green-baseline test result so we can compare after the rename.

**Files:** none modified.

- [ ] **Step 1: Confirm git working tree is clean**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/site-mcp
git status --short
```
Expected: empty output. If there are uncommitted changes, stop and resolve them before continuing.

- [ ] **Step 2: Stop wp-env**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/site-mcp
npx wp-env stop
```
Expected: `✔ Stopped WordPress.`

- [ ] **Step 3: Run unit suite as a green baseline**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/site-mcp
./vendor/bin/phpunit --testsuite=unit
```
Expected: `OK (9 tests, …)`. Record the exact line for later comparison. If tests fail here, stop — the rename is not what's broken.

- [ ] **Step 4: Confirm no other commits are pending**

```bash
git -C /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/site-mcp log --oneline -5
```
Expected: latest commit is the spec commit (`docs: spec for wp.org rename to mcp-site-manager`). No commit needed for this task — it's verification only.

---

## Task 2: Rename plugin directory + main plugin file + main plugin file constants

**Purpose:** Move the plugin folder and rename the entrypoint PHP file. Update the Plugin Name header and the four `SITE_MCP_*` constants. After this task, `OLD_DIR` does not exist and all subsequent commands use `NEW_DIR`.

**Files:**
- Rename: `OLD_DIR/` → `NEW_DIR/`
- Rename: `NEW_DIR/site-mcp.php` → `NEW_DIR/mcp-site-manager.php`
- Modify: `NEW_DIR/mcp-site-manager.php`

- [ ] **Step 1: Move the plugin directory**

```bash
mv /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/site-mcp \
   /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
```
Expected: silent success. Verify:
```bash
ls /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/ | grep -E '^(site-mcp|mcp-site-manager)$'
```
Expected output: `mcp-site-manager` (only).

- [ ] **Step 2: Rename the main plugin file via git mv**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git mv site-mcp.php mcp-site-manager.php
```
Expected: silent success.

- [ ] **Step 3: Replace the contents of `mcp-site-manager.php` (Plugin Name header + constants + class FQCN references)**

```php
<?php
/**
 * Plugin Name:       MCP Site Manager
 * Plugin URI:        https://wordpress.org/plugins/mcp-site-manager/
 * Description:       Manage WordPress from Claude, ChatGPT, Cursor and other MCP clients. Exposes posts, pages, taxonomies, media, plugins, themes, options, menus, diagnostics and maintenance as MCP tools via the MCP Adapter.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.0
 * Author:            mrabbani
 * Author URI:        https://profiles.wordpress.org/mrabbani/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mcp-site-manager
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('MCPSM_VERSION', '0.1.0');
define('MCPSM_FILE', __FILE__);
define('MCPSM_DIR', plugin_dir_path(__FILE__));
define('MCPSM_URL', plugin_dir_url(__FILE__));

$autoload = MCPSM_DIR . 'vendor/autoload.php';
if (!file_exists($autoload)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('MCP Site Manager: composer dependencies missing. Run `composer install` in the plugin directory.', 'mcp-site-manager');
        echo '</p></div>';
    });
    return;
}
require_once $autoload;

register_activation_hook(__FILE__, [\Mrabbani\McpSiteManager\Plugin::class, 'on_activate']);
register_deactivation_hook(__FILE__, [\Mrabbani\McpSiteManager\Plugin::class, 'on_deactivate']);

add_action('plugins_loaded', [\Mrabbani\McpSiteManager\Plugin::class, 'boot'], 5);
```

- [ ] **Step 4: Verify PHP lints**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
php -l mcp-site-manager.php
```
Expected: `No syntax errors detected in mcp-site-manager.php`. The file references classes (`\Mrabbani\McpSiteManager\Plugin`) that don't yet exist under that namespace — that's fine for `php -l`, which only checks syntax.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add -A
git commit -m "rename: directory + entrypoint file + plugin header to mcp-site-manager"
```

---

## Task 3: Update composer.json + regenerate autoload

**Purpose:** Tell Composer the new package name, the new PSR-4 root namespace (`Mrabbani\McpSiteManager\` → `includes/`), and regenerate `vendor/autoload.php`. The next tasks rely on the new autoload to find renamed classes.

**Files:**
- Modify: `NEW_DIR/composer.json`
- Regenerate: `NEW_DIR/vendor/`, `NEW_DIR/composer.lock`

- [ ] **Step 1: Replace the contents of `composer.json`**

```json
{
    "name": "mrabbani/mcp-site-manager",
    "description": "Manage WordPress from Claude, ChatGPT, Cursor and other MCP clients via the MCP Adapter.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "authors": [
        { "name": "mrabbani", "homepage": "https://profiles.wordpress.org/mrabbani/" }
    ],
    "require": {
        "php": ">=8.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "yoast/phpunit-polyfills": "^2.0"
    },
    "autoload": {
        "psr-4": { "Mrabbani\\McpSiteManager\\": "includes/" }
    },
    "autoload-dev": {
        "psr-4": { "Mrabbani\\McpSiteManager\\Tests\\": "tests/" }
    },
    "config": {
        "sort-packages": true
    }
}
```

- [ ] **Step 2: Regenerate the autoloader**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
composer dump-autoload
```
Expected: `Generating autoload files` followed by `Generated autoload files`. (No `composer install` needed — dependencies haven't changed.)

- [ ] **Step 3: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add composer.json composer.lock
git commit -m "rename: composer package name + PSR-4 namespace to Mrabbani\\\\McpSiteManager"
```

(Note: `composer.lock` content hash changes when `name` changes — it's a tiny diff but real, so it's included in the commit.)

---

## Task 4: Rename PHP namespace across includes/ and tests/

**Purpose:** Bulk-rewrite `namespace SiteMcp\…;`, `use SiteMcp\…;`, and `\SiteMcp\…` references everywhere under `includes/` and `tests/` to use the new `Mrabbani\McpSiteManager\` root.

**Files modified (every PHP file under):**
- `NEW_DIR/includes/`
- `NEW_DIR/tests/`

- [ ] **Step 1: Run the namespace rewrite**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager

# `namespace SiteMcp;`            → `namespace Mrabbani\McpSiteManager;`
# `namespace SiteMcp\X;`          → `namespace Mrabbani\McpSiteManager\X;`
# `use SiteMcp\…`                 → `use Mrabbani\McpSiteManager\…`
# `\SiteMcp\…` (in code or arrays) → `\Mrabbani\McpSiteManager\…`

find includes tests -type f -name "*.php" -print0 \
  | xargs -0 perl -i -pe '
      s{\bnamespace\s+SiteMcp\b}{namespace Mrabbani\\McpSiteManager}g;
      s{\buse\s+SiteMcp\\}{use Mrabbani\\McpSiteManager\\}g;
      s{\\SiteMcp\\}{\\Mrabbani\\McpSiteManager\\}g;
  '
```

- [ ] **Step 2: Verify no `SiteMcp` references remain in PHP source**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
grep -rn 'SiteMcp' includes tests 2>/dev/null
```
Expected: empty output. If any matches surface, inspect and adjust manually before committing.

- [ ] **Step 3: PHP-lint every changed file**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
find includes tests -type f -name "*.php" -print0 \
  | xargs -0 -I{} php -l {} | grep -v "No syntax errors" || echo "ALL CLEAN"
```
Expected: `ALL CLEAN`.

- [ ] **Step 4: Run unit tests (they reference test classes by namespace too)**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
composer dump-autoload
./vendor/bin/phpunit --testsuite=unit
```
Expected: `OK (9 tests, …)` — same count as Task 1 baseline.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add -A
git commit -m "rename: PHP namespace SiteMcp -> Mrabbani\\\\McpSiteManager (incl. use + FQCN)"
```

---

## Task 5: Rename PHP constants (`SITE_MCP_*` → `MCPSM_*`)

**Purpose:** The four bootstrap constants are referenced in includes/Plugin.php and tests. Rename in one pass.

**Files modified:** all PHP under `includes/` and `tests/`.

- [ ] **Step 1: Run the constant rewrite**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager

find includes tests -type f -name "*.php" -print0 \
  | xargs -0 perl -i -pe '
      s{\bSITE_MCP_VERSION\b}{MCPSM_VERSION}g;
      s{\bSITE_MCP_FILE\b}{MCPSM_FILE}g;
      s{\bSITE_MCP_DIR\b}{MCPSM_DIR}g;
      s{\bSITE_MCP_URL\b}{MCPSM_URL}g;
  '
```

- [ ] **Step 2: Verify no `SITE_MCP_` references remain**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
grep -rn 'SITE_MCP_' includes tests 2>/dev/null
```
Expected: empty output.

- [ ] **Step 3: PHP-lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
find includes tests -type f -name "*.php" -print0 \
  | xargs -0 -I{} php -l {} | grep -v "No syntax errors" || echo "ALL CLEAN"
```
Expected: `ALL CLEAN`.

- [ ] **Step 4: Unit tests**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
./vendor/bin/phpunit --testsuite=unit
```
Expected: same green count as Task 1.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add -A
git commit -m "rename: bootstrap constants SITE_MCP_* -> MCPSM_*"
```

---

## Task 6: Rename text domain (`'site-mcp'` → `'mcp-site-manager'`)

**Purpose:** Every i18n call (`__()`, `_e()`, `esc_html__()`, `esc_attr__()`, `_n()`, `_x()`, `_ex()`, etc.) must use the slug as text domain. wp.org enforces this.

**Files modified:** all PHP under `includes/`. (Tests don't use i18n. The main plugin file was already updated in Task 2.)

- [ ] **Step 1: Run the text-domain rewrite**

The text-domain string `'site-mcp'` only appears as the second argument to i18n functions. It will not appear in option keys or ability prefixes (those are handled in later tasks). To minimise the risk of replacing unrelated occurrences, target i18n calls explicitly.

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager

find includes -type f -name "*.php" -print0 \
  | xargs -0 perl -i -pe "
      s{(__|_e|_n|_x|_ex|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_n_noop|_nx_noop)\\(([^)]*?)'site-mcp'}{\$1(\$2'mcp-site-manager'}g;
  "
```

- [ ] **Step 2: Verify no remaining `'site-mcp'` text-domain literals**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
grep -rn "'site-mcp'" includes 2>/dev/null
```
Expected: empty output. (The string `site-mcp/` (ability namespace, with slash) is handled in Task 8 and should not appear in this grep because of the closing quote position.)

- [ ] **Step 3: PHP-lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
find includes -type f -name "*.php" -print0 \
  | xargs -0 -I{} php -l {} | grep -v "No syntax errors" || echo "ALL CLEAN"
```
Expected: `ALL CLEAN`.

- [ ] **Step 4: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add -A
git commit -m "rename: text domain site-mcp -> mcp-site-manager (all i18n calls)"
```

---

## Task 7: Rename plugin-owned option keys + DB table suffix

**Purpose:** The plugin owns one option (`site_mcp_log_enabled` → `mcpsm_log_enabled`) and one DB table (`{$wpdb->prefix}site_mcp_log` → `{$wpdb->prefix}mcpsm_log`). Rename in code. (No data migration: pre-1.0, no installed users; the old table will simply not exist on a clean install, and on any local dev wp-env we'll let `dbDelta` create the new one and abandon the old.)

**Files modified:**
- `NEW_DIR/includes/Admin/AbilityLog.php`

- [ ] **Step 1: Replace constant values in AbilityLog.php**

Open `includes/Admin/AbilityLog.php`. Find the two class constants at the top:

```php
public const TABLE_SUFFIX = 'site_mcp_log';
public const OPTION_ENABLED = 'site_mcp_log_enabled';
```

Replace with:

```php
public const TABLE_SUFFIX = 'mcpsm_log';
public const OPTION_ENABLED = 'mcpsm_log_enabled';
```

- [ ] **Step 2: Verify no other `site_mcp` (underscored) identifiers remain**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
grep -rn 'site_mcp' includes tests 2>/dev/null
```
Expected: empty output.

- [ ] **Step 3: PHP-lint**

```bash
php -l /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager/includes/Admin/AbilityLog.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add includes/Admin/AbilityLog.php
git commit -m "rename: AbilityLog option key + DB table suffix to mcpsm_log[_enabled]"
```

---

## Task 8: Rename MCP ability namespace (`site-mcp/` → `mcpsm/`) and ability category slug

**Purpose:** The plugin registers ~74 abilities under namespace `site-mcp/<verb>` and a category slug `site-mcp`. Rename both. (Ability names are the public API surface MCP clients see — this is a deliberate pre-1.0 break.)

**Files modified:**
- `NEW_DIR/includes/Abilities/AbilityBundle.php` (the `wp_register_ability("site-mcp/$local", …)` call + the `'category' => 'site-mcp'` injection)
- `NEW_DIR/includes/Plugin.php` (the `register_category()` slug + `ability_names()` builder + any string literal references)

- [ ] **Step 1: Update `includes/Abilities/AbilityBundle.php`**

In `register()`, change the line:

```php
$name = "site-mcp/$local";
```
to:
```php
$name = "mcpsm/$local";
```

In the same `register()` call, inside the `wp_register_ability(...)` array, change:

```php
'category'            => 'site-mcp',
```
to:
```php
'category'            => 'mcpsm',
```

- [ ] **Step 2: Update `includes/Plugin.php`**

In `register_category()`, change:

```php
wp_register_ability_category('site-mcp', [
    'label'       => __('Site MCP', 'mcp-site-manager'),
    'description' => __('WordPress site management abilities exposed to MCP clients.', 'mcp-site-manager'),
]);
```
to:
```php
wp_register_ability_category('mcpsm', [
    'label'       => __('MCP Site Manager', 'mcp-site-manager'),
    'description' => __('WordPress site management abilities exposed to MCP clients.', 'mcp-site-manager'),
]);
```

In `ability_names()`, change:

```php
$names[] = "site-mcp/$local";
```
to:
```php
$names[] = "mcpsm/$local";
```

- [ ] **Step 3: Update `includes/Admin/SettingsPage.php` ability listing filter**

The settings page filters abilities by name prefix to display only ours. Find the line in `collect_abilities()`:

```php
if (str_starts_with((string) $name, 'site-mcp/')) {
```
Replace with:
```php
if (str_starts_with((string) $name, 'mcpsm/')) {
```

- [ ] **Step 4: Verify no `site-mcp/` (slash form) literals remain in PHP source**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
grep -rn 'site-mcp/' includes 2>/dev/null
```
Expected: empty output. (Note: occurrences inside `docs/` and integration test files are handled in Tasks 11–12.)

- [ ] **Step 5: PHP-lint**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
for f in includes/Abilities/AbilityBundle.php includes/Plugin.php includes/Admin/SettingsPage.php; do
  php -l $f
done | grep -v "No syntax errors" || echo "ALL CLEAN"
```
Expected: `ALL CLEAN`.

- [ ] **Step 6: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add -A
git commit -m "rename: MCP ability namespace site-mcp/ -> mcpsm/ + category slug"
```

---

## Task 9: Update admin Settings page labels + menu slug

**Purpose:** The Settings → Site MCP submenu, page title, and h1 must read "MCP Site Manager". The menu slug also changes so the URL is `options-general.php?page=mcp-site-manager` instead of `?page=site-mcp`.

**Files modified:**
- `NEW_DIR/includes/Admin/SettingsPage.php`

- [ ] **Step 1: Replace the SLUG constant**

In `includes/Admin/SettingsPage.php`, find:

```php
public const SLUG = 'site-mcp';
```
Replace with:
```php
public const SLUG = 'mcp-site-manager';
```

- [ ] **Step 2: Replace the page title strings**

In the same file, find both `add_options_page` arguments and the `<h1>` text:

```php
add_options_page(
    __('Site MCP', 'mcp-site-manager'),
    __('Site MCP', 'mcp-site-manager'),
    'manage_options',
    self::SLUG,
    [self::class, 'render']
);
```
Replace `'Site MCP'` (both occurrences) with `'MCP Site Manager'`. The translatable form becomes `__('MCP Site Manager', 'mcp-site-manager')`.

In `render()`, find:

```php
<h1><?php esc_html_e('Site MCP', 'mcp-site-manager'); ?></h1>
```
Replace with:
```php
<h1><?php esc_html_e('MCP Site Manager', 'mcp-site-manager'); ?></h1>
```

- [ ] **Step 3: Replace the example client config snippet's server key**

In `client_config_snippet()`, find:

```php
'mcpServers' => [
    'site-mcp' => [
```
Replace with:
```php
'mcpServers' => [
    'mcp-site-manager' => [
```

- [ ] **Step 4: Verify no remaining `'Site MCP'` display strings**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
grep -rn "'Site MCP'" includes 2>/dev/null
grep -rn '"Site MCP"' includes 2>/dev/null
```
Expected: both empty.

- [ ] **Step 5: PHP-lint**

```bash
php -l /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager/includes/Admin/SettingsPage.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add -A
git commit -m "rename: admin page slug + labels (Site MCP -> MCP Site Manager)"
```

---

## Task 10: Update readme.txt

**Purpose:** wp.org reads `readme.txt` for the listing page. Update plugin name, contributors, tags, and description to match the new identity.

**Files modified:**
- `NEW_DIR/readme.txt`

- [ ] **Step 1: Replace the contents of `readme.txt`**

```
=== MCP Site Manager ===
Contributors: mrabbani
Tags: mcp, ai, claude, chatgpt, cursor
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage WordPress from Claude, ChatGPT, Cursor and other MCP clients.

== Description ==

MCP Site Manager exposes ~74 WordPress capabilities (posts, pages, custom post types, taxonomies, media, comments, users, plugins, themes, options, navigation menus, diagnostics, cache and cron) as Model Context Protocol (MCP) tools.

Pair it with the **MCP Adapter** plugin and connect any MCP-compatible AI client — Claude Desktop, ChatGPT, Cursor — using a WordPress Application Password.

* Read and write blog posts and pages
* Manage tags, categories and custom taxonomies
* Upload and edit media (URL or base64)
* Moderate comments
* Manage users
* Install, activate, update and delete plugins and themes
* Read and update an allowlisted set of site options
* Edit navigation menus and items
* Site health overview and debug log tail
* Flush caches, list and trigger WP-cron events

All abilities run with the authenticated user's capabilities. The plugin adds nothing to the page output and registers no shortcodes.

== Installation ==

1. Install and activate the **MCP Adapter** plugin first.
2. Activate **MCP Site Manager**.
3. Go to **Settings → MCP Site Manager** for the connection URL and an example client config snippet.
4. Generate an Application Password in your user profile and paste it into your AI client's MCP config.

== Frequently Asked Questions ==

= Does this work without the MCP Adapter plugin? =

No. MCP Site Manager registers WordPress abilities; the MCP Adapter exposes them over MCP transports. Both must be active.

= What permissions does it need? =

Each ability runs with the calling user's WordPress capabilities. A subscriber can only do what a subscriber can do.

== Changelog ==

= 0.1.0 =
* Initial release.
```

- [ ] **Step 2: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add readme.txt
git commit -m "docs: rewrite readme.txt for MCP Site Manager wp.org listing"
```

---

## Task 11: Update wp-env config + integration test bootstrap

**Purpose:** `.wp-env.json` mounted the old folder; update the mapping. Integration test bootstrap defaults pointed at the old endpoint; update the URL. The `mcp-adapter` default-server URL is unchanged because that's owned by `mcp-adapter` itself — only ours changes if we re-introduce a custom server (deferred).

**Files modified:**
- `NEW_DIR/.wp-env.json`
- `NEW_DIR/tests/Integration/bootstrap.php`

- [ ] **Step 1: Replace contents of `.wp-env.json`**

```json
{
    "core": "WordPress/WordPress",
    "phpVersion": "8.1",
    "port": 8890,
    "testsPort": 8891,
    "plugins": [
        "../mcp-adapter",
        "."
    ],
    "config": {
        "WP_DEBUG": true,
        "WP_DEBUG_LOG": true
    },
    "mappings": {
        "wp-content/plugins/mcp-site-manager": "."
    }
}
```

- [ ] **Step 2: Replace contents of `tests/Integration/bootstrap.php`**

The default URL stays at the mcp-adapter default-server endpoint (which is what the current Plugin.php wires our abilities into via the `mcp_adapter_default_server_config` filter):

```php
<?php
require_once __DIR__ . '/../../vendor/autoload.php';

if (!getenv('SITE_MCP_URL'))    putenv('SITE_MCP_URL=http://localhost:8890/wp-json/mcp/mcp-adapter-default-server');
if (!getenv('SITE_MCP_USER'))   putenv('SITE_MCP_USER=admin');
if (!getenv('SITE_MCP_APP_PW')) fwrite(STDERR, "Set SITE_MCP_APP_PW env var before running integration tests.\n");
```

(Env-var names kept as `SITE_MCP_*` to avoid breaking any local shell scripts. Feel free to rename to `MCPSM_*` later if you prefer; not strictly part of this rename.)

- [ ] **Step 3: Restart wp-env on the new path**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npx wp-env destroy --debug
npx wp-env start
```

`destroy` is needed because the old volume references the old plugin path. Expect a confirmation prompt — type `y` to confirm. After `start`, expect `WordPress development site started at http://localhost:8890`.

- [ ] **Step 4: Re-enable permalinks (wp-env starts without them by default)**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npx wp-env run cli wp rewrite structure '/%postname%/' --hard
npx wp-env run cli wp rewrite flush --hard
```
Expected: two `Success:` lines.

- [ ] **Step 5: Confirm the renamed plugin is active and abilities register**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
npx wp-env run cli wp plugin list 2>&1 | grep -E "mcp-site-manager|mcp-adapter"
npx wp-env run cli wp eval 'echo "mcpsm count: " . count(array_filter(array_keys((array) wp_get_abilities()), fn($n)=>str_starts_with($n,"mcpsm/"))) . "\n";'
```
Expected:
```
mcp-adapter      active
mcp-site-manager active
mcpsm count: 74
```

If the count is 0, dependencies likely failed; check `wp-content/debug.log` inside the container.

- [ ] **Step 6: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add .wp-env.json tests/Integration/bootstrap.php
git commit -m "chore(env): point wp-env mapping + integration bootstrap to renamed plugin"
```

---

## Task 12: Update integration smoke tests to use new ability names

**Purpose:** `SmokeTest.php` hard-codes tool names like `site-mcp-posts-list` (mcp-adapter rewrites slashes to hyphens for MCP tool names). After Task 8 the prefix is `mcpsm-`, not `site-mcp-`.

**Files modified:**
- `NEW_DIR/tests/Integration/SmokeTest.php`

- [ ] **Step 1: Bulk-rewrite tool names in `SmokeTest.php`**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
perl -i -pe 's{site-mcp-}{mcpsm-}g' tests/Integration/SmokeTest.php
```

- [ ] **Step 2: Verify no `site-mcp-` references remain in tests**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
grep -rn 'site-mcp-' tests 2>/dev/null
```
Expected: empty output.

- [ ] **Step 3: Generate a fresh app password**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
APP_PW=$(npx wp-env run cli wp user application-password create admin "rename-tests" --porcelain 2>&1 \
  | grep -E '^[a-zA-Z0-9]{20,}' | head -1)
echo "APP_PW=$APP_PW"
```
Expected: a 24-character password printed. Save it for the next step.

- [ ] **Step 4: Run the integration suite**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
SITE_MCP_APP_PW="$APP_PW" \
SITE_MCP_URL="http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
SITE_MCP_USER="admin" \
  ./vendor/bin/phpunit --testsuite=integration
```
Expected: `OK (9 tests, 70 assertions)`. If a single test fails on an HTTP 401 in the very first run (a known race seen in this codebase before), re-run once.

- [ ] **Step 5: Commit**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add tests/Integration/SmokeTest.php
git commit -m "test(int): rewrite smoke tool names site-mcp- -> mcpsm-"
```

---

## Task 13: Update Claude Desktop user config (out-of-tree)

**Purpose:** The user's local Claude Desktop config has a `site-mcp-local` entry pointing at the old endpoint. Rename the server key and update the password if a fresh one was generated in Task 12.

**Files modified:**
- `~/Library/Application Support/Claude/claude_desktop_config.json` (out-of-tree, no commit)

- [ ] **Step 1: Update the entry**

Read the file and locate the `site-mcp-local` block. Rename the key to `mcp-site-manager-local` and ensure the entry reads:

```json
"mcp-site-manager-local": {
  "command": "npx",
  "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
  "env": {
    "WP_API_URL": "http://localhost:8890/wp-json/mcp/mcp-adapter-default-server",
    "WP_API_USERNAME": "admin",
    "WP_API_PASSWORD": "<APP_PW from Task 12>"
  }
}
```

- [ ] **Step 2: Validate JSON**

```bash
python3 -c "import json; json.load(open('/Users/mahbub/Library/Application Support/Claude/claude_desktop_config.json'))" \
  && echo "JSON valid"
```
Expected: `JSON valid`.

- [ ] **Step 3: Quit and relaunch Claude Desktop**

Cmd+Q in Claude Desktop, then reopen. The new server `mcp-site-manager-local` should appear in its MCP servers list. Try a prompt: "Using mcp-site-manager-local, get the active theme details."

No git commit — this file lives outside the repo.

---

## Task 14: Final cross-cutting sanity check

**Purpose:** Catch any lingering `site-mcp` / `SiteMcp` / `SITE_MCP_` / `site_mcp_` reference in source/config/tests that earlier targeted greps missed. Fix anything found, then close the rename.

**Files inspected (all):** `NEW_DIR/`.

- [ ] **Step 1: Sweep for stragglers**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
grep -rn -E "(site-mcp|site_mcp|SiteMcp|SITE_MCP|Site MCP)" \
  --exclude-dir=vendor \
  --exclude-dir=node_modules \
  --exclude-dir=.git \
  --exclude-dir=docs \
  . 2>/dev/null
```
Expected: empty output. The `--exclude-dir=docs` skips spec/plan files which intentionally describe the old name in their migration history.

If the grep finds anything:

1. Inspect each match.
2. Decide: legitimate historical reference (e.g. a migration note in a code comment about "previously named site-mcp") — leave it, but add a `// historical:` comment so future greps can skip it. Or genuine missed identifier — rename it.
3. Re-run the grep until clean (excluding the historical references).

- [ ] **Step 2: Re-run unit + integration**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
./vendor/bin/phpunit --testsuite=unit
SITE_MCP_APP_PW="$APP_PW" \
SITE_MCP_URL="http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
SITE_MCP_USER="admin" \
  ./vendor/bin/phpunit --testsuite=integration
```
Expected: both pass with same counts as previous green runs.

- [ ] **Step 3: One end-to-end manual probe**

```bash
SID=$(curl -sS -i -u admin:$APP_PW \
  -X POST -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  http://localhost:8890/wp-json/mcp/mcp-adapter-default-server \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"sanity","version":"1"}}}' \
  2>&1 | grep -i "Mcp-Session-Id:" | awk '{print $2}' | tr -d '\r')

curl -sS -u admin:$APP_PW \
  -X POST -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -H "Mcp-Session-Id: $SID" \
  http://localhost:8890/wp-json/mcp/mcp-adapter-default-server \
  -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' > /dev/null

curl -sS -u admin:$APP_PW \
  -X POST -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -H "Mcp-Session-Id: $SID" \
  http://localhost:8890/wp-json/mcp/mcp-adapter-default-server \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); names=[t['name'] for t in d['result']['tools']]; mcpsm=[n for n in names if n.startswith('mcpsm-')]; print(f'total={len(names)} mcpsm={len(mcpsm)} sample={mcpsm[:3]}')"
```
Expected: `total=…(>=77)… mcpsm=74 sample=['mcpsm-posts-list', 'mcpsm-posts-get', 'mcpsm-posts-create']`.

- [ ] **Step 4: Commit (if any straggler fixes from Step 1)**

```bash
cd /Users/mahbub/Development/Projects/zip-dokan/wp-content/plugins/mcp-site-manager
git add -A
git diff --cached --stat
git commit -m "rename: clean up trailing site-mcp references" || echo "nothing to commit"
```

If `git diff --cached --stat` shows no changes, skip the commit.

---

## Self-Review

Walked the spec section-by-section against the plan:

- **§2 Decisions table** — every row maps to at least one task: dir/file/header (T2), composer (T3), namespace (T4), constants (T5), text domain (T6), DB + option (T7), ability namespace + category (T8), admin labels + slug (T9). ✅
- **§3 Branding consequences (readme listing line)** — covered in T10. ✅
- **§5 Migration implications**:
  - Plugin folder rename → T2 ✅
  - Plugin file rename → T2 ✅
  - All `namespace`/`use` → T4 ✅
  - All `SITE_MCP_*` constants → T5 ✅
  - `site_mcp_*` function names → none exist (verified during plan-write) ✅
  - DB table + option key → T7 ✅
  - Text-domain strings → T6 ✅
  - composer.json + autoload → T3 ✅
  - `.wp-env.json` mapping → T11 ✅
  - Integration test bootstrap → T11 ✅
  - Plan/spec doc cross-references → intentionally untouched (historical) ✅
  - `AbilityLog::OPTION_ENABLED` constant value → T7 ✅
  - `class_exists('\\WP\\MCP\\Core\\McpAdapter')` external API reference → unchanged ✅
  - Ability local names rename → T8 ✅
  - `Server::ID` constant — not currently used (custom server registration was removed in favor of the `mcp_adapter_default_server_config` filter); spec already calls this out as conditional. Plan does not re-introduce it. ✅
  - Admin Settings page client-config snippet → T9 (server key) + T13 (user's actual config) ✅
- **§8 Acceptance criteria**:
  - Folder/file/namespace/prefix/constants/text-domain/composer/ability/DB renamed → T2–T8 ✅
  - `php -l` passes — verified per task ✅
  - Unit suite passes — verified at T1, T4, T5, T14 ✅
  - Integration smoke passes against wp-env after rename — T12 + T14 ✅
  - `git grep` returns only intentional historical refs — T14 ✅
  - Admin Settings menu label updated — T9 ✅

**Placeholder scan**: no TBDs; no "similar to Task N" references; every code change shows the actual code. ✅

**Type/identifier consistency**: `MCPSM_*` (constants), `mcpsm_` (option/table prefix), `mcpsm/` (hook + ability namespace), `mcp-site-manager` (slug + text domain), `Mrabbani\McpSiteManager\` (namespace) used identically across every task. ✅

One intentional choice noted in the plan body: env-var names in integration tests stay as `SITE_MCP_*` (T11 step 2) to avoid breaking local shell history. If you want them renamed to `MCPSM_*` for full consistency, add a one-line task — straightforward.

---

## Execution Handoff

Plan complete and saved to `wp-content/plugins/site-mcp/docs/superpowers/plans/2026-05-10-mcp-site-manager-rename.md`. (After Task 2 it'll live at `…/mcp-site-manager/docs/superpowers/plans/…`.)

Two execution options:

1. **Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — I execute tasks in this session via `superpowers:executing-plans`, batch execution with checkpoints.

Which approach?
