<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Strategy\Contracts\SignalRepository;
use App\Strategy\Domain\PricePlan;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PostgresMigrationAndCandleBackfillIntegrationTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new DateTimeImmutable('2026-11-09 12:00:00', new DateTimeZone('UTC'));
        CarbonImmutable::setTestNow('2026-11-09T12:00:00Z');

        $this->cleanDatabase();
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    private function cleanDatabase(): void
    {
        DB::table('signal_candle_backfill_items')->delete();
        DB::table('signal_candle_backfill_checkpoints')->delete();
        DB::table('signals')->delete();
        DB::table('market_candles')->delete();
    }

    public function test_migration_is_idempotent_and_ensures_valid_index_and_foreign_key(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        // Instantiate and run the refactored migration up()
        $migration = require base_path('database/migrations/2026_11_08_230000_add_candle_id_to_signals_table.php');
        $migration->up();

        // 1. Column exists
        self::assertTrue(DB::getSchemaBuilder()->hasColumn('signals', 'candle_id'));

        if ($isPgsql) {
            // 2. Index exists and is VALID
            $indexStatus = DB::selectOne("
                SELECT c.relname, i.indisvalid
                FROM pg_class c
                JOIN pg_index i ON c.oid = i.indexrelid
                JOIN pg_class t ON t.oid = i.indrelid
                WHERE t.relname = 'signals' AND c.relname = 'signals_candle_id_index'
            ");
            self::assertNotNull($indexStatus);
            self::assertTrue((bool) $indexStatus->indisvalid);

            // 3. Foreign key exists and is VALID
            $fkValidated = DB::selectOne("
                SELECT conname, convalidated 
                FROM pg_constraint 
                WHERE conname = 'signals_candle_id_foreign'
            ");
            self::assertNotNull($fkValidated);
            self::assertTrue((bool) $fkValidated->convalidated);
        }

        // 4. Repeated run must be completely idempotent and error-free
        $migration->up();
        self::assertTrue(DB::getSchemaBuilder()->hasColumn('signals', 'candle_id'));
    }

    public function test_dry_run_identifies_matches_without_mutating_records(): void
    {
        $openTime = new DateTimeImmutable('2026-11-09 11:45:00', new DateTimeZone('UTC'));
        $closeTime = new DateTimeImmutable('2026-11-09 12:00:00', new DateTimeZone('UTC'));

        // Seed candle
        $candle = CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $openTime,
            'open' => '80000.0',
            'high' => '80500.0',
            'low' => '79900.0',
            'close' => '80200.0',
            'volume' => '100.0',
            'turnover' => '8000000.0',
            'is_closed' => true,
        ]);

        // Seed signal with NULL candle_id
        $signalId = Uuid::uuid4()->toString();
        $signal = SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'side' => 'BUY',
            'score' => 85,
            'decision_time' => $closeTime,
            'candle_close_time' => $closeTime,
            'expires_at' => $closeTime->modify('+60 minutes'),
            'maximum_holding_minutes' => 60,
            'reasons' => ['TREND_PASS'],
            'risks' => [],
            'indicator_snapshot' => ['rules' => ['TREND' => true]],
            'contract' => [],
            'strategy_version' => '1.0',
            'schema_version' => '1.0',
            'candle_id' => null,
        ]);

        // Run dry-run backfill
        $exitCode = Artisan::call('signals:backfill-candle-ids', ['--dry-run' => true]);
        self::assertSame(0, $exitCode);

        $output = Artisan::output();
        self::assertStringContainsString('DRY-RUN', $output);
        self::assertStringContainsString('Matched Signals', $output);

        // Signal record MUST NOT be mutated
        $refreshed = SignalRecord::query()->findOrFail($signalId);
        self::assertNull($refreshed->candle_id);

        // No item in backfill items table in dry-run mode
        self::assertSame(0, DB::table('signal_candle_backfill_items')->count());
    }

    public function test_apply_mode_links_closed_candles_and_persists_journal_and_checkpoint(): void
    {
        $openTime = new DateTimeImmutable('2026-11-09 11:45:00', new DateTimeZone('UTC'));
        $closeTime = new DateTimeImmutable('2026-11-09 12:00:00', new DateTimeZone('UTC'));

        $candle = CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $openTime,
            'open' => '80000.0',
            'high' => '80500.0',
            'low' => '79900.0',
            'close' => '80200.0',
            'volume' => '100.0',
            'turnover' => '8000000.0',
            'is_closed' => true,
        ]);

        $signalId = Uuid::uuid4()->toString();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'side' => 'BUY',
            'score' => 85,
            'decision_time' => $closeTime,
            'candle_close_time' => $closeTime,
            'expires_at' => $closeTime->modify('+60 minutes'),
            'maximum_holding_minutes' => 60,
            'reasons' => [],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'strategy_version' => '1.0',
            'schema_version' => '1.0',
            'candle_id' => null,
        ]);

        $exitCode = Artisan::call('signals:backfill-candle-ids', ['--apply' => true]);
        self::assertSame(0, $exitCode);

        // Signal record MUST be updated
        $refreshed = SignalRecord::query()->findOrFail($signalId);
        self::assertSame((int) $candle->id, $refreshed->candle_id);

        // Backfill journal item must be MATCHED
        $item = DB::table('signal_candle_backfill_items')->where('signal_id', $signalId)->first();
        self::assertNotNull($item);
        self::assertSame('MATCHED', $item->status);
        self::assertSame((int) $candle->id, (int) $item->candle_id);

        // Checkpoint must be updated
        $checkpoint = DB::table('signal_candle_backfill_checkpoints')->first();
        self::assertNotNull($checkpoint);
        self::assertSame(1, (int) $checkpoint->matched_count);
    }

    public function test_unclosed_candle_is_rejected_and_remains_null(): void
    {
        $openTime = new DateTimeImmutable('2026-11-09 11:45:00', new DateTimeZone('UTC'));
        $closeTime = new DateTimeImmutable('2026-11-09 12:00:00', new DateTimeZone('UTC'));

        // Candle is NOT closed
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $openTime,
            'open' => '80000.0',
            'high' => '80500.0',
            'low' => '79900.0',
            'close' => '80200.0',
            'volume' => '100.0',
            'turnover' => '8000000.0',
            'is_closed' => false,
        ]);

        $signalId = Uuid::uuid4()->toString();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'side' => 'BUY',
            'score' => 85,
            'decision_time' => $closeTime,
            'candle_close_time' => $closeTime,
            'expires_at' => $closeTime->modify('+60 minutes'),
            'maximum_holding_minutes' => 60,
            'reasons' => [],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'strategy_version' => '1.0',
            'schema_version' => '1.0',
            'candle_id' => null,
        ]);

        Artisan::call('signals:backfill-candle-ids', ['--apply' => true]);

        $refreshed = SignalRecord::query()->findOrFail($signalId);
        self::assertNull($refreshed->candle_id);

        $item = DB::table('signal_candle_backfill_items')->where('signal_id', $signalId)->first();
        self::assertNotNull($item);
        self::assertSame('UNCLOSED_CANDLE', $item->status);
        self::assertNotNull($item->retry_after);
    }

    public function test_missing_candle_records_retry_and_is_skipped_until_retry_or_force(): void
    {
        $closeTime = new DateTimeImmutable('2026-11-09 12:00:00', new DateTimeZone('UTC'));

        // No candle seeded
        $signalId = Uuid::uuid4()->toString();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'side' => 'BUY',
            'score' => 85,
            'decision_time' => $closeTime,
            'candle_close_time' => $closeTime,
            'expires_at' => $closeTime->modify('+60 minutes'),
            'maximum_holding_minutes' => 60,
            'reasons' => [],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'strategy_version' => '1.0',
            'schema_version' => '1.0',
            'candle_id' => null,
        ]);

        // Run 1: Inspects and marks NO_CANDLE
        Artisan::call('signals:backfill-candle-ids', ['--apply' => true]);
        $item = DB::table('signal_candle_backfill_items')->where('signal_id', $signalId)->first();
        self::assertSame('NO_CANDLE', $item->status);

        // Run 2: Signal has retry_after in future, should be skipped
        Artisan::call('signals:backfill-candle-ids', ['--apply' => true]);
        $output = Artisan::output();
        self::assertStringContainsString('Inspected Signals     | 0', $output);

        // Run 3: Now historical candle arrives, run with --retry-all
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => new DateTimeImmutable('2026-11-09 11:45:00', new DateTimeZone('UTC')),
            'open' => '80000.0',
            'high' => '80500.0',
            'low' => '79900.0',
            'close' => '80200.0',
            'volume' => '100.0',
            'turnover' => '8000000.0',
            'is_closed' => true,
        ]);

        Artisan::call('signals:backfill-candle-ids', ['--apply' => true, '--retry-all' => true, '--reset-checkpoint' => true]);
        $refreshed = SignalRecord::query()->findOrFail($signalId);
        self::assertNotNull($refreshed->candle_id);
    }

    public function test_cross_exchange_isolation_never_links_foreign_candles(): void
    {
        $openTime = new DateTimeImmutable('2026-11-09 11:45:00', new DateTimeZone('UTC'));
        $closeTime = new DateTimeImmutable('2026-11-09 12:00:00', new DateTimeZone('UTC'));

        // Seed Binance candle
        CandleRecord::query()->create([
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $openTime,
            'open' => '80000.0',
            'high' => '80500.0',
            'low' => '79900.0',
            'close' => '80200.0',
            'volume' => '100.0',
            'turnover' => '8000000.0',
            'is_closed' => true,
        ]);

        // Seed Bybit signal
        $signalId = Uuid::uuid4()->toString();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit', // Different exchange!
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'side' => 'BUY',
            'score' => 85,
            'decision_time' => $closeTime,
            'candle_close_time' => $closeTime,
            'expires_at' => $closeTime->modify('+60 minutes'),
            'maximum_holding_minutes' => 60,
            'reasons' => [],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'strategy_version' => '1.0',
            'schema_version' => '1.0',
            'candle_id' => null,
        ]);

        Artisan::call('signals:backfill-candle-ids', ['--apply' => true]);

        $refreshed = SignalRecord::query()->findOrFail($signalId);
        self::assertNull($refreshed->candle_id);

        $item = DB::table('signal_candle_backfill_items')->where('signal_id', $signalId)->first();
        self::assertSame('NO_CANDLE', $item->status);
    }

    public function test_batch_limits_and_checkpoint_resumption(): void
    {
        $baseTime = new DateTimeImmutable('2026-11-09 10:00:00', new DateTimeZone('UTC'));

        // Seed 4 signals and 4 candles
        for ($i = 0; $i < 4; $i++) {
            $close = $baseTime->modify('+'.(($i + 1) * 15).' minutes');
            $open = $close->modify('-15 minutes');

            CandleRecord::query()->create([
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'interval' => '15',
                'open_time' => $open,
                'open' => '80000.0',
                'high' => '80500.0',
                'low' => '79900.0',
                'close' => '80200.0',
                'volume' => '100.0',
                'turnover' => '8000000.0',
                'is_closed' => true,
            ]);

            SignalRecord::query()->create([
                'id' => Uuid::uuid4()->toString(),
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15m',
                'side' => 'BUY',
                'score' => 85,
                'decision_time' => $close,
                'candle_close_time' => $close,
                'expires_at' => $close->modify('+60 minutes'),
                'maximum_holding_minutes' => 60,
                'reasons' => [],
                'risks' => [],
                'indicator_snapshot' => [],
                'contract' => [],
                'strategy_version' => '1.0',
                'schema_version' => '1.0',
                'candle_id' => null,
            ]);
        }

        // Run 1: process max 2 rows
        Artisan::call('signals:backfill-candle-ids', ['--apply' => true, '--batch-size' => 2, '--max-rows' => 2]);
        self::assertSame(2, SignalRecord::query()->whereNotNull('candle_id')->count());
        self::assertSame(2, SignalRecord::query()->whereNull('candle_id')->count());

        // Run 2: resume and process remaining 2 rows
        Artisan::call('signals:backfill-candle-ids', ['--apply' => true, '--batch-size' => 2, '--max-rows' => 2]);
        self::assertSame(4, SignalRecord::query()->whereNotNull('candle_id')->count());
        self::assertSame(0, SignalRecord::query()->whereNull('candle_id')->count());
    }

    public function test_new_signal_creation_links_candle_id_automatically_when_closed_candle_exists(): void
    {
        $openTime = new DateTimeImmutable('2026-11-09 11:45:00', new DateTimeZone('UTC'));
        $closeTime = new DateTimeImmutable('2026-11-09 12:00:00', new DateTimeZone('UTC'));

        $candle = CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $openTime,
            'open' => '80000.0',
            'high' => '80500.0',
            'low' => '79900.0',
            'close' => '80200.0',
            'volume' => '100.0',
            'turnover' => '8000000.0',
            'is_closed' => true,
        ]);

        $decision = new SignalDecision(
            id: Uuid::uuid4()->toString(),
            exchange: 'bybit',
            category: 'linear',
            symbol: 'BTCUSDT',
            timeframe: '15m',
            decisionTime: $closeTime,
            candleCloseTime: $closeTime,
            side: SignalSide::Buy,
            qualityClass: QualityClass::A,
            score: 90,
            pricePlan: new PricePlan('80100', '80300', '79500', '81000', '82000', '1.5', '2.5'),
            modelRiskPercent: '0.02',
            expiresAt: $closeTime->modify('+60 minutes'),
            maximumHoldingMinutes: 60,
            reasons: ['BREAKOUT'],
            risks: [],
            indicatorSnapshot: ['strategy_id' => 'baseline'],
            strategyVersion: '1.0',
            schemaVersion: '1.0',
        );

        $repo = app(SignalRepository::class);
        $saved = $repo->createIfAbsent($decision);

        self::assertNotNull($saved->candleId);
        self::assertSame((int) $candle->id, $saved->candleId);

        $record = SignalRecord::query()->findOrFail($decision->id);
        self::assertSame((int) $candle->id, $record->candle_id);
    }

    public function test_ambiguous_matches_are_rejected_and_remain_unlinked(): void
    {
        $openTime = new DateTimeImmutable('2026-11-09 11:45:00', new DateTimeZone('UTC'));
        $closeTime = new DateTimeImmutable('2026-11-09 12:00:00', new DateTimeZone('UTC'));

        // Seed two candles for the same slot (temporarily bypassing unique constraint for testing or representing duplicate sources)
        // To test backfill code path for multiple matches:
        DB::statement('ALTER TABLE market_candles DROP CONSTRAINT market_candles_identity_unique');
        try {
            $c1 = CandleRecord::query()->create([
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '15',
                'open_time' => $openTime, 'open' => '80000.0', 'high' => '80500.0', 'low' => '79900.0', 'close' => '80200.0',
                'volume' => '100.0', 'turnover' => '8000000.0', 'is_closed' => true,
            ]);
            $c2 = CandleRecord::query()->create([
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '15',
                'open_time' => $openTime, 'open' => '80010.0', 'high' => '80510.0', 'low' => '79910.0', 'close' => '80210.0',
                'volume' => '100.0', 'turnover' => '8000000.0', 'is_closed' => true,
            ]);

            $signalId = Uuid::uuid4()->toString();
            SignalRecord::query()->create([
                'id' => $signalId, 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'timeframe' => '15m',
                'side' => 'BUY', 'score' => 85, 'decision_time' => $closeTime, 'candle_close_time' => $closeTime,
                'expires_at' => $closeTime->modify('+60 minutes'), 'maximum_holding_minutes' => 60,
                'reasons' => [], 'risks' => [], 'indicator_snapshot' => [], 'contract' => [],
                'strategy_version' => '1.0', 'schema_version' => '1.0', 'candle_id' => null,
            ]);

            Artisan::call('signals:backfill-candle-ids', ['--apply' => true]);

            $refreshed = SignalRecord::query()->findOrFail($signalId);
            self::assertNull($refreshed->candle_id);

            $item = DB::table('signal_candle_backfill_items')->where('signal_id', $signalId)->first();
            self::assertNotNull($item);
            self::assertSame('AMBIGUOUS', $item->status);
        } finally {
            // Restore unique constraint
            DB::statement('DELETE FROM market_candles WHERE id = '.(int) ($c2->id ?? 0));
            DB::statement('ALTER TABLE market_candles ADD CONSTRAINT market_candles_identity_unique UNIQUE (exchange, category, symbol, interval, open_time)');
        }
    }

    public function test_concurrent_worker_is_blocked_by_lock(): void
    {
        $scope = 'backfill';
        $redisLock = Cache::lock("signals:backfill-candle-ids:{$scope}", 300);
        self::assertTrue($redisLock->get());

        try {
            // Attempt to run command while Redis lock is held by another worker
            $exitCode = Artisan::call('signals:backfill-candle-ids', ['--dry-run' => true]);
            self::assertSame(1, $exitCode);
            self::assertStringContainsString('Another backfill worker', Artisan::output());
        } finally {
            $redisLock->release();
        }
    }

    public function test_timeline_endpoint_handles_both_null_and_linked_candle_id(): void
    {
        $closeTime1 = new DateTimeImmutable('2026-11-09 12:00:00', new DateTimeZone('UTC'));
        $closeTime2 = new DateTimeImmutable('2026-11-09 12:15:00', new DateTimeZone('UTC'));
        $openTime2 = $closeTime2->modify('-15 minutes');

        $candle = CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '15',
            'open_time' => $openTime2, 'open' => '80000.0', 'high' => '80500.0', 'low' => '79900.0', 'close' => '80200.0',
            'volume' => '100.0', 'turnover' => '8000000.0', 'is_closed' => true,
        ]);

        $sigNullId = Uuid::uuid4()->toString();
        SignalRecord::query()->create([
            'id' => $sigNullId, 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'timeframe' => '15m',
            'side' => 'BUY', 'score' => 85, 'decision_time' => $closeTime1, 'candle_close_time' => $closeTime1,
            'expires_at' => $closeTime1->modify('+60 minutes'), 'maximum_holding_minutes' => 60,
            'reasons' => [], 'risks' => [], 'indicator_snapshot' => [], 'contract' => [],
            'strategy_version' => '1.0', 'schema_version' => '1.0', 'candle_id' => null,
        ]);

        $sigLinkedId = Uuid::uuid4()->toString();
        SignalRecord::query()->create([
            'id' => $sigLinkedId, 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'timeframe' => '15m',
            'side' => 'BUY', 'score' => 85, 'decision_time' => $closeTime2, 'candle_close_time' => $closeTime2,
            'expires_at' => $closeTime2->modify('+60 minutes'), 'maximum_holding_minutes' => 60,
            'reasons' => [], 'risks' => [], 'indicator_snapshot' => [], 'contract' => [],
            'strategy_version' => '1.0', 'schema_version' => '1.0', 'candle_id' => (int) $candle->id,
        ]);

        // Request timeline for signal with NULL candle_id
        $resNull = $this->getJson("/api/v1/strategy-monitor/timeline?signal_id={$sigNullId}");
        $resNull->assertStatus(200);
        $timelineNull = $resNull->json('data.timeline') ?? $resNull->json('timeline', []);
        $stagesNull = array_column($timelineNull, 'stage');
        self::assertNotContains('CANDLE_CLOSED', $stagesNull);

        // Request timeline for signal with linked candle_id
        $resLinked = $this->getJson("/api/v1/strategy-monitor/timeline?signal_id={$sigLinkedId}");
        $resLinked->assertStatus(200);
        $timelineLinked = $resLinked->json('data.timeline') ?? $resLinked->json('timeline', []);
        $stagesLinked = array_column($timelineLinked, 'stage');
        self::assertContains('CANDLE_CLOSED', $stagesLinked);
    }
}
