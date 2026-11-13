<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\LearningPredictorService;
use App\Research\Domain\ResearchPromotionControlRecord;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
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

    public function test_validated_proxy_model_remains_candidate_without_advisory_authority(): void
    {
        $this->seedSnapshots('BTCUSDT', 30);
        $model = $this->service->trainModel('TP_BEFORE_SL', 'BTCUSDT');

        $this->assertNotNull($model);
        $this->assertSame('CANDIDATE', $model->status);
        $this->assertArrayHasKey('brier_score', $model->metrics);
        $this->assertArrayHasKey('log_loss', $model->metrics);
        $this->assertArrayHasKey('ece', $model->metrics);
        $this->assertLessThanOrEqual(0.25, $model->metrics['brier_score']);
        $this->assertSame('DIRECTIONAL_SIGNAL_PROXY_V2', $model->parameters['label_source']);
        $this->assertSame(0, LearningModelArtifactRecord::query()->where('status', 'ACTIVE')->count());
        $this->assertFalse(AutonomyApprovalEventRecord::query()->where('action', 'MODEL_PROMOTION')->exists());
        $this->assertSame('INSUFFICIENT_DATA', $this->service->predictCalibrated('TP_BEFORE_SL', [])['status']);
    }

    public function test_proxy_challenger_is_compared_without_retiring_authorized_champion(): void
    {
        $champion = $this->seedAuthorizedChampion();
        $this->seedSnapshots('BTCUSDT', 30);
        $challenger = $this->service->trainModel('TP_BEFORE_SL', 'BTCUSDT');

        $this->assertNotNull($challenger);
        $this->assertSame('CANDIDATE', $challenger->status);
        $this->assertSame('ACTIVE', $champion->fresh()->status);
        $this->assertLessThan(0.25, $challenger->metrics['brier_score']);
        $this->assertFalse(AutonomyApprovalEventRecord::query()->where('action', 'CHAMPION_RETIRED')->exists());
        $prediction = $this->service->predictCalibrated('TP_BEFORE_SL', []);
        $this->assertSame('CALIBRATED', $prediction['status']);
        $this->assertSame($champion->version, $prediction['model_version']);
    }

    public function test_concept_drift_detection(): void
    {
        $noModelDrift = $this->service->detectConceptDrift('BTCUSDT', 'TP_BEFORE_SL');
        $this->assertFalse($noModelDrift['drift_detected']);
        $this->assertSame('TRAIN_INITIAL', $noModelDrift['recommendation']);

        $this->seedAuthorizedChampion();
        $this->seedSnapshots('BTCUSDT', 30);
        $healthyDrift = $this->service->detectConceptDrift('BTCUSDT', 'TP_BEFORE_SL');
        $this->assertFalse($healthyDrift['drift_detected']);
        $this->assertSame('HEALTHY', $healthyDrift['recommendation']);

        // Mature observations that contradict the authorized champion trigger retraining.
        LearningFeatureSnapshotRecord::query()->update(['label_outcome' => 'SL', 'label_r_multiple' => '-1']);
        $champion = LearningModelArtifactRecord::query()->where('status', 'ACTIVE')->firstOrFail();
        $champion->update(['weights' => ['intercept' => 3.0]]);
        $drift = $this->service->detectConceptDrift('BTCUSDT', 'TP_BEFORE_SL');
        $this->assertTrue($drift['drift_detected']);
        $this->assertSame('TRIGGER_RETRAINING', $drift['recommendation']);
    }

    public function test_drift_monitor_waits_for_enough_complete_matured_observations(): void
    {
        $this->seedAuthorizedChampion();
        $this->seedSnapshots('BTCUSDT', 30);
        $incomplete = LearningFeatureSnapshotRecord::query()->latest('decision_time')->limit(6)->pluck('id');
        LearningFeatureSnapshotRecord::query()->whereIn('id', $incomplete)->update(['label_r_multiple' => null]);

        $drift = $this->service->detectConceptDrift();
        self::assertFalse($drift['drift_detected']);
        self::assertSame('INSUFFICIENT_RECENT_DATA', $drift['status']);
        self::assertSame('WAIT', $drift['recommendation']);
        self::assertSame(9, $drift['sample_size']);
        self::assertArrayNotHasKey('recent_brier_score', $drift);
    }

    private function seedAuthorizedChampion(): LearningModelArtifactRecord
    {
        // Synthetic preexisting operator-approved artifact, independent of proxy training.
        return LearningModelArtifactRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => LearningPredictorService::scopedModelName('TP_BEFORE_SL', 'BTCUSDT', 'bybit'),
            'model_type' => 'LOGISTIC_REGRESSION', 'target_event' => 'TP_BEFORE_SL',
            'version' => 'authorized-v1', 'status' => 'ACTIVE',
            'parameters' => [
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
                'strategy_id' => 'baseline', 'strategy_version' => 'UNVERSIONED',
                'timeframe' => '15', 'side' => 'BUY', 'approved_for_advisory' => true,
            ],
            'weights' => ['intercept' => 0.0],
            'metrics' => ['brier_score' => 0.25, 'log_loss' => 0.6931, 'ece' => 0.05],
            'artifact_hash' => hash('sha256', 'authorized-champion-fixture'),
        ]);
    }

    public function test_training_respects_permission_and_both_kill_switches(): void
    {
        $this->seedSnapshots('BTCUSDT', 20);
        $permissions = app(AutonomyPermissionService::class);
        $permissions->updateSettings(['retrain_models' => false]);
        $this->assertTrainingBlocked();
        $permissions->updateSettings(['retrain_models' => true]);
        $permissions->toggleKillSwitch(true);
        $this->assertTrainingBlocked();
        $permissions->toggleKillSwitch(false);
        ResearchPromotionControlRecord::query()->create([
            'id' => (string) Str::uuid(), 'action' => 'KILL_SWITCH_ACTIVATED',
            'actor' => 'USER_OPERATOR', 'reason' => 'isolated regression', 'created_at' => now(),
        ]);
        $this->assertTrainingBlocked();
    }

    private function assertTrainingBlocked(): void
    {
        try {
            $this->service->trainModel('TP_BEFORE_SL', 'BTCUSDT');
            self::fail('Training must enforce effective permission at the service boundary.');
        } catch (\DomainException $exception) {
            self::assertSame('Model training is blocked by effective autonomy permissions.', $exception->getMessage());
        }
        self::assertSame(0, LearningModelArtifactRecord::query()->count());
    }

    private function seedSnapshots(string $symbol, int $count): void
    {
        $base = CarbonImmutable::parse('2026-10-18 00:00:00');
        for ($i = 0; $i < $count; $i++) {
            $isTp = $i % 2 === 0;
            LearningFeatureSnapshotRecord::query()->create([
                'id' => (string) Str::uuid(),
                'symbol' => $symbol,
                'timeframe' => '15',
                'decision_time' => $base->addHours($i * 2),
                'available_at' => $base->addHours($i * 2),
                'feature_schema_version' => '1.0.0',
                'strategy_id' => 'baseline',
                'regime' => 'BULLISH_TREND',
                'features' => [
                    'close' => 65000.0 + ($i * 10),
                    'hl_spread_pct' => $isTp ? 2.5 : 0.5,
                    'body_spread_pct' => 0.8,
                    'recent_volatility' => 0.45,
                    'rsi_14' => $isTp ? 65.0 : 35.0,
                    'volume_ratio' => $isTp ? 1.8 : 0.6,
                ],
                'label_status' => 'MATURED',
                'label_outcome' => $isTp ? 'TP' : 'SL',
                'label_r_multiple' => $isTp ? 1.87 : -1.13,
                'outcome_at' => $base->addHours($i * 2)->addHour(),
                'data_quality' => [
                    'exchange' => 'bybit', 'category' => 'linear', 'side' => 'BUY',
                    'strategy_version' => 'UNVERSIONED', 'label_policy_version' => 'SIGNAL_PROXY_V2',
                    'candles_evaluated' => 20,
                    'missing_bars' => 0,
                    'no_look_ahead_verified' => true,
                ],
            ]);
        }
    }
}
