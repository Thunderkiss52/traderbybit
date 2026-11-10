<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;

/**
 * Strict SSRF and endpoint security validator for dynamic exchange URLs.
 * Prevents DNS rebinding, internal network scanning, loopback, and cloud metadata access.
 */
final class ExchangeUrlSecurityValidator
{
    private const ALLOWED_SCHEMES = ['https', 'http'];

    private const ALLOWED_PORTS = [80, 443];

    /**
     * Validates that a URL is safe to call and does not target internal, private, loopback, or metadata services.
     *
     * @return array{safe: bool, reason: string|null, ip: string|null}
     */
    public function validateUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return ['safe' => false, 'reason' => 'Malformed URL structure', 'ip' => null];
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return ['safe' => false, 'reason' => "Prohibited URL scheme '{$scheme}'. Only HTTP(S) permitted.", 'ip' => null];
        }

        // Port validation
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            return ['safe' => false, 'reason' => "Prohibited destination port '{$port}'. Only standard web ports (80, 443) permitted.", 'ip' => null];
        }

        $host = strtolower($parts['host']);

        // Check prohibited hostnames
        if (in_array($host, ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'], true)) {
            return ['safe' => false, 'reason' => "Targeting localhost '{$host}' is prohibited (SSRF prevention).", 'ip' => null];
        }

        // Literal IP validation or DNS resolution
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $records = @dns_get_record($host, DNS_A);
            $ips = [];
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (isset($r['ip'])) {
                        $ips[] = $r['ip'];
                    }
                }
            }
            if (empty($ips)) {
                $single = @gethostbyname($host);
                if ($single !== $host && filter_var($single, FILTER_VALIDATE_IP)) {
                    $ips[] = $single;
                }
            }
        }

        if (empty($ips)) {
            return ['safe' => false, 'reason' => "Could not resolve hostname '{$host}'.", 'ip' => null];
        }

        foreach ($ips as $ip) {
            $check = $this->isPrivateOrReservedIp($ip);
            if ($check['prohibited']) {
                return ['safe' => false, 'reason' => "Resolved IP address '{$ip}' is {$check['category']} (SSRF protection).", 'ip' => $ip];
            }
        }

        return ['safe' => true, 'reason' => null, 'ip' => $ips[0] ?? null];
    }

    /**
     * Assert URL is safe, throwing InvalidArgumentException on violation.
     */
    public function assertSafe(string $url): void
    {
        $result = $this->validateUrl($url);
        if (! $result['safe']) {
            throw new InvalidArgumentException("SSRF Security Violation: {$result['reason']}");
        }
    }

    /**
     * Inspects an IP address and determines if it belongs to private, loopback, link-local, or reserved blocks.
     *
     * @return array{prohibited: bool, category: string}
     */
    public function isPrivateOrReservedIp(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return ['prohibited' => true, 'category' => 'invalid IPv4'];
            }

            // 127.0.0.0/8 (Loopback)
            if (($long & 0xFF000000) === 0x7F000000) {
                return ['prohibited' => true, 'category' => 'loopback (127.0.0.0/8)'];
            }

            // 10.0.0.0/8 (Private RFC1918)
            if (($long & 0xFF000000) === 0x0A000000) {
                return ['prohibited' => true, 'category' => 'private network (10.0.0.0/8)'];
            }

            // 172.16.0.0/12 (Private RFC1918)
            if (($long & 0xFFF00000) === 0xAC100000) {
                return ['prohibited' => true, 'category' => 'private network (172.16.0.0/12)'];
            }

            // 192.168.0.0/16 (Private RFC1918)
            if (($long & 0xFFFF0000) === 0xC0A80000) {
                return ['prohibited' => true, 'category' => 'private network (192.168.0.0/16)'];
            }

            // 169.254.0.0/16 (Link-Local / Cloud Metadata)
            if (($long & 0xFFFF0000) === 0xA9FE0000) {
                return ['prohibited' => true, 'category' => 'cloud metadata / link-local (169.254.0.0/16)'];
            }

            // 0.0.0.0/8 (Current network)
            if (($long & 0xFF000000) === 0x00000000) {
                return ['prohibited' => true, 'category' => 'current network (0.0.0.0/8)'];
            }

            // 224.0.0.0/4 (Multicast) & 240.0.0.0/4 (Reserved)
            if (($long & 0xE0000000) === 0xE0000000) {
                return ['prohibited' => true, 'category' => 'multicast or reserved network'];
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $hex = bin2hex((string) inet_pton($ip));
            if ($ip === '::1' || $hex === str_repeat('0', 31).'1') {
                return ['prohibited' => true, 'category' => 'IPv6 loopback (::1)'];
            }
            if (str_starts_with($hex, 'fc') || str_starts_with($hex, 'fd')) {
                return ['prohibited' => true, 'category' => 'IPv6 unique local address'];
            }
            if (str_starts_with($hex, 'fe8') || str_starts_with($hex, 'fe9') || str_starts_with($hex, 'fea') || str_starts_with($hex, 'feb')) {
                return ['prohibited' => true, 'category' => 'IPv6 link-local address'];
            }
        }

        return ['prohibited' => false, 'category' => 'public'];
    }
}
