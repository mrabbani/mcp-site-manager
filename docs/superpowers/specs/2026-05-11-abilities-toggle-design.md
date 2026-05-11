# Abilities Toggle + Search — Design Spec (v1.1)

**Status:** Draft, awaiting user approval
**Date:** 2026-05-11
**Plugin:** mcp-site-manager

## 1. Purpose

Let site admins enable/disable individual MCP abilities and quickly find them via search on the existing **Abilities** tab. Currently every admin who connects gets all 74 abilities; the only gate is the calling user's WP capabilities. Adds a coarse, site-wide on/off switch for ability-level control without requiring a separate plugin.

## 2. Scope

### In scope

- A new option `mcpsm_disabled_abilities` (array of local ability names, e.g. `['themes-delete', 'plugins-delete']`).
- Filter at registration time: `AbilityBundle::register()` skips abilities whose local name is in the disabled list. The MCP-exposed tool list (`Plugin::ability_names()`) likewise excludes them.
- Rewrite the existing **Abilities** tab to a single PHP `<form>` containing per-ability rows with:
  - Checkbox (enabled/disabled)
  - Ability name (`mcpsm/<verb>`)
  - Description
  - Bundle name (e.g. `Themes`)
  - Effective tool name as exposed to MCP clients (e.g. `mcpsm-themes-delete`)
- A "Save" button that POSTs to a new admin-post handler `mcpsm_save_abilities`.
- A "Reset to all-enabled" button that clears the disabled list.
- A vanilla-JS search input above the table that hides non-matching rows as the user types. Matches against ability name + description case-insensitively. No JS framework added.
- A small "Showing N of M" counter that updates as the search filters.

### Out of scope (deferred)

- Per-role / per-user toggles (v2).
- Bundle-level toggle (one click disables an entire bundle). Maybe v1.2 if asked.
- Default-disabled risky verbs at install time (e.g. `themes-delete`). Currently all enabled by default for predictability.
- React rewrite of the Abilities tab (PHP is simpler and matches the Settings tab pattern).
- Audit log of toggle changes.

## 3. Decisions

| Choice | Decision |
|---|---|
| Storage | Single WP option `mcpsm_disabled_abilities` (array of strings, local ability names without the `mcpsm/` prefix). Default: empty array (all enabled). |
| Granularity | Per-ability only. No bundle-level toggle in v1.1. |
| Default | Empty disabled list — every newly registered ability is enabled by default. |
| Persistence | Form POST to admin-post handler `mcpsm_save_abilities` with nonce + cap check. Full bulk save (all checkboxes posted). |
| Filter timing | Inside `AbilityBundle::register()` — disabled abilities are never registered with `wp_register_ability()`, so they don't appear in `wp_get_abilities()`, the MCP tool list, or anywhere else. Cheaper than filtering at request time. |
| Search | Vanilla JS, client-side, no debounce needed (74 rows). Hides rows by toggling a `display:none` style. |
| UI framework | PHP-rendered (matches Settings tab). One `<form>` for the whole table. No React. |
| Toggle save UX | Bulk save (single Save button at the bottom). Not autosave-on-toggle — keeps the surface simple, matches WP admin convention. |
| Removal on uninstall | Option NOT deleted on plugin deactivation (matches current `mcpsm_log_enabled` behavior). |

## 4. UX flow

```
Settings → MCP Site Manager → Abilities tab
┌──────────────────────────────────────────────────────────────────┐
│ Registered abilities                                              │
│                                                                   │
│ Disable individual abilities to hide them from MCP clients.      │
│ Disabled abilities are not registered with WordPress and cannot  │
│ be invoked. The change takes effect on the next page load.       │
│                                                                   │
│ ┌─────────────────────────────────────┐  Showing 74 of 74        │
│ │ Search abilities…                   │                          │
│ └─────────────────────────────────────┘                          │
│                                                                   │
│ ┌────┬────────────────────┬─────────────────────┬─────────┐      │
│ │ ☑  │ Name               │ Description         │ Bundle  │      │
│ ├────┼────────────────────┼─────────────────────┼─────────┤      │
│ │ ☑  │ mcpsm/posts-list   │ List blog posts…    │ Posts   │      │
│ │ ☑  │ mcpsm/posts-create │ Create a new post…  │ Posts   │      │
│ │ ☐  │ mcpsm/themes-delete│ Delete a theme…     │ Themes  │      │
│ │ …  │ …                  │ …                   │ …       │      │
│ └────┴────────────────────┴─────────────────────┴─────────┘      │
│                                                                   │
│ [Save changes]    [Reset to all-enabled]                         │
└──────────────────────────────────────────────────────────────────┘
```

When the user types in the search input, rows whose name or description don't contain the query (case-insensitive) get `style="display:none"` set by JS. The counter updates. Toggles preserve their checked state through search.

After clicking **Save**, redirect to `?tab=abilities` with `?updated=1`; render a success notice "Abilities saved.".

## 5. Implementation outline

### 5.1 Disabled abilities accessor

New helper in a small support class `Support\DisabledAbilities`:

```php
namespace Mrabbani\McpSiteManager\Support;

final class DisabledAbilities
{
    public const OPTION = 'mcpsm_disabled_abilities';

    /** @return string[] */
    public static function all(): array
    {
        $raw = get_option(self::OPTION, []);
        return is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
    }

    public static function contains(string $local_name): bool
    {
        return in_array($local_name, self::all(), true);
    }

    /** @param string[] $names */
    public static function set(array $names): void
    {
        $clean = array_values(array_unique(array_map('strval', $names)));
        update_option(self::OPTION, $clean, false);
    }

    public static function clear(): void
    {
        update_option(self::OPTION, [], false);
    }
}
```

### 5.2 Filter at registration

In `AbilityBundle::register()`, add an early-continue:

```php
public function register(): void
{
    $disabled = \Mrabbani\McpSiteManager\Support\DisabledAbilities::all();
    foreach ($this->abilities() as $local => $spec) {
        if (in_array($local, $disabled, true)) {
            continue;
        }
        // …existing wp_register_ability(...) call…
    }
}
```

In `Plugin::ability_names()`, the same skip:

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

### 5.3 New admin-post handler

Add to `SettingsPage::register()`:

```php
add_action('admin_post_mcpsm_save_abilities', [self::class, 'handle_save_abilities']);
```

Handler:

```php
public static function handle_save_abilities(): void
{
    if (!current_user_can('manage_options')) wp_die();
    check_admin_referer('mcpsm_save_abilities');

    if (!empty($_POST['reset'])) {
        \Mrabbani\McpSiteManager\Support\DisabledAbilities::clear();
    } else {
        // Posted format: enabled[] = local_name for each CHECKED row.
        // Anything in the full ability inventory not in $enabled becomes disabled.
        $enabled = array_map('sanitize_text_field', (array) ($_POST['enabled'] ?? []));
        $all_local = self::all_local_ability_names();
        $disabled = array_values(array_diff($all_local, $enabled));
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
    foreach (Plugin::instance_bundles() as $bundle) {
        foreach (array_keys($bundle->abilities()) as $local) $names[] = $local;
    }
    return $names;
}
```

`Plugin::instance_bundles()` is a new public method that returns the same array `bundles()` currently returns privately. Needed because the SettingsPage handler must enumerate every potential ability (including disabled ones) to compute the disabled set.

### 5.4 Refactor `render_abilities()`

Replace the existing read-only table with a `<form>` containing checkboxes plus the search input. Inline a small `<script>` that filters rows.

Bundle name is derived from the bundle class basename (e.g. `PostsBundle` → `Posts`). To do this, introduce `AbilityBundle::label()` returning a default derived from the class name; bundles can override if they want a friendlier label.

### 5.5 Empty enabled list edge case

If a user disables every ability and clicks Save, the disabled list contains every name. `Plugin::register_abilities()` registers nothing; `Plugin::ability_names()` returns `[]`. The MCP tool list becomes empty (only mcp-adapter's own three built-in abilities remain). MCP clients see a near-empty server. Acceptable — it's exactly what the user asked for.

### 5.6 Search input HTML/JS

```html
<input type="search" id="mcpsm-ability-filter"
       placeholder="<?php esc_attr_e('Search abilities…', 'mcp-site-manager'); ?>"
       style="width:300px;">
<span id="mcpsm-ability-count" style="margin-left:1em;color:#646970;"></span>

<script>
(function() {
    const input = document.getElementById('mcpsm-ability-filter');
    const counter = document.getElementById('mcpsm-ability-count');
    const rows = document.querySelectorAll('tr.mcpsm-ability-row');
    const total = rows.length;
    function update() {
        const q = input.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach(function(row) {
            const haystack = row.dataset.haystack || '';
            const match = q === '' || haystack.indexOf(q) !== -1;
            row.style.display = match ? '' : 'none';
            if (match) shown++;
        });
        counter.textContent = shown === total
            ? 'Showing ' + total + ' of ' + total
            : 'Showing ' + shown + ' of ' + total;
    }
    input.addEventListener('input', update);
    update();
})();
</script>
```

Each row has `data-haystack="mcpsm/posts-list create a new post…"` (lowercased) precomputed in PHP for fast filter.

## 6. File layout

```
includes/
├── Support/
│   └── DisabledAbilities.php         # NEW
├── Abilities/AbilityBundle.php       # MODIFY: skip disabled in register(); add label() helper
├── Plugin.php                        # MODIFY: skip disabled in ability_names(); expose instance_bundles()
└── Admin/SettingsPage.php            # MODIFY: rewrite render_abilities() + new handler

tests/Support/
└── DisabledAbilitiesTest.php         # NEW: option round-trip + contains() + set/clear
```

No changes to React build, REST controller, Stats class, or any other file.

## 7. Permissions and security

- Admin-post handler: cap-checked (`manage_options`) + nonce (`mcpsm_save_abilities`) verified via `check_admin_referer`.
- Save form action posts to the standard `admin-post.php` URL.
- Posted `enabled[]` values are sanitized via `sanitize_text_field` and intersected with the known ability inventory before storage (no arbitrary strings can land in the option).
- All output uses `esc_html`/`esc_attr`/`esc_url`.
- `data-haystack` attribute uses `esc_attr` on the precomputed lowercase string.

## 8. Acceptance criteria

The feature is complete when:

1. The Abilities tab renders the search input + table + Save/Reset buttons.
2. Typing in the search filters rows live; the counter updates.
3. Unchecking a checkbox and clicking Save: the ability disappears from `wp_get_abilities()`, from the MCP tool list (verified via `tools/list`), and the option contains the local name.
4. Re-checking and saving: the ability reappears.
5. Reset button clears every disabled state in one click.
6. `php -l` passes on every changed file.
7. `./vendor/bin/phpunit --testsuite=unit` passes — including a new `DisabledAbilitiesTest`.
8. The dashboard, log, settings, connection tabs are unchanged in behaviour.
9. No new JS framework dependencies; the inline filter script is < 20 lines.
10. Disabling every ability still leaves the WP admin functional (no fatal); the MCP server is just empty.

## 9. Risks

- **Stale cache on the MCP client side**: clients that cached `tools/list` will see the old list until they reconnect. Documented in the description copy at the top of the tab ("takes effect on the next page load" — should be amended to "and on the next MCP client reconnect").
- **Disabled abilities still appear in the Activity Log table**: the log retains historical rows. Acceptable; arguably useful.
- **Bundle name derivation**: relying on class basename means renames change UI labels. Not a real risk since bundle classes are not user-visible by name elsewhere.

## 10. Open questions

None.
