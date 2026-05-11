<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class RestStatsTest extends TestCase
{
    private function call_rest(string $path, ?string $auth_user = null, ?string $auth_pw = null): array
    {
        $url = rtrim((string) getenv('MCPSM_BASE_URL'), '/') . $path;
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($auth_user !== null) {
            $headers[] = 'Authorization: Basic ' . base64_encode("$auth_user:$auth_pw");
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $body, 'json' => json_decode((string) $body, true)];
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $r = $this->call_rest('/wp-json/mcp-site-manager/v1/stats/all');
        $this->assertSame(401, $r['code'], 'Body: ' . substr((string) $r['body'], 0, 300));
    }

    public function test_authenticated_all_returns_combined_payload(): void
    {
        $r = $this->call_rest(
            '/wp-json/mcp-site-manager/v1/stats/all',
            (string) getenv('MCPSM_USER'),
            (string) getenv('MCPSM_APP_PW')
        );
        $this->assertSame(200, $r['code'], 'Body: ' . substr((string) $r['body'], 0, 300));
        $this->assertIsArray($r['json']);
        foreach (['counts', 'latency', 'top_abilities', 'recent_errors', 'window'] as $k) {
            $this->assertArrayHasKey($k, $r['json'], "missing key: $k");
        }
        // counts shape
        foreach (['total', 'success', 'error', 'success_rate'] as $k) {
            $this->assertArrayHasKey($k, $r['json']['counts']);
        }
        // latency shape
        $this->assertArrayHasKey('avg_ms', $r['json']['latency']);
        $this->assertArrayHasKey('p95_ms', $r['json']['latency']);
        // window shape
        $this->assertArrayHasKey('count', $r['json']['window']);
    }

    public function test_top_abilities_respects_limit_query_param(): void
    {
        $r = $this->call_rest(
            '/wp-json/mcp-site-manager/v1/stats/top-abilities?limit=3',
            (string) getenv('MCPSM_USER'),
            (string) getenv('MCPSM_APP_PW')
        );
        $this->assertSame(200, $r['code']);
        $this->assertIsArray($r['json']);
        $this->assertLessThanOrEqual(3, count($r['json']));
    }
}
