<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Support;

use PHPUnit\Framework\TestCase;
use Mrabbani\McpSiteManager\Support\SchemaBuilder;

final class SchemaBuilderTest extends TestCase
{
    public function test_id_param(): void
    {
        $s = SchemaBuilder::object(['id' => SchemaBuilder::int('Object ID', true)]);
        $this->assertSame('object', $s['type']);
        $this->assertContains('id', $s['required']);
        $this->assertSame('integer', $s['properties']['id']['type']);
    }

    public function test_paging_params_present(): void
    {
        $s = SchemaBuilder::paging();
        $this->assertArrayHasKey('per_page', $s);
        $this->assertArrayHasKey('page', $s);
    }
}
