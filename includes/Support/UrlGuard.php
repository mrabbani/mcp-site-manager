<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Support;

defined('ABSPATH') || exit;

/**
 * Validate caller-supplied URLs before the server fetches them.
 *
 * The plugin has three places where an MCP caller can hand us a URL the server
 * will then dereference:
 *   1. media-upload `source_url`  (image/file fetch via download_url)
 *   2. plugins-install `zip_url`  (Plugin_Upgrader::install)
 *   3. themes-install  `zip_url`  (Theme_Upgrader::install)
 *
 * Without a guard, each is a Server-Side Request Forgery surface that lets an
 * authenticated MCP client pivot through the WordPress server into the host's
 * private network (cloud metadata endpoints, internal admin panels, etc.).
 *
 * This guard enforces, in order:
 *   - scheme allowlist (http/https; configurable to https-only)
 *   - optional host allowlist (callable supplies the allowed hosts)
 *   - DNS resolution must succeed
 *   - every resolved IP must be globally routable (no RFC1918, loopback,
 *     link-local, or other reserved ranges)
 *
 * Note on TOCTOU: like any guard that resolves DNS up-front, there's a
 * race window between validation and the actual fetch. WordPress's
 * download_url uses its own resolution. A truly hostile DNS server could
 * answer differently on each lookup. Mitigating that fully would require
 * passing the resolved IP to curl/the HTTP client, which the WP HTTP API
 * does not currently support cleanly. The host allowlist is the strong
 * mitigation; SSRF IP filtering is defense in depth.
 */
final class UrlGuard
{
    /**
     * Validate a URL.
     *
     * @param string               $url       The URL to validate.
     * @param array<string, mixed> $opts      {
     *     @type bool     $https_only    When true, http is rejected. Default false.
     *     @type string[] $allowed_hosts Lowercased hostnames the URL must match.
     *                                   Empty array = no host restriction. Default [].
     *     @type string   $error_code    WP_Error code to return on failure. Default 'mcpsm_url_blocked'.
     * }
     * @return true|\WP_Error
     */
    public static function validate(string $url, array $opts = [])
    {
        $https_only    = (bool) ($opts['https_only'] ?? false);
        $allowed_hosts = (array) ($opts['allowed_hosts'] ?? []);
        $code          = (string) ($opts['error_code'] ?? 'mcpsm_url_blocked');

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return new \WP_Error($code, 'URL must be an absolute http(s) URL.', ['status' => 400]);
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($https_only) {
            if ($scheme !== 'https') {
                return new \WP_Error($code, 'Only https URLs are allowed.', ['status' => 400]);
            }
        } elseif ($scheme !== 'http' && $scheme !== 'https') {
            return new \WP_Error($code, 'Only http and https URLs are allowed.', ['status' => 400]);
        }

        $host = strtolower((string) $parts['host']);
        if (!empty($allowed_hosts)) {
            $allowed_lc = array_map('strtolower', $allowed_hosts);
            if (!in_array($host, $allowed_lc, true)) {
                return new \WP_Error(
                    $code,
                    sprintf('Host "%s" is not on the configured allowlist.', $host),
                    ['status' => 400, 'allowed_hosts' => $allowed_lc]
                );
            }
        }

        $ips = self::resolve($host);
        if (empty($ips)) {
            return new \WP_Error($code, 'Could not resolve URL host.', ['status' => 400]);
        }
        foreach ($ips as $ip) {
            if (!self::is_public_ip($ip)) {
                return new \WP_Error(
                    $code,
                    'URL resolves to a private, loopback, or link-local address.',
                    ['status' => 400]
                );
            }
        }

        return true;
    }

    /**
     * @return string[] Resolved IPs (v4 + v6) or empty array if unresolved.
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['ip']))   $ips[] = $r['ip'];
                if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
        }
        if (empty($ips)) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) $ips = $resolved;
        }
        return $ips;
    }

    public static function is_public_ip(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
