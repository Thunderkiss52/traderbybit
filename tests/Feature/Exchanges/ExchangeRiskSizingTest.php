<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Binance\BinancePaperCapital;
use App\Exchanges\Binance\BinancePaperProvisioner;
use App\Exchanges\Binance\ExchangePaperReconciliation;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Synthetic isolated lifecycle evidence; never prospective return evidence. */
final class ExchangeRiskSizingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // This fixture verifies the preserved original 26-account financial cohort.
        config(['exchanges.enabled' => ['bybit', 'binance']]);
        DB::table('exchanges')->whereNotIn('id', ['bybit', 'binance'])->delete();
    }

    public function test_exchange_costs_drive_risk_and_fill_sizing_and_reconcile_after_retry(): void
    {
        $accounts = $this->accounts();
        $accountIds = array_map(fn (PaperAccountRecord $account) => $account->id, $accounts);
        $untouched = DB::table('paper_accounts')->whereNotIn('id', $accountIds)->orderBy('id')->get()->toJson();
        foreach (['bybit', 'binance'] as $exchange) {
            $this->market($exchange);
            $signal = $this->signal($exchange);
            $service = app(PaperProcessingService::class);
            $service->process($accounts[$exchange]->id, 'BTCUSDT', new DateTimeImmutable('2026-10-14T10:20:00Z'));
            $risk = RiskDecisionRecord::query()->where('signal_id', $signal->id)->firstOrFail();
            self::assertTrue($risk->allowed);
            // Independent hand calculation: risk=50, reference entry=101, stop=95.
            // Binance loss/unit=6+196*(0.001+0.002)=6.588; floor to .001.
            $expectedRiskSize = $exchange === 'binance' ? '7.589' : '8.134';
            $expectedFillSize = $exchange === 'binance' ? '7.363' : '8.107';
            self::assertTrue(BigDecimal::of((string) $risk->snapshot['position_size'])->isEqualTo($expectedRiskSize));
            $position = VirtualPositionRecord::query()->where('signal_id', $signal->id)->firstOrFail();
            self::assertTrue(BigDecimal::of((string) $position->initial_quantity)->isEqualTo($expectedFillSize));
            self::assertSame($exchange, $position->exchange_id);
            self::assertSame($accounts[$exchange]->id, $position->paper_account_id);

            // A new service instance models a subsequent worker invocation.
            app()->forgetInstance(PaperProcessingService::class);
            $service = app(PaperProcessingService::class);
            $service->process($accounts[$exchange]->id, 'BTCUSDT', new DateTimeImmutable('2026-10-14T10:30:00Z'));
            $beforeRetry = $position->refresh()->getAttributes();
            $service->process($accounts[$exchange]->id, 'BTCUSDT', new DateTimeImmutable('2026-10-14T10:30:00Z'));
            self::assertSame($beforeRetry, $position->refresh()->getAttributes());
            self::assertSame('CLOSED_TP', $position->status);
            $executions = DB::table('virtual_executions')->where('position_id', $position->id)->get();
            self::assertCount(3, $executions);
            $fees = BigDecimal::zero();
            foreach ($executions as $execution) {
                self::assertSame($exchange, $execution->exchange_id);
                $fee = BigDecimal::of((string) $execution->quantity)->multipliedBy((string) $execution->price)
                    ->multipliedBy($exchange === 'binance' ? '0.001' : '0.00055');
                self::assertTrue($fee->isEqualTo((string) $execution->commission));
                $fees = $fees->plus($fee);
            }
            $funding = DB::table('position_funding_events')->where('position_id', $position->id)->sole();
            self::assertSame($exchange, $funding->exchange_id);
            self::assertTrue(BigDecimal::of((string) $funding->amount)->isEqualTo(BigDecimal::of((string) $expectedFillSize)->multipliedBy('102')->multipliedBy('-0.001')));
            self::assertTrue(BigDecimal::of((string) $position->gross_pnl)->plus((string) $funding->amount)->minus($fees)->isEqualTo((string) $position->net_pnl));
            self::assertTrue(BigDecimal::of((string) $accounts[$exchange]->refresh()->reserved_risk)->isZero());
        }
        self::assertSame($untouched, DB::table('paper_accounts')->whereNotIn('id', $accountIds)->orderBy('id')->get()->toJson());
        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $report['status'], json_encode($report['issues']));
        self::assertSame(26, $report['verified_pairs']);
        Http::assertNothingSent();
    }

    public function test_missing_binance_costs_fail_before_any_risk_or_fill_writes(): void
    {
        $accounts = $this->accounts();
        $this->market('binance');
        $this->signal('binance');
        config()->set('exchanges.binance.paper_taker_rate', null);
        $before = DB::table('paper_accounts')->orderBy('id')->get()->toJson();
        try {
            app(PaperProcessingService::class)->process($accounts['binance']->id, 'BTCUSDT', new DateTimeImmutable('2026-10-14T10:20:00Z'));
            self::fail('Missing venue costs accepted.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('fee/slippage evidence', $exception->getMessage());
        }
        foreach (['risk_decisions', 'virtual_positions', 'virtual_executions', 'paper_signal_states'] as $table) {
            self::assertSame(0, DB::table($table)->count());
        }
        self::assertSame($before, DB::table('paper_accounts')->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
    }

    private function accounts(): array
    {
        Http::preventStrayRequests();
        $this->travelTo(new DateTimeImmutable('2026-10-14T10:10:00Z'));
        config()->set('exchanges.binance_paper_provisioning_approved', true);
        config()->set('exchanges.binance.paper_execution_approved', true);
        // Synthetic test-only cost assumptions, not claims about exchange fees.
        config()->set('exchanges.binance.paper_taker_rate', '0.001');
        config()->set('exchanges.binance.paper_slippage_rate', '0.002');
        app(PaperAccountService::class)->allPrimaryAccounts();
        app(BinancePaperProvisioner::class)->provision();
        app(BinancePaperCapital::class)->allocate('10000', 'SYNTHETIC-ISOLATED-LIFECYCLE');
        $accounts = [];
        foreach (['bybit', 'binance'] as $exchange) {
            $account = PaperAccountRecord::query()->where('exchange_id', $exchange)->where('primary_strategy_id', 'baseline')->firstOrFail();
            $account->update(['status' => 'ACTIVE']);
            $accounts[$exchange] = $account;
        }

        return $accounts;
    }

    private function signal(string $exchange): SignalRecord
    {
        return SignalRecord::query()->create([
            'id' => (string) Str::uuid(), 'exchange' => $exchange, 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'strategy_id' => 'baseline', 'strategy_version' => PaperAccountRecord::query()->where('exchange_id', $exchange)
                ->where('primary_strategy_id', 'baseline')->value('strategy_version'), 'timeframe' => '15', 'side' => 'BUY',
            'quality_class' => 'A', 'score' => 90, 'decision_time' => '2026-10-14 10:15:00', 'candle_close_time' => '2026-10-14 10:15:00',
            'entry_low' => '100', 'entry_high' => '101', 'stop_loss' => '95', 'take_profit_1' => '105', 'take_profit_2' => '110',
            'risk_reward_1' => '1.5', 'risk_reward_2' => '2.5', 'model_risk_percent' => '0.50',
            'expires_at' => '2026-10-14 10:45:00', 'maximum_holding_minutes' => 240,
            'reasons' => ['SYNTHETIC_TEST_ONLY'], 'risks' => [], 'indicator_snapshot' => ['15m' => ['atr_14' => '1', 'close' => '100']],
            'contract' => ['research_mode' => true], 'schema_version' => '1.0.0',
        ]);
    }

    private function market(string $exchange): void
    {
        MarketContextRecord::query()->create([
            'exchange' => $exchange, 'category' => 'linear', 'symbol' => 'BTCUSDT', 'observed_at' => '2026-10-14 10:15:00',
            'bid' => '100', 'ask' => '100.05', 'turnover_24h' => '100000000', 'tick_size' => '0.1', 'funding_rate' => '0.0001',
            'qty_step' => '0.001', 'min_order_qty' => '0.001', 'max_order_qty' => '1000', 'min_notional_value' => '5',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['10:15', '100.5', '102', '100', '101.2'], ['10:20', '101.2', '106', '99', '105'], ['10:25', '105', '111', '104', '110']] as [$time, $open, $high, $low, $close]) {
            DB::table('market_candles')->insert([
                'exchange' => $exchange, 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '5',
                'open_time' => '2026-10-14 '.$time.':00', 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close,
                'volume' => '100', 'turnover' => '10000', 'is_closed' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('market_funding_events')->insert([
            'exchange' => $exchange, 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'funding_at' => '2026-10-14 10:22:00', 'rate' => '0.001', 'mark_price' => '102',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
