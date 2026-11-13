<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Binance\BinancePortabilityMatrix;
use App\Exchanges\ExchangeComparisonService;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MultiExchangeComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_thirteen_primary_strategies_are_visible_on_every_registered_exchange_without_merging(): void
    {
        $bybit = app(PaperAccountService::class)->allPrimaryAccounts();
        self::assertCount(13, $bybit);
        self::assertSame(13, $bybit->pluck('id')->unique()->count());
        $result = app(ExchangeComparisonService::class)->report();
        self::assertSame(13, $result['strategy_families']);
        self::assertSame(130, $result['expected_accounts']);
        self::assertSame(13, $result['provisioned_accounts']);
        self::assertSame('DISABLED_BY_DEFAULT', $result['portfolio_aggregation']);
        self::assertCount(130, $result['accounts']);
        self::assertCount(13, array_filter($result['accounts'], fn ($row) => $row['exchange_id'] === 'binance'));
        foreach ($result['accounts'] as $row) {
            if ($row['exchange_id'] === 'binance') {
                self::assertNull($row['current_equity']);
                self::assertSame('ACCOUNT_NOT_PROVISIONED', $row['status']);
            }
        }
    }

    public function test_missing_candle_history_is_not_hidden_by_signal_only_execution_limitations(): void
    {
        $rows = app(BinancePortabilityMatrix::class)->report()['rows'];
        self::assertCount(26, $rows);
        $leadLag = collect($rows)->first(fn ($row) => $row['exchange_id'] === 'binance'
            && $row['strategy_id'] === 'btc_eth_lead_lag');
        self::assertSame('CANDLE_HISTORY_INSUFFICIENT', $leadLag['status']);
        self::assertSame([], $leadLag['execution_blockers']);
        self::assertNotEmpty($leadLag['missing_candle_series']);

        $funding = collect($rows)->first(fn ($row) => $row['exchange_id'] === 'binance'
            && $row['strategy_id'] === 'funding_open_interest_squeeze');
        self::assertSame('CANDLE_HISTORY_INSUFFICIENT', $funding['status']);
        self::assertSame([], $funding['execution_blockers']);
    }

    public function test_account_lookups_remain_bybit_scoped(): void
    {
        $service = app(PaperAccountService::class);
        $bybit = $service->primaryAccount('trend_pullback');
        self::assertSame('bybit', $bybit->exchange_id);
        self::assertSame($bybit->id, $service->primaryAccount('trend_pullback')->id);
        $binance = $service->primaryAccount('trend_pullback', 'binance');
        self::assertSame('binance', $binance->exchange_id);
        self::assertNotSame($bybit->id, $binance->id);

        $this->expectException(\DomainException::class);
        $service->primaryAccount('trend_pullback', 'unsupported_exchange');
    }

    public function test_missing_account_values_are_null_not_invented_balance(): void
    {
        $result = $this->getJson('/api/v1/exchanges/comparison');
        $result->assertOk()->assertJsonPath('data.strategy_families', 13)
            ->assertJsonPath('data.expected_accounts', 130);
        $firstBinance = collect($result->json('data.accounts'))->firstWhere('exchange_id', 'binance');
        self::assertNull($firstBinance['current_equity']);
        self::assertNull($firstBinance['starting_balance']);
    }

    public function test_paused_paper_exchange_is_not_reported_as_evaluating_or_awaiting_signal(): void
    {
        app(PaperAccountService::class)->allPrimaryAccounts();
        $exchange = ExchangeRecord::query()->findOrFail('bybit');
        $exchange->update(['paper_approved' => false, 'lifecycle_status' => 'PAUSED']);

        $report = app(ExchangeComparisonService::class)->report();
        $row = collect($report['accounts'])->first(fn ($item) => $item['exchange_id'] === 'bybit'
            && $item['strategy_id'] === 'baseline');
        self::assertNotNull($row);
        self::assertNotSame('AWAITING_SIGNAL', $row['inactivity_reason']);
        self::assertSame('PAPER_EXECUTION_UNAPPROVED', $row['inactivity_reason']);
        self::assertSame('EXECUTION_BLOCKED', $row['evaluation_status']);
    }

    public function test_one_recent_btc_candle_cannot_claim_entire_exchange_market_is_fresh(): void
    {
        app(PaperAccountService::class)->allPrimaryAccounts();
        $exchange = ExchangeRecord::query()->findOrFail('bybit');
        $exchange->update(['paper_approved' => true, 'lifecycle_status' => 'ACTIVE_PAPER']);
        CandleRecord::query()->where('exchange', 'bybit')->delete();
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'interval' => '5', 'open_time' => now('UTC')->subMinutes(5),
            'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100',
            'volume' => '10', 'turnover' => '1000', 'is_closed' => true,
        ]);

        $report = app(ExchangeComparisonService::class)->report();
        $row = collect($report['accounts'])->first(fn ($item) => $item['exchange_id'] === 'bybit'
            && $item['strategy_id'] === 'baseline');
        self::assertNotNull($row);
        self::assertSame('MARKET_DATA_INCOMPLETE', $row['inactivity_reason']);
        self::assertContains('ETHUSDT|5', $row['market_data_missing_series']);
        self::assertContains('BTCUSDT|60', $row['market_data_missing_series']);
    }
}
