<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\PaperTrading\Application\GlobalPaperCapitalGuard;
use App\PaperTrading\Application\PaperExecutionEngine;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Application\PaperSizingSettings;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Application\PositionSizingService;
use App\PaperTrading\Domain\ExecutionQuote;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\InstrumentConstraints;
use App\PaperTrading\Domain\PositionSize;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
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

/** Synthetic isolated boundary controls, never actual PAPER return evidence. */
final class FinalEntrySizingBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsValidatedPaperEvidence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new DateTimeImmutable('2026-11-09T12:05:00Z'));
        config(['paper.global_capital.enabled' => true]);
    }

    public static function invalidSizes(): iterable
    {
        yield 'oversized' => [['quantity' => '0.040', 'notional' => '2000', 'allocatedMargin' => '2000'], 'supplied_position_size_mismatch'];
        yield 'forged notional' => [['quantity' => '0.040'], 'supplied_position_size_mismatch'];
        yield 'zero risk' => [['riskCapital' => '0'], 'invalid_supplied_position_size'];
        yield 'understated risk' => [['riskCapital' => '0.0001'], 'supplied_position_size_mismatch'];
        yield 'not allowed' => [['allowed' => false], 'supplied_position_size_not_allowed'];
        yield 'negative quantity' => [['quantity' => '-0.0001'], 'invalid_supplied_position_size'];
        yield 'nonfinite quantity' => [['quantity' => 'NaN'], 'invalid_supplied_position_size'];
        yield 'exponential quantity' => [['quantity' => '1e100'], 'invalid_supplied_position_size'];
        yield 'missing leverage' => [['leverage' => null], 'invalid_supplied_position_size'];
        yield 'forged leverage' => [['leverage' => '2'], 'supplied_position_size_mismatch'];
        yield 'forged margin' => [['allocatedMargin' => '1'], 'supplied_position_size_mismatch'];
        yield 'wrong lot step' => [['qtyStep' => '0.001'], 'supplied_position_size_mismatch'];
    }

    #[DataProvider('invalidSizes')]
    public function test_forged_sizes_reject_atomically_and_cannot_pass_on_retry(array $changes, string $reason): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture();
        $size = new PositionSize(...array_replace([
            'allowed' => $size->allowed, 'quantity' => $size->quantity, 'riskCapital' => $size->riskCapital,
            'effectiveLossPerUnit' => $size->effectiveLossPerUnit, 'notional' => $size->notional,
            'qtyStep' => $size->qtyStep, 'allocatedMargin' => $size->allocatedMargin, 'leverage' => $size->leverage,
        ], $changes));
        $before = $this->financialState();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
                self::fail('Forged sizing opened exposure.');
            } catch (DomainException $exception) {
                self::assertSame('Entry ineligible: '.$reason, $exception->getMessage());
            }
            self::assertSame($before, $this->financialState());
        }
    }

    public static function ownerModes(): iterable
    {
        yield 'null uses fixed five' => [null];
        yield 'explicit fixed five' => [['mode' => 'fixed_notional', 'amount' => '5']];
        yield 'explicit fixed two thousand' => [['mode' => 'fixed_notional', 'amount' => '2000']];
        yield 'fixed margin' => [['mode' => 'fixed_margin', 'amount' => '1000', 'leverage' => '2']];
        yield 'explicit risk percent' => [['mode' => 'risk_percent']];
    }

    #[DataProvider('ownerModes')]
    public function test_canonical_owner_modes_open_with_exact_accounting(?array $settings): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture($settings);
        $position = app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
        self::assertDecimalSame((string) $size->notional, $position->sizing_snapshot['actual_notional']);
        self::assertDecimalSame((string) $size->notional, (string) BigDecimal::of($position->initial_quantity)->multipliedBy($position->entry_price));
        self::assertDecimalSame($size->riskCapital, $account->refresh()->reserved_risk);
        self::assertDecimalSame((string) BigDecimal::of($position->fees)->negated(), $account->realized_pnl);
        self::assertDecimalSame((string) BigDecimal::of('10000')->minus($position->fees), $account->current_equity);
        self::assertSame($settings, $account->sizing_settings);
        if ($settings === null) {
            self::assertSame('fixed_notional', $position->sizing_snapshot['sizing_mode']);
            self::assertSame('5', $position->sizing_snapshot['requested_notional']);
            self::assertDecimalSame('5', $position->sizing_snapshot['actual_notional']);
        }
    }

    public function test_owner_settings_change_after_size_calculation_rejects_stale_size(): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture(['mode' => 'fixed_notional', 'amount' => '2000']);
        PaperAccountRecord::query()->whereKey($account->id)->update(['sizing_settings' => ['mode' => 'fixed_notional', 'amount' => '5']]);
        $before = $this->financialState();
        try {
            app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
            self::fail('Stale owner settings opened exposure.');
        } catch (DomainException $exception) {
            self::assertSame('Entry ineligible: supplied_position_size_mismatch', $exception->getMessage());
        }
        self::assertSame($before, $this->financialState());
    }

    public static function invalidMetadata(): iterable
    {
        yield 'wrong exchange' => [['exchange' => 'binance'], 'instrument_constraints_unavailable_as_of_execution'];
        yield 'wrong symbol' => [['symbol' => 'ETHUSDT'], 'instrument_constraints_unavailable_as_of_execution'];
        yield 'wrong category' => [['category' => 'spot'], 'instrument_constraints_unavailable_as_of_execution'];
        yield 'future observation' => [['observed_at' => '2026-11-09T12:05:01Z'], 'instrument_constraints_unavailable_as_of_execution'];
        yield 'late import' => [['created_at' => '2026-11-09T12:05:01Z'], 'instrument_constraints_unavailable_as_of_execution'];
        yield 'later revision' => [['updated_at' => '2026-11-09T12:05:01Z'], 'instrument_constraints_unavailable_as_of_execution'];
        yield 'stale' => [['observed_at' => '2026-11-09T11:59:59Z'], 'instrument_constraints_stale'];
        yield 'missing quantity step' => [['qty_step' => null], 'invalid_instrument_constraints'];
        yield 'zero price tick' => [['tick_size' => '0'], 'invalid_instrument_constraints'];
        yield 'below minimum must not upsize' => [['min_notional_value' => '6'], 'below_minimum_notional_order'];
    }

    #[DataProvider('invalidMetadata')]
    public function test_unavailable_or_invalid_execution_constraints_reject_atomically(array $changes, string $reason): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture();
        DB::table('market_context_snapshots')->where('exchange', 'bybit')->update($changes);
        $before = $this->financialState();
        try {
            app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
            self::fail('Unavailable execution constraints opened exposure.');
        } catch (DomainException $exception) {
            self::assertSame('Entry ineligible: '.$reason, $exception->getMessage());
        }
        self::assertSame($before, $this->financialState());
    }

    public function test_execution_metadata_after_decision_but_before_fill_is_available(): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture();
        DB::table('market_context_snapshots')->update([
            'observed_at' => '2026-11-09T12:04:00Z', 'created_at' => '2026-11-09T12:04:01Z', 'updated_at' => '2026-11-09T12:04:01Z',
        ]);
        $position = app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
        self::assertDecimalSame('5', $position->sizing_snapshot['actual_notional']);
    }

    public function test_actual_quote_recalculation_rejects_reference_price_size(): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture();
        $quote = new ExecutionQuote(ExecutionType::Entry, '50010', '50000', '10', 'SYNTHETIC_TEST_ONLY', false);
        $before = $this->financialState();
        try {
            app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
            self::fail('Reference-price size opened at another actual quote.');
        } catch (DomainException $exception) {
            self::assertSame('Entry ineligible: supplied_position_size_mismatch', $exception->getMessage());
        }
        self::assertSame($before, $this->financialState());
    }

    public static function invalidQuotes(): iterable
    {
        yield 'noncompliant tick' => ['50000.05', 'entry_price_tick_mismatch'];
        yield 'zero price' => ['0', 'invalid_entry_quote'];
        yield 'negative price' => ['-1', 'invalid_entry_quote'];
        yield 'nonfinite price' => ['NaN', 'invalid_entry_quote'];
    }

    #[DataProvider('invalidQuotes')]
    public function test_invalid_actual_quotes_reject_without_rounding_or_financial_mutation(string $price, string $reason): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture();
        $quote = new ExecutionQuote(ExecutionType::Entry, $price, '50000', '0', 'SYNTHETIC_TEST_ONLY', false);
        if ($reason === 'entry_price_tick_mismatch') {
            $size = app(PositionSizingService::class)->calculate($account->current_equity, $signal->model_risk_percent, $price, $signal->stop_loss,
                new InstrumentConstraints('0.000001', '0.000001', '1000', '1'), app(PaperExecutionEngine::class)->costs('bybit'), PaperSizingSettings::effectiveForAccount($account->sizing_settings));
            self::assertTrue($size->allowed);
        }
        $before = $this->financialState();
        try {
            app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
            self::fail('Invalid quote opened exposure.');
        } catch (DomainException $exception) {
            self::assertSame('Entry ineligible: '.$reason, $exception->getMessage());
        }
        self::assertSame($before, $this->financialState());
        self::assertSame($price, $quote->price);
    }

    public function test_historical_position_retry_and_exit_survive_owner_size_and_entry_block_changes(): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture(['mode' => 'fixed_notional', 'amount' => '2000']);
        $lifecycle = app(PositionLifecycleService::class);
        $position = $lifecycle->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
        $original = $position->getAttributes();
        $account->update(['sizing_settings' => ['mode' => 'fixed_notional', 'amount' => '5'], 'status' => 'BLOCKED', 'current_drawdown_percent' => '5']);
        $before = $this->financialState();
        $retry = $lifecycle->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
        self::assertSame($original, $retry->getAttributes());
        self::assertSame($before, $this->financialState());
        $exit = new ExecutionQuote(ExecutionType::StopLoss, '49500', '49500', '0', 'SYNTHETIC_TEST_ONLY', false);
        $closed = $lifecycle->executeExit($position, $exit, now()->addMinute()->toDateTimeImmutable());
        self::assertSame('CLOSED_SL', $closed->status);
        self::assertSame($original['sizing_snapshot'], $closed->getRawOriginal('sizing_snapshot'));
        self::assertDecimalSame('0', $account->refresh()->reserved_risk);
        $after = $this->financialState();
        $lifecycle->executeExit($position, $exit, now()->addMinute()->toDateTimeImmutable());
        self::assertSame($after, $this->financialState());
        self::assertDecimalSame((string) BigDecimal::of($closed->gross_pnl)->plus($closed->funding)->minus($closed->fees), $closed->net_pnl);
    }

    public static function entrySides(): iterable
    {
        yield 'BUY' => ['BUY', '50011.1'];
        yield 'SELL' => ['SELL', '49990.9'];
    }

    #[DataProvider('entrySides')]
    public function test_processing_null_settings_uses_fixed_five_after_adverse_tick_rounding(string $side, string $expectedPrice): void
    {
        [$account, $state, $signal] = $this->fixture(null);
        $signal->update(['side' => $side, 'entry_low' => '50001', 'entry_high' => '50001',
            'stop_loss' => $side === 'BUY' ? '49500' : '50500',
            'take_profit_1' => $side === 'BUY' ? '51500' : '48500', 'take_profit_2' => $side === 'BUY' ? '53000' : '47000']);
        if ($side === 'SELL') {
            $this->seedValidatedPaperEvidence($signal, $signal->decision_time->toDateTimeImmutable()->modify('-2 days')->setTime(12, 5));
        }
        DB::table('market_candles')->insert([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '5',
            'open_time' => '2026-11-09T12:00:00Z', 'open' => '50000', 'high' => '50100', 'low' => '49900', 'close' => '50050',
            'volume' => '100', 'turnover' => '5000000', 'is_closed' => true, 'created_at' => '2026-11-09T12:05:00Z', 'updated_at' => '2026-11-09T12:05:00Z',
        ]);
        app(PaperProcessingService::class)->process($account->id, 'BTCUSDT', now()->toDateTimeImmutable());
        $position = VirtualPositionRecord::query()->where('signal_id', $signal->id)->firstOrFail();
        self::assertSame('fixed_notional', $position->sizing_snapshot['sizing_mode']);
        self::assertSame('5', $position->sizing_snapshot['requested_notional']);
        self::assertTrue(BigDecimal::of($position->sizing_snapshot['actual_notional'])->isLessThanOrEqualTo('5'));
        self::assertTrue(BigDecimal::of($position->sizing_snapshot['actual_notional'])->isGreaterThan('4.9'));
        self::assertDecimalSame($expectedPrice, $position->entry_price);
        self::assertTrue(BigDecimal::of($position->entry_price)->remainder('0.1')->isZero());
    }

    public function test_processing_records_final_boundary_denial_and_retry_cannot_write_finances(): void
    {
        [$account, $state, $signal] = $this->fixture();
        $beforeAccount = $account->refresh()->getAttributes();
        $state->update(['processed_at' => '2026-11-09T12:05:00Z']);
        $this->travelTo(new DateTimeImmutable('2026-11-09T12:10:00Z'));
        DB::table('market_candles')->insert([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '5',
            'open_time' => '2026-11-09T12:05:00Z', 'open' => '50000', 'high' => '50100', 'low' => '49900', 'close' => '50050',
            'volume' => '100', 'turnover' => '5000000', 'is_closed' => true, 'created_at' => '2026-11-09T12:10:00Z', 'updated_at' => '2026-11-09T12:10:00Z',
        ]);
        $positionsBefore = DB::table('virtual_positions')->count();
        $processing = app(PaperProcessingService::class);
        $processing->process($account->id, 'BTCUSDT', now()->toDateTimeImmutable());
        self::assertSame('REJECTED', $state->refresh()->status);
        self::assertSame('instrument_constraints_stale', $state->reason);
        self::assertSame($beforeAccount, $account->refresh()->getAttributes());
        self::assertSame($positionsBefore, DB::table('virtual_positions')->count());
        self::assertSame(0, DB::table('virtual_executions')->count());
        $failure = DB::table('trading_journal_events')->where('paper_account_id', $account->id)->where('event_type', 'EXECUTION_FAILED')->sole();
        $payload = json_decode($failure->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('final_entry_boundary', $payload['stage']);
        self::assertSame('instrument_constraints_stale', $payload['reason']);
        $after = $this->financialState();
        $processing->process($account->id, 'BTCUSDT', now()->toDateTimeImmutable());
        self::assertSame($after, $this->financialState());
    }

    public function test_actual_quote_size_cannot_exceed_owner_signal_risk_budget(): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture(['mode' => 'fixed_notional', 'amount' => '2000']);
        $signal->update(['model_risk_percent' => '0.01']);
        $before = $this->financialState();
        try {
            app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
            self::fail('The canonical signal risk cap was bypassed.');
        } catch (DomainException $exception) {
            self::assertSame('Entry ineligible: sizing_exceeds_signal_risk_limit', $exception->getMessage());
        }
        self::assertSame($before, $this->financialState());
    }

    public function test_unsaved_signal_risk_cannot_override_persisted_risk_authority(): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture(['mode' => 'fixed_notional', 'amount' => '2000']);
        DB::table('signals')->where('id', $signal->id)->update(['model_risk_percent' => '0.01']);
        $signal->model_risk_percent = '0.50';
        $before = $this->financialState();
        try {
            app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
            self::fail('Unsaved signal risk overrode persisted authority.');
        } catch (DomainException $exception) {
            self::assertSame('Entry ineligible: sizing_exceeds_signal_risk_limit', $exception->getMessage());
        }
        self::assertSame($before, $this->financialState());
    }

    public function test_final_account_risk_budget_is_rechecked_under_lock(): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture();
        $account->update(['reserved_risk' => '149']);
        $before = $this->financialState();
        try {
            app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
            self::fail('The account risk cap was bypassed.');
        } catch (DomainException $exception) {
            self::assertSame('Entry ineligible: portfolio_risk_limit', $exception->getMessage());
        }
        self::assertSame($before, $this->financialState());
    }

    public function test_small_notional_cannot_bypass_stricter_configured_leverage(): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture(['mode' => 'fixed_margin', 'amount' => '5', 'leverage' => '2']);
        config(['paper.portfolio.maximum_model_leverage' => '1']);
        app()->forgetInstance(PositionSizingService::class);
        $before = $this->financialState();
        try {
            app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
            self::fail('Small notional bypassed configured leverage.');
        } catch (DomainException $exception) {
            self::assertSame('Entry ineligible: leverage_limit_exceeded', $exception->getMessage());
        }
        self::assertSame($before, $this->financialState());
    }

    public function test_global_approval_uses_catalog_and_preserves_binance_veto(): void
    {
        $guard = app(GlobalPaperCapitalGuard::class);
        $bybit = ExchangeRecord::query()->findOrFail('bybit');
        $bybit->update(['paper_approved' => false, 'lifecycle_status' => 'REGISTERED']);
        self::assertFalse($guard->isExchangeExecutionApproved('bybit'));
        $bybit->update(['paper_approved' => true, 'lifecycle_status' => 'ACTIVE_PAPER']);
        self::assertTrue($guard->isExchangeExecutionApproved('bybit'));
        $bybit->update(['lifecycle_status' => 'PAUSED']);
        self::assertFalse($guard->isExchangeExecutionApproved('bybit'));

        ExchangeRecord::query()->findOrFail('binance')->update(['paper_approved' => true, 'lifecycle_status' => 'ACTIVE_PAPER']);
        config(['exchanges.binance.paper_execution_approved' => false]);
        self::assertFalse($guard->isExchangeExecutionApproved('binance'));
        config(['exchanges.binance.paper_execution_approved' => true]);
        self::assertTrue($guard->isExchangeExecutionApproved('binance'));

        // Generic legacy config flags are not execution vetoes for other venues.
        ExchangeRecord::query()->findOrFail('okx')->update(['paper_approved' => true, 'lifecycle_status' => 'ACTIVE_PAPER']);
        config(['exchanges.okx.paper_execution_approved' => false]);
        self::assertTrue($guard->isExchangeExecutionApproved('okx'));
        self::assertFalse($guard->isExchangeExecutionApproved('missing'));
    }

    public function test_unapproved_catalog_capital_cannot_inflate_global_budget(): void
    {
        [$account] = $this->fixture();
        $account->update(['reserved_risk' => '7']);
        ExchangeRecord::query()->findOrFail('bybit')->update(['paper_approved' => false, 'lifecycle_status' => 'REGISTERED']);
        $guard = app(GlobalPaperCapitalGuard::class);
        $summary = $guard->computeCapitalSummary();
        self::assertSame(0, $summary['active_funded_count']);
        self::assertDecimalSame('0', $summary['active_risk_bearing_capital']);
        self::assertDecimalSame('0', $summary['deployable_risk_budget']);
        self::assertDecimalSame('7', $summary['reserved_risk']);
        self::assertSame('global_capital_no_active_risk_capital', $guard->entryRejectionReason('1'));
    }

    public function test_zero_funded_active_capital_is_not_counted_but_legacy_risk_remains(): void
    {
        [$account] = $this->fixture();
        $account->update(['starting_balance' => '0', 'current_equity' => '0', 'peak_equity' => '0', 'reserved_risk' => '7']);
        $guard = app(GlobalPaperCapitalGuard::class);
        $summary = $guard->computeCapitalSummary();
        self::assertSame(0, $summary['active_funded_count']);
        self::assertDecimalSame('0', $summary['active_risk_bearing_capital']);
        self::assertDecimalSame('7', $summary['reserved_risk']);
        self::assertSame('global_capital_no_active_risk_capital', $guard->entryRejectionReason('1'));
    }

    public static function retainedReservations(): iterable
    {
        yield 'blocked funded' => ['BLOCKED', '1000000'];
        yield 'unapproved funded' => ['ACTIVE', '1000000'];
        yield 'active zero funded' => ['ACTIVE', '0'];
    }

    #[DataProvider('retainedReservations')]
    public function test_ineligible_existing_risk_cannot_disappear_from_global_numerator(string $status, string $balance): void
    {
        $this->fixture();
        $other = PaperAccountRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'name' => 'SYNTHETIC legacy exposure', 'exchange_id' => 'okx',
            'strategy_id' => 'baseline', 'primary_strategy_id' => 'baseline', 'is_primary' => true, 'mode' => 'FORWARD_PAPER',
            'status' => $status, 'starting_balance' => $balance, 'current_equity' => $balance, 'peak_equity' => $balance,
            'reserved_risk' => '499', 'realized_pnl' => '0', 'unrealized_pnl' => '0',
            'current_drawdown_percent' => '0', 'maximum_drawdown_percent' => '0',
        ]);
        $guard = app(GlobalPaperCapitalGuard::class);
        $summary = $guard->computeCapitalSummary();
        self::assertSame(1, $summary['active_funded_count']);
        self::assertDecimalSame('10000', $summary['active_risk_bearing_capital']);
        self::assertDecimalSame('499', $summary['reserved_risk']);
        self::assertNull($guard->entryRejectionReason('1'));
        self::assertSame('global_risk_budget_exceeded', $guard->entryRejectionReason('1.000001'));
        $other->update(['reserved_risk' => '501']);
        self::assertSame('global_risk_budget_exceeded', $guard->entryRejectionReason('0'));
    }

    private function fixture(?array $settings = null): array
    {
        $account = PaperAccountRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'name' => 'SYNTHETIC boundary account', 'exchange_id' => 'bybit',
            'strategy_id' => 'trend_pullback', 'primary_strategy_id' => 'trend_pullback', 'strategy_version' => '1.0.0',
            'is_primary' => true, 'mode' => 'FORWARD_PAPER', 'status' => 'ACTIVE', 'sizing_settings' => $settings,
            'starting_balance' => '10000', 'current_equity' => '10000', 'peak_equity' => '10000',
            'reserved_risk' => '0', 'realized_pnl' => '0', 'unrealized_pnl' => '0',
            'current_drawdown_percent' => '0', 'maximum_drawdown_percent' => '0', 'created_at' => '2026-11-09T11:00:00Z',
        ]);
        $signal = SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback', 'strategy_version' => '1.0.0', 'timeframe' => '15m', 'side' => 'BUY',
            'quality_class' => 'A', 'signal_quality' => 'HIGH', 'trade_risk' => 'LOW', 'score' => 95,
            'entry_low' => '50000', 'entry_high' => '50000', 'stop_loss' => '49500', 'take_profit_1' => '51500', 'take_profit_2' => '53000',
            'risk_reward_1' => '3', 'risk_reward_2' => '6', 'model_risk_percent' => '0.50', 'reasons' => ['SYNTHETIC_TEST_ONLY'], 'risks' => [],
            'contract' => ['research_mode' => true], 'indicator_snapshot' => ['15m' => ['atr_14' => '500', 'close' => '50000']],
            'decision_time' => '2026-11-09T12:00:00Z', 'candle_close_time' => '2026-11-09T12:00:00Z', 'created_at' => '2026-11-09T12:00:00Z',
            'expires_at' => '2026-11-09T12:30:00Z', 'maximum_holding_minutes' => 120, 'schema_version' => '1.0.0',
        ]);
        $this->seedValidatedPaperEvidence($signal);
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'paper_account_id' => $account->id, 'exchange_id' => 'bybit', 'signal_id' => $signal->id,
            'status' => 'PENDING_ENTRY', 'processed_at' => '2026-11-09T12:00:00Z', 'created_at' => '2026-11-09T12:00:00Z',
        ]);
        MarketContextRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'observed_at' => '2026-11-09T12:00:00Z',
            'bid' => '50000', 'ask' => '50001', 'turnover_24h' => '100000000', 'tick_size' => '0.1', 'funding_rate' => '0.0001',
            'qty_step' => '0.000001', 'min_order_qty' => '0.000001', 'max_order_qty' => '1000', 'min_notional_value' => '1',
            'created_at' => '2026-11-09T12:00:00Z', 'updated_at' => '2026-11-09T12:00:00Z',
        ]);
        $quote = new ExecutionQuote(ExecutionType::Entry, '50000', '50000', '0', 'SYNTHETIC_TEST_ONLY', false);
        $size = app(PositionSizingService::class)->calculate('10000', '0.50', $quote->price, $signal->stop_loss,
            new InstrumentConstraints('0.000001', '0.000001', '1000', '1'), app(PaperExecutionEngine::class)->costs('bybit'), PaperSizingSettings::effectiveForAccount($settings));
        self::assertTrue($size->allowed, $size->reason ?? 'Canonical fixture sizing failed.');

        return [$account, $state, $signal, $quote, $size];
    }

    private function financialState(): string
    {
        $state = [];
        foreach (['paper_accounts', 'paper_signal_states', 'virtual_positions', 'virtual_executions', 'equity_snapshots', 'trading_journal_events'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return json_encode($state, JSON_THROW_ON_ERROR);
    }
}
