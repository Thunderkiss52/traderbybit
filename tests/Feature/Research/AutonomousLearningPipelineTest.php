<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\LearningFeatureStoreService;
use App\Research\Application\ModelAdvisoryService;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use App\Research\Jobs\EvaluateStrategyCandidateJob;
use App\Research\Jobs\ProcessAutonomousLearningJob;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AutonomousLearningPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_autonomous_learning_pipeline_execution(): void
    {
        $symbol = 'BTCUSDT';
        $t0 = CarbonImmutable::parse('2026-10-18 00:00:00');

        // 1. Seed closed historical candles
        for ($i = 0; $i < 30; $i++) {
            CandleRecord::query()->create([
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => $symbol,
                'interval' => '15',
                'open_time' => $t0->addMinutes($i * 15),
                'open' => (string) (60000 + ($i * 20)),
                'high' => (string) (60100 + ($i * 20)),
                'low' => (string) (59950 + ($i * 20)),
                'close' => (string) (60050 + ($i * 20)),
                'volume' => '10.0',
                'turnover' => '600000.0',
                'is_closed' => true,
            ]);
        }

        // 2. Feature store assembles point-in-time feature snapshot
        $featureStore = app(LearningFeatureStoreService::class);
        $snapshot = $featureStore->assembleSnapshot(
            symbol: $symbol,
            timeframe: '15',
            decisionTime: $t0->addMinutes(20 * 15),
            strategyId: 'trend_pullback',
            regime: 'BULLISH_TREND'
        );

        $this->assertNotNull($snapshot);
        $this->assertSame('PENDING', $snapshot->label_status);
        $this->assertArrayHasKey('rsi_14', $snapshot->features);
        $this->assertArrayHasKey('recent_volatility', $snapshot->features);
        $this->assertTrue($snapshot->data_quality['no_look_ahead_verified']);

        // 3. Seed additional matured snapshots so training threshold (10) is satisfied
        for ($j = 0; $j < 15; $j++) {
            LearningFeatureSnapshotRecord::query()->create([
                'id' => "snap-matured-{$j}",
                'symbol' => $symbol,
                'timeframe' => '15',
                'decision_time' => $t0->addMinutes($j * 15),
                'available_at' => $t0->addMinutes($j * 15 + 15),
                'feature_schema_version' => '1.0.0',
                'strategy_id' => 'baseline',
                'regime' => 'BULLISH_TREND',
                'features' => [
                    'close' => 60000.0 + ($j * 10),
                    'hl_spread_pct' => 1.5,
                    'body_spread_pct' => 0.7,
                    'recent_volatility' => 0.5,
                    'rsi_14' => 52.0,
                    'volume_ratio' => 1.0,
                ],
                'label_status' => 'MATURED',
                'label_outcome' => $j % 2 === 0 ? 'TP' : 'SL',
                'label_r_multiple' => $j % 2 === 0 ? 1.87 : -1.13,
                'outcome_at' => $t0->addMinutes(($j + 4) * 15),
                'data_quality' => [
                    'candles_evaluated' => 20,
                    'missing_bars' => 0,
                    'no_look_ahead_verified' => true,
                ],
            ]);
        }

        // 4. Enable auto_promote_paper in autonomy settings for test
        $autonomy = app(AutonomyPermissionService::class);
        $autonomy->updateSettings(
            capabilities: ['auto_promote_paper' => true, 'discover_hypotheses' => true, 'retrain_models' => true],
            limits: ['max_allowed_drawdown_percent' => 12.0],
            actor: 'USER_OPERATOR'
        );

        // 5. Execute ProcessAutonomousLearningJob
        Queue::fake();
        $job = new ProcessAutonomousLearningJob;
        app()->call([$job, 'handle']);

        // 6. Assert machine learning model was trained and champion artifact created
        $activeModel = LearningModelArtifactRecord::query()
            ->where('target_event', 'TP_BEFORE_SL')
            ->where('name', "Logistic_TP_BEFORE_SL_{$symbol}")
            ->where('status', 'ACTIVE')
            ->first();

        $this->assertNotNull($activeModel);
        $this->assertNotNull($activeModel->weights);
        $this->assertArrayHasKey('brier_score', $activeModel->metrics);

        // 7. Assert strategy candidate was evolved and evaluated
        $candidate = LearningCandidateStrategyRecord::query()->first();
        $this->assertNotNull($candidate);
        $this->assertSame('RESEARCH_ONLY', $candidate->status);
        $this->assertSame('PENDING', $candidate->search_metadata['evaluation_status']);
        Queue::assertPushed(EvaluateStrategyCandidateJob::class);

        // 8. Assert audit approval trail recorded actions
        $auditCount = AutonomyApprovalEventRecord::query()->count();
        $this->assertGreaterThan(0, $auditCount);

        // 9. Assert subsequent decision is evaluated and attributed to the active model artifact
        $advisory = app(ModelAdvisoryService::class);
        $decision = new SignalDecision(
            id: 'test-advisory-decision',
            exchange: 'bybit',
            category: 'linear',
            symbol: $symbol,
            timeframe: '15',
            decisionTime: $t0->addMinutes(30 * 15),
            candleCloseTime: $t0->addMinutes(30 * 15),
            side: SignalSide::Buy,
            qualityClass: QualityClass::A,
            score: 85,
            pricePlan: null,
            modelRiskPercent: '0.50',
            expiresAt: $t0->addMinutes(32 * 15),
            maximumHoldingMinutes: 240,
            reasons: ['test_setup'],
            risks: [],
            indicatorSnapshot: [],
            strategyVersion: '1.0.0',
            schemaVersion: '1.0.0'
        );

        $advised = $advisory->advise($decision, [
            'close' => 60500.0,
            'hl_spread_pct' => 1.5,
            'body_spread_pct' => 0.7,
            'recent_volatility' => 0.5,
            'rsi_14' => 52.0,
            'volume_ratio' => 1.0,
        ]);

        $this->assertArrayHasKey('model_advisory', $advised->indicatorSnapshot);
        $this->assertSame($activeModel->id, $advised->indicatorSnapshot['model_advisory']['model_id']);
        $this->assertArrayHasKey('calibrated_probability', $advised->indicatorSnapshot['model_advisory']);
    }

    public function test_candidate_generation_rotates_fairly_across_all_families(): void
    {
        Queue::fake();
        config(['research.evolution.max_evaluations_per_cycle' => 2, 'research.evolution.max_total_active' => 8]);

        $job = new ProcessAutonomousLearningJob;

        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $families = LearningCandidateStrategyRecord::query()->orderBy('created_at')->pluck('parent_strategy_id')->all();
        $this->assertSame(['baseline', 'trend_pullback', 'bollinger_mean_reversion', 'donchian_atr_breakout'], $families);
        Queue::assertPushed(EvaluateStrategyCandidateJob::class, 4);
    }
}
