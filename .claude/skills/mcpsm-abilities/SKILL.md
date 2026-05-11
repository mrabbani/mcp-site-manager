---
name: mcpsm-abilities
description: Use when adding, modifying, or wiring abilities in the mcp-site-manager plugin — enforces the abilities-API-only registration pattern and forbids re-introducing the `mcp_adapter_default_server_config` filter.
---

# MCP Site Manager — Abilities Registration

## The rule

Abilities are registered **only** through the WordPress Abilities API. The plugin must not add abilities to the MCP Adapter's default server via any filter — discovery is automatic once an ability is registered with `wp_register_ability()`.

**Why:** MCP Adapter's default server already surfaces every registered `mcpsm/*` ability. The old `mcp_adapter_default_server_config` filter duplicated that list and created a second source of truth that drifted from the Abilities API (disabled abilities, renames, new bundles). Removing the filter eliminates the drift and the "why isn't my new ability showing up?" class of bug.

**How to apply:** When you add, rename, disable, or remove an ability:

1. Register/unregister via `wp_register_ability('mcpsm/<verb>', […])` inside an `AbilityBundle` — wired through `Plugin::register_abilities()` on the `wp_abilities_api_init` action.
2. Register the category (once) on `wp_abilities_api_categories_init`.
3. **Do not** add `add_filter('mcp_adapter_default_server_config', …)` anywhere. No `extend_default_server()` method. No `ability_names()` helper that exists only to feed such a filter.
4. Client-visible tool names are derived by MCP Adapter: `mcpsm/<verb>` → `mcpsm-<verb>` (slash → hyphen). Don't hand-maintain a tool list.

## Wiring checklist for a new ability

- [ ] Add the ability to an existing `AbilityBundle` (or create a new bundle under `includes/Abilities/<Domain>/`).
- [ ] Bundle is listed in `Plugin::bundles()`.
- [ ] `execute_callback` is wrapped via `AbilityRunner::run()` (logging + error envelope normalization).
- [ ] Permission check is explicit (`current_user_can(...)`) for direct-PHP abilities; REST-wrapping abilities inherit the underlying endpoint's permission callback.
- [ ] If the ability should be opt-out-able, it participates in `DisabledAbilities` (the REST `AbilitiesController` reads/writes this).
- [ ] Integration test in `tests/Integration/` exercises the JSON-RPC path.

## Red flags — stop and remove

- `add_filter('mcp_adapter_default_server_config', …)` — forbidden. Delete it.
- A method named `extend_default_server`, `register_default_server_tools`, or anything that builds a `tools` array for the default server config — forbidden, delete it.
- A helper that enumerates `mcpsm/*` names purely to feed the MCP Adapter config — forbidden. (Enumeration for the admin UI / REST `AbilitiesController` is fine; that has a different consumer.)
- Hardcoded `mcpsm-<verb>` client-name lists — forbidden; the adapter derives these.

## Reference

- `includes/Plugin.php` — `register_hooks()` shows the only two hand-offs: `wp_abilities_api_categories_init` and `wp_abilities_api_init`.
- `README.md` → "How it works" — matches this skill; keep them in sync if either changes.
