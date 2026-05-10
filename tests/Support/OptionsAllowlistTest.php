<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Support;

use PHPUnit\Framework\TestCase;
use Mrabbani\McpSiteManager\Support\OptionsAllowlist;

final class OptionsAllowlistTest extends TestCase
{
    public function test_allowlist_contains_blogname(): void
    {
        $this->assertTrue(OptionsAllowlist::contains('blogname'));
    }

    public function test_allowlist_excludes_active_plugins(): void
    {
        $this->assertFalse(OptionsAllowlist::contains('active_plugins'));
    }

    public function test_keys_returns_all_allowed(): void
    {
        $keys = OptionsAllowlist::keys();
        $this->assertContains('blogname', $keys);
        $this->assertContains('blogdescription', $keys);
        $this->assertNotContains('siteurl', $keys);
    }
}
