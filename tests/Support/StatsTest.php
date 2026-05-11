<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Support;

use PHPUnit\Framework\TestCase;
use Mrabbani\McpSiteManager\Admin\Stats;

require_once __DIR__ . '/fixtures/wpdb.php';

final class StatsTest extends TestCase
{
    private \FakeWpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new \FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        if (!class_exists('Mrabbani\\McpSiteManager\\Admin\\AbilityLog')) {
            require_once __DIR__ . '/../../includes/Admin/AbilityLog.php';
        }
    }

    private function row(array $overrides = []): array
    {
        static $id = 0;
        $id++;
        return array_merge([
            'id' => $id,
            'ts' => '2026-05-11 12:00:00',
            'user_id' => 1,
            'ability' => 'mcpsm/posts-list',
            'status' => 'ok',
            'error_code' => null,
            'duration_ms' => 50,
        ], $overrides);
    }

    public function test_counts_with_mixed_rows(): void
    {
        $this->wpdb->rows = [
            $this->row(['status' => 'ok']),
            $this->row(['status' => 'ok']),
            $this->row(['status' => 'ok']),
            $this->row(['status' => 'error', 'error_code' => '-32602']),
        ];
        $r = Stats::counts();
        $this->assertSame(4, $r['total']);
        $this->assertSame(3, $r['success']);
        $this->assertSame(1, $r['error']);
        $this->assertSame(0.75, $r['success_rate']);
    }

    public function test_counts_empty(): void
    {
        $this->wpdb->rows = [];
        $r = Stats::counts();
        $this->assertSame(0, $r['total']);
        $this->assertSame(0, $r['success']);
        $this->assertSame(0, $r['error']);
        $this->assertSame(0.0, $r['success_rate']);
    }

    public function test_latency_with_rows(): void
    {
        $this->wpdb->rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $this->wpdb->rows[] = $this->row(['duration_ms' => $i * 10]);
        }
        $r = Stats::latency();
        $this->assertSame(505, $r['avg_ms']);
        $this->assertSame(960, $r['p95_ms']);
    }

    public function test_latency_empty(): void
    {
        $this->wpdb->rows = [];
        $r = Stats::latency();
        $this->assertSame(0, $r['avg_ms']);
        $this->assertSame(0, $r['p95_ms']);
    }
}
