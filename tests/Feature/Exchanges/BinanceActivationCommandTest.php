<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BinanceActivationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_command_fails_without_approve_flag(): void
    {
        $this->artisan('binance:activate-paper')->assertExitCode(1);
    }

    public function test_activation_fails_when_fee_or_slippage_evidence_is_missing(): void
    {
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        config([
            'exchanges.binance.research_cycle_enabled' => true,
            'exchanges.binance.paper_execution_approved' => false,
            'exchanges.binance.paper_taker_rate' => null,
            'exchanges.binance.paper_slippage_rate' => null,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Binance paper fee/slippage evidence is missing or invalid.');

        $this->artisan('binance:activate-paper', ['--approve' => true]);
    }

    public function test_activation_succeeds_under_documented_order_and_is_idempotent(): void
    {
        // 1. Initialize 26-account topology (accounts remain BLOCKED)
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        // Documented order: paper_execution_approved is FALSE before activation!
        config([
            'exchanges.binance.research_cycle_enabled' => true,
            'exchanges.binance.paper_execution_approved' => false,
            'exchanges.binance.paper_taker_rate' => '0.0006',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
        ]);

        $baseHour = (int) floor(time() / 3600) * 3600;
        $now = (new DateTimeImmutable("@{$baseHour}"))->modify('+3 minutes');
        $this->travelTo($now);
        $this->seedReadinessData($now);

        // 2. Run activation command
        $this->artisan('binance:activate-paper', ['--approve' => true])
            ->assertSuccessful();

        // Verify exactly 11 Binance primary accounts are now ACTIVE
        self::assertSame(11, PaperAccountRecord::query()->where('exchange_id', 'binance')->where('status', 'ACTIVE')->count());

        // Verify the 2 signal-only strategies remain BLOCKED
        self::assertSame('BLOCKED', PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'btc_eth_lead_lag')->value('status'));
        self::assertSame('BLOCKED', PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'funding_open_interest_squeeze')->value('status'));

        // 3. Re-run activation command (idempotency policy)
        $this->artisan('binance:activate-paper', ['--approve' => true])
            ->assertSuccessful();

        self::assertSame(11, PaperAccountRecord::query()->where('exchange_id', 'binance')->where('status', 'ACTIVE')->count());
    }

    private function seedReadinessData(DateTimeImmutable|CarbonInterface $now): void
    {
        $symbols = ['BTCUSDT', 'ETHUSDT'];
        $nowSeconds = $now->getTimestamp();
        $baseHour = (int) floor($nowSeconds / 3600) * 3600;
        foreach ($symbols as $symbol) {
            foreach (['5', '15', '30', '60'] as $interval) {
                $seconds = (int) $interval * 60;
                // Seed exactly 220 contiguous candles on the boundary
                for ($i = 1; $i <= 220; $i++) {
                    $timestamp = $baseHour - ($i * $seconds);
                    $openTime = (new DateTimeImmutable("@{$timestamp}"))->setTimezone(new \DateTimeZone('UTC'));
                    CandleRecord::query()->create([
                        'exchange' => 'binance',
                        'category' => 'linear',
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'open_time' => $openTime,
                        'open' => '100',
                        'high' => '101',
                        'low' => '99',
                        'close' => '100.5',
                        'volume' => '100',
                        'turnover' => '10000',
                        'is_closed' => true,
                    ]);
                }
            }
            MarketContextRecord::query()->create([
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => $symbol,
                'observed_at' => $now->modify('-5 minutes'),
                'bid' => '100.4',
                'ask' => '100.6',
                'turnover_24h' => '1000000',
                'tick_size' => '0.1',
                'qty_step' => '0.001',
                'min_order_qty' => '0.001',
                'max_order_qty' => '1000',
                'min_notional_value' => '5',
            ]);
            MarketFundingEventRecord::query()->create([
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => $symbol,
                'rate' => '0.0001',
                'funding_at' => $now->modify('-2 hours'),
                'mark_price' => '100.5',
            ]);
        }
    }
}
