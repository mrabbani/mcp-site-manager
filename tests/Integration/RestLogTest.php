<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class RestLogTest extends TestCase
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

    public function test_unauthenticated_get_returns_401(): void
    {
        $r = $this->call('GET', '/wp-json/mcp-site-manager/v1/log', null, false);
        $this->assertSame(401, $r['code']);
    }

    public function test_get_list_returns_paginated_shape(): void
    {
        $r = $this->call('GET', '/wp-json/mcp-site-manager/v1/log?per_page=5');
        $this->assertSame(200, $r['code']);
        $this->assertArrayHasKey('items', $r['json']);
        $this->assertArrayHasKey('total', $r['json']);
        $this->assertSame(1, $r['json']['page']);
        $this->assertSame(5, $r['json']['per_page']);
        $this->assertIsArray($r['json']['items']);
        if (!empty($r['json']['items'])) {
            $first = $r['json']['items'][0];
            foreach (['id','ts','user_id','user_login','ability','status','error_code','duration_ms'] as $k) {
                $this->assertArrayHasKey($k, $first, "missing key: $k");
            }
        }
    }
}
