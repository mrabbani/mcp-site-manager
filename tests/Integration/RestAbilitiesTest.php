<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class RestAbilitiesTest extends TestCase
{
    private function call(string $method, string $path, ?array $body = null, bool $auth = true): array
    {
        $url = rtrim((string) getenv('MCPSM_BASE_URL'), '/') . $path;
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($auth) $headers[] = 'Authorization: Basic ' . base64_encode(getenv('MCPSM_USER') . ':' . getenv('MCPSM_APP_PW'));
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'json' => json_decode((string) $resp, true)];
    }

    protected function tearDown(): void
    {
        // Always restore to all-enabled at the end of each test.
        $this->call('DELETE', '/wp-json/mcp-site-manager/v1/abilities/disabled');
    }

    public function test_unauthenticated_get_returns_401(): void
    {
        $r = $this->call('GET', '/wp-json/mcp-site-manager/v1/abilities', null, false);
        $this->assertSame(401, $r['code']);
    }

    public function test_get_list_returns_inventory(): void
    {
        $r = $this->call('GET', '/wp-json/mcp-site-manager/v1/abilities');
        $this->assertSame(200, $r['code']);
        $this->assertGreaterThanOrEqual(70, $r['json']['total']);
        $this->assertSame(0, $r['json']['disabled_count']);
        $this->assertArrayHasKey('items', $r['json']);
        $first = $r['json']['items'][0];
        foreach (['id','name','tool_name','label','description','bundle','enabled'] as $k) {
            $this->assertArrayHasKey($k, $first);
        }
    }

    public function test_put_disable_then_enable_round_trips(): void
    {
        $r = $this->call('PUT', '/wp-json/mcp-site-manager/v1/abilities/themes-delete/enabled', ['enabled' => false]);
        $this->assertSame(200, $r['code']);
        $this->assertSame(1, $r['json']['disabled_count']);
        $themes_delete = current(array_filter($r['json']['items'], fn($i) => $i['id'] === 'themes-delete'));
        $this->assertFalse($themes_delete['enabled']);

        $r = $this->call('PUT', '/wp-json/mcp-site-manager/v1/abilities/themes-delete/enabled', ['enabled' => true]);
        $this->assertSame(200, $r['code']);
        $this->assertSame(0, $r['json']['disabled_count']);
    }

    public function test_put_unknown_ability_returns_404(): void
    {
        $r = $this->call('PUT', '/wp-json/mcp-site-manager/v1/abilities/does-not-exist/enabled', ['enabled' => false]);
        $this->assertSame(404, $r['code']);
    }

    public function test_delete_disabled_clears_all(): void
    {
        $this->call('PUT', '/wp-json/mcp-site-manager/v1/abilities/themes-delete/enabled', ['enabled' => false]);
        $this->call('PUT', '/wp-json/mcp-site-manager/v1/abilities/plugins-delete/enabled', ['enabled' => false]);
        $r = $this->call('DELETE', '/wp-json/mcp-site-manager/v1/abilities/disabled');
        $this->assertSame(200, $r['code']);
        $this->assertSame(0, $r['json']['disabled_count']);
    }
}
