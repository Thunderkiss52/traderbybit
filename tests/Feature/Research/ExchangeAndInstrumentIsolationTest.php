<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Exchanges\ExchangeComparisonService;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExchangeAndInstrumentIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure primary accounts exist for bybit
        $this->app->make(PaperAccountService::class)->allPrimaryAccounts();
    }

    public function test_profitability_summary_isolates_by_exchange_and_symbol(): void
    {
        // Default request (Bybit, all symbols)
        $respDefault = $this->getJson('/api/v1/research/profitability/summary');
        $respDefault->assertOk()
            ->assertJsonPath('data.filters.exchange', 'bybit')
            ->assertJsonPath('data.filters.symbol', 'ALL');

        // Filtered by Binance and BTC
        $respBinanceBtc = $this->withHeaders([
            'X-Exchange' => 'binance',
            'X-Instrument' => 'BTC',
        ])->getJson('/api/v1/research/profitability/summary');

        $respBinanceBtc->assertOk()
            ->assertJsonPath('data.filters.exchange', 'binance')
            ->assertJsonPath('data.filters.symbol', 'BTCUSDT');

        // Cross-exchange aggregate (All exchanges)
        $respAll = $this->withHeaders([
            'X-Exchange' => 'all',
        ])->getJson('/api/v1/research/profitability/summary');

        $respAll->assertOk()
            ->assertJsonPath('data.filters.exchange', 'all');
    }

    public function test_profitability_strategies_filters_by_exchange_and_symbol(): void
    {
        $respBybit = $this->withHeaders(['X-Exchange' => 'bybit'])
            ->getJson('/api/v1/research/profitability/strategies');
        $respBybit->assertOk()
            ->assertJsonCount(13, 'data.strategies')
            ->assertJsonPath('data.filters.exchange', 'bybit');

        $first = $respBybit->json('data.strategies.0');
        $this->assertSame('bybit', $first['exchange_id']);
    }

    public function test_dashboard_supports_all_exchanges_and_isolated_exchange(): void
    {
        // When All Exchanges is selected, does not throw or 400
        $respAll = $this->withHeaders(['X-Exchange' => 'all'])->getJson('/api/v1/dashboard');
        $respAll->assertOk()
            ->assertJsonPath('data.filters.exchange', 'all');

        // When specific exchange is selected
        $respBybit = $this->withHeaders(['X-Exchange' => 'bybit'])->getJson('/api/v1/dashboard');
        $respBybit->assertOk()
            ->assertJsonPath('data.filters.exchange', 'bybit');

        // When symbol is selected
        $respBtc = $this->withHeaders([
            'X-Exchange' => 'bybit',
            'X-Instrument' => 'BTC',
        ])->getJson('/api/v1/dashboard');
        $respBtc->assertOk()
            ->assertJsonPath('data.filters.symbol', 'BTCUSDT');
    }

    public function test_paper_accounts_and_journal_support_all_exchanges_gracefully(): void
    {
        // paper/accounts with X-Exchange: all must return OK, not 400
        $respAccounts = $this->withHeaders(['X-Exchange' => 'all'])->getJson('/api/v1/paper/accounts');
        $respAccounts->assertOk();

        // journal with X-Exchange: all must return OK, not 400
        $respJournal = $this->withHeaders(['X-Exchange' => 'all'])->getJson('/api/v1/journal');
        $respJournal->assertOk();
    }

    public function test_strategy_monitor_supports_all_exchanges_and_symbol_filter(): void
    {
        $respAll = $this->withHeaders(['X-Exchange' => 'all'])->getJson('/api/v1/strategy-monitor');
        $respAll->assertOk();

        $respBtc = $this->withHeaders([
            'X-Exchange' => 'bybit',
            'X-Symbol' => 'BTCUSDT',
        ])->getJson('/api/v1/strategy-monitor');
        $respBtc->assertOk();
    }

    public function test_exchange_comparison_returns_inactivity_reasons(): void
    {
        $report = app(ExchangeComparisonService::class)->report();
        $this->assertNotEmpty($report['accounts']);

        $firstRow = $report['accounts'][0];
        $this->assertArrayHasKey('inactivity_reason', $firstRow);
        $this->assertArrayHasKey('inactivity_detail', $firstRow);
        $this->assertIsString($firstRow['inactivity_reason']);
        $this->assertIsString($firstRow['inactivity_detail']);

        // Check Binance rows have valid deterministic inactivity reason
        $binanceRows = array_filter($report['accounts'], fn ($r) => $r['exchange_id'] === 'binance');
        $this->assertNotEmpty($binanceRows);
        foreach ($binanceRows as $bRow) {
            $this->assertContains($bRow['inactivity_reason'], [
                'ACCOUNT_NOT_PROVISIONED', 'ACCOUNT_BLOCKED', 'PAPER_EXECUTION_UNAPPROVED',
                'MARKET_DATA_OFFLINE', 'NO_MARKET_CANDLES', 'MARKET_DATA_STALE', 'AWAITING_SIGNAL',
            ]);
        }
    }

    public function test_signals_and_positions_query_isolation_between_exchanges_and_symbols(): void
    {
        $account = PaperAccountRecord::query()->where('exchange_id', 'bybit')->firstOrFail();

        $sigBybitBtc = $this->createTestSignal('bybit', 'BTCUSDT', $account->strategy_id);
        $sigBybitEth = $this->createTestSignal('bybit', 'ETHUSDT', $account->strategy_id);

        // Create Bybit BTCUSDT position
        $posBybitBtc = VirtualPositionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'paper_account_id' => $account->id,
            'signal_id' => $sigBybitBtc->id,
            'exchange_id' => 'bybit',
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'OPEN',
            'entry_price' => '65000.00',
            'stop_loss' => '64000.00',
            'take_profit_1' => '66000.00',
            'take_profit_2' => '67000.00',
            'risk_capital' => '100.00',
            'initial_quantity' => '0.1',
            'remaining_quantity' => '0.1',
            'qty_step' => '0.001',
            'gross_pnl' => '0.00',
            'net_pnl' => '0.00',
            'unrealized_pnl' => '0.00',
            'fees' => '3.50',
            'funding' => '0.00',
            'opened_at' => CarbonImmutable::now('UTC'),
            'maximum_holding_until' => CarbonImmutable::now('UTC')->addHours(24),
            'execution_model' => 'MARKET_TAKER',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // Create Bybit ETHUSDT position
        $posBybitEth = VirtualPositionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'paper_account_id' => $account->id,
            'signal_id' => $sigBybitEth->id,
            'exchange_id' => 'bybit',
            'symbol' => 'ETHUSDT',
            'side' => 'BUY',
            'quality_class' => 'B',
            'status' => 'OPEN',
            'entry_price' => '3500.00',
            'stop_loss' => '3400.00',
            'take_profit_1' => '3600.00',
            'take_profit_2' => '3700.00',
            'risk_capital' => '100.00',
            'initial_quantity' => '1.0',
            'remaining_quantity' => '1.0',
            'qty_step' => '0.01',
            'gross_pnl' => '0.00',
            'net_pnl' => '0.00',
            'unrealized_pnl' => '0.00',
            'fees' => '1.90',
            'funding' => '0.00',
            'opened_at' => CarbonImmutable::now('UTC'),
            'maximum_holding_until' => CarbonImmutable::now('UTC')->addHours(24),
            'execution_model' => 'MARKET_TAKER',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // Create Binance BTCUSDT position
        $binanceAccount = PaperAccountRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'binance_baseline',
            'mode' => 'FORWARD_PAPER',
            'strategy_id' => 'baseline',
            'primary_strategy_id' => 'baseline',
            'exchange_id' => 'binance',
            'status' => 'BLOCKED',
            'starting_balance' => '10000.00',
            'current_equity' => '10000.00',
            'peak_equity' => '10000.00',
            'reserved_risk' => '0.00',
            'realized_pnl' => '0.00',
            'unrealized_pnl' => '0.00',
            'maximum_drawdown_percent' => '0.00',
            'current_drawdown_percent' => '0.00',
            'is_primary' => true,
        ]);

        $sigBinanceBtc = $this->createTestSignal('binance', 'BTCUSDT', 'baseline');

        $posBinanceBtc = VirtualPositionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'paper_account_id' => $binanceAccount->id,
            'signal_id' => $sigBinanceBtc->id,
            'exchange_id' => 'binance',
            'symbol' => 'BTCUSDT',
            'side' => 'SELL',
            'quality_class' => 'A',
            'status' => 'OPEN',
            'entry_price' => '65100.00',
            'stop_loss' => '66000.00',
            'take_profit_1' => '64000.00',
            'take_profit_2' => '63000.00',
            'risk_capital' => '100.00',
            'initial_quantity' => '0.1',
            'remaining_quantity' => '0.1',
            'qty_step' => '0.001',
            'gross_pnl' => '0.00',
            'net_pnl' => '0.00',
            'unrealized_pnl' => '0.00',
            'fees' => '3.50',
            'funding' => '0.00',
            'opened_at' => CarbonImmutable::now('UTC'),
            'maximum_holding_until' => CarbonImmutable::now('UTC')->addHours(24),
            'execution_model' => 'MARKET_TAKER',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // Query Bybit + BTC: must return only posBybitBtc
        $respBybitBtc = $this->withHeaders([
            'X-Exchange' => 'bybit',
            'X-Instrument' => 'BTC',
        ])->getJson('/api/v1/positions');

        $respBybitBtc->assertOk();
        $ids = collect($respBybitBtc->json('data.data'))->pluck('id')->all();
        $this->assertContains($posBybitBtc->id, $ids);
        $this->assertNotContains($posBybitEth->id, $ids, 'ETH position must not leak into BTC scope');
        $this->assertNotContains($posBinanceBtc->id, $ids, 'Binance position must not leak into Bybit scope');

        // Query Bybit + ETH: must return only posBybitEth
        $respBybitEth = $this->withHeaders([
            'X-Exchange' => 'bybit',
            'X-Instrument' => 'ETH',
        ])->getJson('/api/v1/positions');

        $respBybitEth->assertOk();
        $ethIds = collect($respBybitEth->json('data.data'))->pluck('id')->all();
        $this->assertContains($posBybitEth->id, $ethIds);
        $this->assertNotContains($posBybitBtc->id, $ethIds, 'BTC position must not leak into ETH scope');
        $this->assertNotContains($posBinanceBtc->id, $ethIds, 'Binance position must not leak into Bybit scope');

        // Query Binance + BTC: must return only posBinanceBtc
        $respBinanceBtc = $this->withHeaders([
            'X-Exchange' => 'binance',
            'X-Instrument' => 'BTC',
        ])->getJson('/api/v1/positions');

        $respBinanceBtc->assertOk();
        $binanceIds = collect($respBinanceBtc->json('data.data'))->pluck('id')->all();
        $this->assertContains($posBinanceBtc->id, $binanceIds);
        $this->assertNotContains($posBybitBtc->id, $binanceIds, 'Bybit position must not leak into Binance scope');
    }

    private function createTestSignal(string $exchange, string $symbol, string $strategyId = 'baseline'): SignalRecord
    {
        return SignalRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'timeframe' => '15m',
            'strategy_id' => $strategyId,
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'signal_quality' => 'A',
            'trade_risk' => 'LOW',
            'score' => 85,
            'entry_low' => '50000.00',
            'entry_high' => '50100.00',
            'stop_loss' => '49500.00',
            'take_profit_1' => '51500.00',
            'take_profit_2' => '53000.00',
            'risk_reward_1' => '2.0',
            'risk_reward_2' => '4.0',
            'model_risk_percent' => '0.20',
            'reasons' => ['test_fixture'],
            'risks' => [],
            'contract' => ['rules' => []],
            'indicator_snapshot' => ['15m' => ['atr_14' => '500.00', 'close' => '50000.00']],
            'decision_time' => CarbonImmutable::now('UTC'),
            'candle_close_time' => CarbonImmutable::now('UTC'),
            'candle_id' => null,
            'maximum_holding_minutes' => 120,
            'schema_version' => '1.0.0',
            'expires_at' => CarbonImmutable::now('UTC')->addMinutes(30),
        ]);
    }
}
