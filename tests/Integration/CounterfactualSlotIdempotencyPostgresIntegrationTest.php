<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\CounterfactualAnalyzerService;
use App\Research\Infrastructure\Persistence\CounterfactualEvaluationRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class CounterfactualSlotIdempotencyPostgresIntegrationTest extends TestCase
{
    private array $cleanUpEvaluationIds = [];

    private array $cleanUpObservationIds = [];

    private array $cleanUpSignalIds = [];

    private array $cleanUpCandleIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['research.counterfactual.legacy_analysis_enabled' => true]);
        $this->ensurePostgresTestDatabaseReady('counterfactual_evaluations');
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (! empty($this->cleanUpEvaluationIds)) {
                CounterfactualEvaluationRecord::query()->whereIn('id', $this->cleanUpEvaluationIds)->delete();
            }
            if (! empty($this->cleanUpObservationIds)) {
                StrategyObservationRecord::query()->whereIn('id', $this->cleanUpObservationIds)->delete();
            }
            if (! empty($this->cleanUpSignalIds)) {
                SignalRecord::query()->whereIn('id', $this->cleanUpSignalIds)->delete();
            }
            if (! empty($this->cleanUpCandleIds)) {
                CandleRecord::query()->whereIn('id', $this->cleanUpCandleIds)->delete();
            }
        }

        parent::tearDown();
    }

    public function test_sequential_rerun_returns_canonical_evaluation_without_duplicate(): void
    {
        $now = CarbonImmutable::parse('2026-10-18 14:45:00', 'UTC');

        // Create candle
        $candle = CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'interval' => '15',
            'open_time' => $now->subMinutes(15),
            'open' => '2380.00',
            'high' => '2390.00',
            'low' => '2370.00',
            'close' => '2376.17',
            'volume' => '1000.0',
            'turnover' => '2376170.0',
            'is_closed' => true,
        ]);
        $this->cleanUpCandleIds[] = $candle->id;

        // Create signal
        $signal = SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'timeframe' => '15',
            'side' => 'SKIP',
            'strategy_id' => 'baseline',
            'strategy_version' => '1.2.0',
            'score' => 0,
            'decision_time' => $now,
            'candle_close_time' => $now,
            'expires_at' => $now->addHours(2),
            'maximum_holding_minutes' => 240,
            'reasons' => ['five_minute_confirmation_conflicts'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);
        $this->cleanUpSignalIds[] = $signal->id;

        // Create observation 1
        $obs1 = StrategyObservationRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'strategy_id' => 'baseline',
            'strategy_version' => '1.2.0',
            'configuration_hash' => 'hash_run_1',
            'side' => 'SKIP',
            'score' => 0,
            'decision_time' => $now,
            'reasons' => ['five_minute_confirmation_conflicts'],
            'analysis' => ['diagnostics' => ['decision' => 'SKIP']],
        ]);
        $this->cleanUpObservationIds[] = $obs1->id;

        $service = $this->app->make(CounterfactualAnalyzerService::class);

        // Run 1: Should create canonical evaluation
        $eval1 = $service->createFromObservation($obs1);
        self::assertNotNull($eval1);
        self::assertNull($eval1->superseded_by);
        self::assertSame($signal->id, $eval1->signal_id);
        $this->cleanUpEvaluationIds[] = $eval1->id;

        // Create observation 2 with differing config hash (idempotency enforced by unique slot constraint)
        $obs2Id = (string) Uuid::uuid4();
        StrategyObservationRecord::query()->insertOrIgnore([[
            'id' => $obs2Id,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'strategy_id' => 'baseline',
            'strategy_version' => '1.2.0',
            'configuration_hash' => 'hash_run_2',
            'side' => 'SKIP',
            'quality_class' => null,
            'score' => 0,
            'decision_time' => $now,
            'reasons' => json_encode(['five_minute_confirmation_conflicts'], JSON_THROW_ON_ERROR),
            'analysis' => json_encode(['diagnostics' => ['decision' => 'SKIP']], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
        $this->cleanUpObservationIds[] = $obs2Id;

        // Verify strategy_observations canonical count remains exactly 1 for this slot!
        $canonicalObsCount = StrategyObservationRecord::query()
            ->where('strategy_id', 'baseline')
            ->where('strategy_version', '1.2.0')
            ->where('symbol', 'ETHUSDT')
            ->where('decision_time', $now)
            ->whereNull('superseded_by')
            ->count();
        self::assertSame(1, $canonicalObsCount, 'strategy_observations canonical count must remain 1');

        $obs2 = StrategyObservationRecord::query()
            ->where('strategy_id', 'baseline')
            ->where('strategy_version', '1.2.0')
            ->where('symbol', 'ETHUSDT')
            ->where('decision_time', $now)
            ->whereNull('superseded_by')
            ->firstOrFail();

        // Run 2: Should retrieve the existing canonical evaluation and NOT create a new row
        $eval2 = $service->createFromObservation($obs2);
        self::assertNotNull($eval2);
        self::assertSame($eval1->id, $eval2->id);

        // Verify database has exactly 1 canonical record for this slot
        $canonicalCount = CounterfactualEvaluationRecord::query()
            ->where('strategy_id', 'baseline')
            ->where('symbol', 'ETHUSDT')
            ->where('decision_time', $now)
            ->whereNull('superseded_by')
            ->count();
        self::assertSame(1, $canonicalCount);
    }

    public function test_distinct_strategy_versions_preserve_separate_evaluations(): void
    {
        $now = CarbonImmutable::parse('2026-10-18 15:00:00', 'UTC');

        $candle = CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $now->subMinutes(15),
            'open' => '65000.00',
            'high' => '65500.00',
            'low' => '64800.00',
            'close' => '65200.00',
            'volume' => '500.0',
            'turnover' => '32600000.0',
            'is_closed' => true,
        ]);
        $this->cleanUpCandleIds[] = $candle->id;

        // Strategy version 1.0.0
        $obsV1 = StrategyObservationRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_v1',
            'side' => 'SKIP',
            'score' => 0,
            'decision_time' => $now,
            'reasons' => ['ema_filter_not_met'],
            'analysis' => [],
        ]);
        $this->cleanUpObservationIds[] = $obsV1->id;

        // Candidate strategy version 1.1.0-cand
        $obsV2 = StrategyObservationRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.1.0-cand',
            'configuration_hash' => 'hash_v2',
            'side' => 'SKIP',
            'score' => 0,
            'decision_time' => $now,
            'reasons' => ['adx_low'],
            'analysis' => [],
        ]);
        $this->cleanUpObservationIds[] = $obsV2->id;

        $service = $this->app->make(CounterfactualAnalyzerService::class);
        $eval1 = $service->createFromObservation($obsV1);
        $eval2 = $service->createFromObservation($obsV2);

        $this->cleanUpEvaluationIds[] = $eval1->id;
        $this->cleanUpEvaluationIds[] = $eval2->id;

        self::assertNotSame($eval1->id, $eval2->id);
        self::assertSame('1.0.0', $eval1->strategy_version);
        self::assertSame('1.1.0-cand', $eval2->strategy_version);
    }

    public function test_negative_control_duplicate_direct_insert_rejected_by_postgresql_unique_constraint(): void
    {
        $now = CarbonImmutable::parse('2026-10-18 15:15:00', 'UTC');

        $id1 = (string) Uuid::uuid4();
        $id2 = (string) Uuid::uuid4();
        $signalId = (string) Uuid::uuid4();

        // Direct SQL insert 1
        CounterfactualEvaluationRecord::query()->create([
            'id' => $id1,
            'signal_id' => $signalId,
            'strategy_id' => 'baseline',
            'strategy_version' => '1.2.0',
            'symbol' => 'ETHUSDT',
            'timeframe' => '15',
            'decision_time' => $now,
            'skip_reason' => 'test_skip',
            'filter_family' => 'TEST',
            'candidate_direction' => 'BUY',
            'hypothetical_entry' => '2400.00',
            'hypothetical_sl' => '2364.00',
            'hypothetical_tp' => '2472.00',
            'status' => 'PENDING_MATURITY',
            'decision_snapshot' => [],
            'superseded_by' => null,
        ]);
        $this->cleanUpEvaluationIds[] = $id1;
        $this->cleanUpEvaluationIds[] = $id2;

        // Direct SQL insert 2 with identical slot and signal_id (must trigger UniqueConstraintViolationException)
        $this->expectException(UniqueConstraintViolationException::class);

        CounterfactualEvaluationRecord::query()->create([
            'id' => $id2,
            'signal_id' => $signalId, // Conflicting signal_id and slot
            'strategy_id' => 'baseline',
            'strategy_version' => '1.2.0',
            'symbol' => 'ETHUSDT',
            'timeframe' => '15',
            'decision_time' => $now,
            'skip_reason' => 'test_skip_2',
            'filter_family' => 'TEST',
            'candidate_direction' => 'BUY',
            'hypothetical_entry' => '2400.00',
            'hypothetical_sl' => '2364.00',
            'hypothetical_tp' => '2472.00',
            'status' => 'PENDING_MATURITY',
            'decision_snapshot' => [],
            'superseded_by' => null,
        ]);
    }

    public function test_historical_golden_replay_resolves_eight_raw_to_four_canonical(): void
    {
        $fixturePath = base_path('tests/Fixtures/Golden/golden_counterfactual_duplicates.json');
        if (! file_exists($fixturePath)) {
            $this->markTestSkipped('Golden fixture not found: '.$fixturePath);
        }

        $golden = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(4, $golden);

        $insertedIds = [];
        $totalRawCount = 0;

        foreach ($golden as $slot) {
            $evals = $slot['evaluations'];
            self::assertCount(2, $evals);
            $totalRawCount += count($evals);

            $canonical = null;
            foreach ($evals as $idx => $ev) {
                $supersededBy = null;
                if ($idx > 0 && $canonical !== null) {
                    $supersededBy = $canonical['id'];
                }

                $rec = CounterfactualEvaluationRecord::query()->create([
                    'id' => $ev['id'],
                    'signal_id' => $ev['signal_id'],
                    'strategy_id' => $ev['strategy_id'],
                    'strategy_version' => $ev['strategy_version'],
                    'symbol' => $ev['symbol'],
                    'timeframe' => $ev['timeframe'] ?? '15',
                    'decision_time' => $ev['decision_time'],
                    'skip_reason' => $ev['skip_reason'],
                    'filter_family' => $ev['filter_family'],
                    'candidate_direction' => $ev['candidate_direction'],
                    'hypothetical_entry' => $ev['hypothetical_entry'] ?? '2400.00',
                    'hypothetical_sl' => $ev['hypothetical_sl'] ?? '2364.00',
                    'hypothetical_tp' => $ev['hypothetical_tp'] ?? '2472.00',
                    'status' => $ev['status'],
                    'mfe' => $ev['mfe'] ?? null,
                    'mae' => $ev['mae'] ?? null,
                    'r_multiple' => $ev['r_multiple'] ?? null,
                    'net_return_percent' => $ev['net_return_percent'] ?? null,
                    'decision_snapshot' => json_decode($ev['decision_snapshot'] ?? '[]', true),
                    'superseded_by' => $supersededBy,
                ]);

                if ($idx === 0) {
                    $canonical = $rec;
                }
                $insertedIds[] = $rec->id;
                $this->cleanUpEvaluationIds[] = $rec->id;
            }
        }

        self::assertSame(8, $totalRawCount);
        self::assertCount(8, $insertedIds);

        // Verify that canonical query returns exactly 4 records
        $canonicalRecords = CounterfactualEvaluationRecord::query()
            ->whereIn('id', $insertedIds)
            ->canonical()
            ->get();
        self::assertCount(4, $canonicalRecords);

        // Verify that superseded records are exactly 4
        $supersededRecords = CounterfactualEvaluationRecord::query()
            ->whereIn('id', $insertedIds)
            ->whereNotNull('superseded_by')
            ->get();
        self::assertCount(4, $supersededRecords);
    }

    public function test_negative_control_duplicate_observation_rejected_by_slot_unique_constraint(): void
    {
        $now = CarbonImmutable::parse('2026-10-18 15:30:00', 'UTC');

        $obs1Id = (string) Uuid::uuid4();
        $obs2Id = (string) Uuid::uuid4();

        StrategyObservationRecord::query()->create([
            'id' => $obs1Id,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'momentum',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_slot_test_a',
            'side' => 'SKIP',
            'score' => 0,
            'decision_time' => $now,
            'reasons' => ['rsi_low'],
            'analysis' => [],
            'superseded_by' => null,
        ]);
        $this->cleanUpObservationIds[] = $obs1Id;
        $this->cleanUpObservationIds[] = $obs2Id;

        // Direct second insert with conflicting config hash for identical logical slot must trigger UniqueConstraintViolationException
        $this->expectException(UniqueConstraintViolationException::class);

        StrategyObservationRecord::query()->create([
            'id' => $obs2Id,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'momentum',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_slot_test_b',
            'side' => 'SKIP',
            'score' => 0,
            'decision_time' => $now,
            'reasons' => ['rsi_low'],
            'analysis' => [],
            'superseded_by' => null,
        ]);
    }
}
