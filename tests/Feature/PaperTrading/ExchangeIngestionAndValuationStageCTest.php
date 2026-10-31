<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\MarketData\Jobs\RecoverCandleGapsJob;
use App\MarketData\Jobs\SyncCandlesJob;
use App\PaperTrading\Application\DailyPerformanceService;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\PaperTrading\Jobs\CapturePaperEquityJob;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\TestCase;

final class ExchangeIngestionAndValuationStageCTest extends TestCase
{
    use RefreshDatabase;

    public function test_gap_recovery_routes_strictly_to_binance_ingestion_for_binance_exchange(): void
    {
        config(['exchanges.binance.automatic_candle_recovery_enabled' => true]);
        Queue::fake();
        Http::fake([
            '*fapi.binance.com*' => Http::response([]),
        ]);

        $job = new RecoverCandleGapsJob('binance', 'BTCUSDT');
        try {
            $job->handle();
            self::fail('An empty Binance response cannot complete the missing range.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Binance candle gap recovery incomplete', $e->getMessage());
        }

        // Must NOT push Bybit SyncCandlesJob!
        Queue::assertNotPushed(SyncCandlesJob::class);
    }

    public function test_gap_recovery_routes_to_bybit_sync_candles_job_for_bybit_exchange(): void
    {
        Queue::fake();

        $job = new RecoverCandleGapsJob('bybit', 'BTCUSDT');
        $job->handle();

        // Must push Bybit SyncCandlesJob
        Queue::assertPushed(SyncCandlesJob::class, function (SyncCandlesJob $pushed): bool {
            return $pushed->symbol === 'BTCUSDT';
        });
    }

    public function test_flat_account_checkpoint_is_verified_complete_with_flat_verified_valuation(): void
    {
        $account = app(PaperAccountService::class)->default();
        $account->update(['exchange_id' => 'bybit', 'is_primary' => true, 'status' => 'ACTIVE']);
        // Ensure any earlier initial setup snapshot is not newer than our test checkpoint
        EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->delete();

        // Account is flat (zero open positions)
        self::assertFalse(VirtualPositionRecord::query()->where('paper_account_id', $account->id)->whereIn('status', [PositionStatus::Open->value, PositionStatus::PartiallyClosed->value])->exists());

        $job = new CapturePaperEquityJob;
        $job->handle();

        $snapshot = EquitySnapshotRecord::query()
            ->where('paper_account_id', $account->id)
            ->where('source_event_key', 'like', 'checkpoint:%')
            ->first();

        self::assertNotNull($snapshot);
        self::assertTrue((bool) $snapshot->valuation_fresh);
        self::assertNotNull($snapshot->valued_through);

        // Daily performance evaluation
        $daily = app(DailyPerformanceService::class);
        $report = $daily->report($account->id, 'UTC', null, 1, 10);
        $today = $report['summary']['today'];

        self::assertTrue($today['complete']);
        self::assertSame('FLAT_VERIFIED', $today['valuation_status']);
    }

    public function test_account_with_stale_market_valuation_is_flagged_as_stale_and_incomplete(): void
    {
        $account = app(PaperAccountService::class)->default();
        $account->update(['exchange_id' => 'bybit', 'is_primary' => true, 'status' => 'ACTIVE']);
        // Ensure any earlier initial setup snapshot is not newer than our test checkpoint
        EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->delete();

        $signal = SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'timeframe' => '15',
            'entry_low' => '59900',
            'entry_high' => '60100',
            'stop_loss' => '59000',
            'take_profit_1' => '61000',
            'take_profit_2' => '63000',
            'risk_reward_1' => '1.1',
            'risk_reward_2' => '3.3',
            'decision_time' => new DateTimeImmutable('2026-10-27T08:00:00Z'),
            'candle_close_time' => new DateTimeImmutable('2026-10-27T08:00:00Z'),
            'expires_at' => new DateTimeImmutable('2026-10-27T20:00:00Z'),
            'maximum_holding_minutes' => 720,
            'model_risk_percent' => '0.50',
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['close' => '60000']],
            'contract' => ['research_mode' => true],
            'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // Create an open position whose last_processed_event_at was 25 minutes ago (> 300s)
        $staleTime = CarbonImmutable::now('UTC')->subMinutes(25);
        VirtualPositionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => PositionStatus::Open->value,
            'entry_price' => '60000',
            'stop_loss' => '59000',
            'take_profit_1' => '61000',
            'take_profit_2' => '63000',
            'initial_quantity' => '0.010',
            'remaining_quantity' => '0.010',
            'qty_step' => '0.001',
            'risk_capital' => '100',
            'gross_pnl' => '0',
            'net_pnl' => '0',
            'unrealized_pnl' => '50', // Stale unrealized mark!
            'fees' => '0.33',
            'funding' => '0',
            'opened_at' => $staleTime,
            'maximum_holding_until' => $staleTime->addHours(12),
            'last_processed_event_at' => $staleTime,
            'funding_complete' => false,
            'execution_model' => 'SIMULATED',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        $account->update(['unrealized_pnl' => '50']);

        // Checkpoint runs now (fresh timestamp)
        $job = new CapturePaperEquityJob;
        $job->handle();

        $snapshot = EquitySnapshotRecord::query()
            ->where('paper_account_id', $account->id)
            ->where('source_event_key', 'like', 'checkpoint:%')
            ->first();

        self::assertNotNull($snapshot);
        // Because open position valuation is 25 minutes old, snapshot must record valuation_fresh = false!
        self::assertFalse((bool) $snapshot->valuation_fresh);
        self::assertSame($staleTime->format('Y-m-d H:i:s'), CarbonImmutable::parse($snapshot->valued_through)->format('Y-m-d H:i:s'));

        // Daily performance evaluation
        $daily = app(DailyPerformanceService::class);
        $report = $daily->report($account->id, 'UTC', null, 1, 10);
        $today = $report['summary']['today'];

        self::assertFalse($today['complete']);
        self::assertFalse($today['valuation_fresh']);
        self::assertSame('STALE', $today['valuation_status']);
        self::assertContains('open_positions_market_valuation_stale', $today['incomplete_reasons']);
    }
}
