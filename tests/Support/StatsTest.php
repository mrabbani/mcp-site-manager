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

    public function test_top_abilities_orders_by_calls_desc(): void
    {
        $this->wpdb->rows = [];
        for ($i = 0; $i < 5; $i++) $this->wpdb->rows[] = $this->row(['ability' => 'mcpsm/themes-active', 'duration_ms' => 100, 'status' => 'ok']);
        for ($i = 0; $i < 3; $i++) $this->wpdb->rows[] = $this->row(['ability' => 'mcpsm/posts-list',   'duration_ms' => 50,  'status' => 'ok']);
        $this->wpdb->rows[] = $this->row(['ability' => 'mcpsm/posts-list', 'duration_ms' => 50, 'status' => 'error']);
        for ($i = 0; $i < 2; $i++) $this->wpdb->rows[] = $this->row(['ability' => 'mcpsm/health-overview', 'duration_ms' => 10, 'status' => 'ok']);

        $r = Stats::top_abilities(10);

        $this->assertCount(3, $r);
        $this->assertSame('mcpsm/themes-active', $r[0]['ability']);
        $this->assertSame(5, $r[0]['calls']);
        $this->assertSame(1.0, $r[0]['success_rate']);
        $this->assertSame(100, $r[0]['avg_ms']);

        $this->assertSame('mcpsm/posts-list', $r[1]['ability']);
        $this->assertSame(4, $r[1]['calls']);
        $this->assertSame(0.75, $r[1]['success_rate']);
    }

    public function test_top_abilities_respects_limit(): void
    {
        $this->wpdb->rows = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $this->wpdb->rows[] = $this->row(['ability' => "mcpsm/$name"]);
        }
        $this->assertCount(3, Stats::top_abilities(3));
    }

    public function test_recent_errors_filters_and_joins_user(): void
    {
        $this->wpdb->users_table = [1 => 'admin', 2 => 'editor'];
        $this->wpdb->rows = [
            $this->row(['ability' => 'mcpsm/posts-list', 'status' => 'ok']),
            $this->row(['ability' => 'mcpsm/options-update', 'status' => 'error', 'error_code' => '-32001', 'user_id' => 1]),
            $this->row(['ability' => 'mcpsm/posts-create', 'status' => 'error', 'error_code' => '-32602', 'user_id' => 2]),
            $this->row(['ability' => 'mcpsm/users-create', 'status' => 'error', 'error_code' => '-32603', 'user_id' => 999]),
        ];
        $r = Stats::recent_errors(10);

        $this->assertCount(3, $r);
        $this->assertSame('mcpsm/users-create', $r[0]['ability']);
        $this->assertSame('-32603', $r[0]['error_code']);
        $this->assertSame(999, $r[0]['user_id']);
        $this->assertNull($r[0]['user_login']);

        $this->assertSame('mcpsm/options-update', $r[2]['ability']);
        $this->assertSame('admin', $r[2]['user_login']);
    }

    public function test_recent_errors_respects_limit(): void
    {
        $this->wpdb->rows = [];
        for ($i = 0; $i < 25; $i++) {
            $this->wpdb->rows[] = $this->row(['status' => 'error', 'ability' => "mcpsm/x-$i"]);
        }
        $this->assertCount(5, Stats::recent_errors(5));
    }
}
