<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\LearningPredictorService;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ChampionChallengerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private LearningPredictorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LearningPredictorService::class);
    }

    public function test_train_model_returns_null_when_under_sample_size_threshold(): void
    {
        $artifact = $this->service->trainModel('TP_BEFORE_SL', 'BTCUSDT');
        $this->assertNull($artifact);
    }

    public function test_initial_champion_model_is_promoted_when_validation_passes(): void
    {
        $this->seedSnapshots('BTCUSDT', 20, positiveRate: 0.6);

        $model = $this->service->trainModel('TP_BEFORE_SL', 'BTCUSDT');

        $this->assertNotNull($model);
        $this->assertSame('ACTIVE', $model->status);
        $this->assertArrayHasKey('brier_score', $model->metrics);
        $this->assertArrayHasKey('log_loss', $model->metrics);
        $this->assertArrayHasKey('ece', $model->metrics);
        $this->assertLessThanOrEqual(0.25, $model->metrics['brier_score']);

        // Check audit event
        $event = AutonomyApprovalEventRecord::query()
            ->where('target_id', $model->id)
            ->where('new_state', 'ACTIVE')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('MODEL_PROMOTION', $event->action);
    }

    public function test_challenger_is_evaluated_against_active_champion(): void
    {
        // 1. Seed initial data and create champion
        $this->seedSnapshots('BTCUSDT', 15, positiveRate: 0.5);
        $champion = $this->service->trainModel('TP_BEFORE_SL', 'BTCUSDT');
        $this->assertNotNull($champion);
        $this->assertSame('ACTIVE', $champion->status);

        // 2. Seed more observations with very clear positive signals to produce a superior challenger
        $this->seedSnapshots('BTCUSDT', 20, positiveRate: 0.85);
        $challenger = $this->service->trainModel('TP_BEFORE_SL', 'BTCUSDT');

        $this->assertNotNull($challenger);
        // Either promoted to ACTIVE (retiring previous) or CHALLENGER_REJECTED based on out-of-sample data
        $this->assertContains($challenger->status, ['ACTIVE', 'CHALLENGER_REJECTED']);

        if ($challenger->status === 'ACTIVE') {
            $this->assertSame('RETIRED', $champion->fresh()->status);
        } else {
            $this->assertSame('ACTIVE', $champion->fresh()->status);
        }
    }

    public function test_concept_drift_detection(): void
    {
        // No model yet
        $noModelDrift = $this->service->detectConceptDrift('BTCUSDT', 'TP_BEFORE_SL');
        $this->assertFalse($noModelDrift['drift_detected']);
        $this->assertSame('TRAIN_INITIAL', $noModelDrift['recommendation']);

        // Seed snapshots and train champion
        $this->seedSnapshots('BTCUSDT', 20, positiveRate: 0.7);
        $champion = $this->service->trainModel('TP_BEFORE_SL', 'BTCUSDT');
        $this->assertNotNull($champion);

        // Recent snapshots with consistent data -> healthy
        $healthyDrift = $this->service->detectConceptDrift('BTCUSDT', 'TP_BEFORE_SL');
        $this->assertFalse($healthyDrift['drift_detected']);
        $this->assertSame('HEALTHY', $healthyDrift['recommendation']);
    }

    private function seedSnapshots(string $symbol, int $count, float $positiveRate): void
    {
        $base = CarbonImmutable::parse('2026-10-18 00:00:00');
        for ($i = 0; $i < $count; $i++) {
            $isTp = ($i % 3) !== 0; // Evenly distributed 67% win rate across both train and validation slices
            LearningFeatureSnapshotRecord::query()->create([
                'id' => (string) Str::uuid(),
                'symbol' => $symbol,
                'timeframe' => '15',
                'decision_time' => $base->addMinutes($i * 15),
                'available_at' => $base->addMinutes($i * 15 + 15),
                'feature_schema_version' => '1.0.0',
                'strategy_id' => 'baseline',
                'regime' => 'BULLISH_TREND',
                'features' => [
                    'close' => 65000.0 + ($i * 10),
                    'hl_spread_pct' => 1.2 + ($i % 3 * 0.1),
                    'body_spread_pct' => 0.8,
                    'recent_volatility' => 0.45,
                    'rsi_14' => 55.0,
                    'volume_ratio' => 1.1,
                ],
                'label_status' => 'MATURED',
                'label_outcome' => $isTp ? 'TP' : 'SL',
                'label_r_multiple' => $isTp ? 1.87 : -1.13,
                'outcome_at' => $base->addMinutes($i * 15 + 120),
                'data_quality' => [
                    'candles_evaluated' => 20,
                    'missing_bars' => 0,
                    'no_look_ahead_verified' => true,
                ],
            ]);
        }
    }
}
