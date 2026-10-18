<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Research\Application\LearningPredictorService;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class LearningPredictorRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_rollback_restores_previous_champion(): void
    {
        $service = $this->app->make(LearningPredictorService::class);

        // Create initial champion (v1)
        $v1 = LearningModelArtifactRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'name' => 'Logistic_TP_BEFORE_SL_BTCUSDT',
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => 'TP_BEFORE_SL',
            'version' => '1.0.0',
            'parameters' => ['features' => ['hl_spread_pct']],
            'weights' => ['intercept' => 0.2, 'coefficients' => ['hl_spread_pct' => 0.5]],
            'metrics' => ['brier_score' => 0.22, 'accuracy' => 0.65],
            'artifact_hash' => hash('sha256', 'v1'),
            'status' => 'SUPERSEDED',
            'created_at' => CarbonImmutable::now()->subHours(2),
        ]);

        // Create newly promoted champion (v2)
        $v2 = LearningModelArtifactRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'name' => 'Logistic_TP_BEFORE_SL_BTCUSDT',
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => 'TP_BEFORE_SL',
            'version' => '2.0.0',
            'parameters' => ['features' => ['hl_spread_pct']],
            'weights' => ['intercept' => 0.3, 'coefficients' => ['hl_spread_pct' => 0.8]],
            'metrics' => ['brier_score' => 0.21, 'accuracy' => 0.68],
            'artifact_hash' => hash('sha256', 'v2'),
            'status' => 'ACTIVE',
            'created_at' => CarbonImmutable::now()->subHour(),
        ]);

        // Predict with v2 active
        $predictionBefore = $service->predictCalibrated('TP_BEFORE_SL', ['hl_spread_pct' => 1.2]);
        self::assertSame('CALIBRATED', $predictionBefore['status']);
        self::assertSame('2.0.0', $predictionBefore['model_version']);

        // Rollback v2
        $restored = $service->rollbackModel(
            targetEvent: 'TP_BEFORE_SL',
            symbol: 'BTCUSDT',
            reason: 'Holdout degradation in production'
        );

        self::assertNotNull($restored);
        self::assertSame($v1->id, $restored->id);
        self::assertSame('ACTIVE', $v1->refresh()->status);
        self::assertSame('ROLLED_BACK', $v2->refresh()->status);

        // Predict with v1 restored
        $predictionAfter = $service->predictCalibrated('TP_BEFORE_SL', ['hl_spread_pct' => 1.2]);
        self::assertSame('CALIBRATED', $predictionAfter['status']);
        self::assertSame('1.0.0', $predictionAfter['model_version']);
    }

    public function test_model_rollback_without_previous_champion_gracefully_retires(): void
    {
        $service = $this->app->make(LearningPredictorService::class);

        $v1 = LearningModelArtifactRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'name' => 'Logistic_TP_BEFORE_SL_BTCUSDT',
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => 'TP_BEFORE_SL',
            'version' => '1.0.0',
            'parameters' => ['features' => ['hl_spread_pct']],
            'weights' => ['intercept' => 0.2],
            'metrics' => ['brier_score' => 0.22],
            'artifact_hash' => hash('sha256', 'solo'),
            'status' => 'ACTIVE',
        ]);

        $restored = $service->rollbackModel(
            targetEvent: 'TP_BEFORE_SL',
            symbol: 'BTCUSDT',
            reason: 'Degradation with no prior model'
        );

        self::assertNull($restored);
        self::assertSame('ROLLED_BACK', $v1->refresh()->status);

        $fallback = $service->predictCalibrated('TP_BEFORE_SL', ['hl_spread_pct' => 1.0]);
        self::assertSame('INSUFFICIENT_DATA', $fallback['status']);
        self::assertNull($fallback['probability']);
    }
}
