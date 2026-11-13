<?php

declare(strict_types=1);

namespace Tests\Unit\PaperTrading;

use App\PaperTrading\Application\TradeRiskClassificationService;
use App\PaperTrading\Domain\TradeRiskClass;
use Tests\TestCase;

final class TradeRiskClassificationTest extends TestCase
{
    private TradeRiskClassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TradeRiskClassificationService;
    }

    public function test_low_risk_classification(): void
    {
        $input = [
            'equity' => '10000.00',
            'entry_price' => '50000.00',
            'stop_loss' => '49500.00', // 1.0% distance (LOW)
            'take_profit_1' => '51500.00', // 3.0% gain
            'risk_capital' => '15.00', // 0.15% of equity (LOW)
            'notional' => '1500.00', // 0.15x leverage (LOW)
            'current_drawdown_percent' => '0.2', // LOW
            'open_positions_count' => 0, // LOW
            'atr' => '400.00', // 0.8% ATR (LOW)
            'bid' => '49995.00',
            'ask' => '50005.00', // spread 0.02% (LOW)
            'correlation' => '0.1', // LOW
            'estimated_costs' => '1.50', // 0.03 quantity gives 45 USDT TP1 gain.
            'estimated_cost_drag_percent' => 3.33333333,
        ];

        $result = $this->service->classify($input);

        $this->assertSame(TradeRiskClass::Low, $result['risk_class']);
        $this->assertLessThan(40, $result['risk_score']);
        $this->assertArrayHasKey('risk_equity_percent', $result['factors']);
        $this->assertArrayHasKey('stop_loss_distance_percent', $result['factors']);
        $this->assertArrayHasKey('notional_leverage', $result['factors']);
        $this->assertArrayHasKey('drawdown_percent', $result['factors']);
        $this->assertArrayHasKey('open_positions', $result['factors']);
        $this->assertArrayHasKey('volatility_atr_percent', $result['factors']);
        $this->assertArrayHasKey('spread_drag_percent', $result['factors']);
        $this->assertArrayHasKey('correlation', $result['factors']);
        $this->assertArrayHasKey('execution_cost_drag', $result['factors']);

        unset($input['estimated_cost_drag_percent']);
        $unverified = $this->service->classify($input);
        $this->assertNotSame(TradeRiskClass::Low, $unverified['risk_class']);
        $this->assertContains('execution_cost_drag', $unverified['missing_factors']);
        $this->assertNull($unverified['factors']['execution_cost_drag']['value']);
    }

    public function test_medium_risk_classification(): void
    {
        $input = [
            'equity' => '10000.00',
            'entry_price' => '50000.00',
            'stop_loss' => '49000.00', // 2.0% distance (MEDIUM)
            'take_profit_1' => '51500.00',
            'risk_capital' => '25.00', // 0.25% of equity (MEDIUM)
            'notional' => '8000.00', // 0.8x leverage (MEDIUM)
            'current_drawdown_percent' => '1.5', // MEDIUM
            'open_positions_count' => 1, // MEDIUM
            'atr' => '1200.00', // 2.4% (MEDIUM)
            'bid' => '49980.00',
            'ask' => '50005.00', // 0.05% (MEDIUM)
            'correlation' => '0.55', // MEDIUM
            'estimated_costs' => '6.00',
            'estimated_cost_drag_percent' => 2.5, // 0.16 quantity gives 240 USDT TP1 gain.
        ];

        $result = $this->service->classify($input);

        $this->assertSame(TradeRiskClass::Medium, $result['risk_class']);
        $this->assertGreaterThanOrEqual(40, $result['risk_score']);
        $this->assertLessThan(65, $result['risk_score']);
    }

    public function test_high_risk_classification_due_to_multiple_high_factors(): void
    {
        $input = [
            'equity' => '10000.00',
            'entry_price' => '50000.00',
            'stop_loss' => '46000.00', // 8.0% distance (HIGH)
            'take_profit_1' => '51000.00',
            'risk_capital' => '80.00', // 0.80% of equity (HIGH)
            'notional' => '20000.00', // 2.0x leverage (HIGH)
            'current_drawdown_percent' => '4.5', // 4.5% drawdown (HIGH)
            'open_positions_count' => 3, // HIGH
            'atr' => '2500.00', // 5.0% (HIGH)
            'bid' => '49900.00',
            'ask' => '50100.00', // 0.40% spread (HIGH)
            'correlation' => '0.85', // HIGH
            'estimated_costs' => '40.00',
            'estimated_cost_drag_percent' => 10.0, // 0.4 quantity gives 400 USDT TP1 gain.
        ];

        $result = $this->service->classify($input);

        $this->assertSame(TradeRiskClass::High, $result['risk_class']);
        $this->assertGreaterThanOrEqual(65, $result['risk_score']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_invalid_or_zero_equity_returns_unknown(): void
    {
        $result = $this->service->classify([
            'equity' => '0',
            'entry_price' => '50000',
            'stop_loss' => '49000',
        ]);

        $this->assertSame(TradeRiskClass::Unknown, $result['risk_class']);
        $this->assertContains('Equity must be positive.', $result['warnings']);
    }

    public function test_missing_critical_factors_prevent_low_risk_classification(): void
    {
        // Low stop-loss distance, low drawdown, 0 open positions, but missing ATR, spread, correlation, cost drag
        $result = $this->service->classify([
            'equity' => '10000.00',
            'entry_price' => '50000.00',
            'stop_loss' => '49500.00', // 1.0% (LOW)
            'risk_capital' => '15.00', // 0.15% (LOW)
            'notional' => '1500.00', // 0.15x (LOW)
            'current_drawdown_percent' => '0.2', // LOW
            'open_positions_count' => 0, // LOW
            // atr missing
            // bid/ask missing
            // correlation missing
            // estimated_costs missing
        ]);

        // A missing factor must NOT silently create an artificially favorable LOW risk score
        $this->assertNotSame(TradeRiskClass::Low, $result['risk_class']);
        $this->assertNotEmpty($result['missing_factors']);
        $this->assertContains('volatility_atr_percent', $result['missing_factors']);
        $this->assertContains('spread_drag_percent', $result['missing_factors']);
    }

    public function test_missing_essential_prices_returns_unknown_risk(): void
    {
        // Entry price missing or zero
        $result = $this->service->classify([
            'equity' => '10000.00',
            'entry_price' => '0',
            'stop_loss' => '49500.00',
        ]);

        $this->assertSame(TradeRiskClass::Unknown, $result['risk_class']);
        $this->assertContains('Valid entry and stop-loss prices are required.', $result['warnings']);
    }
}
