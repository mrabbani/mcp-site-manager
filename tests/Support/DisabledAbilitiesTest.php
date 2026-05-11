<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Support;

use PHPUnit\Framework\TestCase;
use Mrabbani\McpSiteManager\Support\DisabledAbilities;

require_once __DIR__ . '/fixtures/options.php';

final class DisabledAbilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__opts'] = [];
    }

    public function test_default_is_empty_array(): void
    {
        $this->assertSame([], DisabledAbilities::all());
        $this->assertFalse(DisabledAbilities::contains('themes-delete'));
    }

    public function test_set_then_all_round_trips(): void
    {
        DisabledAbilities::set(['themes-delete', 'plugins-delete']);
        $this->assertSame(['themes-delete', 'plugins-delete'], DisabledAbilities::all());
    }

    public function test_set_dedupes_and_stringifies(): void
    {
        DisabledAbilities::set(['themes-delete', 'themes-delete', 123]);
        $this->assertSame(['themes-delete', '123'], DisabledAbilities::all());
    }

    public function test_contains_returns_true_for_listed(): void
    {
        DisabledAbilities::set(['plugins-install']);
        $this->assertTrue(DisabledAbilities::contains('plugins-install'));
        $this->assertFalse(DisabledAbilities::contains('plugins-list'));
    }

    public function test_clear_resets_to_empty(): void
    {
        DisabledAbilities::set(['x', 'y']);
        DisabledAbilities::clear();
        $this->assertSame([], DisabledAbilities::all());
    }

    public function test_all_handles_corrupt_option_gracefully(): void
    {
        $GLOBALS['__opts'][DisabledAbilities::OPTION] = 'not-an-array';
        $this->assertSame([], DisabledAbilities::all());
    }
}
