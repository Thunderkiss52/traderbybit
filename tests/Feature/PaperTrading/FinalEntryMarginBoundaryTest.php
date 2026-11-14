<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperExecutionEngine;
use App\PaperTrading\Application\PaperSizingSettings;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Application\PositionSizingService;
use App\PaperTrading\Domain\ExecutionQuote;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\InstrumentConstraints;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\SeedsValidatedPaperEvidence;
use Tests\TestCase;

/** Synthetic isolated boundary controls, never actual PAPER return evidence. */
final class FinalEntryMarginBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsValidatedPaperEvidence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new DateTimeImmutable('2026-11-09T12:05:00Z'));
        config(['paper.global_capital.enabled' => true]);
    }

    public static function marginCases(): iterable
    {
        yield 'two positions exceed equity' => ['0.13', '0.13', ['allocated_margin' => '6500', 'leverage' => '1'], 'insufficient_available_margin'];
        yield 'entry fee cannot be ignored' => ['0.12', '0.12', ['allocated_margin' => '6000', 'leverage' => '1'], 'insufficient_available_margin'];
        yield 'legacy position reserves full notional' => ['0.13', '0.13', null, 'insufficient_available_margin'];
        yield 'invalid leverage reserves full notional' => ['0.13', '0.13', ['allocated_margin' => '1', 'leverage' => '100'], 'insufficient_available_margin'];
        yield 'understated snapshot cannot hide margin' => ['0.13', '0.13', ['allocated_margin' => '1', 'leverage' => '1'], 'insufficient_available_margin'];
        yield 'partial position releases proportionally' => ['0.26', '0.13', ['allocated_margin' => '13000', 'leverage' => '1'], 'insufficient_available_margin'];
        yield 'partial close makes margin available' => ['0.26', '0.08', ['allocated_margin' => '13000', 'leverage' => '1'], null];
        yield 'understated leveraged snapshot reserves full notional' => ['0.13', '0.13', ['allocated_margin' => '1', 'leverage' => '2'], 'insufficient_available_margin'];
        yield 'valid two times leverage reserves margin' => ['0.13', '0.13', ['allocated_margin' => '3250', 'leverage' => '2'], null];
        yield 'remaining margin fits with fees' => ['0.10', '0.10', ['allocated_margin' => '5000', 'leverage' => '1'], null];
    }

    #[DataProvider('marginCases')]
    public function test_remaining_margin_and_entry_costs_constrain_new_entries(string $initial, string $remaining, ?array $snapshot, ?string $reason): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture(['mode' => 'fixed_notional', 'amount' => '4000']);
        $prior = $this->activePosition($account, $signal, 'ETHUSDT', 'SELL', $initial, $remaining, $snapshot);
        $priorSnapshot = $prior->refresh()->getRawOriginal('sizing_snapshot');
        $before = $this->financialState();
        if ($reason !== null) {
            try {
                app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
                self::fail('An entry exceeded available margin.');
            } catch (DomainException $exception) {
                self::assertSame('Entry ineligible: '.$reason, $exception->getMessage());
            }
            self::assertSame($before, $this->financialState());
        } else {
            $position = app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
            self::assertSame('OPEN', $position->status);
            self::assertDecimalSame('4000', $position->sizing_snapshot['allocated_margin']);
        }
        self::assertSame($priorSnapshot, $prior->refresh()->getRawOriginal('sizing_snapshot'));
    }

    public static function sameSymbolCases(): iterable
    {
        yield 'same side' => ['BUY', 'position_already_open'];
        yield 'opposite side' => ['SELL', 'position_conflict'];
    }

    public function test_same_direction_other_symbol_is_rejected_when_causal_correlation_cannot_be_revalidated(): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture();
        // The first other-symbol position is opposite-sided; do not miss a
        // same-direction exposure later in the account's active positions.
        $this->activePosition($account, $signal, 'ETHUSDT', 'SELL', '0.001', '0.001', null);
        $open = $this->activePosition($account, $signal, 'XRPUSDT', 'BUY', '0.001', '0.001', null);
        $before = $this->financialState();
        try {
            app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
            self::fail('Unverified correlated exposure opened.');
        } catch (DomainException $exception) {
            self::assertSame('Entry ineligible: correlation_revalidation_unavailable', $exception->getMessage());
        }
        self::assertSame($before, $this->financialState());
        $exit = new ExecutionQuote(ExecutionType::StopLoss, '49500', '49500', '0', 'SYNTHETIC_TEST_ONLY', false);
        $closed = app(PositionLifecycleService::class)->executeExit($open, $exit, now()->toDateTimeImmutable());
        self::assertSame('CLOSED_SL', $closed->status);
    }

    #[DataProvider('sameSymbolCases')]
    public function test_final_entry_rechecks_same_symbol_under_account_lock(string $side, string $reason): void
    {
        [$account, $state, $signal, $quote, $size] = $this->fixture();
        $this->activePosition($account, $signal, 'BTCUSDT', $side, '0.001', '0.001', null);
        $before = $this->financialState();
        try {
            app(PositionLifecycleService::class)->open($account, $state, $signal, $size, $quote, now()->toDateTimeImmutable());
            self::fail('Conflicting same-symbol position opened.');
        } catch (DomainException $exception) {
            self::assertSame('Entry ineligible: '.$reason, $exception->getMessage());
        }
        self::assertSame($before, $this->financialState());
    }

    private function activePosition(PaperAccountRecord $account, SignalRecord $signal, string $symbol, string $side, string $initial, string $remaining, ?array $snapshot): VirtualPositionRecord
    {
        $prior = $signal->replicate();
        $prior->id = Uuid::uuid4()->toString();
        $prior->symbol = $symbol;
        $prior->side = $side;
        $prior->decision_time = new DateTimeImmutable('2026-11-09T11:45:00Z');
        $prior->candle_close_time = new DateTimeImmutable('2026-11-09T11:45:00Z');
        $prior->save();
        $account->update(['reserved_risk' => '20']);

        return VirtualPositionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'paper_account_id' => $account->id, 'exchange_id' => 'bybit', 'signal_id' => $prior->id,
            'symbol' => $symbol, 'side' => $side, 'quality_class' => 'A',
            'status' => $initial === $remaining ? 'OPEN' : 'PARTIALLY_CLOSED',
            'entry_price' => '50000', 'stop_loss' => '49500', 'take_profit_1' => '51500', 'take_profit_2' => '53000',
            'initial_quantity' => $initial, 'remaining_quantity' => $remaining, 'qty_step' => '0.000001', 'risk_capital' => '20',
            'gross_pnl' => '0', 'fees' => '0', 'funding' => '0', 'net_pnl' => '0', 'unrealized_pnl' => '0', 'funding_complete' => false,
            'opened_at' => '2026-11-09T11:50:00Z', 'maximum_holding_until' => '2026-11-09T13:50:00Z',
            'execution_model' => 'SYNTHETIC_TEST_ONLY', 'strategy_version' => '1.0.0',
            'risk_version' => 'synthetic-test', 'execution_version' => 'synthetic-test', 'schema_version' => '1.0.0',
            'sizing_snapshot' => $snapshot,
        ]);
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
