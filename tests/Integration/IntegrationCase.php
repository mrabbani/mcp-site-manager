<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Integration;

use PHPUnit\Framework\TestCase;

abstract class IntegrationCase extends TestCase
{
    private static ?string $session_id = null;
    private static int $next_id = 1;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$session_id === null) {
            $this->initialize_session();
        }
    }

    private function initialize_session(): void
    {
        [$resp, $headers] = $this->raw_request('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => new \stdClass(),
            'clientInfo'      => ['name' => 'mcpsm-tests', 'version' => '1.0'],
        ], null);

        self::$session_id = $headers['mcp-session-id'] ?? null;
        $this->assertNotNull(self::$session_id, 'No Mcp-Session-Id returned: ' . json_encode($resp));

        // Send initialized notification (no id, no response expected).
        $this->raw_request_notification('notifications/initialized', new \stdClass());
    }

    /**
     * @return array{0: array, 1: array<string, string>}  [decoded body, lower-cased headers]
     */
    private function raw_request(string $method, $params, ?string $session_id): array
    {
        $url = getenv('MCPSM_URL');
        $auth = base64_encode(getenv('MCPSM_USER') . ':' . getenv('MCPSM_APP_PW'));
        $payload = [
            'jsonrpc' => '2.0',
            'id'      => self::$next_id++,
            'method'  => $method,
        ];
        if ($params !== null) $payload['params'] = $params;

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            "Authorization: Basic $auth",
        ];
        if ($session_id !== null) $headers[] = "Mcp-Session-Id: $session_id";

        $resp_headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$resp_headers) {
                if (strpos($h, ':') !== false) {
                    [$k, $v] = explode(':', $h, 2);
                    $resp_headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($h);
            },
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->assertSame(200, $code, "HTTP $code from $method: $body");
        $decoded = json_decode((string) $body, true);
        $this->assertIsArray($decoded, "Bad JSON from $method: $body");
        return [$decoded, $resp_headers];
    }

    private function raw_request_notification(string $method, $params): void
    {
        $url = getenv('MCPSM_URL');
        $auth = base64_encode(getenv('MCPSM_USER') . ':' . getenv('MCPSM_APP_PW'));
        $payload = ['jsonrpc' => '2.0', 'method' => $method];
        if ($params !== null) $payload['params'] = $params;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json, text/event-stream',
                "Authorization: Basic $auth",
                'Mcp-Session-Id: ' . self::$session_id,
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    protected function call(string $method, array $params = []): array
    {
        [$decoded, ] = $this->raw_request($method, $params ?: new \stdClass(), self::$session_id);
        return $decoded;
    }

    protected function tool(string $name, array $args = []): array
    {
        return $this->call('tools/call', ['name' => $name, 'arguments' => $args ?: new \stdClass()]);
    }
}
