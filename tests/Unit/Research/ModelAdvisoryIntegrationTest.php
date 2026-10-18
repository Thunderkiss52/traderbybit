<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\LearningPredictorService;
use App\Research\Application\ModelAdvisoryService;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use App\Strategy\Domain\PricePlan;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ModelAdvisoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private AutonomyPermissionService $autonomy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autonomy = app(AutonomyPermissionService::class);
    }

    private function makeDecision(string $symbol, SignalSide $side): SignalDecision
    {
        $now = new DateTimeImmutable('2026-10-18T12:00:00Z');

        return new SignalDecision(
            id: (string) Str::uuid(),
            exchange: 'bybit',
            category: 'linear',
            symbol: $symbol,
            timeframe: '15',
            decisionTime: $now,
            candleCloseTime: $now,
            side: $side,
            qualityClass: QualityClass::A,
            score: 85,
            pricePlan: new PricePlan('65000', '65050', '64000', '67000', '68000', '1.5', '2.5'),
            modelRiskPercent: '0.25',
            expiresAt: $now->add(new DateInterval('PT30M')),
            maximumHoldingMinutes: 240,
            reasons: ['hourly_ema_trend', 'ema20_pullback'],
            risks: [],
            indicatorSnapshot: ['15m' => ['close' => '65000']],
            strategyVersion: '1.3.0',
            schemaVersion: '1.0.0'
        );
    }

    private function seedActiveModel(string $symbol, float $intercept = 0.0): LearningModelArtifactRecord
    {
        return LearningModelArtifactRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => "Logistic_TP_BEFORE_SL_{$symbol}",
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => 'TP_BEFORE_SL',
            'version' => 'v1.0.0',
            'parameters' => [
                'target_event' => 'TP_BEFORE_SL',
                'symbol' => $symbol,
                'approved_for_advisory' => true,
                'gate6_validation' => ['all_gates_passed' => true, 'policy_version' => '4.0.0'],
            ],
            'weights' => [
                'intercept' => $intercept,
                'coefficients' => [
                    'hl_spread_pct' => 1.5,
                    'recent_volatility' => 0.5,
                    'rsi_14' => 0.05,
                    'volume_ratio' => 0.8,
                ],
                'means' => [
                    'hl_spread_pct' => 1.5,
                    'recent_volatility' => 0.5,
                    'rsi_14' => 50.0,
                    'volume_ratio' => 1.0,
                ],
                'stds' => [
                    'hl_spread_pct' => 0.5,
                    'recent_volatility' => 0.2,
                    'rsi_14' => 10.0,
                    'volume_ratio' => 0.3,
                ],
            ],
            'metrics' => [
                'brier_score' => 0.18,
                'log_loss' => 0.52,
                'ece' => 0.05,
                'accuracy' => 0.72,
            ],
            'artifact_hash' => hash('sha256', "test-model-{$symbol}"),
            'status' => 'ACTIVE',
        ]);
    }

    public function test_shadow_mode_records_predictions_without_altering_trading_decisions(): void
    {
        $symbol = 'BTCUSDT';
        $model = $this->seedActiveModel($symbol, intercept: -2.5); // Very low prior -> low prob
        $decision = $this->makeDecision($symbol, SignalSide::Buy);

        $service = new ModelAdvisoryService(
            predictor: app(LearningPredictorService::class),
            autonomy: $this->autonomy,
            config: [
                'enabled' => true,
                'mode' => 'SHADOW',
                'target_event' => 'TP_BEFORE_SL',
                'minimum_calibrated_probability' => 0.40,
            ]
        );

        $advised = $service->advise($decision, ['hl_spread_pct' => 0.5, 'rsi_14' => 30.0]);

        // In SHADOW mode, BUY is NOT altered even if probability is low!
        $this->assertSame(SignalSide::Buy, $advised->side);
        $this->assertNotContains('ml_advisory_low_probability', $advised->reasons);

        $advisory = $advised->indicatorSnapshot['model_advisory'];
        $this->assertSame('SHADOW', $advisory['mode']);
        $this->assertSame('VETO_LOW_PROBABILITY', $advisory['recommendation']);
        $this->assertFalse($advisory['altered_decision']);
        $this->assertLessThan(0.40, $advisory['calibrated_probability']);

        // Check audit event
        $event = AutonomyApprovalEventRecord::query()->where('action', 'MODEL_ADVISORY_SHADOW')->first();
        $this->assertNotNull($event);
        $this->assertSame('BUY', $event->previous_state);
        $this->assertSame('BUY', $event->new_state);
    }

    public function test_active_mode_vetoes_low_probability_trades_to_skip(): void
    {
        $symbol = 'BTCUSDT';
        $model = $this->seedActiveModel($symbol, intercept: -3.0); // Low prob
        $decision = $this->makeDecision($symbol, SignalSide::Buy);

        $service = new ModelAdvisoryService(
            predictor: app(LearningPredictorService::class),
            autonomy: $this->autonomy,
            config: [
                'enabled' => true,
                'mode' => 'ACTIVE',
                'target_event' => 'TP_BEFORE_SL',
                'minimum_calibrated_probability' => 0.40,
            ]
        );

        $advised = $service->advise($decision, ['hl_spread_pct' => 0.5, 'rsi_14' => 30.0]);

        // In ACTIVE mode with low prob, BUY is transformed to SKIP!
        $this->assertSame(SignalSide::Skip, $advised->side);
        $this->assertContains('ml_advisory_low_probability', $advised->reasons);
        $this->assertNull($advised->qualityClass);
        $this->assertNull($advised->pricePlan);

        $advisory = $advised->indicatorSnapshot['model_advisory'];
        $this->assertSame('ACTIVE', $advisory['mode']);
        $this->assertTrue($advisory['altered_decision']);
        $this->assertSame('VETO_LOW_PROBABILITY', $advisory['recommendation']);

        // Check audit event
        $event = AutonomyApprovalEventRecord::query()->where('action', 'MODEL_ADVISORY_VETO')->first();
        $this->assertNotNull($event);
        $this->assertSame('BUY', $event->previous_state);
        $this->assertSame('SKIP', $event->new_state);
    }

    public function test_active_mode_allows_high_probability_trades(): void
    {
        $symbol = 'BTCUSDT';
        $model = $this->seedActiveModel($symbol, intercept: 1.5); // High prob
        $decision = $this->makeDecision($symbol, SignalSide::Buy);

        $service = new ModelAdvisoryService(
            predictor: app(LearningPredictorService::class),
            autonomy: $this->autonomy,
            config: [
                'enabled' => true,
                'mode' => 'ACTIVE',
                'target_event' => 'TP_BEFORE_SL',
                'minimum_calibrated_probability' => 0.40,
            ]
        );

        $advised = $service->advise($decision, ['hl_spread_pct' => 2.5, 'rsi_14' => 60.0]);

        // High probability trade is permitted to proceed
        $this->assertSame(SignalSide::Buy, $advised->side);
        $this->assertSame(QualityClass::A, $advised->qualityClass);
        $this->assertNotNull($advised->pricePlan);
        $this->assertNotContains('ml_advisory_low_probability', $advised->reasons);

        $advisory = $advised->indicatorSnapshot['model_advisory'];
        $this->assertSame('ALLOW', $advisory['recommendation']);
        $this->assertFalse($advisory['altered_decision']);
        $this->assertGreaterThanOrEqual(0.40, $advisory['calibrated_probability']);

        $event = AutonomyApprovalEventRecord::query()->where('action', 'MODEL_ADVISORY_PASSED')->first();
        $this->assertNotNull($event);
    }
}
