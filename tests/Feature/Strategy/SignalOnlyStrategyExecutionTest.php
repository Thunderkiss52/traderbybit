<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\MarketData\Domain\Interval;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyObservationService;
use App\Strategy\Application\StrategyResearchService;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SignalOnlyStrategyExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_lag_publishes_a_signal_but_never_simulates_a_fill(): void
    {
        config()->set('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        config()->set('research.forward_paper.enabled_strategies', ['btc_eth_lead_lag']);
        config()->set('research.forward_paper.signal_only_strategies', ['btc_eth_lead_lag']);
        $this->seedSymbol('BTCUSDT', '100');
        $this->seedSymbol('ETHUSDT', '101');
        $this->seedContext('BTCUSDT');
        $this->seedContext('ETHUSDT');

        $this->app->make(StrategyResearchService::class)->updateForwardPortfolios();

        $account = $this->app->make(PaperAccountService::class)->primaryAccount('btc_eth_lead_lag');
        $signal = SignalRecord::query()->where('strategy_id', 'btc_eth_lead_lag')->where('symbol', 'BTCUSDT')->first();

        self::assertNotNull($signal);
        self::assertSame('BUY', $signal->side);
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
        self::assertSame(0, VirtualExecutionRecord::query()->count());
        self::assertSame(0, PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->count());
    }

    public function test_observation_service_and_forward_portfolio_share_the_peer_aware_signal(): void
    {
        config()->set('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        config()->set('research.forward_paper.enabled_strategies', ['btc_eth_lead_lag']);
        config()->set('research.forward_paper.signal_only_strategies', ['btc_eth_lead_lag']);
        $this->seedSymbol('BTCUSDT', '100');
        $this->seedSymbol('ETHUSDT', '101');
        $this->seedContext('BTCUSDT');
        $this->seedContext('ETHUSDT');

        $this->app->make(StrategyObservationService::class)->observe('BTCUSDT');
        $this->app->make(StrategyResearchService::class)->updateForwardPortfolios();

        $signals = SignalRecord::query()->where('strategy_id', 'btc_eth_lead_lag')->where('symbol', 'BTCUSDT')->get();

        self::assertCount(1, $signals);
        self::assertSame('BUY', $signals->first()->side);
        self::assertSame(0, VirtualPositionRecord::query()->count());
        self::assertSame(0, VirtualExecutionRecord::query()->count());
    }

    private function seedSymbol(string $symbol, string $lastClose): void
    {
        $spec = [
            [Interval::FiveMinutes, new DateTimeImmutable('2026-10-13T10:10:00Z')],
            [Interval::FifteenMinutes, new DateTimeImmutable('2026-10-13T10:00:00Z')],
            [Interval::ThirtyMinutes, new DateTimeImmutable('2026-10-13T09:30:00Z')],
            [Interval::OneHour, new DateTimeImmutable('2026-10-13T09:00:00Z')],
        ];
        foreach ($spec as [$interval, $lastOpen]) {
            $seconds = intdiv($interval->milliseconds(), 1000);
            for ($index = 219; $index >= 0; $index--) {
                $open = $lastOpen->modify('-'.($index * $seconds).' seconds');
                $close = $index === 0 && $interval === Interval::FifteenMinutes ? $lastClose : '100';
                DB::table('market_candles')->insert([
                    'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => $interval->value,
                    'open_time' => $open, 'open' => $close, 'high' => (string) ((float) $close + 0.5), 'low' => (string) ((float) $close - 0.5),
                    'close' => $close, 'volume' => '100', 'turnover' => '200000', 'is_closed' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedContext(string $symbol): void
    {
        MarketContextRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'observed_at' => new DateTimeImmutable('2026-10-13T10:15:00Z'),
            'bid' => '100.9', 'ask' => '101', 'turnover_24h' => '100000000', 'tick_size' => '0.1',
            'funding_rate' => '0.0001', 'open_interest' => '1000000', 'open_interest_change' => '0.01',
        ]);
    }
}
