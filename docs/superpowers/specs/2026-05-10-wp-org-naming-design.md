# wp.org Naming & Rename — Design Spec

**Status:** Draft, awaiting user approval
**Date:** 2026-05-10
**Author:** brainstormed via superpowers:brainstorming
**Owner:** mrabbani (https://profiles.wordpress.org/mrabbani)

## 1. Purpose

Rename the in-development `site-mcp` plugin to a wp.org-compliant identity ahead of submitting it to the WordPress.org plugin directory. The new identity must:

- Comply with wp.org plugin guidelines (no "WordPress" trademark in slug/name; "WP" prefix avoided for new submissions).
- Be SEO-discoverable for users searching "mcp wordpress", "mcp site manager", "AI site control".
- Use a unique, collision-safe PHP namespace, function/option/hook prefix, and text domain.
- Match modern PSR-4 / Composer practice with a vendor namespace.
- Be coherent across all five surfaces (slug, display name, namespace, prefix, ability namespace).

## 2. Decisions

| Dimension | Old value | New value |
|---|---|---|
| **wp.org slug** (URL + plugin folder) | `site-mcp` | `mcp-site-manager` |
| **Plugin folder** | `wp-content/plugins/site-mcp/` | `wp-content/plugins/mcp-site-manager/` |
| **Display name** (Plugin Name header) | `Site MCP` | `MCP Site Manager` |
| **Display name** (wp.org listing) | n/a | `MCP Site Manager – Manage WordPress from Claude, ChatGPT & Cursor` |
| **PHP namespace** (PSR-4) | `SiteMcp\` | `Mrabbani\McpSiteManager\` |
| **Composer package** | `site-mcp/site-mcp` | `mrabbani/mcp-site-manager` |
| **Function / option prefix** | `site_mcp_` | `mcpsm_` |
| **Hook / filter prefix** | `site_mcp_*` (none used) | `mcpsm/*` (slash-namespaced, modern WP convention) |
| **PHP constant prefix** | `SITE_MCP_*` | `MCPSM_*` |
| **DB table suffix** | `site_mcp_log` | `mcpsm_log` |
| **Text domain** (i18n) | `site-mcp` | `mcp-site-manager` |
| **MCP ability namespace** | `site-mcp/<verb>` | `mcpsm/<verb>` |
| **mcp-adapter server ID** *(if a custom server is re-introduced)* | `site-mcp/v1` | `mcp-site-manager/v1` |

### Rationale

- **`mcp-site-manager`** as slug puts the differentiator (MCP) first, follows the established naming pattern for management-style plugins on .org (MCP Content Manager Lite, MCP Tracker, MCP Server & AI Experiments), and reads naturally as "MCP Site Manager" rather than the more awkward "Site Manager MCP".
- **wp.org availability**: as of 2026-05-10, no exact-match plugin owns this slug (verified via wp.org plugin search). Final guarantee comes only at submission time, so this spec is contingent on the slug remaining free.
- **`Mrabbani\McpSiteManager\` namespace** uses author handle as vendor segment — Composer-compliant, locks out future PSR-4 collisions with anyone else's `McpSiteManager\` or `SiteMcp\`.
- **`mcpsm_` prefix** is 5 chars, pronounceable, and short enough not to bloat function names. A wp.org code search confirms no current plugin uses this prefix.
- **Slash-namespaced hooks (`mcpsm/before_register_ability`)** match the convention used by core's `wp_abilities_api_init` and the mcp-adapter's `mcp_adapter_init` family. Acceptable on wp.org and visually distinct from underscored core hooks.
- **`mcpsm/` for MCP ability namespace** keeps tool names short (e.g. `mcpsm/themes-active`) instead of the verbose `mcp-site-manager/themes-active`. Consistent with the function prefix. Existing client configs that hard-coded `site-mcp/*` will need to be updated; we treat this as a one-time pre-1.0 break.
- **Text domain = slug** is a hard wp.org requirement.

## 3. Branding consequences

The wp.org listing card will read:

> **MCP Site Manager – Manage WordPress from Claude, ChatGPT & Cursor**
> _by mrabbani_

Search keywords this targets: `mcp`, `site manager`, `claude`, `chatgpt`, `cursor`, `model context protocol`, `ai`. Tagline length: 73 chars (wp.org listing-line cap is ~80).

## 4. Out of scope

- Logos, banner, icon assets — separate task before final wp.org submission.
- A full readme.txt rewrite (FAQ, screenshots, changelog) — separate task.
- Submitting to the wp.org plugin review queue.
- Any feature change. This rename is purely identity.

## 5. Migration implications

This plugin is **pre-1.0, never published**. There are no installed users to migrate.

The rename touches:

- Plugin folder name (`site-mcp/` → `mcp-site-manager/`).
- Plugin file name (`site-mcp.php` → `mcp-site-manager.php`).
- All `namespace SiteMcp\…;` declarations and `use SiteMcp\…;` statements.
- All `SITE_MCP_*` constants.
- All `site_mcp_*` function names (none currently exist as standalone functions; all are class methods inside the namespace).
- The `wp_<prefix>_log` DB table (one rename via dbDelta or fresh install — uninstalling the old plugin drops the old table is acceptable for pre-1.0).
- Text-domain strings in every `__()`, `_e()`, `esc_html__()`, etc.
- The `composer.json` `name` field, autoload PSR-4 mapping, and namespace.
- The `.wp-env.json` plugin path mapping.
- Integration test bootstrap URLs, README references, plan/spec doc cross-references.
- The `AbilityLog::OPTION_ENABLED` constant value (`site_mcp_log_enabled` → `mcpsm_log_enabled`) and any similar plugin-owned option keys.
- The `Plugin::dependencies_met` references to `class_exists('\\WP\\MCP\\Core\\McpAdapter')` — unchanged (external API).
- The MCP ability local names (`site-mcp/posts-list` → `mcpsm/posts-list`) registered via `wp_register_ability()`.
- `Server::ID` constant if the custom server registration is reintroduced.
- The Claude Desktop config snippet shown by the admin Settings page.

The local Claude Desktop config currently pointing at our endpoint (`site-mcp-local` server with `mcp-wordpress-remote`) will need its connection re-tested once the plugin is renamed. The default-server URL (`/wp-json/mcp/mcp-adapter-default-server`) does not change because it's owned by `mcp-adapter`, not us.

## 6. Risks

- **Slug squatting between spec and submission.** Mitigation: submit to wp.org review within days of the rename landing.
- **`mcpsm_` collision.** Audited against published wp.org plugin code at spec-write time; future collisions are inherently unpreventable but unlikely with this prefix length.
- **Breaking ability names.** Tooling and example client configs that reference `site-mcp/*` will stop working. Acceptable: pre-1.0, no public users.

## 7. Open questions

None. All naming dimensions are resolved.

## 8. Acceptance criteria

The rename is complete when:

1. Plugin folder, plugin file, namespace, prefix, constants, text domain, composer package, ability namespace, and DB table all use the new identity.
2. `php -l` passes on every PHP file.
3. `./vendor/bin/phpunit --testsuite unit` passes.
4. Integration smoke tests pass against wp-env after the renamed plugin is reactivated.
5. `git grep -i "site_mcp\|sitemcp\|site-mcp"` returns only intentional historical references (e.g. inside the spec/plan docs that describe the rename).
6. The admin Settings page renders correctly under its new "Site MCP" → "MCP Site Manager" menu label.
