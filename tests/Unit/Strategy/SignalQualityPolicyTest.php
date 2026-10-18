<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use App\Strategy\Application\SignalQualityPolicyService;
use App\Strategy\Domain\PricePlan;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SignalQualityPolicyTest extends TestCase
{
    private SignalQualityPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SignalQualityPolicyService;
    }

    public function test_pure_skip_decision_without_plan_is_graded_unrated_and_not_eligible(): void
    {
        $decision = new SignalDecision(
            id: 'test-skip-id',
            symbol: 'BTCUSDT',
            side: SignalSide::Skip,
            qualityClass: null,
            score: 0,
            decisionTime: new DateTimeImmutable('2026-10-18T12:00:00Z'),
            candleCloseTime: new DateTimeImmutable('2026-10-18T12:00:00Z'),
            expiresAt: new DateTimeImmutable('2026-10-18T12:30:00Z'),
            maximumHoldingMinutes: 240,
            pricePlan: null,
            modelRiskPercent: null,
            reasons: ['market_context_stale'],
            risks: [],
            indicatorSnapshot: [],
            exchange: 'bybit',
            category: 'linear',
            timeframe: '15',
            strategyVersion: '1.2.0',
            schemaVersion: '1.0.0',
        );

        $result = $this->service->evaluate($decision);

        self::assertSame(QualityClass::Unrated->value, $result['quality_grade']);
        self::assertSame('NOT_ELIGIBLE', $result['eligibility_status']);
        self::assertSame('market_context_stale', $result['rejection_reason']);
        self::assertSame(0.0, $result['expected_net_r']);
    }

    public function test_skip_with_candidate_plan_preserves_technical_grade_with_skipped_status(): void
    {
        $plan = new PricePlan(
            entryLow: '100',
            entryHigh: '101',
            stopLoss: '95',
            takeProfit1: '110',
            takeProfit2: '115',
            riskReward1: '1.8',
            riskReward2: '2.8',
        );

        $decision = new SignalDecision(
            id: 'test-skip-candidate',
            symbol: 'BTCUSDT',
            side: SignalSide::Skip,
            qualityClass: null,
            score: 85,
            decisionTime: new DateTimeImmutable('2026-10-18T12:00:00Z'),
            candleCloseTime: new DateTimeImmutable('2026-10-18T12:00:00Z'),
            expiresAt: new DateTimeImmutable('2026-10-18T12:30:00Z'),
            maximumHoldingMinutes: 240,
            pricePlan: $plan,
            modelRiskPercent: '0.50',
            reasons: ['trend_filter_failed'],
            risks: [],
            indicatorSnapshot: [],
            exchange: 'bybit',
            category: 'linear',
            timeframe: '15',
            strategyVersion: '1.2.0',
            schemaVersion: '1.0.0',
        );

        $result = $this->service->evaluate($decision, [
            'expected_net_r' => 0.40,
            'uncertainty' => 0.05,
            'sample_size' => 50,
        ]);

        // Quality grade evaluates technical opportunity (Grade A: conservativeNetR = 0.40 - 1.645*0.05 = 0.317 >= 0.30 && score 85 >= 80)
        self::assertSame(QualityClass::A->value, $result['quality_grade']);
        // Direction is SKIP and eligibility is SKIPPED
        self::assertSame(SignalSide::Skip->value, $result['direction']);
        self::assertSame('SKIPPED', $result['eligibility_status']);
        self::assertSame('trend_filter_failed', $result['rejection_reason']);
    }

    public function test_grade_a_requires_both_conservative_expectancy_and_score(): void
    {
        $plan = new PricePlan(
            entryLow: '100',
            entryHigh: '101',
            stopLoss: '95',
            takeProfit1: '110',
            takeProfit2: '115',
            riskReward1: '1.8',
            riskReward2: '2.8',
        );

        $decision = new SignalDecision(
            id: 'test-buy-a',
            symbol: 'BTCUSDT',
            side: SignalSide::Buy,
            qualityClass: QualityClass::A,
            score: 88,
            decisionTime: new DateTimeImmutable('2026-10-18T12:00:00Z'),
            candleCloseTime: new DateTimeImmutable('2026-10-18T12:00:00Z'),
            expiresAt: new DateTimeImmutable('2026-10-18T12:30:00Z'),
            maximumHoldingMinutes: 240,
            pricePlan: $plan,
            modelRiskPercent: '0.50',
            reasons: ['trend_alignment'],
            risks: [],
            indicatorSnapshot: [],
            exchange: 'bybit',
            category: 'linear',
            timeframe: '15',
            strategyVersion: '1.2.0',
            schemaVersion: '1.0.0',
        );

        // Case 1: High score (88) and high conservative expectancy (0.45 - 1.645*0.05 = 0.367 >= 0.30) -> Grade A
        $resultA = $this->service->evaluate($decision, [
            'expected_net_r' => 0.45,
            'uncertainty' => 0.05,
            'sample_size' => 50,
        ]);
        self::assertSame(QualityClass::A->value, $resultA['quality_grade']);
        self::assertSame('ELIGIBLE', $resultA['eligibility_status']);
        self::assertNull($resultA['rejection_reason']);

        // Case 2: High score (88) but negative expectancy (-0.20) -> CANNOT be Grade A (must be Grade E)
        $resultNegative = $this->service->evaluate($decision, [
            'expected_net_r' => -0.20,
            'uncertainty' => 0.05,
            'sample_size' => 50,
        ]);
        self::assertSame(QualityClass::E->value, $resultNegative['quality_grade']);
        self::assertSame('SUB_PAR', $resultNegative['eligibility_status']);
    }

    public function test_uncertainty_reduces_conservative_expectancy(): void
    {
        $plan = new PricePlan(
            entryLow: '100',
            entryHigh: '101',
            stopLoss: '95',
            takeProfit1: '110',
            takeProfit2: '115',
            riskReward1: '1.8',
            riskReward2: '2.8',
        );

        $decision = new SignalDecision(
            id: 'test-buy-uncertainty',
            symbol: 'BTCUSDT',
            side: SignalSide::Buy,
            qualityClass: QualityClass::A,
            score: 82,
            decisionTime: new DateTimeImmutable('2026-10-18T12:00:00Z'),
            candleCloseTime: new DateTimeImmutable('2026-10-18T12:00:00Z'),
            expiresAt: new DateTimeImmutable('2026-10-18T12:30:00Z'),
            maximumHoldingMinutes: 240,
            pricePlan: $plan,
            modelRiskPercent: '0.50',
            reasons: ['trend'],
            risks: [],
            indicatorSnapshot: [],
            exchange: 'bybit',
            category: 'linear',
            timeframe: '15',
            strategyVersion: '1.2.0',
            schemaVersion: '1.0.0',
        );

        // Low uncertainty (0.02): conservativeNetR = 0.35 - 1.645*0.02 = 0.317 >= 0.30 -> Grade A
        $resLowUncertainty = $this->service->evaluate($decision, [
            'expected_net_r' => 0.35,
            'uncertainty' => 0.02,
            'sample_size' => 50,
        ]);
        self::assertSame(QualityClass::A->value, $resLowUncertainty['quality_grade']);

        // High uncertainty (0.10): conservativeNetR = 0.35 - 1.645*0.10 = 0.1855 < 0.30 -> Downgraded to Grade B
        $resHighUncertainty = $this->service->evaluate($decision, [
            'expected_net_r' => 0.35,
            'uncertainty' => 0.10,
            'sample_size' => 50,
        ]);
        self::assertSame(QualityClass::B->value, $resHighUncertainty['quality_grade']);
    }

    public function test_small_sample_size_yields_unrated(): void
    {
        $plan = new PricePlan(
            entryLow: '100',
            entryHigh: '101',
            stopLoss: '95',
            takeProfit1: '110',
            takeProfit2: '115',
            riskReward1: '1.8',
            riskReward2: '2.8',
        );

        $decision = new SignalDecision(
            id: 'test-buy-small-sample',
            symbol: 'BTCUSDT',
            side: SignalSide::Buy,
            qualityClass: QualityClass::A,
            score: 90,
            decisionTime: new DateTimeImmutable('2026-10-18T12:00:00Z'),
            candleCloseTime: new DateTimeImmutable('2026-10-18T12:00:00Z'),
            expiresAt: new DateTimeImmutable('2026-10-18T12:30:00Z'),
            maximumHoldingMinutes: 240,
            pricePlan: $plan,
            modelRiskPercent: '0.50',
            reasons: ['trend'],
            risks: [],
            indicatorSnapshot: [],
            exchange: 'bybit',
            category: 'linear',
            timeframe: '15',
            strategyVersion: '1.2.0',
            schemaVersion: '1.0.0',
        );

        $result = $this->service->evaluate($decision, ['sample_size' => 4]);
        self::assertSame(QualityClass::Unrated->value, $result['quality_grade']);
        self::assertSame('INSUFFICIENT_SAMPLE', $result['eligibility_status']);
    }
}
