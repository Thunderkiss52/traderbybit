<?php

declare(strict_types=1);

namespace Tests\Unit\PaperTrading;

use App\PaperTrading\Application\PaperExecutionEngine;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\MarketBar;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Domain\SignalLifecycle;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ExecutionEngineTest extends TestCase
{
    public function test_entry_uses_side_specific_quote_and_slippage(): void
    {
        $engine = $this->engine();
        $bar = $this->bar(['bid' => '100.4', 'ask' => '100.5']);
        self::assertSame('100.520100000000', $engine->entry('BUY', '100', '101', '95', '105', '110', new DateTimeImmutable('2026-10-14T10:15:00Z'), new DateTimeImmutable('2026-10-14T10:45:00Z'), $bar)?->price);
        self::assertSame('100.379920000000', $engine->entry('SELL', '100', '101', '105', '95', '90', new DateTimeImmutable('2026-10-14T10:15:00Z'), new DateTimeImmutable('2026-10-14T10:45:00Z'), $bar)?->price);
    }

    public function test_ohlc_collision_is_ambiguous_and_stop_is_conservative_first(): void
    {
        $bar = $this->bar(['low' => '94', 'high' => '106']);
        $engine = $this->engine();
        $entry = $engine->entry('BUY', '100', '101', '95', '105', '110', new DateTimeImmutable('2026-10-14T10:15:00Z'), new DateTimeImmutable('2026-10-14T10:45:00Z'), $bar);
        self::assertTrue($entry?->ambiguous);
        $exit = $engine->exit('BUY', 'OPEN', '95', '105', '110', new DateTimeImmutable('2026-10-14T14:20:00Z'), $bar);
        self::assertSame(ExecutionType::StopLoss, $exit?->type);
        self::assertTrue($exit?->ambiguous);
    }

    public function test_fees_slippage_pnl_and_funding_use_decimal_arithmetic(): void
    {
        $engine = $this->engine();
        self::assertSame('0.05500', $engine->commission('10', '10'));
        self::assertSame('50', $engine->grossPnl('BUY', '100', '105', '10'));
        self::assertSame('50', $engine->grossPnl('SELL', '100', '95', '10'));
        self::assertSame('-10.00', $engine->funding('BUY', '10', '100', '0.01'));
        self::assertSame('10.00', $engine->funding('SELL', '10', '100', '0.01'));
    }

    public function test_state_machines_reject_impossible_transitions(): void
    {
        SignalLifecycle::PendingEntry->transitionTo(SignalLifecycle::Active);
        PositionStatus::Open->transitionTo(PositionStatus::PartiallyClosed);
        $this->expectException(DomainException::class);
        PositionStatus::ClosedTp->transitionTo(PositionStatus::Open);
    }

    public function test_execution_result_is_deterministic_and_risk_closure_is_explicit(): void
    {
        $first = $this->engine()->entry('BUY', '100', '101', '95', '105', '110', new DateTimeImmutable('2026-10-14T10:15:00Z'), new DateTimeImmutable('2026-10-14T10:45:00Z'), $this->bar());
        $second = $this->engine()->entry('BUY', '100', '101', '95', '105', '110', new DateTimeImmutable('2026-10-14T10:15:00Z'), new DateTimeImmutable('2026-10-14T10:45:00Z'), $this->bar());
        self::assertEquals($first, $second);
        self::assertSame(ExecutionType::RiskClosure, $this->engine()->riskClosure('BUY', '90', 'closed_5m_ohlc')->type);
    }

    private function engine(): PaperExecutionEngine
    {
        return new PaperExecutionEngine(require dirname(__DIR__, 3).'/config/paper.php');
    }

    private function bar(array $override = []): MarketBar
    {
        $v = array_replace(['low' => '100', 'high' => '102', 'close' => '101', 'bid' => null, 'ask' => null], $override);

        return new MarketBar(new DateTimeImmutable('2026-10-14T10:15:00Z'), new DateTimeImmutable('2026-10-14T10:20:00Z'), '101', $v['high'], $v['low'], $v['close'], true, $v['bid'], $v['ask']);
    }
}
