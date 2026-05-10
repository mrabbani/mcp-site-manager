<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Support;

use PHPUnit\Framework\TestCase;
use Mrabbani\McpSiteManager\Support\ErrorMapper;

final class ErrorMapperTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('WP_Error')) {
            require __DIR__ . '/fixtures/WP_Error.php';
        }
    }

    public function test_maps_4xx_wp_error_to_invalid_params(): void
    {
        $err = new \WP_Error('rest_invalid', 'Bad input', ['status' => 400, 'field' => 'title']);
        $env = ErrorMapper::toMcp($err);
        $this->assertSame(-32602, $env['code']);
        $this->assertSame('Bad input', $env['message']);
        $this->assertSame(400, $env['data']['http_status']);
    }

    public function test_maps_403_to_forbidden(): void
    {
        $err = new \WP_Error('rest_forbidden', 'No', ['status' => 403, 'required_capability' => 'edit_posts']);
        $env = ErrorMapper::toMcp($err);
        $this->assertSame(-32001, $env['code']);
        $this->assertSame('edit_posts', $env['data']['required_capability']);
    }

    public function test_maps_500_to_internal(): void
    {
        $err = new \WP_Error('boom', 'kaboom', ['status' => 500]);
        $env = ErrorMapper::toMcp($err);
        $this->assertSame(-32603, $env['code']);
    }

    public function test_maps_throwable_to_internal(): void
    {
        $env = ErrorMapper::toMcp(new \RuntimeException('x'));
        $this->assertSame(-32603, $env['code']);
    }
}
