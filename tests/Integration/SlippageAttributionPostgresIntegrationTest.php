<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\SlippageAttributionService;
use App\Research\Domain\ResearchGenerationRecord;
use App\Research\Domain\SlippageAttributionFillRecord;
use App\Research\Domain\SlippageAttributionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class SlippageAttributionPostgresIntegrationTest extends TestCase
{
    private array $cleanUpGenerationIds = [];

    private array $cleanUpExecutionIds = [];

    private array $cleanUpPositionIds = [];

    private array $cleanUpSignalIds = [];

    private SlippageAttributionService $service;

    private PaperAccountService $accountService;

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

        $this->accountService = $this->app->make(PaperAccountService::class);
        $this->service = new SlippageAttributionService($this->accountService);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (! empty($this->cleanUpGenerationIds)) {
                SlippageAttributionFillRecord::query()->whereIn('generation_id', $this->cleanUpGenerationIds)->delete();
                SlippageAttributionRecord::query()->whereIn('generation_id', $this->cleanUpGenerationIds)->delete();
                ResearchGenerationRecord::query()->whereIn('id', $this->cleanUpGenerationIds)->delete();
            }
            if (! empty($this->cleanUpExecutionIds)) {
                VirtualExecutionRecord::query()->whereIn('id', $this->cleanUpExecutionIds)->delete();
            }
            if (! empty($this->cleanUpPositionIds)) {
                VirtualPositionRecord::query()->whereIn('id', $this->cleanUpPositionIds)->delete();
            }
            if (! empty($this->cleanUpSignalIds)) {
                SignalRecord::query()->whereIn('id', $this->cleanUpSignalIds)->delete();
            }
        }

        parent::tearDown();
    }

    public function test_postgres_slippage_generation_and_unique_constraints(): void
    {
        $accounts = $this->accountService->allPrimaryAccounts();
        $account = $accounts->first();
        $this->assertNotNull($account, 'Expected at least one primary paper account in bybit_test');

        // Create test signal, position, and execution
        $signalId = Uuid::uuid4()->toString();
        $this->cleanUpSignalIds[] = $signalId;
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 80,
            'decision_time' => CarbonImmutable::now('UTC'),
            'candle_close_time' => CarbonImmutable::now('UTC'),
            'expires_at' => CarbonImmutable::now('UTC')->addHour(),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        $positionId = Uuid::uuid4()->toString();
        $this->cleanUpPositionIds[] = $positionId;
        $now = CarbonImmutable::now('UTC');
        VirtualPositionRecord::query()->create([
            'id' => $positionId,
            'paper_account_id' => $account->id,
            'signal_id' => $signalId,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'CLOSED_TP',
            'entry_price' => '65000.00',
            'stop_loss' => '64000.00',
            'take_profit_1' => '66000.00',
            'take_profit_2' => '67000.00',
            'initial_quantity' => '0.5',
            'remaining_quantity' => '0.0',
            'qty_step' => '0.001',
            'risk_capital' => '100.00',
            'unrealized_pnl' => '0.00',
            'gross_pnl' => '500.00',
            'net_pnl' => '490.00',
            'fees' => '10.00',
            'funding' => '0.00',
            'opened_at' => $now->subHour(),
            'maximum_holding_until' => $now->addHours(3),
            'closed_at' => $now,
            'execution_model' => 'FORWARD_PAPER',
            'strategy_version' => 'BB_RSI_15M',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        $execId = Uuid::uuid4()->toString();
        $this->cleanUpExecutionIds[] = $execId;
        VirtualExecutionRecord::query()->create([
            'id' => $execId,
            'position_id' => $positionId,
            'side' => 'BUY',
            'type' => 'ENTRY',
            'quantity' => '0.5',
            'price' => '65000.00',
            'gross_pnl' => '0.00',
            'commission' => '5.00',
            'slippage' => '13.00',
            'market_event_at' => $now->subHour(),
            'registered_at' => $now->subHour(),
            'price_source' => 'NEXT_CANDLE_OPEN',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:entry:'.$execId,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        $idempotencyKey = 'pg-test-idem-'.Uuid::uuid4()->toString();
        $generation = $this->service->generate([
            'idempotency_key' => $idempotencyKey,
            'accounting_scope' => 'FULL_LIFECYCLE',
        ]);
        $this->cleanUpGenerationIds[] = $generation->id;

        $this->assertSame('SUCCEEDED', $generation->status);
        $this->assertIsArray($generation->versioned_config);
        $this->assertIsArray($generation->row_counts);

        // Verify database unique constraints
        // 1. (generation_id, execution_id) in slippage_attribution_fills
        $this->expectException(UniqueConstraintViolationException::class);
        SlippageAttributionFillRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'generation_id' => $generation->id,
            'execution_id' => $execId,
            'position_id' => $positionId,
            'paper_account_id' => $account->id,
            'strategy_id' => 'BB_RSI_15M',
            'symbol' => 'BTCUSDT',
            'execution_side' => 'BUY',
            'fill_role' => 'ENTRY',
            'quantity' => '0.5',
            'contract_multiplier' => '1.0',
            'reference_price' => '64987.00',
            'fill_price' => '65000.00',
            'reference_notional' => '32493.50',
            'slippage_drag_usdt' => '6.50',
            'slippage_bps' => '2.0000',
            'price_source' => 'NEXT_CANDLE_OPEN',
            'provenance_status' => 'RECOMPUTED_FROM_SOURCE',
            'ambiguous_execution' => false,
            'market_event_at' => $now->subHour(),
        ]);
    }

    public function test_postgres_unique_idempotency_key_constraint(): void
    {
        $idempotencyKey = 'pg-idem-unique-'.Uuid::uuid4()->toString();

        $gen1 = ResearchGenerationRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'package_code' => 'P-1',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => CarbonImmutable::now('UTC'),
            'status' => 'SUCCEEDED',
            'idempotency_key' => $idempotencyKey,
            'request_payload_hash' => 'hash-1',
            'versioned_config' => [],
            'heartbeat_at' => CarbonImmutable::now('UTC'),
            'stale_after_seconds' => 300,
        ]);
        $this->cleanUpGenerationIds[] = $gen1->id;

        $this->expectException(UniqueConstraintViolationException::class);
        $gen2 = ResearchGenerationRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'package_code' => 'P-1',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => CarbonImmutable::now('UTC'),
            'status' => 'SUCCEEDED',
            'idempotency_key' => $idempotencyKey,
            'request_payload_hash' => 'hash-2',
            'versioned_config' => [],
            'heartbeat_at' => CarbonImmutable::now('UTC'),
            'stale_after_seconds' => 300,
        ]);
        $this->cleanUpGenerationIds[] = $gen2->id;
    }
}
