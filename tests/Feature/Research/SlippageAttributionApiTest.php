<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
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
            ->assertJsonPath('position_id', $positionId)
            ->assertJsonPath('position.symbol', 'BTCUSDT');
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
            ->assertJsonPath('generation.package_code', 'P-1')
            ->assertJsonPath('generation.accounting_scope', 'FULL_LIFECYCLE');

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
