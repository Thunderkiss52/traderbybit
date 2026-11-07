<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\Exchanges\Binance\BinancePaperReadiness;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BinancePaperReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // This fixture verifies the preserved original 26-account financial cohort.
        config(['exchanges.enabled' => ['bybit', 'binance']]);
        DB::table('exchanges')->whereNotIn('id', ['bybit', 'binance'])->delete();
    }

    public function test_readiness_fails_when_cost_configuration_is_missing(): void
    {
        $this->seedInitialTopology();

        config([
            'exchanges.binance.paper_taker_rate' => null,
            'exchanges.binance.paper_slippage_rate' => null,
            'exchanges.binance.paper_execution_approved' => false,
        ]);

        $service = $this->app->make(BinancePaperReadiness::class);
        $report = $service->inspect();

        self::assertSame('BLOCKED', $report['overall_readiness']);
        self::assertFalse($report['cost_configuration']['valid']);
        self::assertNotNull($report['cost_configuration']['error']);
        self::assertContains('Cost configuration invalid: Binance paper fee/slippage evidence is missing or invalid.', $report['blockers']);

        $this->artisan('binance:paper-readiness')->assertExitCode(1);
    }

    public function test_readiness_fails_when_cost_rate_is_out_of_bounds(): void
    {
        $this->seedInitialTopology();

        config([
            'exchanges.binance.paper_taker_rate' => '0.15', // >= 0.1 rejected
            'exchanges.binance.paper_slippage_rate' => '0.0002',
            'exchanges.binance.paper_execution_approved' => false,
        ]);

        $service = $this->app->make(BinancePaperReadiness::class);
        $report = $service->inspect();

        self::assertSame('BLOCKED', $report['overall_readiness']);
        self::assertFalse($report['cost_configuration']['valid']);
    }

    public function test_readiness_fails_when_accounts_are_missing_or_not_funded(): void
    {
        // Topology not initialized
        config([
            'exchanges.binance.paper_taker_rate' => '0.0005',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
            'exchanges.binance.paper_execution_approved' => false,
        ]);

        $service = $this->app->make(BinancePaperReadiness::class);
        $report = $service->inspect();

        self::assertSame('BLOCKED', $report['overall_readiness']);
        self::assertFalse($report['account_census']['census_valid']);
        self::assertSame(0, $report['account_census']['actual_count']);
    }

    public function test_readiness_fails_when_candles_are_incomplete(): void
    {
        $this->seedInitialTopology();

        $baseHour = (int) floor(time() / 3600) * 3600;
        $now = (new DateTimeImmutable("@{$baseHour}"))->modify('+3 minutes');
        $this->travelTo($now);
        $this->seedReadinessData($now, includeCandles: false);

        // Seed only 50 candles instead of 220
        $this->seedCandles('BTCUSDT', '5', 50, $baseHour);

        $service = $this->app->make(BinancePaperReadiness::class);
        $report = $service->inspect();

        self::assertSame('BLOCKED', $report['overall_readiness']);
        self::assertFalse($report['candle_history']['all_ready']);
        self::assertFalse($report['candle_history']['symbols']['BTCUSDT']['5']['ready']);
        self::assertSame(50, $report['candle_history']['symbols']['BTCUSDT']['5']['count']);
    }

    public function test_readiness_fails_when_market_context_is_stale_or_missing(): void
    {
        $this->seedInitialTopology();

        $baseHour = (int) floor(time() / 3600) * 3600;
        $now = (new DateTimeImmutable("@{$baseHour}"))->modify('+3 minutes');
        $this->travelTo($now);
        $this->seedReadinessData($now, includeContext: false);

        // Stale context (30 minutes ago, max allowed is 15 min / 900s)
        MarketContextRecord::query()->create([
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'observed_at' => $now->modify('-30 minutes'),
            'bid' => '100.4',
            'ask' => '100.6',
            'turnover_24h' => '1000000',
            'tick_size' => '0.1',
            'qty_step' => '0.001',
            'min_order_qty' => '0.001',
            'max_order_qty' => '1000',
            'min_notional_value' => '5',
        ]);

        $service = $this->app->make(BinancePaperReadiness::class);
        $report = $service->inspect();

        self::assertSame('BLOCKED', $report['overall_readiness']);
        self::assertFalse($report['market_context']['all_fresh']);
        self::assertFalse($report['market_context']['symbols']['BTCUSDT']['fresh']);
        self::assertContains('Binance market context stale for BTCUSDT (age: 1800s, max: 900s)', $report['blockers']);
    }

    public function test_readiness_fails_when_funding_is_stale_or_missing(): void
    {
        $this->seedInitialTopology();

        $baseHour = (int) floor(time() / 3600) * 3600;
        $now = (new DateTimeImmutable("@{$baseHour}"))->modify('+3 minutes');
        $this->travelTo($now);
        $this->seedReadinessData($now, includeFunding: false);

        // Stale funding (30 hours ago, max allowed is 24 hours / 86400s)
        MarketFundingEventRecord::query()->create([
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'rate' => '0.0001',
            'funding_at' => $now->modify('-30 hours'),
            'mark_price' => '100.5',
        ]);

        $service = $this->app->make(BinancePaperReadiness::class);
        $report = $service->inspect();

        self::assertSame('BLOCKED', $report['overall_readiness']);
        self::assertFalse($report['funding_settlements']['all_fresh']);
        self::assertFalse($report['funding_settlements']['symbols']['BTCUSDT']['fresh']);
    }

    public function test_readiness_fails_when_research_cycle_is_stale_or_missing(): void
    {
        $this->seedInitialTopology();

        $baseHour = (int) floor(time() / 3600) * 3600;
        $now = (new DateTimeImmutable("@{$baseHour}"))->modify('+3 minutes');
        $this->travelTo($now);
        $this->seedReadinessData($now, includeResearch: false);

        $service = $this->app->make(BinancePaperReadiness::class);
        $report = $service->inspect();

        self::assertSame('BLOCKED', $report['overall_readiness']);
        self::assertFalse($report['research_cycle']['all_fresh']);
        self::assertContains('Binance research cycle observations missing for BTCUSDT', $report['blockers']);
    }

    public function test_readiness_reports_ready_for_activation_when_execution_not_approved(): void
    {
        $this->seedInitialTopology();

        $baseHour = (int) floor(time() / 3600) * 3600;
        $now = (new DateTimeImmutable("@{$baseHour}"))->modify('+3 minutes');
        $this->travelTo($now);
        $this->seedReadinessData($now);

        config([
            'exchanges.binance.paper_taker_rate' => '0.0005',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
            'exchanges.binance.paper_execution_approved' => false,
        ]);

        $service = $this->app->make(BinancePaperReadiness::class);
        $report = $service->inspect();

        self::assertSame([], $report['blockers']);
        self::assertSame('READY_FOR_ACTIVATION', $report['overall_readiness']);
        self::assertFalse($report['paper_execution_approved']);
        self::assertSame('EXECUTION_BLOCKED_PENDING_APPROVAL', $report['execution_readiness']);
        self::assertTrue($report['cost_configuration']['valid']);
        self::assertSame('0.0005', $report['cost_configuration']['taker_rate']);
        self::assertSame('0.0002', $report['cost_configuration']['slippage_rate']);
        self::assertTrue($report['candle_history']['all_ready']);
        self::assertTrue($report['market_context']['all_fresh']);
        self::assertTrue($report['funding_settlements']['all_fresh']);
        self::assertTrue($report['research_cycle']['all_fresh']);
        self::assertSame('LEDGER_PASS_ONLY', $report['m9_ledger']['status']);
        self::assertSame(26, $report['m9_ledger']['verified_pairs']);

        // Artisan command succeeds (exit code 0) for activation readiness
        $this->artisan('binance:paper-readiness')->assertExitCode(0);
    }

    public function test_readiness_reports_ready_for_paper_execution_when_execution_approved(): void
    {
        $this->seedInitialTopology();

        $baseHour = (int) floor(time() / 3600) * 3600;
        $now = (new DateTimeImmutable("@{$baseHour}"))->modify('+3 minutes');
        $this->travelTo($now);
        $this->seedReadinessData($now);

        config([
            'exchanges.binance.paper_taker_rate' => '0.0005',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
            'exchanges.binance.paper_execution_approved' => true,
        ]);

        $service = $this->app->make(BinancePaperReadiness::class);
        $report = $service->inspect();

        self::assertSame([], $report['blockers']);
        self::assertSame('READY_FOR_PAPER_EXECUTION', $report['overall_readiness']);
        self::assertTrue($report['paper_execution_approved']);
        self::assertSame('READY_FOR_PAPER_EXECUTION', $report['execution_readiness']);

        $this->artisan('binance:paper-readiness', ['--require-execution' => true])->assertExitCode(0);
    }

    public function test_readiness_fails_when_require_execution_option_set_and_approval_is_false(): void
    {
        $this->seedInitialTopology();

        $baseHour = (int) floor(time() / 3600) * 3600;
        $now = (new DateTimeImmutable("@{$baseHour}"))->modify('+3 minutes');
        $this->travelTo($now);
        $this->seedReadinessData($now);

        config([
            'exchanges.binance.paper_taker_rate' => '0.0005',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
            'exchanges.binance.paper_execution_approved' => false,
        ]);

        $service = $this->app->make(BinancePaperReadiness::class);
        $report = $service->inspect(requireExecutionApproval: true);

        self::assertSame('BLOCKED', $report['overall_readiness']);
        self::assertContains('Binance paper execution is not approved (VECTOR_BINANCE_PAPER_EXECUTION_APPROVED=false)', $report['blockers']);

        $this->artisan('binance:paper-readiness', ['--require-execution' => true])->assertExitCode(1);
    }

    public function test_readiness_reports_all_13_strategies_eligible_for_paper_execution(): void
    {
        $service = $this->app->make(BinancePaperReadiness::class);
        $report = $service->inspect();

        self::assertSame(13, $report['strategy_eligibility']['eligible_count']);
        self::assertSame(0, $report['strategy_eligibility']['signal_only_count']);
        self::assertSame([], $report['strategy_eligibility']['signal_only_strategies']);

        self::assertContains('btc_eth_lead_lag', $report['strategy_eligibility']['eligible_strategies']);
        self::assertContains('funding_open_interest_squeeze', $report['strategy_eligibility']['eligible_strategies']);
        self::assertContains('baseline', $report['strategy_eligibility']['eligible_strategies']);
        self::assertContains('trend_pullback', $report['strategy_eligibility']['eligible_strategies']);

        // Dynamically verifies configured signal-only strategies are isolated
        config(['research.forward_paper.signal_only_strategies' => ['btc_eth_lead_lag']]);
        $dynamicReport = $service->inspect();
        self::assertSame(12, $dynamicReport['strategy_eligibility']['eligible_count']);
        self::assertSame(1, $dynamicReport['strategy_eligibility']['signal_only_count']);
        self::assertContains('btc_eth_lead_lag', $dynamicReport['strategy_eligibility']['signal_only_strategies']);
    }

    private function seedInitialTopology(): void
    {
        config([
            'exchanges.binance.paper_taker_rate' => '0.0005',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
            'exchanges.binance.paper_execution_approved' => false,
            'exchanges.binance.research_cycle_enabled' => true,
        ]);

        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-READINESS-TEST');
    }

    private function seedCandles(string $symbol, string $interval, int $count, int $baseHour): void
    {
        $seconds = (int) $interval * 60;
        for ($i = 1; $i <= $count; $i++) {
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

    private function seedReadinessData(
        DateTimeImmutable|CarbonInterface $now,
        bool $includeCandles = true,
        bool $includeContext = true,
        bool $includeFunding = true,
        bool $includeResearch = true,
    ): void {
        $symbols = ['BTCUSDT', 'ETHUSDT'];
        $nowSeconds = $now->getTimestamp();
        $baseHour = (int) floor($nowSeconds / 3600) * 3600;

        foreach ($symbols as $symbol) {
            if ($includeCandles) {
                foreach (['5', '15', '30', '60'] as $interval) {
                    $this->seedCandles($symbol, $interval, 220, $baseHour);
                }
            }

            if ($includeContext) {
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
            }

            if ($includeFunding) {
                MarketFundingEventRecord::query()->create([
                    'exchange' => 'binance',
                    'category' => 'linear',
                    'symbol' => $symbol,
                    'rate' => '0.0001',
                    'funding_at' => $now->modify('-2 hours'),
                    'mark_price' => '100.5',
                ]);
            }

            if ($includeResearch) {
                $primaryStrategies = (array) config('paper.primary_strategies', []);
                $decisionTime = $now->modify('-10 minutes');
                foreach ($primaryStrategies as $strategyId) {
                    StrategyObservationRecord::query()->create([
                        'exchange' => 'binance',
                        'category' => 'linear',
                        'symbol' => $symbol,
                        'strategy_id' => $strategyId,
                        'strategy_version' => '1.0.0',
                        'configuration_hash' => 'hash_test',
                        'side' => 'BUY',
                        'score' => 75,
                        'quality_class' => 'A',
                        'decision_time' => $decisionTime,
                        'reasons' => json_encode(['TEST_SIGNAL']),
                        'analysis' => json_encode(['score' => 75]),
                    ]);
                }
            }
        }
    }
}
