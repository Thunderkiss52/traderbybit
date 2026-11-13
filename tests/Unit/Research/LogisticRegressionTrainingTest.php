<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\LearningPredictorService;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LogisticRegressionTrainingTest extends TestCase
{
    use RefreshDatabase;

    private LearningPredictorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LearningPredictorService::class);
    }

    public function test_train_model_optimizes_weights_via_gradient_descent_not_hardcoded(): void
    {
        $symbol = 'BTCUSDT';
        $base = CarbonImmutable::parse('2026-10-18 00:00:00');

        // Seed 30 observations with high positive correlation between hl_spread_pct and TP outcome
        for ($i = 0; $i < 30; $i++) {
            $isTp = $i % 2 === 0;
            // When isTp is true, spread is high; when false, spread is low
            $spread = $isTp ? 3.5 : 0.5;

            LearningFeatureSnapshotRecord::query()->create([
                'id' => (string) Str::uuid(),
                'symbol' => $symbol,
                'timeframe' => '15',
                'decision_time' => $base->addHours($i * 2), // 2 hours apart so they mature cleanly
                'available_at' => $base->addHours($i * 2),
                'feature_schema_version' => '1.0.0',
                'strategy_id' => 'baseline',
                'regime' => 'BULLISH_TREND',
                'features' => [
                    'close' => 60000.0 + ($i * 10),
                    'hl_spread_pct' => $spread,
                    'body_spread_pct' => 0.5,
                    'recent_volatility' => 0.8,
                    'rsi_14' => $isTp ? 65.0 : 35.0,
                    'volume_ratio' => $isTp ? 1.8 : 0.6,
                ],
                'label_status' => 'MATURED',
                'label_outcome' => $isTp ? 'TP' : 'SL',
                'label_r_multiple' => $isTp ? 1.87 : -1.13,
                'outcome_at' => $base->addHours($i * 2)->addMinutes(60),
                'data_quality' => [
                    'exchange' => 'bybit', 'category' => 'linear', 'side' => 'BUY',
                    'strategy_version' => 'UNVERSIONED', 'label_policy_version' => 'SIGNAL_PROXY_V2',
                    'candles_evaluated' => 20,
                    'missing_bars' => 0,
                    'no_look_ahead_verified' => true,
                ],
            ]);
        }

        $artifact = $this->service->trainModel('TP_BEFORE_SL', $symbol);

        $this->assertNotNull($artifact);
        $this->assertSame('LOGISTIC_REGRESSION', $artifact->model_type);
        $this->assertSame('BATCH_GRADIENT_DESCENT_L2', $artifact->parameters['algorithm']);

        $weights = (array) $artifact->weights;
        $this->assertArrayHasKey('coefficients', $weights);
        $this->assertArrayHasKey('means', $weights);
        $this->assertArrayHasKey('stds', $weights);

        // Coefficients must be optimized from data: since high hl_spread and RSI correlate with TP,
        // their trained coefficients must be strictly positive, not arbitrary constants like 0.05
        $this->assertGreaterThan(0.0, (float) $weights['coefficients']['hl_spread_pct']);
        $this->assertGreaterThan(0.0, (float) $weights['coefficients']['rsi_14']);

        $this->assertSame('CANDIDATE', $artifact->status);
        $this->assertGreaterThan(0.0, $artifact->metrics['brier_skill_score']);
        $this->assertLessThan($artifact->metrics['baseline_brier_score'], $artifact->metrics['brier_score']);
        $this->assertSame('INSUFFICIENT_DATA', $this->service->predictCalibrated('TP_BEFORE_SL', [
            'hl_spread_pct' => 4.0, 'rsi_14' => 70.0, 'recent_volatility' => 0.8, 'volume_ratio' => 2.0,
        ])['status']);

        // Independent operator-approved artifacts exercise inference with trained coefficients.
        $approved = LearningModelArtifactRecord::query()->create([
            'id' => (string) Str::uuid(), 'name' => $artifact->name, 'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => 'TP_BEFORE_SL', 'version' => 'approved-fixture-v1',
            'parameters' => array_merge($artifact->parameters, ['approved_for_advisory' => true]),
            'weights' => $weights, 'metrics' => $artifact->metrics,
            'artifact_hash' => hash('sha256', json_encode($weights)), 'status' => 'ACTIVE',
        ]);
        $predHigh = $this->service->predictCalibrated('TP_BEFORE_SL', [
            'hl_spread_pct' => 4.0, 'rsi_14' => 70.0, 'recent_volatility' => 0.8, 'volume_ratio' => 2.0,
        ]);
        $predLow = $this->service->predictCalibrated('TP_BEFORE_SL', [
            'hl_spread_pct' => 0.2, 'rsi_14' => 30.0, 'recent_volatility' => 0.8, 'volume_ratio' => 0.4,
        ]);
        $this->assertSame('CALIBRATED', $predHigh['status']);
        $this->assertSame('CALIBRATED', $predLow['status']);
        $this->assertSame($approved->version, $predHigh['model_version']);
        $this->assertGreaterThan($predLow['probability'], $predHigh['probability']);
        $this->assertSame('CANDIDATE', $artifact->fresh()->status);

    }

    public function test_train_model_fails_closed_when_data_is_insufficient(): void
    {
        // 5 observations is under the minimum threshold
        $symbol = 'ETHUSDT';
        $base = CarbonImmutable::parse('2026-10-18 00:00:00');
        for ($i = 0; $i < 5; $i++) {
            LearningFeatureSnapshotRecord::query()->create([
                'id' => (string) Str::uuid(),
                'symbol' => $symbol,
                'timeframe' => '15',
                'decision_time' => $base->addHours($i),
                'available_at' => $base->addHours($i),
                'feature_schema_version' => '1.0.0',
                'strategy_id' => 'baseline',
                'regime' => 'BULLISH_TREND',
                'features' => ['hl_spread_pct' => 1.0, 'recent_volatility' => 0.5, 'rsi_14' => 50.0, 'volume_ratio' => 1.0],
                'label_status' => 'MATURED',
                'label_outcome' => 'TP',
                'label_r_multiple' => 1.87,
                'outcome_at' => $base->addHours($i)->addMinutes(30),
                'data_quality' => [
                    'exchange' => 'bybit', 'category' => 'linear', 'side' => 'BUY',
                    'strategy_version' => 'UNVERSIONED', 'label_policy_version' => 'SIGNAL_PROXY_V2', 'no_look_ahead_verified' => true],
            ]);
        }

        $artifact = $this->service->trainModel('TP_BEFORE_SL', $symbol);
        $this->assertNull($artifact);
    }

    public function test_training_rejects_one_class_labels_without_division_by_zero_or_artifact(): void
    {
        $base = CarbonImmutable::parse('2026-10-18 00:00:00');
        foreach (['TP', 'SL'] as $outcome) {
            $symbol = $outcome === 'TP' ? 'BTCUSDT' : 'ETHUSDT';
            for ($i = 0; $i < 20; $i++) {
                LearningFeatureSnapshotRecord::query()->create([
                    'id' => (string) Str::uuid(),
                    'symbol' => $symbol,
                    'timeframe' => '15',
                    'decision_time' => $base->addHours($i * 3),
                    'available_at' => $base->addHours($i * 3),
                    'feature_schema_version' => '1.0.0',
                    'strategy_id' => 'baseline',
                    'regime' => 'UNKNOWN',
                    'features' => ['hl_spread_pct' => 1.0, 'recent_volatility' => 0.5, 'rsi_14' => 50.0, 'volume_ratio' => 1.0],
                    'label_status' => 'MATURED',
                    'label_outcome' => $outcome,
                    'label_r_multiple' => $outcome === 'TP' ? 2 : -1,
                    'outcome_at' => $base->addHours($i * 3)->addMinutes(30),
                    'data_quality' => ['exchange' => 'bybit', 'category' => 'linear', 'side' => 'BUY',
                        'strategy_version' => 'UNVERSIONED', 'label_policy_version' => 'SIGNAL_PROXY_V2', 'no_look_ahead_verified' => true],
                ]);
            }
            $this->assertNull($this->service->trainModel('TP_BEFORE_SL', $symbol, 'bybit'));
        }
        $this->assertSame(0, LearningModelArtifactRecord::query()->count());
    }

    public function test_matured_labels_never_cross_exchange_boundaries(): void
    {
        $base = CarbonImmutable::parse('2026-10-18 00:00:00');
        for ($i = 0; $i < 20; $i++) {
            foreach (['bybit', 'binance'] as $exchange) {
                $outcome = $exchange === 'binance' ? 'TP' : ($i % 2 === 0 ? 'TP' : 'SL');
                LearningFeatureSnapshotRecord::query()->create([
                    'id' => (string) Str::uuid(),
                    'symbol' => 'ETHUSDT',
                    'timeframe' => '15',
                    'decision_time' => $base->addHours($i * 3),
                    'available_at' => $base->addHours($i * 3),
                    'feature_schema_version' => '1.0.0',
                    'strategy_id' => 'baseline',
                    'regime' => 'UNKNOWN',
                    'features' => ['hl_spread_pct' => 1.0 + ($i % 2), 'recent_volatility' => 0.5, 'rsi_14' => 50.0, 'volume_ratio' => 1.0],
                    'label_status' => 'MATURED',
                    'label_outcome' => $outcome,
                    'label_r_multiple' => $outcome === 'TP' ? 2 : -1,
                    'outcome_at' => $base->addHours($i * 3)->addMinutes(30),
                    'data_quality' => ['exchange' => $exchange, 'category' => 'linear', 'side' => 'BUY',
                        'strategy_version' => 'UNVERSIONED', 'label_policy_version' => 'SIGNAL_PROXY_V2', 'no_look_ahead_verified' => true],
                ]);
            }
        }

        $bybit = $this->service->trainModel('TP_BEFORE_SL', 'ETHUSDT', 'bybit');
        $this->assertNotNull($bybit);
        $this->assertSame(20, $bybit->parameters['total_sample_size']);
        $this->assertSame('bybit', $bybit->parameters['exchange']);
        $this->assertNull($this->service->trainModel('TP_BEFORE_SL', 'ETHUSDT', 'binance'));
        $this->assertSame('INSUFFICIENT_DATA', $this->service->predictCalibrated('TP_BEFORE_SL', [], 'ETHUSDT', 'binance')['status']);
    }

    public function test_training_rejects_noncausal_incomplete_or_nonfinite_observations(): void
    {
        $snapshots = $this->seedValidCohort();
        $snapshot = $snapshots[0];
        $original = $snapshot->attributesToArray();
        $features = $snapshot->features;
        $cases = [
            ['available_at' => $snapshot->decision_time->addSecond()],
            ['outcome_at' => $snapshot->decision_time->subSecond()],
            ['outcome_at' => CarbonImmutable::now()->addDay()],
            ['outcome_at' => null],
            ['label_outcome' => 'AMBIGUOUS'],
            ['label_outcome' => null],
            ['label_r_multiple' => null],
            ['features' => array_diff_key($features, ['rsi_14' => true])],
            ['features' => array_merge($features, ['rsi_14' => '1e309'])],
            ['features' => array_merge($features, ['rsi_14' => 1e308])],
            ['features' => array_merge($features, ['rsi_14' => 'not-a-number'])],
            ['data_quality' => array_merge($snapshot->data_quality, ['no_look_ahead_verified' => false])],
        ];
        foreach ($cases as $index => $changes) {
            $snapshot->refresh()->fill($original)->save();
            $snapshot->fill($changes)->save();
            $this->assertNull($this->service->trainModel(), 'Invalid observation case '.$index.' must fail closed.');
            $this->assertSame(0, LearningModelArtifactRecord::query()->count());
        }
        // Restoring the test fixture supplies a positive control.
        $snapshot->refresh()->fill($original)->save();
        $this->assertNotNull($this->service->trainModel());
    }

    public function test_nonfinite_numeric_labels_are_rejected_by_training_or_postgres_precision(): void
    {
        $snapshot = $this->seedValidCohort()[0];
        // PostgreSQL constrained NUMERIC permits NaN, which Brick decimal casting rejects.
        LearningFeatureSnapshotRecord::query()->whereKey($snapshot->id)->update(['label_r_multiple' => 'NaN']);
        self::assertNull($this->service->trainModel());
        self::assertSame(0, LearningModelArtifactRecord::query()->count());
        LearningFeatureSnapshotRecord::query()->whereKey($snapshot->id)->update(['label_r_multiple' => '2.0000']);
        foreach (['Infinity', '-Infinity'] as $value) {
            try {
                DB::transaction(fn () => LearningFeatureSnapshotRecord::query()->whereKey($snapshot->id)->update(['label_r_multiple' => $value]));
                self::fail('Constrained NUMERIC must reject infinite labels.');
            } catch (QueryException $exception) {
                self::assertSame('22003', $exception->errorInfo[0]);
            }
        }
        self::assertNotNull($this->service->trainModel());
    }

    public function test_inference_rejects_missing_nonfinite_features_and_corrupt_scaling(): void
    {
        $artifact = LearningModelArtifactRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => LearningPredictorService::scopedModelName('TP_BEFORE_SL', 'BTCUSDT', 'bybit'),
            'model_type' => 'LOGISTIC_REGRESSION', 'target_event' => 'TP_BEFORE_SL',
            'version' => 'approved-v1', 'status' => 'ACTIVE',
            'parameters' => ['exchange' => 'bybit', 'approved_for_advisory' => true],
            'weights' => ['intercept' => 0.0, 'coefficients' => ['rsi_14' => 1.0], 'means' => ['rsi_14' => 50.0], 'stds' => ['rsi_14' => 10.0]],
            'metrics' => ['brier_score' => 0.2, 'ece' => 0.05], 'artifact_hash' => hash('sha256', 'approved-v1'),
        ]);
        foreach ([[], ['rsi_14' => '1e309'], ['rsi_14' => 'invalid'], ['rsi_14' => INF]] as $features) {
            try {
                $this->service->predictCalibrated('TP_BEFORE_SL', $features);
                self::fail('Inference must reject unavailable or nonfinite features.');
            } catch (\DomainException $exception) {
                self::assertSame('Model inference requires finite features and valid scaling.', $exception->getMessage());
            }
        }
        $weights = $artifact->weights;
        $artifact->update(['weights' => array_merge($weights, ['stds' => ['rsi_14' => 0]])]);
        try {
            $this->service->predictCalibrated('TP_BEFORE_SL', ['rsi_14' => 60.0]);
            self::fail('Inference must reject zero scaling.');
        } catch (\DomainException $exception) {
            self::assertSame('Model inference requires finite features and valid scaling.', $exception->getMessage());
        }
        $artifact->update(['weights' => $weights]);
        $this->assertSame('CALIBRATED', $this->service->predictCalibrated('TP_BEFORE_SL', ['rsi_14' => 60.0])['status']);
    }

    public function test_training_cohorts_never_mix_direction_category_strategy_or_version(): void
    {
        $snapshots = $this->seedValidCohort();
        $snapshot = $snapshots[0];
        foreach ([['side' => 'SELL'], ['category' => 'spot'], ['strategy_version' => '2.0.0'], ['label_policy_version' => 'LEGACY']] as $changes) {
            $quality = $snapshot->data_quality;
            $snapshot->update(['data_quality' => array_merge($quality, $changes)]);
            // Eleven rows in this cohort cannot be topped up with a different identity.
            $this->assertNull($this->service->trainModel());
            $snapshot->update(['data_quality' => $quality]);
        }
        foreach (['strategy_id' => 'trend_pullback', 'timeframe' => '5'] as $field => $value) {
            $original = $snapshot->{$field};
            $snapshot->update([$field => $value]);
            $this->assertNull($this->service->trainModel());
            $snapshot->update([$field => $original]);
        }
        $this->assertSame(0, LearningModelArtifactRecord::query()->count());
        $this->assertNotNull($this->service->trainModel());
    }

    /** @return list<LearningFeatureSnapshotRecord> */
    private function seedValidCohort(): array
    {
        $snapshots = [];
        $base = CarbonImmutable::parse('2026-10-18 00:00:00', 'UTC');
        for ($i = 0; $i < 12; $i++) {
            $positive = $i % 2 === 0;
            $snapshots[] = LearningFeatureSnapshotRecord::query()->create([
                'id' => (string) Str::uuid(), 'symbol' => 'BTCUSDT', 'timeframe' => '15',
                'decision_time' => $base->addHours($i * 3), 'available_at' => $base->addHours($i * 3),
                'feature_schema_version' => '1.0.0', 'strategy_id' => 'baseline', 'regime' => 'UNKNOWN',
                'features' => ['hl_spread_pct' => $positive ? 2.0 : 0.5, 'recent_volatility' => 0.5, 'rsi_14' => $positive ? 65.0 : 35.0, 'volume_ratio' => 1.0],
                'label_status' => 'MATURED', 'label_outcome' => $positive ? 'TP' : 'SL',
                'label_r_multiple' => $positive ? 2 : -1, 'outcome_at' => $base->addHours($i * 3)->addHour(),
                'data_quality' => ['exchange' => 'bybit', 'category' => 'linear', 'side' => 'BUY',
                    'strategy_version' => 'UNVERSIONED', 'label_policy_version' => 'SIGNAL_PROXY_V2', 'no_look_ahead_verified' => true],
            ]);
        }

        return $snapshots;
    }

    public function test_missing_exchange_provenance_is_not_assumed_bybit(): void
    {
        $base = CarbonImmutable::parse('2026-10-18 00:00:00');
        for ($i = 0; $i < 20; $i++) {
            LearningFeatureSnapshotRecord::query()->create([
                'id' => (string) Str::uuid(),
                'symbol' => 'BTCUSDT', 'timeframe' => '15',
                'decision_time' => $base->addHours($i * 3),
                'available_at' => $base->addHours($i * 3),
                'feature_schema_version' => '1.0.0',
                'strategy_id' => 'baseline', 'regime' => 'UNKNOWN',
                'features' => ['hl_spread_pct' => 1.0],
                'label_status' => 'MATURED',
                'label_outcome' => $i % 2 ? 'TP' : 'SL',
                'outcome_at' => $base->addHours($i * 3)->addMinutes(30),
                'data_quality' => ['no_look_ahead_verified' => true],
            ]);
        }
        $this->assertNull($this->service->trainModel('TP_BEFORE_SL', 'BTCUSDT', 'bybit'));
    }
}
