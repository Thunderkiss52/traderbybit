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
            'zero risk' => [['risk_percent' => '0'], 'invalid_signal_risk'],
            'negative risk' => [['risk_percent' => '-0.50'], 'invalid_signal_risk'],
            'maximum positions' => [['open_positions' => 3], 'maximum_positions_reached'],
            'portfolio risk' => [['total_risk_percent' => '1.25', 'risk_percent' => '0.50'], 'portfolio_risk_limit'],
            'correlation' => [['same_direction_correlated_exposure' => true, 'correlation' => '0.85'], 'correlation_limit'],
            'missing correlation' => [['same_direction_correlated_exposure' => true, 'correlation' => null], 'correlation_data_insufficient'],
            'drawdown block' => [['drawdown_percent' => '5'], 'drawdown_entry_block'],
            'drawdown halt' => [['drawdown_percent' => '10'], 'drawdown_halt'],
            'cooldown' => [['cooldown_until' => new DateTimeImmutable('2026-10-14T11:00:00Z')], 'losing_streak_cooldown'],
            'market safety' => [['market_failures' => ['market_data_stale']], 'market_data_stale'],
        ];
    }

    public function test_pearson_correlation_is_deterministic(): void
    {
        $service = new CorrelationService($this->config());
        self::assertSame('1.000000000000', $service->pearson(['1', '2', '3', '4'], ['2', '4', '6', '8']));
        self::assertSame('-1.000000000000', $service->pearson(['1', '2', '3', '4'], ['8', '6', '4', '2']));
        self::assertNull($service->pearson(['1', '1'], ['2', '2']));
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
