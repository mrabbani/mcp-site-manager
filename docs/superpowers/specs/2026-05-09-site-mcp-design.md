# site-mcp — Design Spec (v1)

**Status:** Draft, awaiting user approval
**Date:** 2026-05-09
**Author:** brainstormed via superpowers:brainstorming

## 1. Purpose

Build a WordPress plugin (`site-mcp`) that lets MCP-aware AI clients — Claude Desktop, ChatGPT, Cursor, and similar — manage a WordPress site over the Model Context Protocol. The plugin registers WordPress abilities via the **Abilities API**; the already-installed `mcp-adapter` plugin exposes them as MCP tools at a single HTTP endpoint.

## 2. Scope

### In scope (v1)

Two surfaces:

1. **Core content** — posts, pages, custom post types, taxonomies (tags/categories/custom), media, comments.
2. **Site administration** — plugins, themes, users, whitelisted options, navigation menus, site health/diagnostics, cache and cron utilities.

### Out of scope (deferred to v2+)

- WooCommerce abilities (products, orders, customers, coupons, reviews) — v2.
- Dokan multivendor abilities (vendors, vendor products, withdrawals) — v3.
- OAuth 2.1 transport (v1 uses Application Passwords only).
- Front-end UI for end users.
- Multisite-network-level operations.

## 3. Non-goals

- Not a replacement for `wp-cli` or `wp-admin`. Parity with REST-exposed surface is the bar.
- Not a permission system. The plugin defers to WordPress capabilities and existing REST permission callbacks.
- Not an auth provider. WordPress core's Application Passwords handles auth.

## 4. Architecture

### Plugin shape

Standard WordPress plugin at `wp-content/plugins/site-mcp/`. Composer-autoloaded PHP under `includes/`. Optional JS build under `src/` → `build/` (likely unused in v1).

### Dependencies (hard requires, checked at activation)

- `mcp-adapter` plugin active (provides MCP transport + Abilities API bridge).
- WordPress ≥ 6.8.
- PHP ≥ 8.0.
- Application Passwords enabled (default in WP core; SSL recommended but not enforced by core for app passwords as of WP 5.7+).

If any are missing, plugin self-deactivates with an admin notice. No fatal errors.

### Boot sequence

| Hook | Action |
|---|---|
| `plugins_loaded` (priority 5) | Load Composer autoloader, run dependency check, instantiate `Plugin` singleton. |
| `abilities_api_init` | Each `AbilityBundle` registers its abilities via `wp_register_ability()`. |
| `mcp_adapter_init` | Register one MCP server `site-mcp/v1` with all ability names; HTTP transport mounted at `/wp-json/site-mcp/v1/mcp`. |
| `admin_menu` | Register Settings → Site MCP page. |

### Folder layout

```
site-mcp/
├── site-mcp.php                # bootstrap, constants, activation hook
├── composer.json               # PSR-4 autoload: SiteMcp\ → includes/
├── includes/
│   ├── Plugin.php              # singleton, wires hooks
│   ├── Server.php              # mcp-adapter server registration
│   ├── Admin/
│   │   ├── SettingsPage.php
│   │   └── AbilityLog.php      # custom table reader/writer
│   ├── Abilities/
│   │   ├── AbilityBundle.php   # abstract base
│   │   ├── Content/            # PostsBundle, PagesBundle, CptBundle
│   │   ├── Taxonomy/
│   │   ├── Media/
│   │   ├── Comments/
│   │   ├── Users/
│   │   ├── Plugins/
│   │   ├── Themes/
│   │   ├── Options/
│   │   ├── Menus/
│   │   ├── Diagnostics/
│   │   └── Maintenance/        # cache, cron
│   └── Support/
│       ├── RestInvoker.php     # internal WP_REST_Request dispatcher
│       ├── SchemaBuilder.php   # JSON Schema helpers
│       ├── ErrorMapper.php     # WP_Error/Throwable → MCP error envelope
│       ├── AbilityRunner.php   # try/catch wrapper around execute_callback
│       └── OptionsAllowlist.php
├── src/                        # JS source (currently unused in v1)
├── build/                      # @wordpress/scripts output, gitignored
├── assets/
├── tests/
├── package.json
└── readme.txt
```

### Ability naming convention

`site-{domain}-{verb}`. Verbs: `list`, `get`, `create`, `update`, `delete`, plus domain-specific verbs (`activate`, `switch`, `upload`, `flush-rewrite`, `tail`, etc.).

### Single MCP server

One server `site-mcp/v1`, one URL, one connection per client. Bundles are an internal organisational tool, not a transport split.

### REST-wrapping pattern

For abilities that wrap a REST endpoint, the `execute_callback` builds a `WP_REST_Request`, sets method/route/body/query, dispatches via `rest_do_request()`, and returns the response data on success or maps to MCP error on failure. This reuses every existing REST permission check, sanitiser, schema validator, and filter — no reimplementation.

For abilities without a REST equivalent (plugin install/activate, theme switch, cache flush, cron, options outside REST), the `execute_callback` calls the relevant core PHP API directly.

## 5. Ability inventory (v1)

Approximately 73 abilities across 11 domains (counts: content 16, taxonomy 6, media 5, comments 6, users 6, plugins 7, themes 5, options 3, menus 11, diagnostics 3, maintenance 5).

### 5.1 Content
| Ability | Wraps |
|---|---|
| `site-posts-list` | `GET /wp/v2/posts` |
| `site-posts-get` | `GET /wp/v2/posts/{id}` |
| `site-posts-create` | `POST /wp/v2/posts` |
| `site-posts-update` | `PUT /wp/v2/posts/{id}` |
| `site-posts-delete` | `DELETE /wp/v2/posts/{id}` (`force` flag) |
| `site-pages-{list,get,create,update,delete}` | `…/pages` |
| `site-cpt-list-types` | `GET /wp/v2/types` |
| `site-cpt-{list,get,create,update,delete}` | dispatches to `{rest_base}` for the given `post_type` arg; only CPTs with `show_in_rest=true` are eligible |

### 5.2 Taxonomy
| Ability | Wraps |
|---|---|
| `site-taxonomies-list` | `GET /wp/v2/taxonomies` |
| `site-terms-list` | `GET /wp/v2/{taxonomy}` |
| `site-terms-{get,create,update,delete}` | `…/{taxonomy}/{id}` |

### 5.3 Media
| Ability | Implementation |
|---|---|
| `site-media-list` | `GET /wp/v2/media` |
| `site-media-get` | `GET /wp/v2/media/{id}` |
| `site-media-upload` | accepts `{source_url}` or `{base64, filename, mime_type}`; URL path uses `media_sideload_image()`; base64 path writes to temp file then `wp_handle_sideload()` |
| `site-media-update` | `PUT /wp/v2/media/{id}` (alt, caption, title) |
| `site-media-delete` | `DELETE /wp/v2/media/{id}` |

### 5.4 Comments
| Ability | Implementation |
|---|---|
| `site-comments-{list,get,create,update,delete}` | `/wp/v2/comments` |
| `site-comments-moderate` | direct: `wp_set_comment_status()` — `approve`/`hold`/`spam`/`trash`/`unspam` |

### 5.5 Users
| Ability | Implementation |
|---|---|
| `site-users-{list,get,create,update,delete}` | `/wp/v2/users` |
| `site-users-me` | `GET /wp/v2/users/me` |

### 5.6 Plugins (direct PHP, no core REST)
| Ability | Implementation |
|---|---|
| `site-plugins-list` | `get_plugins()` + `is_plugin_active()` |
| `site-plugins-activate` | `activate_plugin($file)` |
| `site-plugins-deactivate` | `deactivate_plugins([$file])` |
| `site-plugins-install` | `Plugin_Upgrader` from `.org` slug or zip URL |
| `site-plugins-update` | `Plugin_Upgrader::upgrade()` |
| `site-plugins-delete` | `delete_plugins([$file])` |
| `site-plugins-search` | WP.org plugins API (`plugins_api()`) |

### 5.7 Themes (direct PHP, no core REST)
| Ability | Implementation |
|---|---|
| `site-themes-list` | `wp_get_themes()` + `wp_get_theme()` |
| `site-themes-switch` | `switch_theme($stylesheet)` |
| `site-themes-install` | `Theme_Upgrader` |
| `site-themes-update` | `Theme_Upgrader::upgrade()` |
| `site-themes-delete` | `delete_theme($stylesheet)` |

### 5.8 Options (strict allowlist)

Allowed keys: `blogname`, `blogdescription`, `permalink_structure`, `default_category`, `posts_per_page`, `timezone_string`, `date_format`, `time_format`, `start_of_week`, `WPLANG`, `default_comment_status`, `default_ping_status`, `comment_registration`, `show_on_front`, `page_on_front`, `page_for_posts`. Allowlist defined in `OptionsAllowlist::ALLOWED`. Not user-extensible in v1 (deferred to v2 via filter).

| Ability | Notes |
|---|---|
| `site-options-list` | returns all allowlisted options + current values |
| `site-options-get` | one key, allowlist-enforced |
| `site-options-update` | allowlist-enforced; logged to ability log |

### 5.9 Menus (REST navigation endpoints)
| Ability | Wraps |
|---|---|
| `site-menus-{list,get,create,update,delete}` | `/wp/v2/menus` |
| `site-menu-items-{list,get,create,update,delete}` | `/wp/v2/menu-items` |
| `site-menu-locations-list` | `GET /wp/v2/menu-locations` |

### 5.10 Diagnostics (read-only)
| Ability | Returns |
|---|---|
| `site-health-overview` | WP version, PHP version, MySQL version, multisite flag, active theme, plugin counts (active/inactive), `WP_DEBUG` state, REST API reachable, app-passwords enabled |
| `site-health-debug-log-tail` | last N lines of `wp-content/debug.log` if `WP_DEBUG_LOG` is enabled and the file is readable; `N` capped at 500 |
| `site-health-rest-status` | result of `rest_get_url_prefix()` reachability check + auth method probe |

### 5.11 Maintenance
| Ability | Implementation |
|---|---|
| `site-cache-flush-rewrite` | `flush_rewrite_rules()` |
| `site-cache-flush-object` | `wp_cache_flush()` |
| `site-cron-list` | `_get_cron_array()` formatted |
| `site-cron-run` | `spawn_cron()` |
| `site-cron-unschedule` | `wp_unschedule_event($timestamp, $hook, $args)` |

## 6. Permissions

Each ability defines a `permission_callback`. Two patterns:

**REST-wrapping abilities:** callback returns `is_user_logged_in()`. The wrapped REST endpoint enforces the real cap via its own permission check during `rest_do_request()`. If the inner check fails, `WP_REST_Response` returns a 401/403 which `ErrorMapper` translates to MCP error `-32001`.

**Direct-PHP abilities:** explicit `current_user_can()` checks in the callback.

| Domain | Cap (direct-PHP only; REST wrappers handled by REST) |
|---|---|
| Plugins (all verbs) | `activate_plugins`; install/update/delete additionally require `install_plugins` |
| Themes (all verbs) | `switch_themes`; install/update/delete additionally require `install_themes` |
| Options (all verbs) | `manage_options` |
| Diagnostics | `manage_options` |
| Maintenance (cache, cron) | `manage_options` |
| Comments moderation (`site-comments-moderate`) | `moderate_comments` |

Authentication is provided by WordPress core's Application Passwords handler. The MCP HTTP endpoint accepts `Authorization: Basic <user:app_password>`. No custom auth code in this plugin.

## 7. Error handling

`Support\ErrorMapper::toMcp(WP_Error|Throwable): array` returns a JSON-RPC error envelope per the MCP 2025-06-18 spec.

| Input | MCP error code | Notes |
|---|---|---|
| `WP_Error` w/ HTTP 400–499 (excluding auth) | `-32602` (invalid params) | first error message + all error data |
| `WP_Error` w/ HTTP 401/403 | `-32001` (custom: Forbidden) | includes `data.required_capability` if known |
| `WP_Error` w/ HTTP 500–599 | `-32603` (internal error) | message redacted in production; full message via `error_log()` |
| Uncaught `Throwable` | `-32603` | logged with stack trace; message redacted in production |

Every `execute_callback` body runs inside `Support\AbilityRunner::run(callable)`, which centralises the try/catch, logs to ability log, and emits the MCP error envelope.

## 8. Admin screen (Settings → Site MCP)

Single PHP-rendered page, no React in v1. Capability: `manage_options`.

1. **Status panel** — colored dots:
   - `mcp-adapter` active
   - Abilities API available (`function_exists('wp_register_ability')`)
   - Application Passwords enabled (`wp_is_application_passwords_available()`)
   - Server registered with mcp-adapter
2. **Connection info** — MCP URL, "Copy URL" button, pre-filled `claude_desktop_config.json` snippet, link to user profile for app-password generation.
3. **Registered abilities table** — name, domain, one-line description, required cap. Sortable by domain; not paginated.
4. **Activity log** — last 50 invocations from `wp_site_mcp_log` table: timestamp, user, ability, success/error, duration ms. "Clear log" button. Logging on/off toggle stored in option `site_mcp_log_enabled` (default: on).

`wp_site_mcp_log` schema:

```
id BIGINT UNSIGNED PK
ts DATETIME
user_id BIGINT UNSIGNED
ability VARCHAR(190)
status ENUM('ok','error')
error_code VARCHAR(32) NULL
duration_ms INT UNSIGNED
INDEX (ts), INDEX (user_id), INDEX (ability)
```

Auto-trim: on insert, if `COUNT(*) > 1000`, delete oldest beyond 1000.

## 9. Testing

### 9.1 PHPUnit (wp-env)

- `Plugin::register_abilities()` registers all expected ability names.
- Each `AbilityBundle::register()` produces the expected ability count and names.
- `permission_callback` returns false for logged-out / wrong-cap users.
- `RestInvoker` dispatches and returns expected status/body.
- `ErrorMapper` produces correct envelopes for representative `WP_Error` and `Throwable` inputs.
- `OptionsAllowlist::contains()` enforces the list.
- `AbilityLog` insert + auto-trim past 1000.

### 9.2 Integration (wp-env + HTTP)

- Connect via Basic Auth + app password to `/wp-json/site-mcp/v1/mcp`.
- `tools/list` returns ≥ 70 tools, all named `site-…`.
- For each bundle, invoke one representative tool and assert response shape:
  - `site-posts-create` then `site-posts-get` round-trip.
  - `site-terms-create` then `site-terms-delete`.
  - `site-media-upload` (URL source) returns attachment id.
  - `site-options-update` for `blogname` then revert.
  - `site-plugins-activate`/`deactivate` against `hello.php`.
  - `site-themes-switch` to default theme then back.
  - `site-cache-flush-rewrite` returns ok.
  - `site-health-overview` returns the expected keys.

### 9.3 Manual acceptance (Claude Desktop)

Documented in `docs/qa/v1-acceptance.md`:

1. List my latest 5 posts.
2. Create a draft post titled "Hello from MCP" with a paragraph.
3. Tag it with an existing tag.
4. Upload an image from this URL and set it as the post's featured image.
5. Switch to the `twentytwentyfour` theme, then switch back.
6. Deactivate the `akismet` plugin, then reactivate.
7. Show me the last 20 lines of the debug log.

All seven must complete end-to-end via Claude Desktop talking only to this MCP server.

## 10. Open questions deferred to implementation

- Exact CPT routing for non-`show_in_rest` types — current plan: silently exclude. Confirm during implementation.
- Whether to expose Gutenberg block validation errors when creating posts with raw block markup — likely yes via REST passthrough.
- Multisite behaviour — v1 single-site only; multisite explicitly untested.

## 11. v2 / v3 roadmap (informational, not in scope)

- v2: WooCommerce bundle (products, orders, customers, coupons, reviews) wrapping `wc/v3/...`.
- v3: Dokan multivendor bundle (vendors, vendor products, vendor orders, withdrawals) wrapping `dokan/v1/...`.
- v2: Options allowlist filter (`site_mcp_options_allowlist`) for site-owner extension.
- v2+: OAuth 2.1 transport per the MCP 2025-06-18 spec.
