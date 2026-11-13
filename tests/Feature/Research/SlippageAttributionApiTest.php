<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Domain\SlippageAttributionFillRecord;
use App\Research\Domain\SlippageAttributionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class SlippageAttributionApiTest extends TestCase
{
    use RefreshDatabase;

    private string $validToken = 'test-research-token-secret-12345';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.research_api_token' => $this->validToken]);
        $this->app->make(PaperAccountService::class)->allPrimaryAccounts();
    }

    public function test_get_slippage_attribution_is_purely_read_only(): void
    {
        $writeQueries = 0;
        DB::listen(function ($query) use (&$writeQueries) {
            $sql = strtolower(trim($query->sql));
            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete')) {
                $writeQueries++;
            }
        });

        $response = $this->getJson('/api/v1/research/profitability/slippage-attribution');

        $this->assertSame(0, $writeQueries, 'GET /research/profitability/slippage-attribution must execute 0 DB write queries');
        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'data' => [
                    'status',
                    'provenance',
                ],
            ]);
    }

    public function test_position_slippage_attribution_endpoint_read_only_and_not_found(): void
    {
        // 404 test
        $response404 = $this->getJson('/api/v1/research/profitability/slippage-attribution/positions/non-existent-uuid');
        $response404->assertStatus(404)
            ->assertJsonPath('error.code', 'POSITION_NOT_FOUND');

        // Seed position
        $account = PaperAccountRecord::query()->firstOrFail();
        $signalId = Uuid::uuid4()->toString();
        $this->createSignal($signalId);

        $positionId = Uuid::uuid4()->toString();
        $openedAt = CarbonImmutable::parse('2026-10-15 10:00:00', 'UTC');
        VirtualPositionRecord::query()->create([
            'id' => $positionId,
            'paper_account_id' => $account->id,
            'signal_id' => $signalId,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'CLOSED_TP',
            'entry_price' => '100000.00',
            'stop_loss' => '99000.00',
            'take_profit_1' => '101000.00',
            'take_profit_2' => '102000.00',
            'initial_quantity' => '1.0',
            'remaining_quantity' => '0.0',
            'qty_step' => '0.001',
            'risk_capital' => '100.00',
            'unrealized_pnl' => '0.00',
            'gross_pnl' => '1000.00',
            'net_pnl' => '980.00',
            'fees' => '20.00',
            'funding' => '0.00',
            'opened_at' => $openedAt,
            'maximum_holding_until' => $openedAt->addHours(4),
            'closed_at' => $openedAt->addHours(1),
            'execution_model' => 'FORWARD_PAPER',
            'strategy_version' => 'BB_RSI_15M',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        $generations = [Uuid::uuid4()->toString(), Uuid::uuid4()->toString()];
        foreach ($generations as $index => $generation) {
            SlippageAttributionRecord::query()->create([
                'generation_id' => $generation, 'position_id' => $positionId, 'paper_account_id' => $account->id,
                'strategy_id' => 'baseline', 'symbol' => 'BTCUSDT', 'position_side' => 'BUY',
                'algorithm_version' => 'test', 'created_at' => now()->addSeconds($index),
            ]);
            SlippageAttributionFillRecord::query()->create([
                'generation_id' => $generation, 'position_id' => $positionId, 'paper_account_id' => $account->id,
                'execution_id' => Uuid::uuid4()->toString(), 'strategy_id' => 'baseline', 'symbol' => 'BTCUSDT',
                'execution_side' => 'BUY', 'fill_role' => 'ENTRY', 'quantity' => '1', 'contract_multiplier' => '1',
                'reference_price' => '99999', 'fill_price' => '100000', 'reference_notional' => '99999',
                'slippage_drag_usdt' => '1', 'price_source' => 'fixture', 'market_event_at' => $openedAt,
            ]);
        }

        $writeQueries = 0;
        DB::listen(function ($query) use (&$writeQueries) {
            $sql = strtolower(trim($query->sql));
            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete')) {
                $writeQueries++;
            }
        });

        $response = $this->getJson("/api/v1/research/profitability/slippage-attribution/positions/{$positionId}");

        $this->assertSame(0, $writeQueries, 'GET position attribution must execute 0 DB write queries');
        $response->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('data.position_id', $positionId)
            ->assertJsonPath('data.position.symbol', 'BTCUSDT')
            ->assertJsonCount(1, 'data.fills')
            ->assertJsonPath('data.fills.0.generation_id', $generations[1]);
        $this->getJson("/api/v1/research/profitability/slippage-attribution/positions/{$positionId}?generation_id={$generations[0]}")
            ->assertOk()->assertJsonCount(1, 'data.fills')->assertJsonPath('data.fills.0.generation_id', $generations[0]);
        self::assertSame(0, $writeQueries);
    }

    public function test_post_generations_requires_auth_and_rejects_idempotency_conflicts(): void
    {
        $payload = [
            'idempotency_key' => 'api-idem-key-1',
            'package_code' => 'P-1',
            'accounting_scope' => 'FULL_LIFECYCLE',
        ];

        // 1. Missing token -> 401
        $resNoAuth = $this->postJson('/api/v1/research/profitability/generations', $payload);
        $resNoAuth->assertStatus(401);

        // 2. Invalid token -> 403
        $resBadAuth = $this->postJson('/api/v1/research/profitability/generations', $payload, [
            'X-Research-Token' => 'invalid-token-here',
        ]);
        $resBadAuth->assertStatus(403);

        // 3. Valid token -> 201 Created
        $resSuccess = $this->postJson('/api/v1/research/profitability/generations', $payload, [
            'X-Research-Token' => $this->validToken,
        ]);
        $resSuccess->assertStatus(201)
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('data.generation.package_code', 'P-1')
            ->assertJsonPath('data.generation.accounting_scope', 'FULL_LIFECYCLE');

        // 4. Same key + conflicting scope -> 409 Conflict
        $conflictingPayload = [
            'idempotency_key' => 'api-idem-key-1',
            'package_code' => 'P-1',
            'accounting_scope' => 'WINDOW_FLOW',
        ];
        $resConflict = $this->postJson('/api/v1/research/profitability/generations', $conflictingPayload, [
            'X-Research-Token' => $this->validToken,
        ]);
        $resConflict->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_scoped_generation_verifies_only_its_selected_market_and_retries_idempotently(): void
    {
        $payload = [
            'idempotency_key' => 'api-scoped-retry', 'package_code' => 'P-1',
            'exchange' => ' BYBIT ', 'symbol' => ' btcusdt ',
        ];
        $headers = ['X-Research-Token' => $this->validToken];
        $created = $this->postJson('/api/v1/research/profitability/generations', $payload, $headers)
            ->assertCreated()->assertJsonPath('data.generation.metadata.exchange', 'bybit')
            ->assertJsonPath('data.generation.metadata.symbol', 'BTCUSDT');
        $this->travel(2)->seconds();
        $this->postJson('/api/v1/research/profitability/generations', $payload, $headers)
            ->assertCreated()->assertJsonPath('data.generation.id', $created->json('data.generation.id'));
        $this->getJson('/api/v1/research/profitability/summary?exchange=bybit&symbol=BTCUSDT')
            ->assertOk()->assertJsonPath('data.gated_modules.P-1.status', 'IMPLEMENTED_VERIFIED');
        $this->getJson('/api/v1/research/profitability/slippage-attribution?exchange=bybit&symbol=BTCUSDT')
            ->assertOk()->assertJsonPath('data.generation_id', $created->json('data.generation.id'));
        foreach ([['exchange' => 'binance'], ['symbol' => 'ETHUSDT']] as $change) {
            $this->postJson('/api/v1/research/profitability/generations', array_replace($payload, $change), $headers)
                ->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        }
        foreach (['exchange=binance&symbol=BTCUSDT', 'exchange=bybit&symbol=ETHUSDT', 'exchange=bybit', 'exchange=all&symbol=BTCUSDT', 'exchange=all'] as $scope) {
            $this->getJson('/api/v1/research/profitability/summary?'.$scope)
                ->assertOk()->assertJsonPath('data.gated_modules.P-1.status', 'NOT_GENERATED');
            $this->getJson('/api/v1/research/profitability/slippage-attribution?'.$scope)
                ->assertOk()->assertJsonPath('data.status', 'NO_GENERATION_AVAILABLE');
        }
        $this->postJson('/api/v1/research/profitability/generations', array_replace($payload, ['exchange' => 'unknown_venue']), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('exchange');
    }

    public function test_global_generation_does_not_certify_selected_markets_or_window_only_evidence(): void
    {
        $headers = ['X-Research-Token' => $this->validToken];
        $payload = [
            'idempotency_key' => 'api-global-generation', 'package_code' => 'P-1',
            'exchange' => 'all', 'symbol' => 'ALL',
        ];
        $this->postJson('/api/v1/research/profitability/generations', $payload, $headers)->assertCreated();
        $this->getJson('/api/v1/research/profitability/summary?exchange=all')
            ->assertOk()->assertJsonPath('data.gated_modules.P-1.status', 'IMPLEMENTED_VERIFIED');
        foreach (['exchange=bybit', 'exchange=bybit&symbol=BTCUSDT', 'exchange=all&symbol=BTCUSDT'] as $scope) {
            $this->getJson('/api/v1/research/profitability/summary?'.$scope)
                ->assertOk()->assertJsonPath('data.gated_modules.P-1.status', 'NOT_GENERATED');
            $this->getJson('/api/v1/research/profitability/slippage-attribution?'.$scope)
                ->assertOk()->assertJsonPath('data.status', 'NO_GENERATION_AVAILABLE');
        }
        $this->postJson('/api/v1/research/profitability/generations', array_replace($payload, [
            'idempotency_key' => 'api-window-generation', 'exchange' => 'bybit', 'symbol' => 'BTCUSDT',
            'accounting_scope' => 'WINDOW_FLOW', 'window_start' => '2026-10-19T00:00:00Z', 'window_end' => '2026-10-20T00:00:00Z',
        ]), $headers)->assertCreated();
        $this->getJson('/api/v1/research/profitability/summary?exchange=bybit&symbol=BTCUSDT')
            ->assertOk()->assertJsonPath('data.gated_modules.P-1.status', 'NOT_GENERATED');
    }

    public function test_f24_slippage_position_trace_scopes_accounts_and_exchanges_without_foreign_fills(): void
    {
        // Initialize Binance accounts
        $this->app->make(BinanceDeploymentInitializer::class)->initialize();

        // 1. Seed two distinct accounts (Bybit and Binance)
        $bybitAccount = PaperAccountRecord::query()->where('exchange_id', 'bybit')->firstOrFail();
        $binanceAccount = PaperAccountRecord::query()->where('exchange_id', 'binance')->firstOrFail();

        // 2. Seed a position for Bybit account
        $bybitPosId = Uuid::uuid4()->toString();
        $openedAt = CarbonImmutable::parse('2026-10-15 10:00:00', 'UTC');
        $this->createSignal($bybitPosId);

        VirtualPositionRecord::query()->create([
            'id' => $bybitPosId,
            'paper_account_id' => $bybitAccount->id,
            'signal_id' => $bybitPosId,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'CLOSED_TP',
            'entry_price' => '60000.00',
            'stop_loss' => '59000.00',
            'take_profit_1' => '61000.00',
            'take_profit_2' => '62000.00',
            'initial_quantity' => '1.0',
            'remaining_quantity' => '0.0',
            'qty_step' => '0.001',
            'risk_capital' => '100.00',
            'unrealized_pnl' => '0.00',
            'gross_pnl' => '1000.00',
            'net_pnl' => '980.00',
            'fees' => '20.00',
            'funding' => '0.00',
            'opened_at' => $openedAt,
            'maximum_holding_until' => $openedAt->addHours(4),
            'closed_at' => $openedAt->addHours(1),
            'execution_model' => 'FORWARD_PAPER',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // Seed two generations for this position
        $genA = Uuid::uuid4()->toString();
        $genB = Uuid::uuid4()->toString();

        foreach ([$genA, $genB] as $idx => $gen) {
            SlippageAttributionRecord::query()->create([
                'generation_id' => $gen,
                'position_id' => $bybitPosId,
                'paper_account_id' => $bybitAccount->id,
                'strategy_id' => 'baseline',
                'symbol' => 'BTCUSDT',
                'position_side' => 'BUY',
                'algorithm_version' => '1.0.0',
                'created_at' => now()->addSeconds($idx),
            ]);
            SlippageAttributionFillRecord::query()->create([
                'generation_id' => $gen,
                'position_id' => $bybitPosId,
                'paper_account_id' => $bybitAccount->id,
                'execution_id' => Uuid::uuid4()->toString(),
                'strategy_id' => 'baseline',
                'symbol' => 'BTCUSDT',
                'execution_side' => 'BUY',
                'fill_role' => 'ENTRY',
                'quantity' => '1',
                'contract_multiplier' => '1',
                'reference_price' => '59990',
                'fill_price' => '60000',
                'reference_notional' => '59990',
                'slippage_drag_usdt' => '10',
                'price_source' => 'fixture',
                'market_event_at' => $openedAt->addSeconds($idx),
            ]);
        }

        // Also seed an unrelated fill for Binance account with same generation ID to test cross-account contamination guard
        SlippageAttributionFillRecord::query()->create([
            'generation_id' => $genA,
            'position_id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $binanceAccount->id,
            'execution_id' => Uuid::uuid4()->toString(),
            'strategy_id' => 'baseline',
            'symbol' => 'BTCUSDT',
            'execution_side' => 'BUY',
            'fill_role' => 'ENTRY',
            'quantity' => '5',
            'contract_multiplier' => '1',
            'reference_price' => '50000',
            'fill_price' => '50100',
            'reference_notional' => '250000',
            'slippage_drag_usdt' => '500',
            'price_source' => 'fixture',
            'market_event_at' => $openedAt,
        ]);

        // 3. Request trace with correct account & exchange and explicit generation
        $resExact = $this->getJson("/api/v1/research/profitability/slippage-attribution/positions/{$bybitPosId}?account_id={$bybitAccount->id}&exchange=bybit&generation_id={$genA}");
        $resExact->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('data.position_id', $bybitPosId)
            ->assertJsonPath('data.position.symbol', 'BTCUSDT')
            ->assertJsonCount(1, 'data.fills')
            ->assertJsonPath('data.fills.0.generation_id', $genA)
            ->assertJsonPath('data.fills.0.paper_account_id', $bybitAccount->id);

        // 4. Request trace with FOREIGN account filter -> zero fills, zero foreign data leakage
        $resForeignAccount = $this->getJson("/api/v1/research/profitability/slippage-attribution/positions/{$bybitPosId}?account_id={$binanceAccount->id}");
        $resForeignAccount->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('data.position_id', $bybitPosId)
            ->assertJsonCount(0, 'data.fills')
            ->assertJsonPath('data.position', null);

        // 5. Request trace with FOREIGN exchange filter -> zero fills, zero foreign data leakage
        $resForeignExchange = $this->getJson("/api/v1/research/profitability/slippage-attribution/positions/{$bybitPosId}?exchange=binance");
        $resForeignExchange->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('data.position_id', $bybitPosId)
            ->assertJsonCount(0, 'data.fills')
            ->assertJsonPath('data.position', null);
    }

    private function createSignal(string $signalId): void
    {
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 80,
            'decision_time' => now('UTC'),
            'candle_close_time' => now('UTC'),
            'expires_at' => now('UTC')->addHour(),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
    }
}
