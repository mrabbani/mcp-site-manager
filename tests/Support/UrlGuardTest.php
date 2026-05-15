<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Tests\Support;

use PHPUnit\Framework\TestCase;
use Mrabbani\McpSiteManager\Support\UrlGuard;

require_once __DIR__ . '/fixtures/url-helpers.php';

final class UrlGuardTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('WP_Error')) {
            require __DIR__ . '/fixtures/WP_Error.php';
        }
    }

    public function test_accepts_public_https_url(): void
    {
        // 1.1.1.1 (Cloudflare DNS) is a globally routable IPv4.
        $this->assertTrue(UrlGuard::validate('https://1.1.1.1/path'));
    }

    public function test_accepts_public_http_when_not_https_only(): void
    {
        $this->assertTrue(UrlGuard::validate('http://1.1.1.1/'));
    }

    public function test_rejects_http_when_https_only(): void
    {
        $r = UrlGuard::validate('http://1.1.1.1/zip', ['https_only' => true]);
        $this->assertInstanceOf(\WP_Error::class, $r);
        $this->assertSame('mcpsm_url_blocked', $r->get_error_code());
    }

    public function test_rejects_non_http_scheme(): void
    {
        $r = UrlGuard::validate('ftp://1.1.1.1/');
        $this->assertInstanceOf(\WP_Error::class, $r);
    }

    public function test_rejects_file_scheme(): void
    {
        $r = UrlGuard::validate('file:///etc/passwd');
        $this->assertInstanceOf(\WP_Error::class, $r);
    }

    public function test_rejects_loopback_ipv4(): void
    {
        $r = UrlGuard::validate('http://127.0.0.1/');
        $this->assertInstanceOf(\WP_Error::class, $r);
    }

    public function test_rejects_rfc1918_10_dot(): void
    {
        $r = UrlGuard::validate('http://10.0.0.5/');
        $this->assertInstanceOf(\WP_Error::class, $r);
    }

    public function test_rejects_rfc1918_192_168(): void
    {
        $r = UrlGuard::validate('http://192.168.1.1/');
        $this->assertInstanceOf(\WP_Error::class, $r);
    }

    public function test_rejects_rfc1918_172_16(): void
    {
        $r = UrlGuard::validate('http://172.16.5.5/');
        $this->assertInstanceOf(\WP_Error::class, $r);
    }

    public function test_rejects_link_local(): void
    {
        // 169.254.169.254 is the AWS/GCP metadata endpoint — the canonical
        // SSRF target. Must always be blocked.
        $r = UrlGuard::validate('http://169.254.169.254/latest/meta-data/');
        $this->assertInstanceOf(\WP_Error::class, $r);
    }

    public function test_rejects_loopback_ipv6(): void
    {
        $r = UrlGuard::validate('http://[::1]/');
        $this->assertInstanceOf(\WP_Error::class, $r);
    }

    public function test_rejects_malformed_url(): void
    {
        $r = UrlGuard::validate('not a url');
        $this->assertInstanceOf(\WP_Error::class, $r);
    }

    public function test_rejects_url_without_scheme(): void
    {
        $r = UrlGuard::validate('//example.com/x');
        $this->assertInstanceOf(\WP_Error::class, $r);
    }

    public function test_rejects_off_allowlist_host(): void
    {
        $r = UrlGuard::validate('https://1.1.1.1/x', [
            'allowed_hosts' => ['downloads.wordpress.org', 'github.com'],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $r);
    }

    public function test_allowlist_is_case_insensitive(): void
    {
        // Hostnames are case-insensitive per RFC 4343; matching must reflect that.
        // We use an IP-as-host so DNS resolution doesn't run; allowlist comparison
        // happens before resolution.
        $r = UrlGuard::validate('https://1.1.1.1/x', [
            'allowed_hosts' => ['1.1.1.1'],
        ]);
        $this->assertTrue($r);
    }

    public function test_custom_error_code_is_used(): void
    {
        $r = UrlGuard::validate('http://127.0.0.1/', ['error_code' => 'custom_code']);
        $this->assertInstanceOf(\WP_Error::class, $r);
        $this->assertSame('custom_code', $r->get_error_code());
    }

    public function test_is_public_ip_recognises_public(): void
    {
        $this->assertTrue(UrlGuard::is_public_ip('8.8.8.8'));
        $this->assertTrue(UrlGuard::is_public_ip('1.1.1.1'));
    }

    public function test_is_public_ip_rejects_private(): void
    {
        $this->assertFalse(UrlGuard::is_public_ip('10.0.0.1'));
        $this->assertFalse(UrlGuard::is_public_ip('192.168.0.1'));
        $this->assertFalse(UrlGuard::is_public_ip('127.0.0.1'));
        $this->assertFalse(UrlGuard::is_public_ip('169.254.169.254'));
        $this->assertFalse(UrlGuard::is_public_ip('::1'));
    }
}
