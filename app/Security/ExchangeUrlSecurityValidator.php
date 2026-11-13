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
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return ['safe' => false, 'reason' => 'Embedded credentials and fragments are prohibited', 'ip' => null];
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
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            $ips = [];
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (isset($r['ip'])) {
                        $ips[] = $r['ip'];
                    } elseif (isset($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
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
        // PHP does not consider all special-purpose blocks reserved (notably
        // 100.64/10, benchmarking and documentation ranges). Deny those below.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['prohibited' => true, 'category' => 'private or reserved address'];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $number = ip2long($ip);
            foreach ([
                ['100.64.0.0', 10],
                ['192.0.0.0', 24],
                ['192.0.2.0', 24],
                ['198.18.0.0', 15],
                ['198.51.100.0', 24],
                ['203.0.113.0', 24],
            ] as [$network, $prefix]) {
                $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
                if (($number & $mask) === (ip2long($network) & $mask)) {
                    return ['prohibited' => true, 'category' => 'special-purpose IPv4'];
                }
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bytes = inet_pton($ip);
            // Documentation IPv6 prefix 2001:db8::/32 cannot be an exchange endpoint.
            if (substr($bytes, 0, 4) === hex2bin('20010db8')) {
                return ['prohibited' => true, 'category' => 'documentation IPv6'];
            }
        }
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
