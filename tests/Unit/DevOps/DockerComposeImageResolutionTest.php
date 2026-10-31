<?php

declare(strict_types=1);

namespace Tests\Unit\DevOps;

use PHPUnit\Framework\TestCase;

final class DockerComposeImageResolutionTest extends TestCase
{
    private string $composePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->composePath = dirname(__DIR__, 3).'/compose.yaml';
    }

    public function test_compose_yaml_enforces_required_image_environment_variables(): void
    {
        self::assertFileExists($this->composePath);
        $content = (string) file_get_contents($this->composePath);

        self::assertStringContainsString(
            '${APP_IMAGE:?APP_IMAGE is required}:${IMAGE_TAG:?IMAGE_TAG is required}',
            $content,
            'compose.yaml must enforce required APP_IMAGE and IMAGE_TAG'
        );

        self::assertStringContainsString(
            '${WEB_IMAGE:?WEB_IMAGE is required}:${IMAGE_TAG:?IMAGE_TAG is required}',
            $content,
            'compose.yaml must enforce required WEB_IMAGE and IMAGE_TAG'
        );

        self::assertStringContainsString(
            '${RESEARCH_WORKER_IMAGE:?RESEARCH_WORKER_IMAGE is required}:${IMAGE_TAG:?IMAGE_TAG is required}',
            $content,
            'compose.yaml must enforce required RESEARCH_WORKER_IMAGE and IMAGE_TAG'
        );
    }

    public function test_compose_yaml_does_not_contain_fallback_local_image_names(): void
    {
        self::assertFileExists($this->composePath);
        $content = (string) file_get_contents($this->composePath);

        self::assertStringNotContainsString(
            ':-bybit-signals-app',
            $content,
            'compose.yaml must never fall back to unqualified bybit-signals-app'
        );

        self::assertStringNotContainsString(
            ':-bybit-signals-web',
            $content,
            'compose.yaml must never fall back to unqualified bybit-signals-web'
        );

        self::assertStringNotContainsString(
            ':-bybit-signals-research-worker',
            $content,
            'compose.yaml must never fall back to unqualified bybit-signals-research-worker'
        );
    }

    public function test_docker_compose_config_resolves_all_services_with_sha_tagged_ghcr_images(): void
    {
        $composeCheck = exec('which docker 2>/dev/null');
        if (empty($composeCheck)) {
            self::markTestSkipped('Docker is not available in the execution environment');
        }

        $repoRoot = dirname(__DIR__, 3);
        $envExample = $repoRoot.'/.env.production.example';
        self::assertFileExists($envExample);

        $tmpDir = sys_get_temp_dir().'/vector_compose_test_'.uniqid();
        mkdir($tmpDir);

        try {
            $tmpEnv = $tmpDir.'/.env.production';
            $envContent = (string) file_get_contents($envExample);
            $envContent = str_replace(
                [
                    'ghcr.io/OWNER/REPOSITORY/app',
                    'ghcr.io/OWNER/REPOSITORY/web',
                    'ghcr.io/OWNER/REPOSITORY/research-worker',
                ],
                [
                    'ghcr.io/vector/app',
                    'ghcr.io/vector/web',
                    'ghcr.io/vector/research-worker',
                ],
                $envContent
            );
            file_put_contents($tmpEnv, $envContent);

            $cmd = sprintf(
                'ENV_FILE=%s IMAGE_TAG=sha-1234567890ab docker compose --env-file %s -f %s config --format json 2>&1',
                escapeshellarg($tmpEnv),
                escapeshellarg($tmpEnv),
                escapeshellarg($this->composePath)
            );

            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);
            $outputStr = implode("\n", $output);

            self::assertSame(0, $exitCode, 'Docker compose config must succeed: '.$outputStr);

            $data = json_decode($outputStr, true);
            self::assertIsArray($data);
            self::assertArrayHasKey('services', $data);

            $services = $data['services'];

            self::assertSame(
                'ghcr.io/vector/app:sha-1234567890ab',
                $services['app']['image'] ?? null
            );
            self::assertSame(
                'ghcr.io/vector/web:sha-1234567890ab',
                $services['web']['image'] ?? null
            );
            self::assertSame(
                'ghcr.io/vector/research-worker:sha-1234567890ab',
                $services['research-worker']['image'] ?? null
            );
            self::assertSame(
                'ghcr.io/vector/app:sha-1234567890ab',
                $services['queue']['image'] ?? null
            );
            self::assertSame(
                'ghcr.io/vector/app:sha-1234567890ab',
                $services['audit-worker']['image'] ?? null
            );
        } finally {
            if (file_exists($tmpDir.'/.env.production')) {
                unlink($tmpDir.'/.env.production');
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    public function test_docker_compose_config_fails_when_research_worker_image_missing(): void
    {
        $composeCheck = exec('which docker 2>/dev/null');
        if (empty($composeCheck)) {
            self::markTestSkipped('Docker is not available in the execution environment');
        }

        $repoRoot = dirname(__DIR__, 3);
        $tmpDir = sys_get_temp_dir().'/vector_compose_test_'.uniqid();
        mkdir($tmpDir);

        try {
            $tmpEnv = $tmpDir.'/.env.production';
            $envContent = (string) file_get_contents($repoRoot.'/.env.production.example');
            $filteredLines = array_filter(
                explode("\n", $envContent),
                fn (string $line): bool => ! str_starts_with($line, 'RESEARCH_WORKER_IMAGE=')
            );
            file_put_contents($tmpEnv, implode("\n", $filteredLines));

            $cmd = sprintf(
                'ENV_FILE=%s IMAGE_TAG=sha-1234567890ab docker compose --env-file %s -f %s config 2>&1',
                escapeshellarg($tmpEnv),
                escapeshellarg($tmpEnv),
                escapeshellarg($this->composePath)
            );

            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);
            $outputStr = implode("\n", $output);

            self::assertNotSame(0, $exitCode, 'Docker compose config must fail when RESEARCH_WORKER_IMAGE is missing');
            self::assertStringContainsString('RESEARCH_WORKER_IMAGE is required', $outputStr);
        } finally {
            if (file_exists($tmpDir.'/.env.production')) {
                unlink($tmpDir.'/.env.production');
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    public function test_docker_compose_config_fails_when_app_image_missing(): void
    {
        $composeCheck = exec('which docker 2>/dev/null');
        if (empty($composeCheck)) {
            self::markTestSkipped('Docker is not available in the execution environment');
        }

        $repoRoot = dirname(__DIR__, 3);
        $tmpDir = sys_get_temp_dir().'/vector_compose_test_'.uniqid();
        mkdir($tmpDir);

        try {
            $tmpEnv = $tmpDir.'/.env.production';
            $envContent = (string) file_get_contents($repoRoot.'/.env.production.example');
            $filteredLines = array_filter(
                explode("\n", $envContent),
                fn (string $line): bool => ! str_starts_with($line, 'APP_IMAGE=')
            );
            file_put_contents($tmpEnv, implode("\n", $filteredLines));

            $cmd = sprintf(
                'ENV_FILE=%s IMAGE_TAG=sha-1234567890ab docker compose --env-file %s -f %s config 2>&1',
                escapeshellarg($tmpEnv),
                escapeshellarg($tmpEnv),
                escapeshellarg($this->composePath)
            );

            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);
            $outputStr = implode("\n", $output);

            self::assertNotSame(0, $exitCode, 'Docker compose config must fail when APP_IMAGE is missing');
            self::assertStringContainsString('APP_IMAGE is required', $outputStr);
        } finally {
            if (file_exists($tmpDir.'/.env.production')) {
                unlink($tmpDir.'/.env.production');
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    public function test_docker_compose_config_fails_when_web_image_missing(): void
    {
        $composeCheck = exec('which docker 2>/dev/null');
        if (empty($composeCheck)) {
            self::markTestSkipped('Docker is not available in the execution environment');
        }

        $repoRoot = dirname(__DIR__, 3);
        $tmpDir = sys_get_temp_dir().'/vector_compose_test_'.uniqid();
        mkdir($tmpDir);

        try {
            $tmpEnv = $tmpDir.'/.env.production';
            $envContent = (string) file_get_contents($repoRoot.'/.env.production.example');
            $filteredLines = array_filter(
                explode("\n", $envContent),
                fn (string $line): bool => ! str_starts_with($line, 'WEB_IMAGE=')
            );
            file_put_contents($tmpEnv, implode("\n", $filteredLines));

            $cmd = sprintf(
                'ENV_FILE=%s IMAGE_TAG=sha-1234567890ab docker compose --env-file %s -f %s config 2>&1',
                escapeshellarg($tmpEnv),
                escapeshellarg($tmpEnv),
                escapeshellarg($this->composePath)
            );

            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);
            $outputStr = implode("\n", $output);

            self::assertNotSame(0, $exitCode, 'Docker compose config must fail when WEB_IMAGE is missing');
            self::assertStringContainsString('WEB_IMAGE is required', $outputStr);
        } finally {
            if (file_exists($tmpDir.'/.env.production')) {
                unlink($tmpDir.'/.env.production');
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }
}
