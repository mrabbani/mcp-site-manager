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

It plugs into the **MCP Adapter** library — already bundled with WooCommerce 10.3+, or installable in one click from the admin notice on activation — and connects to any MCP-compatible AI client (Claude Desktop, Claude Code, Cursor, VS Code, ChatGPT) via the HTTP transport (Application Password) or STDIO transport (WP-CLI, no password).

New to the WordPress MCP Adapter? Read the official primer on the WordPress Developer Blog: https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/ — it explains the Abilities API and the adapter's transports.

Need to wire up a specific AI client? Jump straight to the upstream walkthrough: https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/#connecting-ai-applications (Claude Desktop, Claude Code, Cursor, VS Code).

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

1. Install and activate **MCP Site Manager**.
2. If prompted, click **Install MCP Adapter** in the admin notice — one click downloads the latest release and activates it. Skipped on sites that already ship the library (e.g. WooCommerce 10.3+).
3. Go to **Settings → MCP Site Manager** for the connection URL and ready-to-paste client snippets.
4. For HTTP transport: generate an Application Password in your user profile. For STDIO transport (local dev with WP-CLI): no password needed.

== Frequently Asked Questions ==

= Does this work without the standalone MCP Adapter plugin? =

Yes — as long as the MCP Adapter *library* is reachable. WooCommerce 10.3+ vendors it via Composer, so WooCommerce sites need no extra install. On other sites, the admin notice installs the standalone plugin for you in one click.

= Which AI clients are supported? =

Anything that speaks MCP: Claude Desktop, Claude Code, Cursor, VS Code (note: VS Code uses `servers`, not `mcpServers`), ChatGPT, plus any custom client over HTTP or STDIO. See the GitHub README for ready-to-paste snippets.

= What permissions does it need? =

Each ability runs with the calling user's WordPress capabilities. A subscriber can only do what a subscriber can do. For production, create a dedicated WP user with the minimum capabilities needed for the abilities you expose.

== Changelog ==

= 0.1.0 =
* Initial release.
* Guided install: admin notice with one-click *Install MCP Adapter* button when the dependency is missing.
* Self-bootstrap: the MCP Adapter is initialized automatically when the library is reachable (e.g. WooCommerce sites), removing the need for a separate plugin install.
* Docs: client configs for Claude Desktop, Claude Code, Cursor and VS Code; STDIO transport guidance for local development.
* Filter: `mcpsm_adapter_download_url` for pinning a specific MCP Adapter release.
