=== MCP Site Manager ===
Contributors: mrabbani
Tags: mcp, ai, automation, content-management, llm
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Control your WordPress site from Claude, ChatGPT, Cursor, and any other Model Context Protocol (MCP) client.

== Description ==

**MCP Site Manager** turns your WordPress site into a tool any MCP-compatible AI client can drive. It registers ~74 capabilities — covering posts, pages, taxonomies, media, comments, users, plugins, themes, options, navigation menus, site health and maintenance — as WordPress Abilities, which the companion [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) library then exposes over MCP transports.

The MCP Adapter is already bundled with WooCommerce 10.3+; on other sites the plugin offers a one-click *Install MCP Adapter* button on activation. Connect Claude Desktop, Claude Code, Cursor, VS Code, ChatGPT, or any other MCP client via the HTTP transport (Application Password) or STDIO transport (WP-CLI, no password). No third-party services. No data leaves your site except in direct response to a tool call from your authenticated client.

New to the WordPress MCP Adapter? Read the official primer on the WordPress Developer Blog: https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/ — it explains the Abilities API and the adapter's transports.

Need to wire up a specific AI client? Jump straight to the upstream walkthrough: https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/#connecting-ai-applications (Claude Desktop, Claude Code, Cursor, VS Code).

= What you can do =

* **Content** — read, create, update, and delete posts, pages, and any public custom post type. Manage revisions, statuses, slugs, and excerpts.
* **Taxonomies** — manage tags, categories, and custom taxonomies; assign terms to posts.
* **Media** — upload from a public URL or base64, set alt text, captions, and featured images.
* **Comments** — list, moderate, reply, spam, trash.
* **Users** — list, get, create, update, delete (capability-gated).
* **Plugins & Themes** — search the .org directories, install, activate, update, delete.
* **Options** — read and update a curated, filterable allowlist of safe site options (siteurl, blog title, timezone, etc.). Plugin-internal `mcpsm_*` keys are always denied.
* **Navigation Menus** — create menus, add/remove items, set locations.
* **Diagnostics** — site health overview, recent debug log tail, PHP/WP environment summary.
* **Maintenance** — flush caches, list and trigger WP-cron events.

= Security model =

* Every ability runs with the **calling user's WordPress capabilities**. A Subscriber can only do what a Subscriber can do.
* Authentication uses **Application Passwords**. Revoke at any time from your user profile.
* `media-upload` URLs are validated to block server-side request forgery (private, loopback, and link-local addresses are rejected before download).
* Site options exposed via MCP are restricted to a curated allowlist; both the allowlist and a denylist of protected prefixes are filterable.
* Every ability call is logged to a local table (toggleable) with user, ability, status, error code, and duration.
* Plugin uninstall removes the log table and plugin-owned options.

= Privacy =

This plugin makes **no outbound network calls** of its own. The only network traffic it triggers is:

1. Downloading media you ask it to upload (`media-upload` with a `source_url`).
2. Fetching plugin/theme packages from WordPress.org when you ask it to install or update one.
3. Whatever your active WordPress core, themes, or other plugins do — unchanged.

It does not phone home, collect analytics, or send any data about your site, users, or content to any external service.

== Installation ==

1. Install and activate **MCP Site Manager**.
2. If prompted, click **Install MCP Adapter** in the admin notice — one click downloads the latest release and activates it. Skipped on sites that already ship the library (e.g. WooCommerce 10.3+).
3. Go to **Settings → MCP Site Manager** for the connection URL and a ready-to-paste client config snippet.
4. For HTTP transport: generate a new **Application Password** in your WordPress user profile and paste it into your AI client's MCP server configuration. For STDIO transport (local dev with WP-CLI): no password needed.
5. Restart the client. The MCP tools (`mcpsm-posts-list`, `mcpsm-media-upload`, etc.) will appear.

== Frequently Asked Questions ==

= Does this work without the standalone MCP Adapter plugin? =

Yes — as long as the MCP Adapter *library* is reachable. WooCommerce 10.3+ vendors it via Composer, so WooCommerce sites need no extra install. On other sites, the admin notice installs the standalone plugin for you in one click.

= Which AI clients are supported? =

Anything that speaks MCP: Claude Desktop, Claude Code, Cursor, VS Code (note: VS Code uses `servers`, not `mcpServers`), ChatGPT, plus any custom client over HTTP or STDIO. See the GitHub README for ready-to-paste snippets.

= What permissions does the AI client get? =

Exactly the permissions of the WordPress user whose Application Password is used. Capability checks run on every tool call. A Subscriber-level Application Password cannot edit posts, install plugins, or change options — no matter what the AI asks for. For production, create a dedicated WP user with the minimum capabilities needed for the abilities you expose.

= Is my content sent anywhere? =

Only to the MCP client you connect — that is, only in direct response to tool calls your AI client makes against your site. The plugin makes no outbound calls except those listed in the Privacy section above.

= Can I disable individual abilities? =

Yes. The **MCP Site Manager** admin screen has per-ability toggles. Disabled abilities are not exposed to MCP clients.

= Can I extend the options allowlist? =

Yes — use the `mcpsm_options_allowlist` filter to add safe option keys, and `mcpsm_options_denylist_prefixes` to protect additional key prefixes from being written.

= Does it work on multisite? =

The plugin activates per-site. Uninstall cleans up data on every site in the network.

== Screenshots ==

1. Dashboard — at-a-glance status of the MCP Adapter dependency, ability counts, and recent activity.
2. Connection — copy-paste connection URL and ready-to-use client config snippets for Claude, Cursor, and other MCP clients.
3. Abilities — per-ability toggles with bulk enable/disable for fine-grained control over which tools are exposed.
4. Activity log — every MCP tool call recorded with user, status, duration, and error code; filterable and searchable.
5. Settings — toggle activity logging, configure log retention, and adjust other plugin-wide options.

== Changelog ==

= 0.1.0 =
* Initial release. ~74 abilities covering content, taxonomies, media, comments, users, plugins, themes, options, navigation menus, diagnostics, and maintenance.
* Guided install: admin notice with one-click *Install MCP Adapter* button when the dependency is missing.
* Self-bootstrap: the MCP Adapter library is initialized automatically when reachable (e.g. WooCommerce sites), removing the need for a separate plugin install.
* SSRF protection on media-upload source URLs.
* Per-ability disable toggles.
* Activity log with server-side pagination, search, and filtering.
* Options allowlist with filterable denylist prefixes.
* Filter: `mcpsm_adapter_download_url` for pinning a specific MCP Adapter release.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
