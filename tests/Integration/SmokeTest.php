<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Integration;

final class SmokeTest extends IntegrationCase
{
    public function test_tools_list_contains_all_bundles(): void
    {
        $r = $this->call('tools/list');
        $names = array_map(fn($t) => $t['name'], $r['result']['tools'] ?? []);
        $this->assertGreaterThanOrEqual(70, count($names));
        foreach ([
            'site-mcp-posts-list', 'site-mcp-pages-create', 'site-mcp-cpt-list-types',
            'site-mcp-terms-list', 'site-mcp-media-upload', 'site-mcp-comments-moderate',
            'site-mcp-users-me', 'site-mcp-plugins-list', 'site-mcp-themes-list',
            'site-mcp-options-list', 'site-mcp-menus-list', 'site-mcp-health-overview',
            'site-mcp-cache-flush-rewrite',
        ] as $expected) {
            $this->assertContains($expected, $names, "missing tool: $expected");
        }
    }

    public function test_post_round_trip(): void
    {
        $created = $this->tool('site-mcp-posts-create', ['title' => 'mcp-smoke', 'status' => 'draft']);
        $id = $created['result']['structuredContent']['id'] ?? $created['result']['content'][0]['id'] ?? null;
        $this->assertNotNull($id, json_encode($created));
        $got = $this->tool('site-mcp-posts-get', ['id' => $id]);
        // Title in view-context REST is a {rendered} object; in edit context it has {raw,rendered}.
        $title = $got['result']['structuredContent']['title'] ?? null;
        $rendered = is_array($title) ? ($title['rendered'] ?? $title['raw'] ?? null) : $title;
        $this->assertStringContainsStringIgnoringCase('mcp-smoke', (string) $rendered, json_encode($got));
        $this->tool('site-mcp-posts-delete', ['id' => $id, 'force' => true]);
    }

    public function test_term_round_trip(): void
    {
        $c = $this->tool('site-mcp-terms-create', ['taxonomy' => 'category', 'name' => 'mcp-smoke-cat']);
        $id = $c['result']['structuredContent']['id'] ?? null;
        $this->assertNotNull($id);
        $this->tool('site-mcp-terms-delete', ['taxonomy' => 'category', 'id' => $id]);
    }

    public function test_options_blogname_roundtrip(): void
    {
        $orig = $this->tool('site-mcp-options-get', ['key' => 'blogname']);
        $original = $orig['result']['structuredContent']['value'] ?? null;
        $this->assertNotNull($original);

        $this->tool('site-mcp-options-update', ['key' => 'blogname', 'value' => 'mcp-test-name']);
        $check = $this->tool('site-mcp-options-get', ['key' => 'blogname']);
        $this->assertSame('mcp-test-name', $check['result']['structuredContent']['value']);

        $this->tool('site-mcp-options-update', ['key' => 'blogname', 'value' => $original]);
    }

    public function test_options_denylist_blocks_active_plugins(): void
    {
        $r = $this->tool('site-mcp-options-update', ['key' => 'active_plugins', 'value' => []]);
        // MCP delivers tool failures as result.isError=true with an error message in content.
        $this->assertTrue($r['result']['isError'] ?? false, json_encode($r));
        $text = $r['result']['content'][0]['text'] ?? '';
        $this->assertStringContainsString('allowlist', $text);
    }

    public function test_plugin_activate_deactivate_hello(): void
    {
        $this->tool('site-mcp-plugins-activate',   ['plugin' => 'hello.php']);
        $list = $this->tool('site-mcp-plugins-list');
        $found = false;
        foreach ($list['result']['structuredContent']['items'] as $item) {
            if ($item['plugin'] === 'hello.php') { $found = $item['active']; break; }
        }
        $this->assertTrue($found);
        $this->tool('site-mcp-plugins-deactivate', ['plugin' => 'hello.php']);
    }

    public function test_theme_switch_roundtrip(): void
    {
        $list = $this->tool('site-mcp-themes-list');
        $items = $list['result']['structuredContent']['items'];
        $current = null; $candidate = null;
        foreach ($items as $i) {
            if ($i['active']) $current = $i['stylesheet'];
            if (!$i['active'] && $candidate === null) $candidate = $i['stylesheet'];
        }
        if ($candidate === null) $this->markTestSkipped('Need at least 2 themes installed');
        $this->tool('site-mcp-themes-switch', ['stylesheet' => $candidate]);
        $this->tool('site-mcp-themes-switch', ['stylesheet' => $current]);
    }

    public function test_health_overview(): void
    {
        $r = $this->tool('site-mcp-health-overview');
        $sc = $r['result']['structuredContent'];
        foreach (['wp_version','php_version','active_theme','plugin_total','plugin_active'] as $k) {
            $this->assertArrayHasKey($k, $sc);
        }
    }

    public function test_cache_flush_rewrite_ok(): void
    {
        $r = $this->tool('site-mcp-cache-flush-rewrite');
        $this->assertTrue($r['result']['structuredContent']['flushed']);
    }
}
