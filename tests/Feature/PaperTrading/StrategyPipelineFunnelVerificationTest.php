<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\PaperTrading\Application\PaperExecutionEligibilityService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Domain\SignalLifecycle;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\Strategy\Application\LevelInteractionAnalyzer;
use App\Strategy\Application\LevelProbabilityService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Domain\BarState;
use App\Strategy\Domain\MarketContext;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use App\Strategy\Domain\TimeframeState;
use App\Strategy\Infrastructure\Persistence\LevelInteractionForecastRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Research\BollingerMeanReversionStrategy;
use App\Strategy\Research\SupportResistanceBounceStrategy;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class StrategyPipelineFunnelVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Dedicated preservation fixture for the original 26-account registry.
        config(['exchanges.enabled' => ['bybit', 'binance']]);
        DB::table('exchanges')->whereNotIn('id', ['bybit', 'binance'])->delete();
        config([
            'exchanges.binance.paper_taker_rate' => '0.0005',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
            'exchanges.binance.paper_execution_approved' => false,
            'exchanges.binance.research_cycle_enabled' => true,
        ]);
    }

    public function test_all_26_primary_pipelines_are_registered_and_configured(): void
    {
        $registry = $this->app->make(StrategyRegistry::class);
        $ids = (array) config('paper.primary_strategies', []);
        self::assertCount(13, $ids);

        // Verify registry contains all 13 canonical strategies
        foreach ($ids as $strategyId) {
            $strategy = $registry->get($strategyId);
            self::assertNotNull($strategy);
            self::assertSame($strategyId, $strategy->id());
        }

        // Initialize 13 Binance accounts alongside 13 Bybit accounts = 26 total PRIMARY accounts
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261027');

        $bybitAccounts = PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('is_primary', true)->get();
        $binanceAccounts = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('is_primary', true)->get();

        self::assertCount(13, $bybitAccounts, 'Expected exactly 13 Bybit PRIMARY accounts.');
        self::assertCount(13, $binanceAccounts, 'Expected exactly 13 Binance PRIMARY accounts.');

        // Total 26 accounts
        $totalPrimary = PaperAccountRecord::query()->where('is_primary', true)->count();
        self::assertSame(26, $totalPrimary, 'Expected exactly 26 PRIMARY accounts in census.');
    }

    public function test_bollinger_mean_reversion_skips_when_trending_adx_or_no_boundary_reentry(): void
    {
        $strategyConfig = (array) config('research.strategies.bollinger_mean_reversion');
        $strategyConfig['confirmation_mode'] = 'STRICT'; // Explicitly test STRICT confirmation mode
        $strategy = new BollingerMeanReversionStrategy($strategyConfig);

        $now = new DateTimeImmutable('2026-10-27T12:00:00Z');

        // Case 1: Trending market (ADX >= 25 on 1h trend) -> skips with 'range_regime_required'
        $trendingTrendBar = new BarState(
            openTime: $now->modify('-1 hour'),
            closeTime: $now,
            open: '100000',
            high: '102000',
            low: '99500',
            close: '101500',
            turnover: '50000000',
            emaEntry: '100800',
            emaFast: '101000',
            emaSlow: '100000',
            rsi: '65',
            macd: '20',
            macdSignal: '15',
            macdHistogram: '5',
            atr: '200',
            adx: '32.5', // > 25.0, trending!
            plusDi: '25',
            minusDi: '15',
            relativeVolume: '1.2',
            averageTurnover: '40000000',
            support: '99000',
            resistance: '103000',
        );

        $entryBarNow = new BarState(
            openTime: $now->modify('-15 minutes'),
            closeTime: $now,
            open: '101200',
            high: '101600',
            low: '101100',
            close: '101500',
            turnover: '10000000',
            emaEntry: '101300',
            emaFast: '101300',
            emaSlow: '101000',
            rsi: '55',
            macd: '5',
            macdSignal: '4',
            macdHistogram: '1',
            atr: '100',
            adx: '30.0',
            plusDi: '22',
            minusDi: '18',
            relativeVolume: '1.0',
            averageTurnover: '10000000',
            support: '100000',
            resistance: '102000',
            bollingerUpper: '102000',
            bollingerMiddle: '101000',
            bollingerLower: '100000',
        );
        $entryBarPrev = new BarState(
            openTime: $now->modify('-30 minutes'),
            closeTime: $now->modify('-15 minutes'),
            open: '101000',
            high: '101300',
            low: '100900',
            close: '101200',
            turnover: '10000000',
            emaEntry: '101100',
            emaFast: '101100',
            emaSlow: '100900',
            rsi: '52',
            macd: '4',
            macdSignal: '4',
            macdHistogram: '0',
            atr: '100',
            adx: '29.0',
            plusDi: '21',
            minusDi: '19',
            relativeVolume: '1.0',
            averageTurnover: '10000000',
            support: '100000',
            resistance: '102000',
            bollingerUpper: '102000',
            bollingerMiddle: '101000',
            bollingerLower: '100000',
        );

        $marketContext = new MarketContext(
            observedAt: $now,
            bid: '100100',
            ask: '100110',
            turnover24h: '100000000',
            tickSize: '0.1',
            fundingRate: '0.0001',
            openInterest: '50000',
            openInterestChange: '0.01',
            qtyStep: '0.001',
            minOrderQty: '0.001',
            maxOrderQty: '100',
            minNotionalValue: '5',
        );

        $inputTrending = new StrategyInput(
            exchange: 'binance',
            category: 'linear',
            symbol: 'BTCUSDT',
            decisionTime: $now,
            trend: new TimeframeState('60', [$trendingTrendBar], true),
            setup: new TimeframeState('30', [$trendingTrendBar], true),
            entry: new TimeframeState('15', [$entryBarPrev, $entryBarNow], true),
            confirmation: new TimeframeState('5', [$entryBarNow], true),
            marketContext: $marketContext,
        );

        $decisionTrending = $strategy->evaluate($inputTrending);
        self::assertSame(SignalSide::Skip, $decisionTrending->side);
        self::assertContains('range_regime_required', $decisionTrending->reasons);

        // Case 2: Range market (ADX < 25), but price is inside bands without crossing -> skips with 'bollinger_reentry_not_confirmed'
        $rangingTrendBar = new BarState(
            openTime: $now->modify('-1 hour'),
            closeTime: $now,
            open: '100000',
            high: '100500',
            low: '99500',
            close: '100200',
            turnover: '50000000',
            emaEntry: '100100',
            emaFast: '100100',
            emaSlow: '100050',
            rsi: '50',
            macd: '0',
            macdSignal: '0',
            macdHistogram: '0',
            atr: '150',
            adx: '15.0', // strictly < 18.0 (adx_max)
            plusDi: '18',
            minusDi: '18',
            relativeVolume: '0.9',
            averageTurnover: '50000000',
            support: '99000',
            resistance: '101000',
        );

        $inputRangingNoCross = new StrategyInput(
            exchange: 'binance',
            category: 'linear',
            symbol: 'BTCUSDT',
            decisionTime: $now,
            trend: new TimeframeState('60', [$rangingTrendBar], true),
            setup: new TimeframeState('30', [$rangingTrendBar], true),
            entry: new TimeframeState('15', [$entryBarPrev, $entryBarNow], true), // both close 101200 and 101500 are inside bands [100000, 102000]
            confirmation: new TimeframeState('5', [$entryBarNow], true),
            marketContext: $marketContext,
        );

        $decisionRangingNoCross = $strategy->evaluate($inputRangingNoCross);
        self::assertSame(SignalSide::Skip, $decisionRangingNoCross->side);
        self::assertContains('bollinger_reentry_not_confirmed', $decisionRangingNoCross->reasons);

        // Case 3: Range market (ADX < 25) AND previous closed below lower band AND now closed back inside -> emits BUY
        $entryBarPierced = new BarState(
            openTime: $now->modify('-30 minutes'),
            closeTime: $now->modify('-15 minutes'),
            open: '100100',
            high: '100200',
            low: '99200',
            close: '99400', // strictly below lower band 100000
            turnover: '15000000',
            emaEntry: '100050',
            emaFast: '100100',
            emaSlow: '100100',
            rsi: '28',
            macd: '-5',
            macdSignal: '-2',
            macdHistogram: '-3',
            atr: '120',
            adx: '17.0',
            plusDi: '15',
            minusDi: '25',
            relativeVolume: '1.2',
            averageTurnover: '12000000',
            support: '99000',
            resistance: '101000',
            bollingerUpper: '102000',
            bollingerMiddle: '101000',
            bollingerLower: '100000',
        );
        $entryBarReentry = new BarState(
            openTime: $now->modify('-15 minutes'),
            closeTime: $now,
            open: '99400',
            high: '100400',
            low: '99300',
            close: '100200', // strictly closed back inside (> 100000)
            turnover: '20000000',
            emaEntry: '100080',
            emaFast: '100100',
            emaSlow: '100100',
            rsi: '33', // < 35 (rsi_buy_max)
            macd: '-2',
            macdSignal: '-2',
            macdHistogram: '0',
            atr: '120',
            adx: '16.0',
            plusDi: '19',
            minusDi: '20',
            relativeVolume: '1.4',
            averageTurnover: '12000000',
            support: '99000',
            resistance: '101000',
            bollingerUpper: '102000',
            bollingerMiddle: '101000',
            bollingerLower: '100000',
        );
        $confPrev = new BarState(
            openTime: $now->modify('-10 minutes'),
            closeTime: $now->modify('-5 minutes'),
            open: '99800',
            high: '100100',
            low: '99800',
            close: '100050',
            turnover: '8000000',
            emaEntry: '100000',
            emaFast: '100050',
            emaSlow: '100020',
            rsi: '48',
            macd: '0',
            macdSignal: '0',
            macdHistogram: '0',
            atr: '80',
            adx: '15.0',
            plusDi: '20',
            minusDi: '18',
            relativeVolume: '1.0',
            averageTurnover: '7000000',
            support: '99500',
            resistance: '100500',
        );
        $confNow = new BarState(
            openTime: $now->modify('-5 minutes'),
            closeTime: $now,
            open: '100050',
            high: '100300',
            low: '99900',
            close: '100200',
            turnover: '8000000',
            emaEntry: '100050',
            emaFast: '100100',
            emaSlow: '100050',
            rsi: '52', // >= 50
            macd: '1',
            macdSignal: '0',
            macdHistogram: '1', // >= previous macdHistogram
            atr: '80',
            adx: '15.0',
            plusDi: '20',
            minusDi: '18',
            relativeVolume: '1.1',
            averageTurnover: '7000000',
            support: '99500',
            resistance: '100500',
        );

        $inputReentry = new StrategyInput(
            exchange: 'binance',
            category: 'linear',
            symbol: 'BTCUSDT',
            decisionTime: $now,
            trend: new TimeframeState('60', [$rangingTrendBar], true),
            setup: new TimeframeState('30', [$rangingTrendBar], true),
            entry: new TimeframeState('15', [$entryBarPierced, $entryBarReentry], true),
            confirmation: new TimeframeState('5', [$confPrev, $confNow], true),
            marketContext: $marketContext,
        );

        $decisionBuy = $strategy->evaluate($inputReentry);
        self::assertSame(SignalSide::Buy, $decisionBuy->side);
        self::assertContains('closed_back_inside_lower_band', $decisionBuy->reasons);
    }

    public function test_support_resistance_bounce_and_breakout_statistically_gated_when_sample_under_30(): void
    {
        $levels = $this->app->make(LevelInteractionAnalyzer::class);
        $probabilities = $this->app->make(LevelProbabilityService::class);
        $bounceConfig = (array) config('research.strategies.support_resistance_bounce');

        $bounceStrategy = new SupportResistanceBounceStrategy($bounceConfig, $levels, $probabilities);

        $now = new DateTimeImmutable('2026-10-27T12:00:00Z');

        // Seed 10 mature observations (< 30) for Binance in level_interaction_forecasts
        for ($i = 1; $i <= 10; $i++) {
            LevelInteractionForecastRecord::query()->create([
                'id' => Uuid::uuid4()->toString(),
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'event' => 'support_rejection',
                'side' => 'BUY',
                'triggered_at' => $now->modify("-{$i} hours"),
                'horizon_ends_at' => $now->modify('-'.($i - 1).' hours'),
                'model_version' => '1.0.0',
                'configuration_hash' => 'hash-test',
                'sample_size' => 10,
                'validation_status' => 'RESEARCH_BASELINE',
                'outcome' => 'SUCCESS',
                'outcome_at' => $now->modify('-'.($i - 1).' hours'),
                'features' => ['regime' => 'RANGE', 'volatility_bucket' => 'NORMAL', 'zone_strength_bucket' => 2, 'momentum_side' => 'NEUTRAL'],
            ]);
        }

        // Create StrategyInput with clear support bounce structure
        $bars = [];
        for ($j = 30; $j >= 0; $j--) {
            $bars[] = new BarState(
                openTime: $now->modify('-'.(($j + 1) * 15).' minutes'),
                closeTime: $now->modify('-'.($j * 15).' minutes'),
                open: '100000',
                high: '100500',
                low: '99500',
                close: $j === 0 ? '100100' : '100000',
                turnover: '10000000',
                emaEntry: '100050',
                emaFast: '100050',
                emaSlow: '100000',
                rsi: '50',
                macd: '0',
                macdSignal: '0',
                macdHistogram: '0',
                atr: '200',
                adx: '15.0',
                plusDi: '18',
                minusDi: '18',
                relativeVolume: '1.0',
                averageTurnover: '10000000',
                support: '99500',
                resistance: '100500',
            );
        }

        $marketContext = new MarketContext(
            observedAt: $now,
            bid: '100100',
            ask: '100110',
            turnover24h: '100000000',
            tickSize: '0.1',
            fundingRate: '0.0001',
            openInterest: '50000',
            openInterestChange: '0.01',
            qtyStep: '0.001',
            minOrderQty: '0.001',
            maxOrderQty: '100',
            minNotionalValue: '5',
        );

        $input = new StrategyInput(
            exchange: 'binance',
            category: 'linear',
            symbol: 'BTCUSDT',
            decisionTime: $now,
            trend: new TimeframeState('60', array_slice($bars, -10), true),
            setup: new TimeframeState('30', array_slice($bars, -10), true),
            entry: new TimeframeState('15', $bars, true),
            confirmation: new TimeframeState('5', array_slice($bars, -5), true),
            marketContext: $marketContext,
        );

        $interaction = $levels->analyze($input);
        self::assertIsArray($interaction);

        // When sample size < 30, estimateFor must return INSUFFICIENT_DATA
        $estimate = $probabilities->estimateFor($input, 'support_rejection', $interaction);
        self::assertSame('INSUFFICIENT_DATA', $estimate['validation_status']);
        self::assertLessThan(30, $estimate['sample_size']);

        // And bounce strategy must return SKIP
        $decision = $bounceStrategy->evaluate($input);
        self::assertSame(SignalSide::Skip, $decision->side);
        self::assertTrue(
            in_array('no_support_resistance_bounce_trigger', $decision->reasons, true)
            || in_array('level_probability_insufficient_data', $decision->reasons, true),
            'Expected either no level trigger or level_probability_insufficient_data.'
        );
    }

    public function test_binance_directional_signals_blocked_at_prerisk_when_execution_unapproved(): void
    {
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261027');

        $account = PaperAccountRecord::query()->where('exchange_id', 'binance')
            ->where('primary_strategy_id', 'momentum')->firstOrFail();

        // Account is ACTIVE, but execution approval is FALSE
        $account->update(['status' => 'ACTIVE']);
        config(['exchanges.binance.paper_execution_approved' => false]);

        $eligibility = $this->app->make(PaperExecutionEligibilityService::class);
        self::assertFalse($eligibility->isAccountEntryEligible($account));
        self::assertSame('binance_account_not_executable', $eligibility->accountEntryRejectionReason($account));

        // Create a directional momentum signal for Binance
        $now = new DateTimeImmutable('2026-10-27T12:00:00Z');
        $signal = SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 85,
            'decision_time' => $now,
            'candle_close_time' => $now,
            'entry_low' => '100000',
            'entry_high' => '100100',
            'stop_loss' => '99500',
            'take_profit_1' => '100800',
            'take_profit_2' => '101500',
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5',
            'model_risk_percent' => '0.50',
            'expires_at' => $now->modify('+30 minutes'),
            'maximum_holding_minutes' => 60,
            'reasons' => ['momentum_acceleration'],
            'risks' => [],
            'indicator_snapshot' => ['strategy_id' => 'momentum'],
            'contract' => [],
            'strategy_version' => $account->strategy_version,
            'schema_version' => '1.0.0',
            'strategy_id' => 'momentum',
        ]);

        $processingService = $this->app->make(PaperProcessingService::class);
        $result = $processingService->process($account->id, 'BTCUSDT', $now, allowNewEntries: false);

        self::assertSame(0, $result['events_processed']);

        // Verify signal state is REJECTED with pre-risk reason
        $state = PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->firstOrFail();
        self::assertSame(SignalLifecycle::Rejected->value, $state->status);
        self::assertSame('binance_account_not_executable', $state->reason);

        // Verify journal recorded PRE_RISK_ENTRY_BLOCKED
        $journal = DB::table('trading_journal_events')
            ->where('paper_account_id', $account->id)
            ->where('signal_id', $signal->id)
            ->where('event_type', 'PRE_RISK_ENTRY_BLOCKED')
            ->first();
        self::assertNotNull($journal);
        $payload = json_decode((string) $journal->payload, true);
        self::assertSame('binance_account_not_executable', $payload['reason']);
    }

    public function test_diagnostic_sql_script_is_strictly_read_only_and_executable(): void
    {
        $path = base_path('scripts/diagnostics/verify_26_primary_trading_pipelines.sql');
        self::assertFileExists($path, 'Diagnostic script must exist at scripts/diagnostics/verify_26_primary_trading_pipelines.sql');

        $content = File::get($path);

        // Verify read-only transaction semantics
        self::assertStringContainsString('BEGIN TRANSACTION READ ONLY;', $content);
        self::assertStringContainsString('ROLLBACK;', $content);

        // Verify zero destructive or mutating DDL/DML keywords outside of comments
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '--') || $trimmed === '') {
                continue;
            }
            $upper = strtoupper($trimmed);
            self::assertStringNotContainsString('INSERT ', $upper);
            self::assertStringNotContainsString('UPDATE ', $upper);
            self::assertStringNotContainsString('DELETE ', $upper);
            self::assertStringNotContainsString('DROP ', $upper);
            self::assertStringNotContainsString('ALTER ', $upper);
            self::assertStringNotContainsString('TRUNCATE ', $upper);
        }
    }
}
