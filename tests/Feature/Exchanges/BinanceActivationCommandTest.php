<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\Exchanges\Binance\BinancePaperActivation;
use App\Exchanges\Binance\BinancePaperSafety;
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
        $activationService = $this->app->make(BinancePaperActivation::class);
        $result = $activationService->activate();

        self::assertSame(13, $result['paper_activated']);
        self::assertSame(0, $result['already_active']);
        self::assertSame(0, $result['still_signal_only']);
        self::assertFalse($result['paper_execution_approved']);
        self::assertSame('EXECUTION_BLOCKED_PENDING_APPROVAL', $result['execution_readiness']);

        $this->artisan('binance:activate-paper', ['--approve' => true])
            ->assertSuccessful();

        // Verify exactly 13 Binance primary accounts are now ACTIVE
        self::assertSame(13, PaperAccountRecord::query()->where('exchange_id', 'binance')->where('status', 'ACTIVE')->count());

        // Verify all 13 strategies including previously signal-only strategies are ACTIVE
        self::assertSame('ACTIVE', PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'btc_eth_lead_lag')->value('status'));
        self::assertSame('ACTIVE', PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'funding_open_interest_squeeze')->value('status'));

        // 3. Re-run activation command (idempotency policy)
        $rerunResult = $activationService->activate();
        self::assertSame(0, $rerunResult['paper_activated']);
        self::assertSame(13, $rerunResult['already_active']);
        self::assertSame(0, $rerunResult['still_signal_only']);

        $this->artisan('binance:activate-paper', ['--approve' => true])
            ->assertSuccessful();

        self::assertSame(13, PaperAccountRecord::query()->where('exchange_id', 'binance')->where('status', 'ACTIVE')->count());

        // Verify that even though accounts are ACTIVE, assertExecutable still fails fail-closed while paper_execution_approved is false
        $activeAccount = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('status', 'ACTIVE')->first();
        self::assertNotNull($activeAccount);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Binance paper execution is not approved.');
        BinancePaperSafety::assertExecutable($activeAccount);
    }

    public function test_activation_reflects_approved_execution_readiness_when_flag_enabled(): void
    {
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        config([
            'exchanges.binance.research_cycle_enabled' => true,
            'exchanges.binance.paper_execution_approved' => true,
            'exchanges.binance.paper_taker_rate' => '0.0005',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
        ]);

        $baseHour = (int) floor(time() / 3600) * 3600;
        $now = (new DateTimeImmutable("@{$baseHour}"))->modify('+3 minutes');
        $this->travelTo($now);
        $this->seedReadinessData($now);

        $activationService = $this->app->make(BinancePaperActivation::class);
        $result = $activationService->activate();

        self::assertSame(13, $result['paper_activated']);
        self::assertTrue($result['paper_execution_approved']);
        self::assertSame('READY_FOR_PAPER_EXECUTION', $result['execution_readiness']);

        $activeAccount = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('status', 'ACTIVE')->first();
        self::assertNotNull($activeAccount);
        // Does not throw when execution is approved
        BinancePaperSafety::assertExecutable($activeAccount);
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
