<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\LearningFeatureStoreService;
use App\Research\Application\LearningPredictorService;
use App\Research\Application\ModelAdvisoryService;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use App\Research\Jobs\EvaluateStrategyCandidateJob;
use App\Research\Jobs\ProcessAutonomousLearningJob;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class AutonomousLearningPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_autonomous_learning_pipeline_execution(): void
    {
        $symbol = 'BTCUSDT';
        $t0 = CarbonImmutable::parse('2026-10-18 00:00:00');
        $baselineVersion = app(StrategyRegistry::class)->get('baseline')->version();

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

        // Directional proxy labels require an immutable signal with an actual trade plan.
        SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'exchange' => 'bybit', 'category' => 'linear',
            'symbol' => $symbol, 'strategy_id' => 'trend_pullback', 'strategy_version' => '1.0.0',
            'timeframe' => '15', 'side' => 'BUY', 'quality_class' => 'A', 'score' => 85,
            'entry_low' => '60400', 'entry_high' => '60450', 'stop_loss' => '59000',
            'take_profit_1' => '62000', 'take_profit_2' => '63000',
            'decision_time' => $t0->addMinutes(20 * 15), 'candle_close_time' => $t0->addMinutes(20 * 15),
            'expires_at' => $t0->addMinutes(22 * 15), 'maximum_holding_minutes' => 240,
            'reasons' => [], 'risks' => [], 'indicator_snapshot' => [], 'contract' => [], 'schema_version' => '1.0.0',
        ]);

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

        // 3. Seed a complete, causal cohort above the 12-observation training threshold
        for ($j = 0; $j < 15; $j++) {
            LearningFeatureSnapshotRecord::query()->create([
                'id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "snap-matured-{$j}"),
                'symbol' => $symbol,
                'timeframe' => '15',
                'decision_time' => $t0->addMinutes($j * 15),
                'available_at' => $t0->addMinutes($j * 15),
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
                    'exchange' => 'bybit', 'category' => 'linear', 'side' => 'BUY',
                    'strategy_version' => $baselineVersion, 'label_policy_version' => 'SIGNAL_PROXY_V2',
                    'candles_evaluated' => 20,
                    'missing_bars' => 0,
                    'no_look_ahead_verified' => true,
                ],
            ]);
        }

        // 4. Enable research capabilities while retaining the production drawdown limit
        $autonomy = app(AutonomyPermissionService::class);
        $autonomy->updateSettings(
            capabilities: ['auto_promote_paper' => false, 'discover_hypotheses' => true, 'retrain_models' => true],
            limits: ['max_allowed_drawdown_percent' => 5.0],
            actor: 'USER_OPERATOR'
        );

        // 5. Execute ProcessAutonomousLearningJob
        Queue::fake();
        $job = new ProcessAutonomousLearningJob;
        app()->call([$job, 'handle']);

        // 6. Proxy training produces research candidates without active trading authority
        $proxyModel = LearningModelArtifactRecord::query()
            ->where('target_event', 'TP_BEFORE_SL')
            ->where('name', LearningPredictorService::scopedModelName('TP_BEFORE_SL', $symbol, 'bybit', strategyVersion: $baselineVersion))
            ->where('status', 'CANDIDATE')
            ->first();

        $this->assertNotNull($proxyModel);
        $this->assertNotNull($proxyModel->weights);
        $this->assertArrayHasKey('brier_score', $proxyModel->metrics);
        $this->assertFalse(LearningModelArtifactRecord::query()->where('status', 'ACTIVE')->exists());

        // 7. Assert strategy candidate was evolved and evaluated
        $candidate = LearningCandidateStrategyRecord::query()->first();
        $this->assertNotNull($candidate);
        $this->assertSame('RESEARCH_ONLY', $candidate->status);
        $this->assertSame('PENDING', $candidate->search_metadata['evaluation_status']);
        Queue::assertPushed(EvaluateStrategyCandidateJob::class);

        // 8. Assert audit approval trail recorded actions
        $auditCount = AutonomyApprovalEventRecord::query()->count();
        $this->assertGreaterThan(0, $auditCount);

        // 9. A proxy candidate cannot masquerade as an active advisory model
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
            strategyVersion: $baselineVersion,
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
        $this->assertSame('NO_ACTIVE_MODEL', $advised->indicatorSnapshot['model_advisory']['status']);
        $this->assertFalse($advised->indicatorSnapshot['model_advisory']['eligible_for_advisory']);
        $this->assertNull($advised->indicatorSnapshot['model_advisory']['calibrated_probability']);
        $this->assertSame('CANDIDATE', $proxyModel->fresh()->status);
        $this->assertArrayHasKey('calibrated_probability', $advised->indicatorSnapshot['model_advisory']);
    }

    public function test_candidate_generation_rotates_fairly_across_all_families(): void
    {
        Queue::fake();
        config(['research.evolution.max_evaluations_per_cycle' => 2, 'research.evolution.max_total_active' => 8]);

        $job = new ProcessAutonomousLearningJob;

        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        // created_at has second precision; queue dispatch order is the scheduling contract.
        $families = Queue::pushed(EvaluateStrategyCandidateJob::class)->map(
            fn (EvaluateStrategyCandidateJob $queued) => LearningCandidateStrategyRecord::query()->findOrFail($queued->candidateId)->parent_strategy_id
        )->all();
        $this->assertSame(['baseline', 'trend_pullback', 'bollinger_mean_reversion', 'donchian_atr_breakout'], $families);
        $this->assertSame(4, LearningCandidateStrategyRecord::query()->count());
        Queue::assertPushed(EvaluateStrategyCandidateJob::class, 4);
    }

    public function test_f18_training_purging_fallback_activates_without_future_label_leakage(): void
    {
        $symbol = 'BTCUSDT';
        $t0 = CarbonImmutable::parse('2026-10-18 00:00:00');

        // Create 18 matured snapshots.
        // Standard 70% split gives splitIndex = floor(18 * 0.70) = 12 (valStartTime = t0 + 12*15m).
        // By setting outcome_at for items 1..11 to t0 + 15*15m (> valStartTime),
        // only item 0 (1 sample < 5 threshold) survives standard purging in rawTrain.
        // This forces purging fallback to activate.
        // Fallback selects initial 5 samples (0..4) and shifts validation window
        // to decision_time >= latest outcome of 0..4 (t0 + 15*15m), leaving items 15, 16, 17 (3 samples >= 3).
        for ($j = 0; $j < 18; $j++) {
            $decTime = $t0->addMinutes($j * 15);
            $outcomeTime = ($j >= 1 && $j <= 11) ? $t0->addMinutes(15 * 15) : $decTime->addMinutes(15);

            LearningFeatureSnapshotRecord::query()->create([
                'id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "snap-purging-{$j}"),
                'symbol' => $symbol,
                'timeframe' => '15',
                'decision_time' => $decTime,
                'available_at' => $decTime,
                'feature_schema_version' => '1.0.0',
                'strategy_id' => 'baseline',
                'regime' => 'BULLISH_TREND',
                'features' => [
                    'close' => 60000.0 + ($j * 10),
                    'hl_spread_pct' => 1.5,
                    'body_spread_pct' => 0.7,
                    'recent_volatility' => 0.5,
                    'rsi_14' => 50.0 + ($j * 0.5),
                    'volume_ratio' => 1.0,
                ],
                'label_status' => 'MATURED',
                'label_outcome' => $j % 2 === 0 ? 'TP' : 'SL',
                'label_r_multiple' => $j % 2 === 0 ? 1.87 : -1.13,
                'outcome_at' => $outcomeTime,
                'data_quality' => [
                    'exchange' => 'bybit', 'category' => 'linear', 'side' => 'BUY',
                    'strategy_version' => 'UNVERSIONED', 'label_policy_version' => 'SIGNAL_PROXY_V2',
                    'candles_evaluated' => 20,
                    'missing_bars' => 0,
                    'no_look_ahead_verified' => true,
                ],
            ]);
        }

        $predictor = app(LearningPredictorService::class);
        $artifact = $predictor->trainModel('TP_BEFORE_SL', $symbol);

        $this->assertNotNull($artifact, 'Model should train successfully using purging fallback');
        $params = $artifact->parameters;
        $this->assertArrayHasKey('purging_diagnostics', $params);
        $this->assertTrue($params['purging_diagnostics']['fallback_activated'], 'Fallback should have activated');
        $this->assertFalse($params['purging_diagnostics']['future_label_leakage_detected'], 'Zero future label leakage');
        $this->assertGreaterThanOrEqual(5, $params['train_sample_size']);
        $this->assertGreaterThanOrEqual(3, $params['validation_sample_size']);
    }
}
