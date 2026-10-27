<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Domain\ExecutionQuote;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\PositionSize;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PositionLifecycleStageBTest extends TestCase
{
    use RefreshDatabase;

    public function test_tp1_zero_quantity_is_safely_skipped_without_creating_execution(): void
    {
        $lifecycle = app(PositionLifecycleService::class);
        $account = app(PaperAccountService::class)->default();
        $account->update(['exchange_id' => 'bybit', 'is_primary' => true, 'status' => 'ACTIVE']);

        $signal = $this->createSignal('bybit', 'BTCUSDT', 'BUY');
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'status' => 'PENDING_ENTRY',
        ]);

        $openTime = new DateTimeImmutable('2026-10-27T10:00:00Z');
        // Initial quantity = 0.001, step = 0.001, tp1_fraction = 0.50 -> raw tp1 quantity floors to 0
        $size = new PositionSize(true, '0.001', '100', '10', '100', qtyStep: '0.001');
        $entryQuote = new ExecutionQuote(ExecutionType::Entry, '60000', '60000', '0', 'test', false);

        $position = $lifecycle->open($account, $state, $signal, $size, $entryQuote, $openTime);
        self::assertSame('0.001000000000', (string) $position->initial_quantity);
        self::assertSame('0.001000000000', (string) $position->remaining_quantity);
        self::assertSame(PositionStatus::Open->value, $position->status);

        // Attempt TP1 exit: 50% of 0.001 at step 0.001 floors to 0.000
        $tp1Quote = new ExecutionQuote(ExecutionType::TakeProfit1, '61000', '61000', '0', 'test', false);
        $tp1Time = new DateTimeImmutable('2026-10-27T10:05:00Z');
        $updated = $lifecycle->executeExit($position, $tp1Quote, $tp1Time);

        // Must remain OPEN, remaining quantity unchanged, ZERO TakeProfit1 executions created
        self::assertSame(PositionStatus::Open->value, $updated->status);
        self::assertSame('0.001000000000', (string) $updated->remaining_quantity);
        self::assertFalse(VirtualExecutionRecord::query()->where('type', ExecutionType::TakeProfit1->value)->exists());
        self::assertSame(1, VirtualExecutionRecord::query()->where('position_id', $position->id)->count()); // Only entry

        // Now full exit via StopLoss: should execute cleanly
        $slQuote = new ExecutionQuote(ExecutionType::StopLoss, '59000', '59000', '0', 'test', false);
        $slTime = new DateTimeImmutable('2026-10-27T10:10:00Z');
        $closed = $lifecycle->executeExit($updated, $slQuote, $slTime);

        self::assertSame(PositionStatus::ClosedSl->value, $closed->status);
        self::assertSame('0.000000000000', (string) $closed->remaining_quantity);
        self::assertSame(2, VirtualExecutionRecord::query()->where('position_id', $position->id)->count());
        $slExecution = VirtualExecutionRecord::query()->where('position_id', $position->id)->where('type', ExecutionType::StopLoss->value)->first();
        self::assertNotNull($slExecution);
        self::assertSame('0.001000000000', (string) $slExecution->quantity);
    }

    public function test_standard_tp1_and_tp2_execute_correct_fractions_and_preserve_identities(): void
    {
        $lifecycle = app(PositionLifecycleService::class);
        $account = app(PaperAccountService::class)->default();
        $account->update(['exchange_id' => 'bybit', 'is_primary' => true, 'status' => 'ACTIVE']);

        $signal = $this->createSignal('bybit', 'BTCUSDT', 'BUY');
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'status' => 'PENDING_ENTRY',
        ]);

        $openTime = new DateTimeImmutable('2026-10-27T10:00:00Z');
        // Initial quantity = 0.010, step = 0.001, tp1_fraction = 0.50 -> 0.005 TP1, 0.005 remaining
        $size = new PositionSize(true, '0.010', '100', '10', '600', qtyStep: '0.001');
        $entryQuote = new ExecutionQuote(ExecutionType::Entry, '60000', '60000', '0', 'test', false);

        $position = $lifecycle->open($account, $state, $signal, $size, $entryQuote, $openTime);

        // TP1 execution
        $tp1Quote = new ExecutionQuote(ExecutionType::TakeProfit1, '62000', '62000', '0', 'test', false);
        $partiallyClosed = $lifecycle->executeExit($position, $tp1Quote, new DateTimeImmutable('2026-10-27T10:05:00Z'));

        self::assertSame(PositionStatus::PartiallyClosed->value, $partiallyClosed->status);
        self::assertSame('0.005000000000', (string) $partiallyClosed->remaining_quantity);

        // TP2 execution
        $tp2Quote = new ExecutionQuote(ExecutionType::TakeProfit2, '64000', '64000', '0', 'test', false);
        $fullyClosed = $lifecycle->executeExit($partiallyClosed, $tp2Quote, new DateTimeImmutable('2026-10-27T10:10:00Z'));

        self::assertSame(PositionStatus::ClosedTp->value, $fullyClosed->status);
        self::assertSame('0.000000000000', (string) $fullyClosed->remaining_quantity);
        self::assertSame(3, VirtualExecutionRecord::query()->where('position_id', $position->id)->count());
    }

    public function test_late_arriving_funding_settles_on_closed_position_with_correct_historical_quantity(): void
    {
        $lifecycle = app(PositionLifecycleService::class);
        $account = app(PaperAccountService::class)->default();
        $account->update(['exchange_id' => 'bybit', 'is_primary' => true, 'status' => 'ACTIVE']);
        $startBal = BigDecimal::of($account->starting_balance);

        $signal = $this->createSignal('bybit', 'BTCUSDT', 'BUY');
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'status' => 'PENDING_ENTRY',
        ]);

        // Position opens at 23:45 UTC on Oct 26
        $openTime = new DateTimeImmutable('2026-10-26T23:45:00Z');
        $size = new PositionSize(true, '0.010', '100', '10', '600', qtyStep: '0.001');
        $entryQuote = new ExecutionQuote(ExecutionType::Entry, '60000', '60000', '0', 'test', false);

        $position = $lifecycle->open($account, $state, $signal, $size, $entryQuote, $openTime);

        // Position takes partial TP1 (0.005) at 23:55 UTC
        $tp1Quote = new ExecutionQuote(ExecutionType::TakeProfit1, '61000', '61000', '0', 'test', false);
        $position = $lifecycle->executeExit($position, $tp1Quote, new DateTimeImmutable('2026-10-26T23:55:00Z'));

        // Funding boundary is at 2026-10-27 00:00:00 UTC (at this moment, remaining quantity held was 0.005)

        // Position hits StopLoss at 00:10 UTC on Oct 27
        $slQuote = new ExecutionQuote(ExecutionType::StopLoss, '59000', '59000', '0', 'test', false);
        $closed = $lifecycle->executeExit($position, $slQuote, new DateTimeImmutable('2026-10-27T00:10:00Z'));
        self::assertSame(PositionStatus::ClosedSl->value, $closed->status);

        // At 00:10 UTC, funding for 00:00:00 has NOT arrived yet
        self::assertFalse($closed->funding_complete);
        self::assertSame('0.000000000000', (string) $closed->funding);
        $netBeforeFunding = BigDecimal::of($closed->net_pnl);

        // Now, at 00:11:08 UTC, Bybit funding event for 00:00:00 is ingested
        $mfe = MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'funding_at' => new DateTimeImmutable('2026-10-27T00:00:00Z'),
            'rate' => '0.000100000000', // +0.01%
            'mark_price' => '60000.000000000000',
        ]);

        // Reconcile pending funding
        $appliedCount = $lifecycle->reconcilePendingFunding('bybit', 'BTCUSDT', new DateTimeImmutable('2026-10-26T00:00:00Z'));
        self::assertSame(1, $appliedCount);

        // Check PositionFundingEventRecord
        $pfe = PositionFundingEventRecord::query()->where('position_id', $closed->id)->first();
        self::assertNotNull($pfe);
        // Quantity held at 00:00 UTC was 0.005000000000 (after TP1 partial exit!)
        self::assertSame('0.005000000000', (string) $pfe->quantity);
        // Amount for BUY: -(quantity * mark_price * rate) = -(0.005 * 60000 * 0.0001) = -0.03
        self::assertSame('-0.030000000000', (string) $pfe->amount);

        // Check position updated
        $closedFresh = $closed->fresh();
        self::assertSame('-0.030000000000', (string) $closedFresh->funding);
        self::assertTrue(BigDecimal::of($closedFresh->net_pnl)->isEqualTo($netBeforeFunding->minus('0.03')));
        // Position status MUST remain ClosedSl (never reopen!)
        self::assertSame(PositionStatus::ClosedSl->value, $closedFresh->status);
        // Now funding_complete MUST be true!
        self::assertTrue($closedFresh->funding_complete);

        // Check financial accounting invariant: equity = starting_balance + realized_pnl + unrealized_pnl
        $accountFresh = $account->fresh();
        $expectedEquity = $startBal->plus($accountFresh->realized_pnl)->plus($accountFresh->unrealized_pnl);
        self::assertTrue(BigDecimal::of($accountFresh->current_equity)->isEqualTo($expectedEquity));

        // Re-run reconcilePendingFunding: strictly IDEMPOTENT (no new events applied)
        $secondRunCount = $lifecycle->reconcilePendingFunding('bybit', 'BTCUSDT', new DateTimeImmutable('2026-10-26T00:00:00Z'));
        self::assertSame(0, $secondRunCount);
        self::assertSame(1, PositionFundingEventRecord::query()->where('position_id', $closed->id)->count());
    }

    public function test_funding_completeness_evaluates_correctly_for_zero_settlement_lifetime(): void
    {
        $lifecycle = app(PositionLifecycleService::class);
        $account = app(PaperAccountService::class)->default();
        $account->update(['exchange_id' => 'bybit', 'is_primary' => true, 'status' => 'ACTIVE']);

        // Insert a recent funding event to establish alive funding watermark
        MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'funding_at' => new DateTimeImmutable('2026-10-27T08:00:00Z'),
            'rate' => '0.0001',
            'mark_price' => '60000',
        ]);

        $signal = $this->createSignal('bybit', 'BTCUSDT', 'BUY');
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'status' => 'PENDING_ENTRY',
        ]);

        // Position opens at 10:15 and closes at 10:45 (no 8h boundary: 00:00, 08:00, 16:00 falls in between)
        $openTime = new DateTimeImmutable('2026-10-27T10:15:00Z');
        $size = new PositionSize(true, '0.010', '100', '10', '600', qtyStep: '0.001');
        $entryQuote = new ExecutionQuote(ExecutionType::Entry, '60000', '60000', '0', 'test', false);

        $position = $lifecycle->open($account, $state, $signal, $size, $entryQuote, $openTime);
        self::assertFalse($position->funding_complete); // OPEN positions are false

        $slQuote = new ExecutionQuote(ExecutionType::StopLoss, '59000', '59000', '0', 'test', false);
        $closed = $lifecycle->executeExit($position, $slQuote, new DateTimeImmutable('2026-10-27T10:45:00Z'));

        // Closed position with provably zero expected settlements is immediately verified funding complete
        self::assertTrue($closed->fresh()->funding_complete);
    }

    private function createSignal(string $exchange, string $symbol, string $side): SignalRecord
    {
        $at = new DateTimeImmutable('2026-10-26T23:30:00Z');

        return SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'side' => $side,
            'quality_class' => 'A',
            'score' => 90,
            'timeframe' => '15',
            'entry_low' => '59900',
            'entry_high' => '60100',
            'stop_loss' => '59000',
            'take_profit_1' => '61000',
            'take_profit_2' => '63000',
            'risk_reward_1' => '1.1',
            'risk_reward_2' => '3.3',
            'decision_time' => $at,
            'candle_close_time' => $at,
            'expires_at' => $at->modify('+24 hours'),
            'maximum_holding_minutes' => 720,
            'model_risk_percent' => '0.50',
            'reasons' => ['test_fixture'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['close' => '60000']],
            'contract' => ['research_mode' => true],
            'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
    }
}
