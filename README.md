# MCP Site Manager

> Manage WordPress from Claude, ChatGPT, Cursor and any other MCP client.

[![License: GPL v2+](https://img.shields.io/badge/License-GPL_v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net/releases/8.0/)
[![WordPress 6.8+](https://img.shields.io/badge/WordPress-6.8%2B-21759b.svg)](https://wordpress.org/download/)

MCP Site Manager exposes ~74 WordPress capabilities — content, taxonomies, media, comments, users, plugins, themes, options, navigation menus, diagnostics, cache and cron — as [Model Context Protocol](https://modelcontextprotocol.io/) tools. Pair it with [WP MCP Adapter](https://github.com/WordPress/mcp-adapter), authenticate with an Application Password, and any MCP-compatible AI agent can drive your WordPress site.

## Quick start

1. Install and activate MCP Site Manager (this plugin).
2. If the MCP Adapter dependency is missing, an admin notice appears with an
   **Install MCP Adapter** button — one click downloads the latest release
   asset from [WordPress/mcp-adapter Releases](https://github.com/WordPress/mcp-adapter/releases)
   and activates it. Pin a specific release tag by filtering
   `mcpsm_adapter_download_url`.
3. Visit **Settings → MCP Site Manager** for the connection URL.
4. Generate an Application Password from your user profile.
5. Add the snippet to your MCP client config (example below).

### Claude Desktop config

`~/Library/Application Support/Claude/claude_desktop_config.json` on macOS, `%APPDATA%\Claude\claude_desktop_config.json` on Windows:

```json
{
  "mcpServers": {
    "mcp-site-manager": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://your-site.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "abcd efgh ijkl mnop qrst uvwx"
      }
    }
  }
}
```

Quit and relaunch Claude Desktop. Try: *"List my latest 5 posts"*.

## Architecture

```
mcp-site-manager/
├── mcp-site-manager.php          # Plugin bootstrap: header, constants, hooks
├── composer.json                 # PSR-4 → Mrabbani\McpSiteManager\
├── readme.txt                    # wp.org listing
├── includes/
│   ├── Plugin.php                # Singleton, dependency check, hook wiring
│   ├── Admin/
│   │   ├── SettingsPage.php      # Settings → MCP Site Manager UI
│   │   └── AbilityLog.php        # Custom DB table + recent invocations
│   ├── Abilities/
│   │   ├── AbilityBundle.php     # Abstract base — declarative ability map
│   │   ├── Content/              # Posts, pages, custom post types
│   │   ├── Taxonomy/
│   │   ├── Media/
│   │   ├── Comments/
│   │   ├── Users/
│   │   ├── Plugins/              # install/activate/update/delete
│   │   ├── Themes/               # list/active/switch/install/update/delete
│   │   ├── Options/              # allowlisted only
│   │   ├── Menus/
│   │   ├── Diagnostics/          # health overview, debug log tail
│   │   └── Maintenance/          # cache flush, cron list/run/unschedule
│   └── Support/
│       ├── RestInvoker.php       # internal WP_REST_Request dispatcher
│       ├── SchemaBuilder.php     # JSON Schema helpers for ability inputs
│       ├── ErrorMapper.php       # WP_Error/Throwable → MCP envelope
│       ├── AbilityRunner.php     # try/catch + log + error mapping
│       └── OptionsAllowlist.php
└── tests/
    ├── Support/                  # PHPUnit unit tests
    └── Integration/              # wp-env + curl JSON-RPC smoke tests
```

### How it works

1. **Bootstrap.** On `plugins_loaded` priority 5, `Plugin::boot()` checks for the MCP Adapter dependency and wires hooks.
2. **Category registration.** On `wp_abilities_api_categories_init`, registers the `mcpsm` category.
3. **Ability registration.** On `wp_abilities_api_init`, each `AbilityBundle` registers its abilities via `wp_register_ability('mcpsm/<verb>', […])`. MCP Adapter's default server discovers registered abilities on its own — no filter wiring required. Clients see them as `mcpsm-<verb>` (slashes are mcp-adapter-rewritten to hyphens).
4. **Execution.** When a client calls `tools/call`, `AbilityBundle::register()` wraps the execute callback in `AbilityRunner::run()`, which centralizes try/catch, logging to the activity log table, and mapping `WP_Error`/`Throwable` to JSON-RPC error envelopes.

### Two ability patterns

- **REST-wrapping** (most abilities). The `execute_callback` builds a `WP_REST_Request` and dispatches via `rest_do_request()`. Reuses every existing REST permission check, sanitizer and schema validator. No reimplementation.
- **Direct PHP** (plugin/theme install/activate/delete, options, cache, cron, diagnostics). Where WP has no REST endpoint, the callback calls core APIs directly (`Plugin_Upgrader`, `switch_theme`, `wp_cache_flush`, etc.) with explicit `current_user_can()` checks.

### Permissions

Every ability runs with the **calling user's WordPress capabilities**. A subscriber can only do what a subscriber can do. Authentication uses WP core's [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) — no custom auth code. Direct-PHP abilities use granular WP caps:

| Domain | Required cap |
|---|---|
| Plugins (all verbs) | `activate_plugins`; install/update/delete also need `install_plugins`/`update_plugins`/`delete_plugins` |
| Themes (all verbs) | `switch_themes`; install/update/delete also need `install_themes`/`update_themes`/`delete_themes` |
| Options | `manage_options` |
| Diagnostics | `manage_options` |
| Maintenance (cache, cron) | `manage_options` |
| Comments moderation | `moderate_comments` |

REST-wrapping abilities defer to the wrapped endpoint's own permission callback (e.g. `edit_posts`, `upload_files`).

### Options allowlist

To prevent accidental destruction of plugin-internal options or auth data, the Options bundle only reads/writes a hard-coded allowlist:

```
blogname, blogdescription, permalink_structure, default_category,
posts_per_page, timezone_string, date_format, time_format,
start_of_week, WPLANG, default_comment_status, default_ping_status,
comment_registration, show_on_front, page_on_front, page_for_posts
```

Anything outside the list is rejected with a `403` and an `allowed_keys` payload telling the client what's available.

## Ability inventory (~74)

| Domain | Abilities |
|---|---|
| Posts | `posts-list`, `posts-get`, `posts-create`, `posts-update`, `posts-delete` |
| Pages | `pages-list`, `pages-get`, `pages-create`, `pages-update`, `pages-delete` |
| CPT | `cpt-list-types`, `cpt-list`, `cpt-get`, `cpt-create`, `cpt-update`, `cpt-delete` |
| Taxonomy | `taxonomies-list`, `terms-list`, `terms-get`, `terms-create`, `terms-update`, `terms-delete` |
| Media | `media-list`, `media-get`, `media-upload`, `media-update`, `media-delete` |
| Comments | `comments-list`, `comments-get`, `comments-create`, `comments-update`, `comments-delete`, `comments-moderate` |
| Users | `users-list`, `users-get`, `users-me`, `users-create`, `users-update`, `users-delete` |
| Plugins | `plugins-list`, `plugins-activate`, `plugins-deactivate`, `plugins-install`, `plugins-update`, `plugins-delete`, `plugins-search` |
| Themes | `themes-list`, `themes-active`, `themes-switch`, `themes-install`, `themes-update`, `themes-delete` |
| Options | `options-list`, `options-get`, `options-update` |
| Menus | `menus-list`, `menus-get`, `menus-create`, `menus-update`, `menus-delete`, `menu-items-{list,get,create,update,delete}`, `menu-locations-list` |
| Diagnostics | `health-overview`, `health-debug-log-tail`, `health-rest-status` |
| Maintenance | `cache-flush-rewrite`, `cache-flush-object`, `cron-list`, `cron-run`, `cron-unschedule` |

Tool names as seen by MCP clients are hyphen-prefixed: `mcpsm-posts-list`, `mcpsm-themes-active`, etc.

## Local development

### Prerequisites

- PHP ≥ 8.0
- Composer
- Node.js 18+ (for `@wordpress/env`)
- Docker Desktop (for wp-env)

### Setup

```bash
git clone https://github.com/mrabbani/mcp-site-manager.git
cd mcp-site-manager
composer install
npm install

# Place mcp-adapter alongside this repo (sibling directory) so .wp-env.json finds it:
git clone https://github.com/WordPress/mcp-adapter.git ../mcp-adapter
( cd ../mcp-adapter && composer install )

npx wp-env start
npx wp-env run cli wp rewrite structure '/%postname%/' --hard
npx wp-env run cli wp rewrite flush --hard
```

WordPress is now live at http://localhost:8890 (admin / password).

### Tests

```bash
# Unit tests (no WordPress)
./vendor/bin/phpunit --testsuite=unit

# Integration tests (against running wp-env)
APP_PW=$(npx wp-env run cli wp user application-password create admin "tests" --porcelain | grep -E '^[a-zA-Z0-9]{20,}' | head -1)

MCPSM_APP_PW="$APP_PW" \
MCPSM_URL="http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
MCPSM_USER="admin" \
  ./vendor/bin/phpunit --testsuite=integration
```

Expected: `OK (9 tests, 70 assertions)` for integration; `OK (9 tests, 17 assertions)` for unit.

### Manual probe

```bash
APP_PW="<paste from above>"

# 1. Initialize the MCP session, capture session id
SID=$(curl -sS -i -u admin:$APP_PW \
  -X POST -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  http://localhost:8890/wp-json/mcp/mcp-adapter-default-server \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"probe","version":"1"}}}' \
  2>&1 | grep -i "Mcp-Session-Id:" | awk '{print $2}' | tr -d '\r')

# 2. Send the "initialized" notification (required by MCP)
curl -sS -u admin:$APP_PW \
  -X POST -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -H "Mcp-Session-Id: $SID" \
  http://localhost:8890/wp-json/mcp/mcp-adapter-default-server \
  -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' > /dev/null

# 3. List tools
curl -sS -u admin:$APP_PW \
  -X POST -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -H "Mcp-Session-Id: $SID" \
  http://localhost:8890/wp-json/mcp/mcp-adapter-default-server \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | python3 -m json.tool | head -50

# 4. Call a tool
curl -sS -u admin:$APP_PW \
  -X POST -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -H "Mcp-Session-Id: $SID" \
  http://localhost:8890/wp-json/mcp/mcp-adapter-default-server \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcpsm-themes-active","arguments":{}}}' \
  | python3 -m json.tool
```

## Adding a new ability

Each ability is a single entry in a bundle's `abilities()` map. Example: a hypothetical `posts-trash-empty`:

```php
// In includes/Abilities/Content/PostsBundle.php

public function abilities(): array
{
    return [
        // ... existing abilities ...

        'posts-trash-empty' => [
            'label'               => __('Empty trash', 'mcp-site-manager'),
            'description'         => __('Permanently delete every trashed post.', 'mcp-site-manager'),
            'input_schema'        => S::object([]),
            'permission_callback' => self::require_cap('delete_posts'),
            'execute' => function () {
                $trashed = get_posts(['post_status' => 'trash', 'numberposts' => -1, 'fields' => 'ids']);
                $count = 0;
                foreach ($trashed as $id) {
                    if (wp_delete_post($id, true)) $count++;
                }
                return ['deleted' => $count];
            },
        ],
    ];
}
```

That's it. The bundle base wraps your `execute` in `AbilityRunner` (logging + error handling), registers it with `wp_register_ability()`, and the default-server filter exposes it to MCP clients automatically as `mcpsm-posts-trash-empty`.

## Contributing

Issues and PRs welcome at https://github.com/mrabbani/mcp-site-manager/issues.

Local checks before submitting:

```bash
# Lint
find includes tests -type f -name "*.php" -print0 | xargs -0 -I{} php -l {} | grep -v "No syntax errors" || echo "ALL CLEAN"

# Tests
./vendor/bin/phpunit --testsuite=unit
# ...integration as above
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) (or the GPL-2.0 text at https://www.gnu.org/licenses/gpl-2.0.html).

## Credits

Built by [mrabbani](https://profiles.wordpress.org/mrabbani/) on top of [WordPress's MCP Adapter](https://github.com/WordPress/mcp-adapter) and the [WordPress Abilities API](https://make.wordpress.org/core/?s=abilities+api) shipping in WordPress 6.9.
