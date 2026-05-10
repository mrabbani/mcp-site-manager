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
