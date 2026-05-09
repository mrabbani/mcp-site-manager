# site-mcp v1 manual acceptance

Run with Claude Desktop pointed at `https://<site>/wp-json/site-mcp/v1/mcp`
authenticated via Application Password (Basic Auth).

| # | Prompt | Pass criteria |
|---|---|---|
| 1 | "List my latest 5 posts" | Returns 5 posts as a structured list. |
| 2 | "Create a draft post titled 'Hello from MCP' with one paragraph of body" | Post appears in wp-admin as draft. |
| 3 | "Tag that post with the tag 'mcp'" | Tag created if missing; assigned to the post. |
| 4 | "Upload this image https://placekitten.com/600/400 and set it as the post's featured image" | Attachment in Media Library; post's featured image set. |
| 5 | "Switch to the twentytwentyfour theme, then switch back" | Active theme changes twice; site still loads. |
| 6 | "Deactivate the akismet plugin, then reactivate it" | Plugin status flips and restores. |
| 7 | "Show me the last 20 lines of the debug log" | Last lines returned (or correct error if WP_DEBUG_LOG off). |

All seven must complete end-to-end through Claude Desktop talking only to this MCP server.
Record any failures inline below the table with reproduction notes.
