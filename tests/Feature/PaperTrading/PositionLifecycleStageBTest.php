<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperExecutionEligibilityService;
use App\PaperTrading\Application\PaperExecutionEngine;
use App\PaperTrading\Application\PerformanceStatisticsService;
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
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\SeedsValidatedPaperEvidence;
use Tests\TestCase;

final class PositionLifecycleStageBTest extends TestCase
{
    use RefreshDatabase;
    use SeedsValidatedPaperEvidence;

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

        $archivedEvidence = DB::table('virtual_positions')->where('paper_account_id', '!=', $account->id)->orderBy('id')->get()->toJson();

        $position = $lifecycle->open($account, $state, $signal, $size, $entryQuote, $openTime);

        // TP1 execution
        $tp1Quote = new ExecutionQuote(ExecutionType::TakeProfit1, '62000', '62000', '0', 'test', false);
        $partiallyClosed = $lifecycle->executeExit($position, $tp1Quote, new DateTimeImmutable('2026-10-27T10:05:00Z'));

        self::assertSame(PositionStatus::PartiallyClosed->value, $partiallyClosed->status);
        self::assertSame('0.005000000000', (string) $partiallyClosed->remaining_quantity);
        $partialMetrics = app(PerformanceStatisticsService::class)->calculate($account->id)->metrics;
        self::assertSame(0, $partialMetrics['closed_positions']);
        self::assertNull($partialMetrics['win_rate']);
        self::assertNull($partialMetrics['profit_factor']);
        self::assertNull($partialMetrics['average_r']);
        self::assertDecimalSame('0', $partialMetrics['net_pnl']);
        self::assertDecimalSame((string) $partiallyClosed->fees, $partialMetrics['open_position_fees']);
        self::assertDecimalSame('10', (string) $partiallyClosed->gross_pnl);
        self::assertDecimalSame((string) BigDecimal::of('10')->minus($partiallyClosed->fees)->plus($partiallyClosed->funding), (string) $partiallyClosed->net_pnl);
        $lifecycle->markToMarket($partiallyClosed, '63000', new DateTimeImmutable('2026-10-27T10:06:00Z'));
        self::assertDecimalSame('15', (string) $partiallyClosed->fresh()->unrealized_pnl);
        self::assertDecimalSame('9.4995', (string) $account->fresh()->realized_pnl);
        self::assertDecimalSame('15', (string) $account->fresh()->unrealized_pnl);

        // TP2 execution
        $tp2Quote = new ExecutionQuote(ExecutionType::TakeProfit2, '64000', '64000', '0', 'test', false);
        $fullyClosed = $lifecycle->executeExit($partiallyClosed, $tp2Quote, new DateTimeImmutable('2026-10-27T10:10:00Z'));

        self::assertSame(PositionStatus::ClosedTp->value, $fullyClosed->status);
        self::assertSame('0.000000000000', (string) $fullyClosed->remaining_quantity);
        self::assertSame(3, VirtualExecutionRecord::query()->where('position_id', $position->id)->count());
        $closedMetrics = app(PerformanceStatisticsService::class)->calculate($account->id)->metrics;
        self::assertSame(1, $closedMetrics['closed_positions']);
        self::assertSame(1, $closedMetrics['winning_trades']);
        self::assertDecimalSame('100', $closedMetrics['win_rate']);
        self::assertDecimalSame((string) $fullyClosed->net_pnl, $closedMetrics['net_pnl']);
        self::assertDecimalSame((string) $fullyClosed->fees, $closedMetrics['fees']);
        self::assertDecimalSame('0', $closedMetrics['open_position_fees']);
        self::assertDecimalSame('0', (string) $fullyClosed->unrealized_pnl);
        self::assertSame($archivedEvidence, DB::table('virtual_positions')->where('paper_account_id', '!=', $account->id)->orderBy('id')->get()->toJson());
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
        $appliedCount = $lifecycle->reconcilePendingFunding('bybit', 'BTCUSDT', new DateTimeImmutable('2026-10-27T00:00:00Z'));
        self::assertSame(1, $appliedCount);

        $fundingSnapshot = DB::table('equity_snapshots')->where('paper_account_id', $account->id)
            ->where('source_event_key', 'funding:'.$closed->id.':'.$mfe->id)->first();
        self::assertNotNull($fundingSnapshot);
        self::assertGreaterThanOrEqual($closed->closed_at->getTimestamp(), (new DateTimeImmutable($fundingSnapshot->recorded_at))->getTimestamp(),
            'A late funding adjustment must not insert current equity into a past performance timestamp.');

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
        $secondRunCount = $lifecycle->reconcilePendingFunding('bybit', 'BTCUSDT', new DateTimeImmutable('2026-10-27T00:00:00Z'));
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

    public function test_out_of_order_funding_preserves_prior_settlements_and_execution_history(): void
    {
        $lifecycle = app(PositionLifecycleService::class);
        $account = app(PaperAccountService::class)->default();
        $signal = $this->createSignal('bybit', 'BTCUSDT', 'BUY');
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'paper_account_id' => $account->id,
            'signal_id' => $signal->id, 'status' => 'PENDING_ENTRY',
        ]);
        $position = $lifecycle->open($account, $state, $signal,
            new PositionSize(true, '0.010', '100', '10', '600', qtyStep: '0.001'),
            new ExecutionQuote(ExecutionType::Entry, '60000', '60000', '0', 'fixture', false),
            new DateTimeImmutable('2026-10-27T07:45:00Z'));
        $position = $lifecycle->executeExit($position,
            new ExecutionQuote(ExecutionType::TakeProfit1, '61000', '61000', '0', 'fixture', false),
            new DateTimeImmutable('2026-10-27T15:55:00Z'));
        $position = $lifecycle->executeExit($position,
            new ExecutionQuote(ExecutionType::StopLoss, '59000', '59000', '0', 'fixture', false),
            new DateTimeImmutable('2026-10-27T16:10:00Z'));
        $executions = DB::table('virtual_executions')->orderBy('id')->get()->toJson();
        $netBefore = $position->net_pnl;
        foreach (['16:00' => '0.005', '08:00' => '0.010'] as $time => $quantity) {
            $existing = DB::table('position_funding_events')->orderBy('id')->get();
            $event = MarketFundingEventRecord::query()->create([
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
                'funding_at' => new DateTimeImmutable("2026-10-27T{$time}:00Z"),
                'rate' => '0.0001', 'mark_price' => '60000',
            ]);
            $lifecycle->applyFunding($position->fresh(), new DateTimeImmutable('2026-10-27T17:00:00Z'));
            $settlement = PositionFundingEventRecord::query()->where('market_funding_event_id', $event->id)->sole();
            self::assertDecimalSame($quantity, (string) $settlement->quantity);
            foreach ($existing as $row) {
                self::assertEquals($row, DB::table('position_funding_events')->where('id', $row->id)->sole());
            }
        }
        $beforeRetry = DB::table('position_funding_events')->orderBy('id')->get()->toJson();
        $lifecycle->applyFunding($position->fresh(), new DateTimeImmutable('2026-10-27T17:00:00Z'));
        self::assertSame($beforeRetry, DB::table('position_funding_events')->orderBy('id')->get()->toJson());
        self::assertSame($executions, DB::table('virtual_executions')->orderBy('id')->get()->toJson());
        self::assertSame(2, PositionFundingEventRecord::query()->count());
        self::assertDecimalSame('-0.09', (string) $position->fresh()->funding);
        self::assertDecimalSame((string) BigDecimal::of($netBefore)->minus('0.09'), (string) $position->fresh()->net_pnl);
        self::assertSame(PositionStatus::ClosedSl->value, $position->fresh()->status);
        self::assertDecimalSame((string) BigDecimal::of($account->starting_balance)->plus($account->fresh()->realized_pnl), (string) $account->fresh()->current_equity);
    }

    #[DataProvider('ineligibleEvidenceCohorts')]
    public function test_final_entry_rejects_out_of_scope_or_unavailable_evidence(string $case): void
    {
        $account = app(PaperAccountService::class)->default();
        $signal = $this->createSignal('bybit', 'BTCUSDT', 'BUY', seedEvidence: false);
        $cohort = $signal->replicate();
        $asOf = $signal->decision_time->toDateTimeImmutable();
        $latest = null;
        $available = null;
        $restated = null;
        match ($case) {
            'exchange' => $cohort->exchange = 'binance',
            'strategy' => $cohort->strategy_id = 'trend_pullback',
            'symbol' => $cohort->symbol = 'ETHUSDT',
            'direction' => $cohort->side = 'SELL',
            'version' => $cohort->strategy_version = 'unrelated-version',
            'timeframe' => $cohort->timeframe = '5',
            'future_close' => $latest = $asOf->modify('+1 hour'),
            'stale' => $latest = $asOf->modify('-31 days'),
            'late_creation' => $available = $asOf->modify('+1 second'),
            'late_restatement' => $restated = $asOf->modify('+1 second'),
            'none', 'incomplete_funding' => null,
        };
        if ($case !== 'none') {
            $archive = $this->seedValidatedPaperEvidence($cohort, $latest, $available, $restated, fundingComplete: $case !== 'incomplete_funding');
            if ($case === 'incomplete_funding') {
                self::assertTrue(BigDecimal::of((string) $archive->realized_pnl)->isPositive());
                self::assertSame(10, DB::table('virtual_positions')->where('paper_account_id', $archive->id)->where('funding_complete', false)->count());
            }
        }
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'paper_account_id' => $account->id,
            'signal_id' => $signal->id, 'status' => 'PENDING_ENTRY',
        ]);
        $before = DB::table('virtual_positions')->orderBy('id')->get()->toJson();
        try {
            app(PaperExecutionEligibilityService::class)->assertOpenEligible($account, $state, $signal, $asOf->modify('+5 minutes'));
            self::fail('Out-of-scope evidence authorized a PAPER entry.');
        } catch (DomainException $exception) {
            self::assertSame('Entry ineligible: missing_or_stale_validated_edge_or_cost_evidence', $exception->getMessage());
        }
        self::assertSame($before, DB::table('virtual_positions')->orderBy('id')->get()->toJson());
        self::assertSame(0, DB::table('virtual_positions')->where('paper_account_id', $account->id)->count());
        self::assertDecimalSame('0', (string) $account->fresh()->realized_pnl);
    }

    public static function ineligibleEvidenceCohorts(): array
    {
        return array_map(fn (string $case): array => [$case], [
            'none', 'exchange', 'strategy', 'symbol', 'direction', 'version', 'timeframe',
            'future_close', 'stale', 'late_creation', 'late_restatement', 'incomplete_funding',
        ]);
    }

    public function test_final_entry_accepts_matching_minute_alias_and_preserves_archived_evidence(): void
    {
        $account = app(PaperAccountService::class)->default();
        $signal = $this->createSignal('bybit', 'BTCUSDT', 'BUY', seedEvidence: false);
        $cohort = $signal->replicate();
        $cohort->timeframe = '15m';
        $archive = $this->seedValidatedPaperEvidence($cohort);
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'paper_account_id' => $account->id,
            'signal_id' => $signal->id, 'status' => 'PENDING_ENTRY',
        ]);
        $before = DB::table('virtual_positions')->where('paper_account_id', $archive->id)->orderBy('id')->get()->toJson();
        app(PaperExecutionEligibilityService::class)->assertOpenEligible($account, $state, $signal, $signal->decision_time->toDateTimeImmutable());
        self::assertSame($before, DB::table('virtual_positions')->where('paper_account_id', $archive->id)->orderBy('id')->get()->toJson());
        self::assertDecimalSame((string) BigDecimal::of($archive->starting_balance)->plus($archive->realized_pnl), (string) $archive->current_equity);
        self::assertDecimalSame((string) $archive->realized_pnl, (string) DB::table('virtual_positions')->where('paper_account_id', $archive->id)->sum('net_pnl'));
        foreach (DB::table('virtual_positions')->where('paper_account_id', $archive->id)->get() as $prior) {
            self::assertDecimalSame((string) BigDecimal::of($prior->gross_pnl)->minus($prior->fees)->plus($prior->funding), $prior->net_pnl);
        }
    }

    public function test_final_entry_rejects_missing_or_excessive_tp1_cost_evidence(): void
    {
        $account = app(PaperAccountService::class)->default();
        $signal = $this->createSignal('bybit', 'BTCUSDT', 'BUY');
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'paper_account_id' => $account->id,
            'signal_id' => $signal->id, 'status' => 'PENDING_ENTRY',
        ]);
        foreach (['60100' => 'missing_or_stale_validated_edge_or_cost_evidence', '60100.01' => 'execution_cost_drag_exceeds_edge'] as $target => $reason) {
            // Explicit risk classification keeps this control focused on the final cost gate.
            $signal->forceFill(['take_profit_1' => (string) $target, 'trade_risk' => 'LOW'])->save();
            try {
                app(PaperExecutionEligibilityService::class)->assertOpenEligible($account, $state, $signal, $signal->decision_time->toDateTimeImmutable());
                self::fail('Missing or excessive costs authorized a PAPER entry.');
            } catch (DomainException $exception) {
                self::assertSame('Entry ineligible: '.$reason, $exception->getMessage());
            }
        }
    }

    public function test_round_trip_tp1_cost_estimate_uses_fee_and_slippage_once_and_is_quantity_invariant(): void
    {
        $engine = app(PaperExecutionEngine::class);
        $large = $engine->estimateRoundTripTp1Costs('101', '105', '10');
        $small = $engine->estimateRoundTripTp1Costs('101', '105', '0.001');
        self::assertDecimalSame('1.545', $large['estimated_costs']);
        self::assertDecimalSame('0.0001545', $small['estimated_costs']);
        self::assertSame(3.8625, $large['cost_drag_percent']);
        self::assertSame($large['cost_drag_percent'], $small['cost_drag_percent']);
    }

    private function createSignal(string $exchange, string $symbol, string $side, bool $seedEvidence = true): SignalRecord
    {
        $at = new DateTimeImmutable('2026-10-26T23:30:00Z');

        $signal = SignalRecord::query()->create([
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
        if ($seedEvidence && in_array($signal->side, ['BUY', 'SELL'], true)) {
            $this->seedValidatedPaperEvidence($signal);
        }

        return $signal;
    }
}
