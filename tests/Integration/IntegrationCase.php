<?php
declare(strict_types=1);

namespace SiteMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;

abstract class IntegrationCase extends TestCase
{
    protected function call(string $method, array $params = []): array
    {
        $url = getenv('SITE_MCP_URL');
        $auth = base64_encode(getenv('SITE_MCP_USER') . ':' . getenv('SITE_MCP_APP_PW'));
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => $params,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                "Authorization: Basic $auth",
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->assertSame(200, $code, "HTTP $code: $resp");
        $decoded = json_decode((string) $resp, true);
        $this->assertIsArray($decoded, "Bad JSON: $resp");
        return $decoded;
    }

    protected function tool(string $name, array $args = []): array
    {
        return $this->call('tools/call', ['name' => $name, 'arguments' => $args]);
    }
}
