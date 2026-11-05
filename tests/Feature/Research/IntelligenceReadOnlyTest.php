<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\StrategyHealthService;
use App\Research\Infrastructure\Persistence\AutonomySettingRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class IntelligenceReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_health_failure_is_redacted_and_reported_as_degraded(): void
    {
        config(['app.debug' => false]);
        Redis::shouldReceive('connection')->once()->andThrow(new \RuntimeException(
            'SYNTHETIC_SECRET redis://private-host password=synthetic /private/path'
        ));
        Log::spy();

        $response = $this->getJson('/api/v1/research/health/data-freshness')->assertOk()
            ->assertJsonPath('data.status', 'WARNING')
            ->assertJsonPath('data.queues.error', 'Queue health is unavailable.')
            ->assertJsonPath('data.queues.status', 'UNAVAILABLE');
        foreach (['SYNTHETIC_SECRET', 'private-host', 'password=', '/private/path'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $response->getContent());
        }
        Log::shouldHaveReceived('warning')->with('Research queue health unavailable.', [
            'code' => 'QUEUE_HEALTH_UNAVAILABLE',
            'exception' => \RuntimeException::class,
            'request_id' => $response->headers->get('X-Request-ID'),
        ])->once();
    }

    public function test_health_and_autonomy_reads_do_not_initialize_or_persist_records(): void
    {
        self::assertSame(0, AutonomySettingRecord::query()->count());
        $writes = [];
        DB::listen(static function ($event) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        foreach (range(1, 2) as $attempt) {
            foreach (['health/overview', 'health/strategy-funnel', 'learning/overview', 'learning/autonomy-settings', 'autonomy/registry', 'autonomy/experiments', 'autonomy/promotion-controls'] as $endpoint) {
                $this->getJson('/api/v1/research/'.$endpoint)->assertOk();
            }
        }
        self::assertSame([], $writes);
        $this->assertDatabaseCount('autonomy_settings', 0);
        $this->assertDatabaseCount('strategy_health_snapshots', 0);
    }

    public function test_autonomy_reads_preserve_saved_policy_and_explicit_writers_still_persist(): void
    {
        $service = app(AutonomyPermissionService::class);
        $settings = $service->updateSettings(['auto_promote_paper' => true]);
        $service->toggleKillSwitch(true);
        $before = AutonomySettingRecord::query()->findOrFail($settings->id)->getRawOriginal();
        $this->getJson('/api/v1/research/learning/autonomy-settings')->assertOk()
            ->assertJsonPath('data.capabilities.auto_promote_paper', true)
            ->assertJsonPath('data.kill_switch_active', true);
        self::assertSame($before, AutonomySettingRecord::query()->findOrFail($settings->id)->getRawOriginal());
        self::assertFalse($service->isAllowed('auto_promote_paper'));

        app(StrategyHealthService::class)->overview();
        $this->assertDatabaseCount('strategy_health_snapshots', 13);
    }

    public function test_repeated_intelligence_reads_do_not_write_to_the_database(): void
    {
        $writes = [];
        DB::listen(static function ($event) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        foreach (range(1, 2) as $attempt) {
            foreach (['recommendations', 'report', 'degradation', 'correlations'] as $endpoint) {
                $this->getJson('/api/v1/research/'.$endpoint)->assertOk();
            }
        }
        self::assertSame([], $writes);
    }

    public function test_production_errors_redact_internal_details_and_keep_request_ids(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('/api/v1/_caught-error',
            fn () => response()->json(['message' => 'SQLSTATE password=secret /internal/path', 'trace' => ['internal']], 500));
        $response = $this->getJson('/api/v1/_caught-error')->assertStatus(500)
            ->assertJsonPath('error.code', 'server_error');
        self::assertNotEmpty($response->json('error.request_id'));
        self::assertSame($response->json('error.request_id'), $response->headers->get('X-Request-ID'));
        foreach (['SQLSTATE', 'secret', '/internal/path', 'trace'] as $secret) {
            self::assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_missing_build_metadata_is_explicitly_unknown(): void
    {
        self::assertFileDoesNotExist(base_path('build-metadata.json'));
        $this->getJson('/api/v1/system/version')->assertOk()
            ->assertJsonPath('git_sha', null)->assertJsonPath('build_timestamp', null)
            ->assertJsonPath('build_metadata_status', 'UNKNOWN');
    }

    public function test_generation_requires_research_authorization(): void
    {
        config(['services.research_api_token' => 'local-remediation-test-token']);
        $this->postJson('/api/v1/research/recommendations/generate')->assertUnauthorized();
        $this->withHeader('X-Research-Token', 'local-remediation-test-token')
            ->postJson('/api/v1/research/recommendations/generate')->assertOk();
    }
}
