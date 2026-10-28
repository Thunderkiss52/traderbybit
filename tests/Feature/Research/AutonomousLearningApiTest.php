<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\Research\Application\ResearchReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AutonomousLearningApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_learning_overview_and_autonomy_settings_endpoints(): void
    {
        $res = $this->getJson('/api/v1/research/learning/overview');
        $res->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'settings',
                    'feature_store',
                    'models',
                    'candidates',
                    'operating_mode',
                ],
            ])
            ->assertJsonPath('data.operating_mode', 'PAPER_TRADING_RESEARCH_ONLY');

        $settingsRes = $this->getJson('/api/v1/research/learning/autonomy-settings');
        $settingsRes->assertOk()
            ->assertJsonPath('data.kill_switch_active', false);

        $updateRes = $this->postJson('/api/v1/research/learning/autonomy-settings', [
            'capabilities' => ['auto_promote_paper' => true],
            'limits' => ['max_active_shadow_experiments' => 3],
        ]);
        $updateRes->assertOk()
            ->assertJsonPath('data.capabilities.auto_promote_paper', true)
            ->assertJsonPath('data.max_active_shadow_experiments', 3);

        $killRes = $this->postJson('/api/v1/research/learning/kill-switch', [
            'active' => true,
            'reason' => 'Emergency risk test',
        ]);
        $killRes->assertOk()
            ->assertJsonPath('data.kill_switch_active', true);
    }

    public function test_candidate_lifecycle_api_endpoints(): void
    {
        $this->app->make(PaperAccountService::class)->default();

        // 1. Generate candidate
        $genRes = $this->postJson('/api/v1/research/learning/candidates/generate', [
            'parent_strategy_id' => 'trend_pullback',
            'seed' => 4567,
        ]);
        $genRes->assertOk()
            ->assertJsonPath('data.status', 'RESEARCH_ONLY')
            ->assertJsonPath('data.parent_strategy_id', 'trend_pullback');

        $candidateId = $genRes->json('data.id');

        // 2. Create completed backtest run for validation evidence
        $passingRun = BacktestRunRecord::query()->create([
            'id' => (string) Str::uuid(),
            'fingerprint' => 'fp-'.Str::random(16),
            'symbols' => ['BTCUSDT'],
            'strategy_config' => ['id' => 'trend_pullback'],
            'risk_config' => [],
            'execution_config' => [],
            'strategy_version' => '1.3.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'backtest_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'dataset_checksum' => 'checksum-'.Str::random(16),
            'status' => 'COMPLETED',
            'type' => 'SPLIT',
            'from_at' => now()->subDays(30),
            'to_at' => now(),
            'metrics' => [
                'test' => [
                    'total_trades' => 45,
                    'average_r' => 0.40,
                    'profit_factor' => 1.85,
                    'win_rate' => 0.58,
                    'maximum_drawdown_percent' => 6.5,
                ],
                'all_segments' => ['total_trades' => 120],
                'test_is_out_of_sample' => true,
            ],
            'started_at' => now()->subHours(1),
            'completed_at' => now(),
        ]);

        // 2. Evaluate candidate using immutable run ID
        $evalRes = $this->postJson('/api/v1/research/learning/candidates/'.$candidateId.'/evaluate', [
            'validation_run_id' => $passingRun->id,
        ]);
        $evalRes->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.validation_gates.all_gates_passed', false);

        // 3. Promote candidate
        $promoteRes = $this->postJson('/api/v1/research/learning/candidates/'.$candidateId.'/promote', [
            'actor' => 'USER_OPERATOR',
        ]);
        $promoteRes->assertUnprocessable();

        // 5. Query candidate list
        $listRes = $this->getJson('/api/v1/research/learning/candidates');
        $listRes->assertOk()
            ->assertJsonPath('data.total', 1);

        // 6. Audit trail
        $auditRes = $this->getJson('/api/v1/research/learning/audit-trail');
        $auditRes->assertOk()
            ->assertJsonStructure(['data' => ['data', 'total']]);
    }

    public function test_models_endpoint(): void
    {
        $res = $this->getJson('/api/v1/research/learning/models');
        $res->assertOk();

        $trainRes = $this->postJson('/api/v1/research/learning/models/train', [
            'target_event' => 'TP_BEFORE_SL',
            'symbol' => 'BTCUSDT',
        ]);
        $trainRes->assertOk();
    }

    public function test_f14_research_report_freshness_shapes_and_stale_reasons(): void
    {
        Redis::shouldReceive('connection->llen')->andReturn(0);

        // 1. Fresh candle scenario: seed fresh candle within 5 minutes
        $now = now('UTC');
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $now->subMinutes(5),
            'open' => '65000',
            'high' => '65100',
            'low' => '64900',
            'close' => '65050',
            'volume' => '100',
            'turnover' => '6500000',
            'is_closed' => true,
        ]);

        $reportService = app(ResearchReportService::class);
        $freshReport = $reportService->generateReport('daily');

        self::assertArrayHasKey('system_freshness', $freshReport);
        self::assertArrayHasKey('status', $freshReport['system_freshness']);
        self::assertArrayHasKey('candle_lag_seconds', $freshReport['system_freshness']);
        self::assertArrayHasKey('candle_lag_minutes', $freshReport['system_freshness']);
        self::assertArrayHasKey('redis_queue_depth', $freshReport['system_freshness']);
        self::assertArrayHasKey('active_alerts', $freshReport['system_freshness']);
        self::assertSame('HEALTHY', $freshReport['system_freshness']['status']);

        // 2. Stale scenario: delete fresh candle and seed stale candle from 3 hours ago
        CandleRecord::query()->delete();
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $now->subHours(3),
            'open' => '65000',
            'high' => '65100',
            'low' => '64900',
            'close' => '65050',
            'volume' => '100',
            'turnover' => '6500000',
            'is_closed' => true,
        ]);

        $staleReport = $reportService->generateReport('daily');
        self::assertSame('WARNING', $staleReport['system_freshness']['status']);
        self::assertGreaterThanOrEqual(120, $staleReport['system_freshness']['candle_lag_minutes']);
        self::assertNotEmpty($staleReport['system_freshness']['active_alerts']);
        self::assertSame('MISSING_CANDLE_INTERVALS', $staleReport['system_freshness']['active_alerts'][0]['code']);

        // 3. Test API data-freshness endpoint returns exact same shape
        $apiRes = $this->getJson('/api/v1/research/health/data-freshness');
        $apiRes->assertOk()
            ->assertJsonPath('data.status', 'WARNING')
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'timestamp',
                    'database',
                    'candle_freshness',
                    'queues',
                    'execution_failures_24h',
                    'alerts',
                ],
            ]);
    }
}
