<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Exchanges\Binance\BinancePaperCapital;
use App\Exchanges\Binance\BinancePaperProvisioner;
use App\Exchanges\Binance\ExchangePaperReconciliation;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\PaperTrading\Jobs\ProcessPaperTradingJob;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Jobs\ProcessPaperPipelineJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class TradingPipelineForwardCausalityTest extends TestCase
{
    use RefreshDatabase;

    private string $strategyVersion;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.pipeline_mode' => 'EVENT_DRIVEN',
            'exchanges.binance.paper_taker_rate' => '0.0005',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
            'exchanges.binance.paper_execution_approved' => true,
            'exchanges.binance.research_cycle_enabled' => true,
            'paper.processing.max_signal_ingest_lag_seconds' => 900,
        ]);
        $this->strategyVersion = app(StrategyRegistry::class)->get('baseline')->version();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    public function test_forward_paper_does_not_fill_a_bar_that_closed_before_order_activation(): void
    {
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:00:00Z'));
        $account = $this->baselineAccount();
        $decision = new DateTimeImmutable('2026-10-26T10:15:00Z');
        $this->seedContext($decision);
        $this->seedSignal($decision, new DateTimeImmutable('2026-10-26T10:15:01Z'));
        $this->seedFiveMinuteBar($decision, '101.2', '100.2', '100.8');

        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:20:30Z'));
        $this->paperJob('2026-10-26T10:20:00Z');

        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
        self::assertSame(0, VirtualExecutionRecord::query()->where('exchange_id', 'bybit')->count());
        $state = PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->firstOrFail();
        self::assertSame('PENDING_ENTRY', $state->status);

        $nextDecision = new DateTimeImmutable('2026-10-26T10:20:00Z');
        $this->seedFiveMinuteBar($nextDecision, '101.5', '100.5', '101.0');

        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:25:05Z'));
        $this->paperJob('2026-10-26T10:25:00Z');

        $position = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->firstOrFail();
        self::assertSame(PositionStatus::Open->value, $position->status);
        self::assertSame(1, VirtualExecutionRecord::query()->where('exchange_id', 'bybit')->where('type', 'ENTRY')->count());
    }

    public function test_forward_paper_rejects_a_pending_fill_older_than_the_freshness_window(): void
    {
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:00:00Z'));
        $account = $this->baselineAccount();
        $decision = new DateTimeImmutable('2026-10-26T10:15:00Z');
        $this->seedContext($decision);
        $this->seedSignal($decision, new DateTimeImmutable('2026-10-26T10:15:01Z'));
        $this->seedFiveMinuteBar($decision, '101.2', '100.2', '100.8');

        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:15:05Z'));
        $this->paperJob('2026-10-26T10:15:00Z');

        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:30:00Z'));
        $this->paperJob('2026-10-26T10:30:00Z');

        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
        $state = PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->firstOrFail();
        self::assertSame('PENDING_ENTRY', $state->status);
        self::assertSame('2026-10-26 10:20:00', $state->processed_at?->format('Y-m-d H:i:s'));
    }

    public function test_ambiguous_entry_bar_does_not_exit_before_the_next_closed_bar(): void
    {
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:00:00Z'));
        $account = $this->baselineAccount();
        $decision = new DateTimeImmutable('2026-10-26T10:15:00Z');
        $this->seedContext($decision);
        $this->seedSignal($decision, new DateTimeImmutable('2026-10-26T10:15:01Z'));
        $this->seedFiveMinuteBar($decision, '109.0', '90.0', '100.8');

        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:15:05Z'));
        $this->paperJob('2026-10-26T10:15:00Z');
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());

        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:20:02Z'));
        $this->paperJob('2026-10-26T10:20:00Z');

        $position = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->firstOrFail();
        self::assertSame(PositionStatus::Open->value, $position->status);
        self::assertTrue((bool) $position->ambiguous_execution);
        self::assertSame(1, VirtualExecutionRecord::query()->where('position_id', $position->id)->count());

        $this->seedFiveMinuteBar(new DateTimeImmutable('2026-10-26T10:20:00Z'), '109.0', '100.5', '108.5');

        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:25:02Z'));
        $this->paperJob('2026-10-26T10:25:00Z');

        self::assertSame(PositionStatus::PartiallyClosed->value, $position->fresh()->status);
        self::assertSame(2, VirtualExecutionRecord::query()->where('position_id', $position->id)->count());
    }

    public function test_event_driven_paper_never_ingests_signals_created_before_the_account(): void
    {
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:20:02Z'));
        $account = $this->baselineAccount();
        $decision = new DateTimeImmutable('2026-10-26T10:15:00Z');
        $this->seedContext($decision);
        $this->seedSignal($decision, new DateTimeImmutable('2026-10-26T10:15:01Z'));
        $this->seedFiveMinuteBar($decision, '101.2', '100.2', '100.8');

        $this->paperJob('2026-10-26T10:20:00Z');

        self::assertSame(0, PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->count());
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
    }

    public function test_stale_event_does_not_block_scheduled_management_of_existing_position(): void
    {
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:00:00Z'));
        $account = $this->baselineAccount();
        $decision = new DateTimeImmutable('2026-10-26T10:15:00Z');
        $this->seedContext($decision);
        $this->seedSignal($decision, new DateTimeImmutable('2026-10-26T10:15:01Z'));
        $this->seedFiveMinuteBar($decision, '101.2', '100.2', '100.8');

        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:15:05Z'));
        $this->paperJob('2026-10-26T10:15:00Z');

        $this->seedFiveMinuteBar(new DateTimeImmutable('2026-10-26T10:20:00Z'), '101.5', '100.5', '101.0');
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:25:05Z'));
        $this->paperJob('2026-10-26T10:25:00Z');

        $position = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->firstOrFail();
        self::assertSame(PositionStatus::Open->value, $position->status);

        $this->seedFiveMinuteBar(new DateTimeImmutable('2026-10-26T10:25:00Z'), '109.0', '100.5', '108.5');
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:40:00Z'));

        $this->paperJob('2026-10-26T10:30:00Z');
        self::assertSame(PositionStatus::Open->value, $position->fresh()->status);

        (new ProcessPaperTradingJob($account->id, 'BTCUSDT'))->handle(app(PaperProcessingService::class));

        self::assertSame(PositionStatus::PartiallyClosed->value, $position->fresh()->status);
        self::assertSame(2, VirtualExecutionRecord::query()->where('position_id', $position->id)->count());
    }

    public function test_binance_forward_fill_reconciles_130_accounts_and_preserves_other_venues(): void
    {
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:00:00Z'));
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        app(PaperAccountService::class)->allPrimaryAccounts();
        app(BinancePaperProvisioner::class)->provision();
        app(BinancePaperCapital::class)->allocate('10000', 'STAGE-D-ISOLATED');
        app(PaperTopologySynchronizer::class)->sync(true);
        self::assertSame(130, PaperAccountRecord::query()->where('is_primary', true)->count());
        $otherVenues = DB::table('paper_accounts')->whereNotIn('exchange_id', ['bybit', 'binance'])->orderBy('id')->get();
        self::assertCount(104, $otherVenues);
        foreach ($otherVenues as $otherAccount) {
            self::assertSame('BLOCKED', $otherAccount->status);
            self::assertDecimalSame('0', $otherAccount->starting_balance);
        }
        $account = PaperAccountRecord::query()->where('exchange_id', 'binance')
            ->where('primary_strategy_id', 'baseline')->firstOrFail();
        $account->update(['status' => 'ACTIVE']);

        $decision = new DateTimeImmutable('2026-10-26T10:15:00Z');
        $this->seedContext($decision, 'binance');
        $this->seedSignal($decision, new DateTimeImmutable('2026-10-26T10:15:01Z'), 'binance', $account->strategy_version);
        $this->seedFiveMinuteBar($decision, '101.2', '100.2', '100.8', 'binance');

        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:15:05Z'));
        $this->paperJob('2026-10-26T10:15:00Z', 'binance');
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());

        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-26T10:20:02Z'));
        $this->paperJob('2026-10-26T10:20:00Z', 'binance');

        $position = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->firstOrFail();
        self::assertSame('binance', $position->exchange_id);
        self::assertSame(PositionStatus::Open->value, $position->status);
        self::assertSame(1, VirtualExecutionRecord::query()->where('position_id', $position->id)->where('type', 'ENTRY')->count());

        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $report['status'], json_encode($report['issues']));
        self::assertSame(130, $report['expected_pairs']);
        self::assertSame(130, $report['verified_pairs']);
        self::assertSame($otherVenues->toJson(), DB::table('paper_accounts')->whereNotIn('exchange_id', ['bybit', 'binance'])->orderBy('id')->get()->toJson());
    }

    private function baselineAccount(): PaperAccountRecord
    {
        $service = app(PaperAccountService::class);
        $strategy = app(StrategyRegistry::class)->get('baseline');

        return $service->strategyForward('baseline', $strategy->version(), $strategy->parameters());
    }

    private function paperJob(string $at, string $exchange = 'bybit'): void
    {
        (new ProcessPaperPipelineJob($exchange, 'BTCUSDT', $at))->handle(
            app(TradingPipelineEventService::class),
            app(PaperProcessingService::class),
        );
    }

    private function seedContext(DateTimeImmutable $at, string $exchange = 'bybit'): void
    {
        MarketContextRecord::query()->create([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'observed_at' => $at,
            'funding_rate' => '0.0001',
            'open_interest' => '50000',
            'open_interest_change' => '0.01',
            'turnover_24h' => '100000000',
            'tick_size' => '0.1',
            'bid' => '100.0',
            'ask' => '100.1',
            'qty_step' => '0.001',
            'min_order_qty' => '0.001',
            'max_order_qty' => '100.0',
            'min_notional_value' => '5.0',
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function seedSignal(DateTimeImmutable $decision, DateTimeImmutable $createdAt, string $exchange = 'bybit', ?string $strategyVersion = null): void
    {
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'strategy_id' => 'baseline',
            'strategy_version' => $strategyVersion ?? $this->strategyVersion,
            'schema_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 85,
            'model_risk_percent' => '0.50',
            'entry_low' => '100.0',
            'entry_high' => '101.0',
            'stop_loss' => '95.0',
            'take_profit_1' => '108.0',
            'take_profit_2' => '115.0',
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5',
            'decision_time' => $decision,
            'candle_close_time' => $decision,
            'maximum_holding_minutes' => 240,
            'expires_at' => $decision->modify('+30 minutes'),
            'reasons' => ['trend' => true],
            'risks' => [],
            'indicator_snapshot' => [
                'strategy_id' => 'baseline',
                '15m' => ['atr_14' => '2.0', 'close' => '100.0'],
            ],
            'contract' => ['version' => '1.0.0'],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function seedFiveMinuteBar(DateTimeImmutable $openTime, string $high, string $low, string $close, string $exchange = 'bybit'): void
    {
        CandleRecord::query()->create([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '5',
            'open_time' => $openTime,
            'open' => '100.0',
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => '100.0',
            'turnover' => '10000.0',
            'is_closed' => true,
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
