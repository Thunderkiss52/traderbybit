<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

final class RuntimeHardeningConfigTest extends TestCase
{
    public function test_php_runtime_hardening_directives_are_shipped(): void
    {
        $ini = $this->readRepoFile('docker/php/php.ini');

        $this->assertStringContainsString('expose_php=Off', $ini);
        $this->assertStringContainsString('display_errors=Off', $ini);
        $this->assertStringContainsString('session.upload_progress.enabled=0', $ini);
        $this->assertStringContainsString('session.use_strict_mode=1', $ini);
        $this->assertStringContainsString('session.use_only_cookies=1', $ini);
        $this->assertStringContainsString('session.cookie_httponly=1', $ini);
        $this->assertStringContainsString('session.cookie_samesite=Lax', $ini);
    }

    public function test_transport_limits_exceed_the_application_upload_limit(): void
    {
        $appLimit = (int) config('quote_integrity.evidence.max_upload_size_bytes');
        $this->assertSame(20 * 1024 * 1024, $appLimit);

        $ini = $this->readRepoFile('docker/php/php.ini');
        $this->assertGreaterThan($appLimit, $this->parseIniSize($ini, 'post_max_size'));
        $this->assertGreaterThan($appLimit, $this->parseIniSize($ini, 'upload_max_filesize'));

        $nginx = $this->readRepoFile('docker/nginx/default.conf');
        $this->assertMatchesRegularExpression('/client_max_body_size\s+([0-9]+[kKmMgG]?)\s*;/', $nginx);
        preg_match('/client_max_body_size\s+([0-9]+[kKmMgG]?)\s*;/', $nginx, $matches);
        $this->assertGreaterThan($appLimit, $this->parseSize($matches[1]));
    }

    public function test_nginx_ships_security_headers_for_every_response(): void
    {
        $snippet = $this->readRepoFile('docker/nginx/security-headers.conf');

        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=()',
        ] as $header => $value) {
            $this->assertStringContainsString($header, $snippet);
            $this->assertStringContainsString($value, $snippet);
        }
        $this->assertStringContainsString('always', $snippet);

        $nginx = $this->readRepoFile('docker/nginx/default.conf');
        $this->assertSame(
            4,
            substr_count($nginx, 'include /etc/nginx/snippets/security-headers.conf;'),
            'Security headers must be included in the server block and every location that declares add_header.',
        );

        $dockerfile = $this->readRepoFile('docker/php/Dockerfile');
        $this->assertStringContainsString(
            'COPY docker/nginx/security-headers.conf /etc/nginx/snippets/security-headers.conf',
            $dockerfile,
        );
    }

    private function readRepoFile(string $relativePath): string
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function parseIniSize(string $ini, string $directive): int
    {
        $this->assertMatchesRegularExpression('/^'.preg_quote($directive, '/').'\s*=\s*([0-9]+[kKmMgG]?)\s*$/m', $ini);
        preg_match('/^'.preg_quote($directive, '/').'\s*=\s*([0-9]+[kKmMgG]?)\s*$/m', $ini, $matches);

        return $this->parseSize($matches[1]);
    }

    private function parseSize(string $value): int
    {
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'k' => $number * 1024,
            'm' => $number * 1024 * 1024,
            'g' => $number * 1024 * 1024 * 1024,
            default => $number,
        };
    }
}
