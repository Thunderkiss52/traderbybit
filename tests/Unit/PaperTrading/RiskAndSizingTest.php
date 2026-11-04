<?php

declare(strict_types=1);

namespace Tests\Unit\PaperTrading;

use App\PaperTrading\Application\CorrelationService;
use App\PaperTrading\Application\PositionSizingService;
use App\PaperTrading\Application\RiskEngine;
use App\PaperTrading\Domain\InstrumentConstraints;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RiskAndSizingTest extends TestCase
{
    public function test_position_size_includes_fees_slippage_and_rounds_down_to_qty_step(): void
    {
        $result = (new PositionSizingService($this->config()))->calculate('10000', '0.50', '100', '95', new InstrumentConstraints('0.1', '0.1', '1000', '5'));

        self::assertTrue($result->allowed);
        self::assertSame('9.7', $result->quantity);
        self::assertSame('50.000000000000000000000000', $result->riskCapital);
        self::assertSame('970.0', $result->notional);
    }

    public function test_position_size_rejects_minimum_notional_and_exposure_limit(): void
    {
        $service = new PositionSizingService($this->config());
        self::assertSame('below_minimum_order', $service->calculate('100', '0.10', '10', '1', new InstrumentConstraints('1', '1', '100', '50'))->reason);

        $config = $this->config();
        $config['portfolio']['maximum_notional_percent'] = '1';
        self::assertSame('notional_limit_exceeded', (new PositionSizingService($config))->calculate('10000', '0.50', '100', '99', new InstrumentConstraints('0.001', '0.001', '1000', '5'))->reason);
    }

    #[DataProvider('riskFailures')]
    public function test_risk_engine_rejects_portfolio_guards(array $override, string $reason): void
    {
        $input = array_replace($this->baseRiskInput(), $override);
        self::assertContains($reason, (new RiskEngine($this->config()))->assess($input)->reasons);
    }

    public static function riskFailures(): array
    {
        return [
            'zero equity' => [['equity' => '0'], 'insufficient_free_capital'],
            'negative equity' => [['equity' => '-1'], 'insufficient_free_capital'],
            'exhausted capital' => [['free_capital' => '-0.000000000001'], 'insufficient_free_capital'],
            'class A excess' => [['risk_percent' => '0.500000000001'], 'signal_risk_above_class_limit'],
            'class B excess' => [['quality_class' => 'B', 'risk_percent' => '0.250000000001'], 'signal_risk_above_class_limit'],
            'class C excess' => [['quality_class' => 'C', 'risk_percent' => '0.100000000001'], 'signal_risk_above_class_limit'],
            'notional excess' => [['notional_after' => '20000.000000000001'], 'portfolio_notional_limit'],
            'zero risk' => [['risk_percent' => '0'], 'invalid_signal_risk'],
            'negative risk' => [['risk_percent' => '-0.50'], 'invalid_signal_risk'],
            'maximum positions' => [['open_positions' => 3], 'maximum_positions_reached'],
            'portfolio risk' => [['total_risk_percent' => '1.25', 'risk_percent' => '0.50'], 'portfolio_risk_limit'],
            'negative open risk' => [['total_risk_percent' => '-500'], 'invalid_total_risk_percent'],
            'negative drawdown' => [['drawdown_percent' => '-1'], 'invalid_drawdown_percent'],
            'unknown quality' => [['quality_class' => 'Z'], 'invalid_signal_quality_class'],
            'correlation' => [['same_direction_correlated_exposure' => true, 'correlation' => '0.85'], 'correlation_limit'],
            'missing correlation' => [['same_direction_correlated_exposure' => true, 'correlation' => null], 'correlation_data_insufficient'],
            'drawdown block' => [['drawdown_percent' => '5'], 'drawdown_entry_block'],
            'drawdown halt' => [['drawdown_percent' => '10'], 'drawdown_halt'],
            'cooldown' => [['cooldown_until' => new DateTimeImmutable('2026-10-14T11:00:00Z')], 'losing_streak_cooldown'],
            'market safety' => [['market_failures' => ['market_data_stale']], 'market_data_stale'],
        ];
    }

    #[DataProvider('allowedBoundaries')]
    public function test_exact_risk_boundaries_remain_allowed(array $override): void
    {
        $result = (new RiskEngine($this->config()))->assess(array_replace($this->baseRiskInput(), $override));
        self::assertTrue($result->allowed, implode(', ', $result->reasons));
        self::assertSame([], $result->reasons);
    }

    public static function allowedBoundaries(): array
    {
        return [
            'A limit' => [['risk_percent' => '0.50']],
            'B limit' => [['quality_class' => 'B', 'risk_percent' => '0.25']],
            'C limit' => [['quality_class' => 'C', 'risk_percent' => '0.10']],
            'portfolio risk limit' => [['total_risk_percent' => '1.00', 'risk_percent' => '0.50']],
            'portfolio notional limit' => [['notional_after' => '20000']],
            'position below limit' => [['open_positions' => 2]],
            'drawdown below block' => [['drawdown_percent' => '4.999999999999']],
            'cooldown expires now' => [['cooldown_until' => new DateTimeImmutable('2026-10-14T10:00:00Z')]],
        ];
    }

    public function test_pearson_correlation_is_deterministic(): void
    {
        $service = new CorrelationService($this->config());
        self::assertSame('1.000000000000', $service->pearson(['1', '2', '3', '4'], ['2', '4', '6', '8']));
        self::assertSame('-1.000000000000', $service->pearson(['1', '2', '3', '4'], ['8', '6', '4', '2']));
        self::assertNull($service->pearson(['1', '1'], ['2', '2']));
    }

    public function test_config_cannot_relax_paper_risk_ceiling(): void
    {
        $config = $this->config();
        $config['risk_by_quality'] = ['A' => '25', 'B' => '25', 'C' => '25'];
        $config['portfolio']['maximum_total_risk_percent'] = '50';
        $config['portfolio']['maximum_notional_percent'] = '1000';
        $config['drawdown'] = ['block_new_entries_percent' => '50', 'halt_percent' => '100'];
        $risk = new RiskEngine($config);
        self::assertContains('signal_risk_above_class_limit', $risk->assess(array_replace($this->baseRiskInput(), ['risk_percent' => '0.51']))->reasons);
        self::assertContains('portfolio_risk_limit', $risk->assess(array_replace($this->baseRiskInput(), ['total_risk_percent' => '1.25']))->reasons);
        self::assertContains('portfolio_notional_limit', $risk->assess(array_replace($this->baseRiskInput(), ['notional_after' => '20001']))->reasons);
        self::assertContains('drawdown_entry_block', $risk->assess(array_replace($this->baseRiskInput(), ['drawdown_percent' => '5']))->reasons);
        self::assertContains('drawdown_halt', $risk->assess(array_replace($this->baseRiskInput(), ['drawdown_percent' => '10']))->reasons);
    }

    public function test_sizing_rejects_unsafe_inputs_even_when_config_is_relaxed(): void
    {
        $config = $this->config();
        $config['portfolio']['maximum_notional_percent'] = '1000';
        $config['portfolio']['maximum_model_leverage'] = '10';
        $sizing = new PositionSizingService($config);
        $limits = new InstrumentConstraints('0.001', '0.001', '1000', '5');
        self::assertSame('invalid_sizing_inputs', $sizing->calculate('10000', '0.51', '100', '99', $limits)->reason);
        self::assertSame('invalid_sizing_inputs', $sizing->calculate('0', '0.5', '100', '99', $limits)->reason);
        self::assertSame('invalid_sizing_inputs', $sizing->calculate('10000', '0.5', '0', '99', $limits)->reason);
        self::assertSame('notional_limit_exceeded', $sizing->calculate('10000', '0.5', '100', '99.99', $limits)->reason);
    }

    private function baseRiskInput(): array
    {
        return [
            'account_status' => 'ACTIVE', 'cooldown_until' => null, 'at' => new DateTimeImmutable('2026-10-14T10:00:00Z'),
            'open_positions' => 0, 'equity' => '10000', 'free_capital' => '10000', 'risk_percent' => '0.50', 'quality_class' => 'A',
            'total_risk_percent' => '0', 'drawdown_percent' => '0', 'market_failures' => [],
            'same_direction_correlated_exposure' => false, 'correlation' => null, 'notional_after' => '1000',
        ];
    }

    private function config(): array
    {
        return require dirname(__DIR__, 3).'/config/paper.php';
    }
}
