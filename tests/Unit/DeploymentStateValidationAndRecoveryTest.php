<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentStateValidationAndRecoveryTest extends TestCase
{
    private string $tempDir;

    private string $scriptsDir;

    private string $dummyEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/bybit_test_state_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
        $this->scriptsDir = dirname(__DIR__, 2).'/docker/scripts';

        $this->dummyEnv = $this->tempDir.'/.env.test';
        file_put_contents($this->dummyEnv, implode("\n", [
            'APP_KEY=base64:u4/W3LqNf1EaM91kYn8X0gA5O1z2Y3X4V5U6T7S8R9Q=',
            'POSTGRES_DB=bybit_test',
            'POSTGRES_USER=postgres',
            'POSTGRES_PASSWORD=testpass',
            'REDIS_PASSWORD=bybit_redis_secret',
            'UI_BASIC_AUTH_USER=testuser',
            'UI_BASIC_AUTH_PASSWORD=testpassword',
            'COMPOSE_PROJECT_NAME=bybit-signals-test',
            'ALLOW_TEST_PROJECT=1',
            'PG_PORT=25432',
        ])."\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function runScript(string $script, array $args = [], array $env = []): array
    {
        $defaultEnv = [
            'ENV_FILE' => $this->dummyEnv,
            'DEPLOY_STATE_DIR' => $this->tempDir,
            'SKIP_DOCKER_INSPECT' => '1',
            'ALLOW_TEST_PROJECT' => '1',
            'MIN_FREE_DISK_GB' => '1',
            'PG_PORT' => '25432',
            'PGPASSWORD' => 'testpass',
        ];
        $mergedEnv = array_merge($defaultEnv, $env);

        $envString = '';
        foreach ($mergedEnv as $k => $v) {
            $envString .= sprintf('%s=%s ', escapeshellarg($k), escapeshellarg($v));
        }

        $command = sprintf(
            'env %s bash %s %s 2>&1',
            $envString,
            escapeshellarg($this->scriptsDir.'/'.$script),
            implode(' ', array_map('escapeshellarg', $args))
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [
            'exitCode' => $exitCode,
            'output' => implode("\n", $output),
        ];
    }

    public function test_clean_state_validation_passes(): void
    {
        file_put_contents($this->tempDir.'/.deploy-current', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/.deploy-previous', "sha-37d30ee343b6305bc764281c4baa7f755863e2f5\n");
        file_put_contents($this->tempDir.'/release-current', "94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-previous', "37d30ee343b6305bc764281c4baa7f755863e2f5\n");

        $result = $this->runScript('validate-deployment-state.sh', ['pre-deploy']);
        $this->assertSame(0, $result['exitCode'], $result['output']);
        $this->assertStringContainsString('Pre-deployment state validation passed', $result['output']);
    }

    public function test_corrupted_duplicate_tags_fails_closed_in_pre_deploy(): void
    {
        file_put_contents($this->tempDir.'/.deploy-current', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/.deploy-previous', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-current', "94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-previous', "37d30ee343b6305bc764281c4baa7f755863e2f5\n");

        $result = $this->runScript('validate-deployment-state.sh', ['pre-deploy']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Deployment state corruption detected', $result['output']);
        $this->assertStringContainsString('Duplicate image tags', $result['output']);
        $this->assertStringContainsString('recover-deployment-state.sh', $result['output']);
    }

    public function test_corrupted_state_blocks_rollback(): void
    {
        file_put_contents($this->tempDir.'/.deploy-current', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/.deploy-previous', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");

        $result = $this->runScript('validate-deployment-state.sh', ['pre-rollback']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Cannot rollback', $result['output']);
    }

    public function test_missing_release_metadata_fails_closed(): void
    {
        file_put_contents($this->tempDir.'/.deploy-current', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");

        $result = $this->runScript('validate-deployment-state.sh', ['pre-deploy']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Missing release metadata', $result['output']);
    }

    public function test_inspect_mode_detects_corrupted_state(): void
    {
        file_put_contents($this->tempDir.'/.deploy-current', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/.deploy-previous', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-current', "94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-previous', "37d30ee343b6305bc764281c4baa7f755863e2f5\n");

        $result = $this->runScript('validate-deployment-state.sh', ['inspect']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Corrupted State      : YES', $result['output']);
        $this->assertStringContainsString('recover-deployment-state.sh --repair', $result['output']);
    }

    public function test_recovery_refuses_silent_inference_on_conflicting_state(): void
    {
        file_put_contents($this->tempDir.'/.deploy-current', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/.deploy-previous', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-current', "94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-previous', "37d30ee343b6305bc764281c4baa7f755863e2f5\n");

        // Attempting recovery without explicit targets must fail closed
        $result = $this->runScript('recover-deployment-state.sh', ['--repair', '--skip-readiness']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Ambiguous recovery state', $result['output']);
        $this->assertStringContainsString('Automatic inference is prohibited', $result['output']);
    }

    public function test_recovery_succeeds_with_explicit_targets_and_deterministic_sha_resolution(): void
    {
        file_put_contents($this->tempDir.'/.deploy-current', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/.deploy-previous', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-current', "94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-previous', "37d30ee343b6305bc764281c4baa7f755863e2f5\n");

        // Supply explicit target tags; release SHAs are deterministically extracted from sha-<40hex>
        $result = $this->runScript('recover-deployment-state.sh', [
            '--repair',
            '--skip-readiness',
            '--target-tag', 'sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c',
            '--previous-tag', 'sha-37d30ee343b6305bc764281c4baa7f755863e2f5',
        ]);
        $this->assertSame(0, $result['exitCode'], $result['output']);
        $this->assertStringContainsString('Deployment State Recovery Successfully Completed', $result['output']);

        $stateFile = $this->tempDir.'/deploy-state.json';
        $this->assertFileExists($stateFile);
        $state = json_decode(file_get_contents($stateFile), true);

        // When skip-readiness is used, status is DEGRADED_UNVERIFIED
        $this->assertSame('DEGRADED_UNVERIFIED', $state['status']);
        $this->assertSame('sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c', $state['current_image_tag']);
        $this->assertSame('sha-37d30ee343b6305bc764281c4baa7f755863e2f5', $state['previous_image_tag']);
        $this->assertSame('94c853458e5a38f4bc46f32c4f95c56f52216e6c', $state['current_release_sha']);
        $this->assertSame('37d30ee343b6305bc764281c4baa7f755863e2f5', $state['previous_release_sha']);
    }

    public function test_deployment_lock_allows_nested_reentrancy_and_never_unlinks(): void
    {
        $lockFile = $this->tempDir.'/deployment.lock';

        // Run bash script that acquires lock, invokes a nested script that reentrantly acquires lock, releases, and verifies lock file is NOT unlinked
        $script = <<<BASH
set -Eeuo pipefail
DEPLOY_STATE_DIR="{$this->tempDir}"
ENV_FILE="{$this->dummyEnv}"
source "{$this->scriptsDir}/common.sh"

acquire_deployment_lock "PARENT_OPERATION"
echo "PARENT_LOCKED: \$\$"

# Call nested child script in descendant subshell
bash -c "
DEPLOY_STATE_DIR='{$this->tempDir}'
ENV_FILE='{$this->dummyEnv}'
source '{$this->scriptsDir}/common.sh'
acquire_deployment_lock 'NESTED_OPERATION'
echo 'NESTED_SUCCESS'
release_deployment_lock
"

release_deployment_lock
echo "PARENT_RELEASED"
BASH;

        $cmd = sprintf('bash -c %s 2>&1', escapeshellarg($script));
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $outStr = implode("\n", $output);

        $this->assertSame(0, $exitCode, $outStr);
        $this->assertStringContainsString('Deployment lock reentrancy verified', $outStr);
        $this->assertStringContainsString('NESTED_SUCCESS', $outStr);
        $this->assertStringContainsString('PARENT_RELEASED', $outStr);

        // Assert: Lockfile must NEVER be unlinked on release!
        $this->assertFileExists($lockFile, 'Lockfile must not be unlinked on release');
    }

    public function test_competing_independent_process_blocked_by_lock(): void
    {
        $lockFile = $this->tempDir.'/deployment.lock';
        $fp = fopen($lockFile, 'c+');
        $this->assertTrue(flock($fp, LOCK_EX | LOCK_NB));
        fwrite($fp, "pid=999999\n");
        fflush($fp);

        // Attempting recovery while lock is held by independent PID 999999 must fail closed
        $result = $this->runScript('recover-deployment-state.sh', [
            '--repair',
            '--skip-readiness',
            '--target-tag', 'sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c',
            '--previous-tag', 'sha-37d30ee343b6305bc764281c4baa7f755863e2f5',
        ]);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Deployment lock is held by another process', $result['output']);

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function test_auto_recover_on_deploy_heals_duplicate_state_automatically(): void
    {
        // Setup production corrupted state
        file_put_contents($this->tempDir.'/.deploy-current', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/.deploy-previous', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-current', "94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-previous', "37d30ee343b6305bc764281c4baa7f755863e2f5\n");

        // When AUTO_RECOVER_ON_DEPLOY=1, pre-deploy validation automatically recovers the state
        $result = $this->runScript('validate-deployment-state.sh', ['pre-deploy'], [
            'AUTO_RECOVER_ON_DEPLOY' => '1',
            'SKIP_DOCKER_INSPECT' => '1',
            'SKIP_READINESS' => '1',
            'FORCE_CONSISTENT' => '1',
            'ALLOW_EMERGENCY_SKIP' => '1',
        ]);

        $this->assertSame(0, $result['exitCode'], $result['output']);
        $this->assertStringContainsString('AUTO_RECOVER_ON_DEPLOY=1 active', $result['output']);
        $this->assertStringContainsString('Pre-deployment state validation passed', $result['output']);

        // Assert: .deploy-previous has been automatically healed to sha-37d3...
        $this->assertSame(
            "sha-37d30ee343b6305bc764281c4baa7f755863e2f5\n",
            file_get_contents($this->tempDir.'/.deploy-previous')
        );
        $this->assertSame(
            "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n",
            file_get_contents($this->tempDir.'/.deploy-current')
        );
    }

    public function test_same_sha_reconcile_state_preserves_distinct_previous_state(): void
    {
        file_put_contents($this->tempDir.'/.deploy-current', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/.deploy-previous', "sha-37d30ee343b6305bc764281c4baa7f755863e2f5\n");
        file_put_contents($this->tempDir.'/release-current', "94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-previous', "37d30ee343b6305bc764281c4baa7f755863e2f5\n");

        $script = <<<BASH
set -Eeuo pipefail
DEPLOY_STATE_DIR="{$this->tempDir}"
ENV_FILE="{$this->dummyEnv}"
source "{$this->scriptsDir}/common.sh"

CURRENT_TAG="$(<"\${DEPLOY_STATE_DIR}/.deploy-current")"
PREVIOUS_TAG="$(<"\${DEPLOY_STATE_DIR}/.deploy-previous")"
NEW_TAG="\${CURRENT_TAG}"

IS_SAME_TAG_RECONCILE=1
new_rel="\${NEW_TAG#sha-}"
if [[ "\${IS_SAME_TAG_RECONCILE}" == "1" ]]; then
    prev_target_tag="\${PREVIOUS_TAG}"
    prev_target_rel=""
    if [[ -f "\${DEPLOY_STATE_DIR}/release-previous" ]]; then
        prev_target_rel="$(<"\${DEPLOY_STATE_DIR}/release-previous")"
    elif [[ -n "\${prev_target_tag}" ]]; then
        prev_target_rel="\${prev_target_tag#sha-}"
    fi
    commit_deployment_state "\${NEW_TAG}" "\${prev_target_tag}" "\${new_rel}" "\${prev_target_rel}" "CONSISTENT"
fi
BASH;

        $cmd = sprintf('bash -c %s 2>&1', escapeshellarg($script));
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));

        // Assert: previous remains sha-37d3..., NOT overwritten by sha-94c8...
        $this->assertSame(
            "sha-37d30ee343b6305bc764281c4baa7f755863e2f5\n",
            file_get_contents($this->tempDir.'/.deploy-previous')
        );
        $this->assertSame(
            "37d30ee343b6305bc764281c4baa7f755863e2f5\n",
            file_get_contents($this->tempDir.'/release-previous')
        );

        // Pre-rollback validation must PASS because distinct target exists
        $result = $this->runScript('validate-deployment-state.sh', ['pre-rollback']);
        $this->assertSame(0, $result['exitCode'], $result['output']);

        // Post-deploy validation must PASS
        $result = $this->runScript('validate-deployment-state.sh', ['post-deploy']);
        $this->assertSame(0, $result['exitCode'], $result['output']);
    }

    public function test_same_sha_wrapper_preserves_previous_release_directory(): void
    {
        $releasesDir = $this->tempDir.'/releases';
        mkdir($releasesDir.'/37d30ee343b6305bc764281c4baa7f755863e2f5', 0755, true);
        mkdir($releasesDir.'/94c853458e5a38f4bc46f32c4f95c56f52216e6c', 0755, true);

        file_put_contents($this->tempDir.'/release-current', "94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-previous', "37d30ee343b6305bc764281c4baa7f755863e2f5\n");

        $script = <<<BASH
set -Eeuo pipefail
STATE_DIR="{$this->tempDir}"
RELEASES_DIR="{$releasesDir}"
release_sha="94c853458e5a38f4bc46f32c4f95c56f52216e6c"
old_release_sha="94c853458e5a38f4bc46f32c4f95c56f52216e6c"

preserved_prev_sha=""
if [[ -f "\${STATE_DIR}/release-previous" ]]; then
    preserved_prev_sha="$(<"\${STATE_DIR}/release-previous")"
fi

for candidate in "\${RELEASES_DIR}"/*; do
    [[ -d "\${candidate}" ]] || continue
    candidate_sha="$(basename "\${candidate}")"
    [[ "\${candidate_sha}" == "\${release_sha}" || "\${candidate_sha}" == "\${old_release_sha}" || "\${candidate_sha}" == "\${preserved_prev_sha}" ]] && continue
    rm -rf --one-file-system -- "\${candidate}"
done
if [[ -n "\${old_release_sha}" && "\${old_release_sha}" != "\${release_sha}" ]]; then
    printf '%s\n' "\${old_release_sha}" >"\${STATE_DIR}/release-previous"
fi
BASH;

        $cmd = sprintf('bash -c %s 2>&1', escapeshellarg($script));
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));

        // Both candidate directories must still exist
        $this->assertDirectoryExists($releasesDir.'/37d30ee343b6305bc764281c4baa7f755863e2f5');
        $this->assertDirectoryExists($releasesDir.'/94c853458e5a38f4bc46f32c4f95c56f52216e6c');

        // release-previous must NOT be corrupted to 94c8...
        $this->assertSame(
            "37d30ee343b6305bc764281c4baa7f755863e2f5\n",
            file_get_contents($this->tempDir.'/release-previous')
        );
    }

    public function test_inspect_mode_with_unpopulated_services_prints_complete_service_matrix(): void
    {
        file_put_contents($this->tempDir.'/.deploy-current', "sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/.deploy-previous', "sha-37d30ee343b6305bc764281c4baa7f755863e2f5\n");
        file_put_contents($this->tempDir.'/release-current', "94c853458e5a38f4bc46f32c4f95c56f52216e6c\n");
        file_put_contents($this->tempDir.'/release-previous', "37d30ee343b6305bc764281c4baa7f755863e2f5\n");

        $result = $this->runScript('validate-deployment-state.sh', ['inspect']);
        $this->assertSame(0, $result['exitCode'], $result['output']);
        $this->assertStringContainsString('Running Services:', $result['output']);

        $expectedServices = [
            'app', 'web', 'queue', 'queue-bybit', 'queue-binance',
            'scheduler', 'market-ws', 'audit-worker', 'backtest-worker', 'research-worker',
        ];
        foreach ($expectedServices as $svc) {
            $this->assertStringContainsString($svc, $result['output'], "Missing service '{$svc}' in inspect output.");
            $this->assertStringContainsString('[NOT_CHECKED]', $result['output']);
        }
    }

    public function test_corrupted_metadata_in_state_file_fails_closed(): void
    {
        $state = [
            'status' => 'CORRUPTED',
            'current_image_tag' => 'sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c',
            'previous_image_tag' => 'sha-37d30ee343b6305bc764281c4baa7f755863e2f5',
            'current_release_sha' => '94c853458e5a38f4bc46f32c4f95c56f52216e6c',
            'previous_release_sha' => '37d30ee343b6305bc764281c4baa7f755863e2f5',
            'services' => [],
        ];
        file_put_contents($this->tempDir.'/deploy-state.json', json_encode($state, JSON_PRETTY_PRINT));

        $result = $this->runScript('validate-deployment-state.sh', ['pre-deploy']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('deploy-state.json explicitly records status CORRUPTED', $result['output']);
    }

    public function test_rollback_failed_state_fails_closed_in_pre_deploy(): void
    {
        $state = [
            'status' => 'ROLLBACK_FAILED',
            'current_image_tag' => 'sha-94c853458e5a38f4bc46f32c4f95c56f52216e6c',
            'previous_image_tag' => 'sha-37d30ee343b6305bc764281c4baa7f755863e2f5',
            'current_release_sha' => '94c853458e5a38f4bc46f32c4f95c56f52216e6c',
            'previous_release_sha' => '37d30ee343b6305bc764281c4baa7f755863e2f5',
            'services' => [],
        ];
        file_put_contents($this->tempDir.'/deploy-state.json', json_encode($state, JSON_PRETTY_PRINT));

        $result = $this->runScript('validate-deployment-state.sh', ['pre-deploy']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('prior rollback failed verification', $result['output']);
    }
}
