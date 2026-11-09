<?php

declare(strict_types=1);

namespace Tests\Unit\PaperTrading;

use App\PaperTrading\Application\PreExecutionQualityRiskGate;
use App\PaperTrading\Domain\QualityRiskGateDecision;
use App\PaperTrading\Domain\TradeRiskClass;
use App\Strategy\Domain\SignalQuality;
use Tests\TestCase;

final class PreExecutionQualityRiskGateTest extends TestCase
{
    private PreExecutionQualityRiskGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new PreExecutionQualityRiskGate;
    }

    public function test_high_quality_low_risk_is_eligible(): void
    {
        $eval = $this->gate->evaluate([
            'signal_quality' => SignalQuality::High,
            'trade_risk' => TradeRiskClass::Low,
        ]);

        $this->assertSame(QualityRiskGateDecision::Eligible, $eval['decision']);
        $this->assertTrue($eval['allowed']);
        $this->assertEmpty($eval['rejection_reasons']);
        $this->assertSame('HIGH_LOW', $eval['quality_risk_pair']);
    }

    public function test_high_quality_medium_risk_with_positive_edge(): void
    {
        $eval = $this->gate->evaluate([
            'signal_quality' => SignalQuality::High,
            'trade_risk' => TradeRiskClass::Medium,
            'expected_net_edge' => 4.5,
        ]);

        $this->assertSame(QualityRiskGateDecision::Eligible, $eval['decision']);
        $this->assertTrue($eval['allowed']);
        $this->assertEmpty($eval['rejection_reasons']);
    }

    public function test_high_quality_medium_risk_with_negative_edge_is_rejected(): void
    {
        $eval = $this->gate->evaluate([
            'signal_quality' => SignalQuality::High,
            'trade_risk' => TradeRiskClass::Medium,
            'expected_net_edge' => -1.2,
        ]);

        $this->assertSame(QualityRiskGateDecision::Rejected, $eval['decision']);
        $this->assertFalse($eval['allowed']);
        $this->assertContains('high_quality_medium_risk_negative_edge', $eval['rejection_reasons']);
    }

    public function test_high_risk_is_always_rejected(): void
    {
        $evalHighQuality = $this->gate->evaluate([
            'signal_quality' => SignalQuality::High,
            'trade_risk' => TradeRiskClass::High,
        ]);

        $this->assertSame(QualityRiskGateDecision::Rejected, $evalHighQuality['decision']);
        $this->assertFalse($evalHighQuality['allowed']);
        $this->assertContains('high_risk_trade_rejected', $evalHighQuality['rejection_reasons']);

        $evalMedQuality = $this->gate->evaluate([
            'signal_quality' => SignalQuality::Medium,
            'trade_risk' => TradeRiskClass::High,
        ]);

        $this->assertSame(QualityRiskGateDecision::Rejected, $evalMedQuality['decision']);
        $this->assertFalse($evalMedQuality['allowed']);
        $this->assertContains('high_risk_trade_rejected', $evalMedQuality['rejection_reasons']);
    }

    public function test_low_quality_is_observation_only(): void
    {
        $eval = $this->gate->evaluate([
            'signal_quality' => SignalQuality::Low,
            'trade_risk' => TradeRiskClass::Low,
        ]);

        $this->assertSame(QualityRiskGateDecision::ObservationOnly, $eval['decision']);
        $this->assertFalse($eval['allowed']);
        $this->assertContains('low_signal_quality_observation_only', $eval['rejection_reasons']);
    }

    public function test_unknown_quality_or_risk_is_rejected(): void
    {
        $eval = $this->gate->evaluate([
            'signal_quality' => SignalQuality::Unknown,
            'trade_risk' => TradeRiskClass::Low,
        ]);

        $this->assertSame(QualityRiskGateDecision::Rejected, $eval['decision']);
        $this->assertFalse($eval['allowed']);
        $this->assertContains('unknown_quality_or_risk', $eval['rejection_reasons']);

        $eval2 = $this->gate->evaluate([
            'signal_quality' => SignalQuality::High,
            'trade_risk' => TradeRiskClass::Unknown,
        ]);

        $this->assertSame(QualityRiskGateDecision::Rejected, $eval2['decision']);
        $this->assertFalse($eval2['allowed']);
        $this->assertContains('unknown_quality_or_risk', $eval2['rejection_reasons']);
    }

    public function test_medium_quality_low_risk_conditionally_eligible(): void
    {
        $eval = $this->gate->evaluate([
            'signal_quality' => SignalQuality::Medium,
            'trade_risk' => TradeRiskClass::Low,
            'drawdown_percent' => 0.5,
        ]);

        $this->assertSame(QualityRiskGateDecision::ConditionallyEligible, $eval['decision']);
        $this->assertTrue($eval['allowed']);
    }

    public function test_medium_quality_low_risk_blocked_by_drawdown(): void
    {
        $eval = $this->gate->evaluate([
            'signal_quality' => SignalQuality::Medium,
            'trade_risk' => TradeRiskClass::Low,
            'drawdown_percent' => 3.2, // >= 2.50
        ]);

        $this->assertSame(QualityRiskGateDecision::Rejected, $eval['decision']);
        $this->assertFalse($eval['allowed']);
        $this->assertContains('medium_quality_drawdown_limit', $eval['rejection_reasons']);
    }

    public function test_directional_negative_expectancy_rejection(): void
    {
        $eval = $this->gate->evaluate([
            'signal_quality' => SignalQuality::High,
            'trade_risk' => TradeRiskClass::Low,
            'side' => 'SELL',
            'directional_sample_size' => 15,
            'directional_win_rate' => 0.20, // < 0.30
            'directional_profit_factor' => 0.40, // < 0.60
        ]);

        $this->assertSame(QualityRiskGateDecision::Rejected, $eval['decision']);
        $this->assertFalse($eval['allowed']);
        $this->assertContains('directional_negative_expectancy_SELL', $eval['rejection_reasons']);
    }
}
