<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\Research\Application\SlotIntegrityService;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class SlotIntegrityAndRiskFunnelPostgresIntegrationTest extends TestCase
{
    private array $cleanUpSignalIds = [];

    private array $cleanUpDecisionIds = [];

    private array $cleanUpObservationIds = [];

    private array $cleanUpCandleIds = [];

    private array $cleanUpStateIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $testPort = (int) env('TEST_DB_PORT', 25432);
        $testHost = env('TEST_DB_HOST', '127.0.0.1');
        $testDb = env('TEST_DB_DATABASE', 'bybit_test');
        $testUser = env('TEST_DB_USERNAME', 'postgres');
        $testPass = env('TEST_DB_PASSWORD', 'testpass');

        $connectionOk = @fsockopen($testHost, $testPort, $errno, $errstr, 1);
        if (! $connectionOk) {
            $this->markTestSkipped("PostgreSQL test container is not reachable on {$testHost}:{$testPort}.");
        }
        fclose($connectionOk);

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => $testHost,
            'database.connections.pgsql.port' => $testPort,
            'database.connections.pgsql.database' => $testDb,
            'database.connections.pgsql.username' => $testUser,
            'database.connections.pgsql.password' => $testPass,
        ]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $currentDb = DB::connection()->getDatabaseName();
        if ($currentDb !== 'bybit_test') {
            $this->fail("SAFETY ABORT: PostgreSQL test must run strictly against isolated 'bybit_test' database (current: {$currentDb}).");
        }
        if (app()->environment('production')) {
            $this->fail('SAFETY ABORT: Cannot run integration tests in production environment.');
        }
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (! empty($this->cleanUpDecisionIds)) {
                RiskDecisionRecord::query()->whereIn('id', $this->cleanUpDecisionIds)->delete();
            }
            if (! empty($this->cleanUpStateIds)) {
                PaperSignalStateRecord::query()->whereIn('id', $this->cleanUpStateIds)->delete();
            }
            if (! empty($this->cleanUpSignalIds)) {
                SignalRecord::query()->whereIn('id', $this->cleanUpSignalIds)->delete();
            }
            if (! empty($this->cleanUpObservationIds)) {
                StrategyObservationRecord::query()->whereIn('id', $this->cleanUpObservationIds)->delete();
            }
            if (! empty($this->cleanUpCandleIds)) {
                CandleRecord::query()->whereIn('id', $this->cleanUpCandleIds)->delete();
            }
        }

        parent::tearDown();
    }

    public function test_golden_risk_decisions_funnel_and_reason_multisets(): void
    {
        $fixturePath = base_path('tests/Fixtures/Golden/golden_risk_decisions_109.json');
        if (! file_exists($fixturePath)) {
            $this->markTestSkipped('Golden fixture not found: '.$fixturePath);
        }

        $records = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(109, $records);

        $approved = array_filter($records, fn ($r) => trim((string) ($r['allowed'] ?? '')) === '1');
        $rejected = array_filter($records, fn ($r) => trim((string) ($r['allowed'] ?? '')) !== '1');

        self::assertCount(31, $approved);
        self::assertCount(78, $rejected);

        $exposureCodes = ['position_already_open', 'correlation_limit', 'maximum_positions_reached', 'position_conflict'];
        $exposureIncidences = 0;
        $twoReasonRows = [];

        foreach ($records as $r) {
            $reasons = json_decode((string) ($r['reasons'] ?? '[]'), true) ?: [];
            $matchingCodes = array_intersect($reasons, $exposureCodes);
            $exposureIncidences += count($matchingCodes);
            if (count($matchingCodes) >= 2) {
                $twoReasonRows[$r['signal_id']] = [
                    'decision_id' => $r['id'],
                    'reasons' => array_values($matchingCodes),
                ];
            }
        }

        self::assertSame(52, $exposureIncidences);
        self::assertCount(3, $twoReasonRows);

        // Verify exact three multi-reason records from Section 1
        self::assertArrayHasKey('aec279e4-e13e-5281-bdcd-e1d36b64bee3', $twoReasonRows);
        self::assertSame('78bb2282-4420-5e4a-8cdc-e47e645dcdc8', $twoReasonRows['aec279e4-e13e-5281-bdcd-e1d36b64bee3']['decision_id']);
        self::assertEqualsCanonicalizing(['maximum_positions_reached', 'correlation_limit'], $twoReasonRows['aec279e4-e13e-5281-bdcd-e1d36b64bee3']['reasons']);

        self::assertArrayHasKey('94ba88f1-e34b-56dc-9607-d328f4ad661c', $twoReasonRows);
        self::assertSame('ba8c9396-7532-55a0-9f73-587538c24d2d', $twoReasonRows['94ba88f1-e34b-56dc-9607-d328f4ad661c']['decision_id']);
        self::assertEqualsCanonicalizing(['maximum_positions_reached', 'position_already_open'], $twoReasonRows['94ba88f1-e34b-56dc-9607-d328f4ad661c']['reasons']);

        self::assertArrayHasKey('4d49920c-2ed6-543e-9f06-8dcaaf38448b', $twoReasonRows);
        self::assertSame('def1ee8d-68c1-54d0-9231-3092ca9e9545', $twoReasonRows['4d49920c-2ed6-543e-9f06-8dcaaf38448b']['decision_id']);
        self::assertEqualsCanonicalizing(['maximum_positions_reached', 'correlation_limit'], $twoReasonRows['4d49920c-2ed6-543e-9f06-8dcaaf38448b']['reasons']);
    }

    public function test_slot_integrity_cadence_detects_missing_unexplained_and_market_data_unavailable(): void
    {
        $service = $this->app->make(SlotIntegrityService::class);

        // Historical time in past (>2 hours ago, outside recent live candle windows)
        $pastTime = CarbonImmutable::now('UTC')->subDays(10)->setMinute(0)->setSecond(0)->setMicrosecond(0);
        $slot1 = $pastTime;
        $slot2 = $pastTime->addMinutes(15);

        // Slot 1: Has closed candle but NO observation -> MISSING_UNEXPLAINED
        $candle1 = CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $slot1->subMinutes(15),
            'open' => '60000.00',
            'high' => '60500.00',
            'low' => '59800.00',
            'close' => '60200.00',
            'volume' => '100.0',
            'turnover' => '6020000.0',
            'is_closed' => true,
        ]);
        $this->cleanUpCandleIds[] = $candle1->id;

        // Slot 2: No closed candle and no observation -> MARKET_DATA_UNAVAILABLE

        $result = $service->auditCadence($slot1, $slot2, ['BTCUSDT']);
        self::assertIsArray($result);
        self::assertArrayHasKey('metrics', $result);

        $metrics = $result['metrics'];
        // All 8 strategies evaluated for BTCUSDT at slot1 should be MISSING_UNEXPLAINED (8 slots)
        self::assertGreaterThanOrEqual(8, $metrics['missing_unexplained_slots_total']);
        self::assertGreaterThanOrEqual(8, $metrics['state_distribution'][SlotIntegrityService::STATE_MARKET_DATA_UNAVAILABLE]);

        // Alerts should include MISSING_UNEXPLAINED_SLOTS_DETECTED
        $alertNames = collect($result['alerts'])->pluck('name')->all();
        self::assertContains('MISSING_UNEXPLAINED_SLOTS_DETECTED', $alertNames);
    }

    public function test_opportunity_tracer_separates_signal_opportunity_from_risk_decision_events(): void
    {
        $now = CarbonImmutable::now('UTC');
        $signalId = (string) Uuid::uuid4();

        // 1. Create candidate opportunity (Signal)
        $signal = SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'strategy_id' => 'momentum',
            'strategy_version' => '1.0.0',
            'score' => 88,
            'decision_time' => $now->subMinutes(20),
            'candle_close_time' => $now->subMinutes(20),
            'expires_at' => $now->addHours(2),
            'maximum_holding_minutes' => 240,
            'reasons' => ['momentum_breakout'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);
        $this->cleanUpSignalIds[] = $signal->id;

        $accountId = PaperAccountRecord::query()->first()->id;

        // 2. Create first evaluation event: initial check rejected by correlation limit
        $decisionId1 = (string) Uuid::uuid4();
        $decision1 = RiskDecisionRecord::query()->create([
            'id' => $decisionId1,
            'paper_account_id' => $accountId,
            'signal_id' => $signalId,
            'allowed' => false,
            'reasons' => ['correlation_limit'],
            'snapshot' => ['exposure_ratio' => '0.85'],
            'risk_version' => '1.0.0',
            'decided_at' => $now->subMinutes(19),
        ]);
        $this->cleanUpDecisionIds[] = $decision1->id;

        // 3. Create second evaluation event: pre-execution recheck under updated risk policy version
        $decisionId2 = (string) Uuid::uuid4();
        $decision2 = RiskDecisionRecord::query()->create([
            'id' => $decisionId2,
            'paper_account_id' => $accountId,
            'signal_id' => $signalId,
            'allowed' => false,
            'reasons' => ['maximum_positions_reached'],
            'snapshot' => ['open_positions' => 3],
            'risk_version' => '1.0.1',
            'decided_at' => $now->subMinutes(18),
        ]);
        $this->cleanUpDecisionIds[] = $decision2->id;

        $service = $this->app->make(SlotIntegrityService::class);
        $funnel = $service->traceOpportunityFunnel($signalId);

        self::assertNotNull($funnel);
        self::assertSame($signalId, $funnel['opportunity_id']);
        self::assertSame('BUY', $funnel['side']);
        self::assertSame('momentum', $funnel['strategy_id']);

        // Assert that both decision events are traced under the single opportunity
        self::assertCount(2, $funnel['events']);
        self::assertSame($decisionId1, $funnel['events'][0]['event_id']);
        self::assertFalse($funnel['events'][0]['allowed']);
        self::assertSame(['correlation_limit'], $funnel['events'][0]['reasons']);

        self::assertSame($decisionId2, $funnel['events'][1]['event_id']);
        self::assertFalse($funnel['events'][1]['allowed']);
        self::assertSame(['maximum_positions_reached'], $funnel['events'][1]['reasons']);
    }

    public function test_negative_control_duplicate_risk_decision_rejected_by_unique_constraint(): void
    {
        $now = CarbonImmutable::parse('2026-10-18 14:45:00', 'UTC');
        $signalId = (string) Uuid::uuid4();

        $signal = SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'strategy_id' => 'momentum',
            'strategy_version' => '1.0.0',
            'score' => 85,
            'decision_time' => $now->subMinutes(20),
            'candle_close_time' => $now->subMinutes(20),
            'expires_at' => $now->addHours(2),
            'maximum_holding_minutes' => 240,
            'reasons' => ['momentum_breakout'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);
        $this->cleanUpSignalIds[] = $signal->id;

        $accountId = PaperAccountRecord::query()->first()->id;

        // Insert first decision
        $decision1 = RiskDecisionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $accountId,
            'signal_id' => $signalId,
            'allowed' => false,
            'reasons' => ['correlation_limit'],
            'snapshot' => ['exposure_ratio' => '0.85'],
            'risk_version' => '1.0.0',
            'decided_at' => $now->subMinutes(19),
        ]);
        $this->cleanUpDecisionIds[] = $decision1->id;

        // Attempt duplicate insert with same (paper_account_id, signal_id, risk_version)
        $id2 = (string) Uuid::uuid4();
        $this->cleanUpDecisionIds[] = $id2;
        $this->expectException(UniqueConstraintViolationException::class);

        RiskDecisionRecord::query()->create([
            'id' => $id2,
            'paper_account_id' => $accountId,
            'signal_id' => $signalId,
            'allowed' => true,
            'reasons' => [],
            'snapshot' => ['exposure_ratio' => '0.80'],
            'risk_version' => '1.0.0',
            'decided_at' => $now->subMinutes(18),
        ]);
    }
}
