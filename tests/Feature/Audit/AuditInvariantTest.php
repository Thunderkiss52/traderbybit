<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Domain\SignalLifecycle;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\PaperTrading\Jobs\ProcessPaperTradingJob;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class AuditInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_peak_equity_never_falls_below_starting_balance_and_drawdown_is_positive_on_loss(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();
        self::assertSame('10000', $account->starting_balance);
        self::assertSame('10000', $account->peak_equity);
        self::assertSame('0', $account->current_drawdown_percent);

        // Open a trade that hits SL (loss)
        $this->signal('BUY', 'BTCUSDT', '10:15');
        $this->context('BTCUSDT', '10:15');
        $this->candle('10:15', '100.5', '102', '100', '101');
        $this->candle('10:20', '101', '102', '94', '96'); // Low hits SL at 95

        $service = $this->app->make(PaperProcessingService::class);
        $service->process(symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:25:00Z'));

        $account->refresh();
        $position = VirtualPositionRecord::query()->firstOrFail();
        self::assertSame(PositionStatus::ClosedSl->value, $position->status);
        self::assertTrue(BigDecimal::of($position->net_pnl)->isNegative());

        // Invariant: peak equity MUST NOT fall below starting balance (10000)
        self::assertTrue(
            BigDecimal::of($account->peak_equity)->isGreaterThanOrEqualTo(BigDecimal::of($account->starting_balance)),
            "Peak equity ({$account->peak_equity}) dropped below starting balance ({$account->starting_balance})"
        );
        self::assertSame('10000', (string) BigDecimal::of($account->peak_equity)->toScale(0));

        // Invariant: drawdown must be positive when current equity is below peak
        self::assertTrue(
            BigDecimal::of($account->current_equity)->isLessThan(BigDecimal::of($account->peak_equity))
        );
        self::assertTrue(
            BigDecimal::of($account->current_drawdown_percent)->isGreaterThan(BigDecimal::zero()),
            "Current drawdown percent should be positive, got {$account->current_drawdown_percent}"
        );
        self::assertTrue(
            BigDecimal::of($account->maximum_drawdown_percent)->isGreaterThanOrEqualTo(BigDecimal::of($account->current_drawdown_percent)),
            "Maximum drawdown ({$account->maximum_drawdown_percent}) is less than current drawdown ({$account->current_drawdown_percent})"
        );
    }

    public function test_mark_to_market_drawdown_is_positive_on_unrealized_loss(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();
        $this->signal('BUY', 'BTCUSDT', '10:15');
        $this->context('BTCUSDT', '10:15');
        $this->candle('10:15', '100.5', '101.5', '100', '101');

        $service = $this->app->make(PaperProcessingService::class);
        $service->process(symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:20:00Z'));

        $position = VirtualPositionRecord::query()->firstOrFail();
        self::assertSame(PositionStatus::Open->value, $position->status);

        // Mark to market at price 98 (adverse move, below entry 100.5)
        $lifecycle = $this->app->make(PositionLifecycleService::class);
        $lifecycle->markToMarket($position, '98', new DateTimeImmutable('2026-10-14T10:22:00Z'));

        $account->refresh();
        self::assertTrue(
            BigDecimal::of($account->current_drawdown_percent)->isGreaterThan(BigDecimal::zero()),
            "Mark to market drawdown should be positive, got {$account->current_drawdown_percent}"
        );
        self::assertTrue(
            BigDecimal::of($account->peak_equity)->isGreaterThanOrEqualTo(BigDecimal::of($account->starting_balance)),
            "Peak equity ({$account->peak_equity}) must be >= starting balance"
        );
    }

    public function test_late_signals_create_terminal_state_and_do_not_starve_query(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-14T10:10:00Z'));
        $account = $this->app->make(PaperAccountService::class)->default();

        // Signal decision was 10:15, but created 25 minutes late at 10:40 (> 300s late)
        $this->travelTo(new DateTimeImmutable('2026-10-14T10:40:00Z'));
        $signal = $this->signal('BUY', 'BTCUSDT', '10:15');

        $service = $this->app->make(PaperProcessingService::class);
        $service->process(accountId: $account->id, symbol: 'BTCUSDT');

        // State record MUST be created with LATE_SIGNAL_NOT_EXECUTABLE
        $state = PaperSignalStateRecord::query()->where('signal_id', $signal->id)->firstOrFail();
        self::assertSame(SignalLifecycle::LateSignalNotExecutable->value, $state->status);
        self::assertSame('late_signal_not_executable', $state->reason);

        // Risk decision record MUST exist and be rejected
        $decision = RiskDecisionRecord::query()->where('signal_id', $signal->id)->firstOrFail();
        self::assertFalse((bool) $decision->allowed);
        self::assertContains('late_signal_not_executable', (array) $decision->reasons);

        // Running processing again must not crash, duplicate, or re-process
        $service->process(accountId: $account->id, symbol: 'BTCUSDT');
        self::assertSame(1, PaperSignalStateRecord::query()->where('signal_id', $signal->id)->count());
        self::assertSame(1, RiskDecisionRecord::query()->where('signal_id', $signal->id)->count());
    }

    public function test_process_paper_trading_job_processes_all_active_strategy_accounts(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $defaultAccount = $accountService->default();
        $momentumAccount = $accountService->strategyForward('momentum', '1.0.0', ['period' => 14]);
        $trendAccount = $accountService->strategyForward('trend_pullback', '1.3.0', ['ema' => 50]);

        $this->candle('10:15', '100', '101', '99', '100.5');

        $job = new ProcessPaperTradingJob(at: '2026-10-14T10:20:00Z');
        $job->handle($this->app->make(PaperProcessingService::class));

        // All 3 accounts must remain in active state
        self::assertSame('ACTIVE', $defaultAccount->refresh()->status);
        self::assertSame('ACTIVE', $momentumAccount->refresh()->status);
        self::assertSame('ACTIVE', $trendAccount->refresh()->status);
    }

    public function test_audit_csv_momentum_account_drawdown_recalculation(): void
    {
        // Recreate the exact numbers from audit_data/paper_accounts.csv row 2:
        // Starting balance: 10000.00
        // Current equity: 9950.313422250598
        // Realized PnL: -43.359370829402
        // Unrealized PnL: -6.327206920000
        $startingBalance = BigDecimal::of('10000.000000000000');
        $realized = BigDecimal::of('-43.359370829402');
        $unrealized = BigDecimal::of('-6.327206920000');
        $equity = $startingBalance->plus($realized)->plus($unrealized);

        self::assertSame('9950.313422250598', (string) $equity);

        // Correct invariant calculation:
        $peak = BigDecimal::max($startingBalance, $equity);
        self::assertSame('10000.000000000000', (string) $peak);

        $drawdown = $peak->minus($equity)->dividedBy($peak, 24, RoundingMode::HalfEven)->multipliedBy(100)->toScale(8, RoundingMode::HalfEven);
        // (10000 - 9950.31342225) / 10000 * 100 = 0.49686578%
        self::assertSame('0.49686578', (string) $drawdown);
        self::assertNotSame('0.00000000', (string) $drawdown);
    }

    private function signal(string $side, string $symbol = 'BTCUSDT', string $time = '10:15', string $strategyId = 'baseline'): SignalRecord
    {
        $at = new DateTimeImmutable("2026-10-14T{$time}:00Z");
        $buy = $side === 'BUY';

        return SignalRecord::query()->create([
            'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "audit-test:{$symbol}:{$side}:{$time}")->toString(),
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
            'timeframe' => '15', 'side' => $side, 'quality_class' => 'A', 'score' => 90,
            'decision_time' => $at, 'candle_close_time' => $at,
            'entry_low' => $buy ? '100' : '99', 'entry_high' => $buy ? '101' : '100',
            'stop_loss' => $buy ? '95' : '105', 'take_profit_1' => $buy ? '105' : '95',
            'take_profit_2' => $buy ? '110' : '90', 'risk_reward_1' => '1.5', 'risk_reward_2' => '2.5',
            'model_risk_percent' => '0.50', 'expires_at' => $at->modify('+30 minutes'),
            'maximum_holding_minutes' => 240, 'reasons' => ['fixture'], 'risks' => [],
            'indicator_snapshot' => ['15m' => ['atr_14' => '1', 'close' => '100']],
            'contract' => ['research_mode' => true],
            'strategy_version' => '1.0.0', 'schema_version' => '1.0.0', 'strategy_id' => $strategyId,
        ]);
    }

    private function context(string $symbol = 'BTCUSDT', string $time = '10:15'): void
    {
        MarketContextRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
            'observed_at' => new DateTimeImmutable("2026-10-14T{$time}:00Z"),
            'bid' => '100', 'ask' => '100.05', 'turnover_24h' => '100000000',
            'tick_size' => '0.1', 'funding_rate' => '0.0001', 'open_interest' => '1000000',
            'qty_step' => '0.001', 'min_order_qty' => '0.001', 'max_order_qty' => '1000', 'min_notional_value' => '5',
        ]);
    }

    private function candle(string $time, string $open, string $high, string $low, string $close, string $symbol = 'BTCUSDT'): void
    {
        DB::table('market_candles')->insert([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => '5',
            'open_time' => new DateTimeImmutable("2026-10-14T{$time}:00Z"),
            'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close,
            'volume' => '100', 'turnover' => '10000', 'is_closed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
