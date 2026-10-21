<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\SlippageAttributionService;
use App\Research\Exceptions\IdempotencyConflictException;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class SlippageAttributionServiceTest extends TestCase
{
    use RefreshDatabase;

    private SlippageAttributionService $service;

    private PaperAccountService $accountService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountService = $this->app->make(PaperAccountService::class);
        $this->service = new SlippageAttributionService($this->accountService);
    }

    public function test_signed_adverse_drag_mathematical_identity(): void
    {
        // BUY execution: adverse drag = (fill_price - ref_price) * qty * multiplier
        // where ref_price = fill_price - slippage
        $buyFillPrice = BigDecimal::of('100.02');
        $buySlippage = BigDecimal::of('0.02');
        $buyRefPrice = $buyFillPrice->minus($buySlippage); // 100.00
        $qty = BigDecimal::of('2.5');
        $multiplier = BigDecimal::of('1.0');

        $buyDrag = $buyFillPrice->minus($buyRefPrice)->multipliedBy($qty)->multipliedBy($multiplier);
        $this->assertSame('0.0500', (string) $buyDrag->toScale(4));

        $buyRefNotional = $buyRefPrice->multipliedBy($qty)->multipliedBy($multiplier);
        $this->assertSame('250.0000', (string) $buyRefNotional->toScale(4));

        $buyBps = $buyDrag->dividedBy($buyRefNotional, 8)->multipliedBy(10000)->toScale(4);
        $this->assertSame('2.0000', (string) $buyBps);

        // SELL execution: adverse drag = (ref_price - fill_price) * qty * multiplier
        // where ref_price = fill_price + slippage
        $sellFillPrice = BigDecimal::of('99.98');
        $sellSlippage = BigDecimal::of('0.02');
        $sellRefPrice = $sellFillPrice->plus($sellSlippage); // 100.00

        $sellDrag = $sellRefPrice->minus($sellFillPrice)->multipliedBy($qty)->multipliedBy($multiplier);
        $this->assertSame('0.0500', (string) $sellDrag->toScale(4));

        $sellRefNotional = $sellRefPrice->multipliedBy($qty)->multipliedBy($multiplier);
        $this->assertSame('250.0000', (string) $sellRefNotional->toScale(4));

        $sellBps = $sellDrag->dividedBy($sellRefNotional, 8)->multipliedBy(10000)->toScale(4);
        $this->assertSame('2.0000', (string) $sellBps);
    }

    public function test_returns_no_generation_available_when_table_empty(): void
    {
        $summary = $this->service->getLatestSummary('FULL_LIFECYCLE');

        $this->assertSame('NO_GENERATION_AVAILABLE', $summary['status']);
        $this->assertSame('INSUFFICIENT_DATA', $summary['provenance']);
    }

    public function test_generate_computes_attribution_and_stores_immutable_generation(): void
    {
        $this->accountService->allPrimaryAccounts();
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

        // Entry execution (BUY side: ref = 100000 - 20 = 99980, fill = 100000)
        VirtualExecutionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'position_id' => $positionId,
            'side' => 'BUY',
            'type' => 'ENTRY',
            'quantity' => '1.0',
            'price' => '100000.00',
            'gross_pnl' => '0.00',
            'commission' => '10.00',
            'slippage' => '20.00',
            'market_event_at' => $openedAt,
            'registered_at' => $openedAt,
            'price_source' => 'NEXT_CANDLE_OPEN',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:entry:'.$positionId,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // Exit execution (SELL side: ref = 101000 + 20.20 = 101020.20, fill = 101000.00)
        VirtualExecutionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'position_id' => $positionId,
            'side' => 'SELL',
            'type' => 'TAKE_PROFIT_FINAL',
            'quantity' => '1.0',
            'price' => '101000.00',
            'gross_pnl' => '1000.00',
            'commission' => '10.00',
            'slippage' => '20.20',
            'market_event_at' => $openedAt->addHours(1),
            'registered_at' => $openedAt->addHours(1),
            'price_source' => 'CANDLE_HIGH_HIT',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:exit:'.$positionId,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        $idempotencyKey = 'test-idem-key-1';
        $generation = $this->service->generate([
            'idempotency_key' => $idempotencyKey,
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-10-20T12:00:00Z',
        ]);

        $this->assertSame('SUCCEEDED', $generation->status);
        $this->assertSame('P-1', $generation->package_code);
        $this->assertNotNull($generation->output_hash);
        $this->assertSame(2, $generation->row_counts['fills']);
        $this->assertSame(1, $generation->row_counts['positions']);

        // Test idempotency: calling again with exact same payload returns existing record
        $recalled = $this->service->generate([
            'idempotency_key' => $idempotencyKey,
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-10-20T12:00:00Z',
        ]);
        $this->assertSame($generation->id, $recalled->id);

        // Test idempotency conflict: calling with different parameters throws IdempotencyConflictException
        $this->expectException(IdempotencyConflictException::class);
        $this->service->generate([
            'idempotency_key' => $idempotencyKey,
            'accounting_scope' => 'WINDOW_FLOW',
            'as_of' => '2026-10-20T12:00:00Z',
        ]);
    }

    public function test_get_latest_summary_computes_counterfactual_without_double_deduction(): void
    {
        $this->accountService->allPrimaryAccounts();
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

        VirtualExecutionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'position_id' => $positionId,
            'side' => 'BUY',
            'type' => 'ENTRY',
            'quantity' => '1.0',
            'price' => '100000.00',
            'gross_pnl' => '0.00',
            'commission' => '10.00',
            'slippage' => '20.00',
            'market_event_at' => $openedAt,
            'registered_at' => $openedAt,
            'price_source' => 'NEXT_CANDLE_OPEN',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:entry:'.$positionId,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        $this->service->generate([
            'idempotency_key' => 'summary-key-1',
            'accounting_scope' => 'FULL_LIFECYCLE',
        ]);

        $summary = $this->service->getLatestSummary('FULL_LIFECYCLE');

        $this->assertSame('SUCCEEDED', $summary['status']);
        $this->assertSame(1, $summary['summary']['total_fills']);
        $this->assertSame('20', (string) $summary['summary']['total_slippage_drag_usdt']);

        // Check counterfactual
        $counterfactual = $summary['counterfactual'];
        $this->assertSame('COMPUTED', $counterfactual['status']);
        $this->assertSame('HYPOTHETICAL', $counterfactual['provenance']);
        $this->assertTrue($counterfactual['double_deduction_prohibited']);
        $this->assertSame('980', (string) $counterfactual['baseline_actual_net_pnl']);
        $this->assertSame('20', (string) $counterfactual['attributable_slippage_drag']);
        // Hypothetical Net PnL = 980 + 20 = 1000
        $this->assertStringStartsWith('1000', (string) $counterfactual['hypothetical_net_pnl_zero_slippage']);
    }

    public function test_invalid_position_with_matching_population_keeps_counterfactual_null(): void
    {
        $this->accountService->allPrimaryAccounts();
        $account = PaperAccountRecord::query()->firstOrFail();

        $signalId = Uuid::uuid4()->toString();
        $this->createSignal($signalId);

        $positionId = Uuid::uuid4()->toString();
        $openedAt = CarbonImmutable::parse('2026-10-15 10:00:00', 'UTC');
        $this->createPosition($positionId, $account->id, $signalId, $openedAt, [
            'initial_quantity' => '0',
            'remaining_quantity' => '0',
            'net_pnl' => '980.00',
            'gross_pnl' => '1000.00',
            'fees' => '20.00',
        ]);
        $this->createExecution($positionId, $openedAt, [
            'side' => 'BUY',
            'type' => 'ENTRY',
            'quantity' => '0',
            'price' => '100000.00',
            'slippage' => '20.00',
            'commission' => '10.00',
        ]);

        $this->service->generate(['idempotency_key' => 'invalid-pop-key', 'accounting_scope' => 'FULL_LIFECYCLE']);

        $counterfactual = $this->service->getLatestSummary('FULL_LIFECYCLE')['counterfactual'];

        // The population count matches (one attribution, one position), but the
        // position data is invalid. Unavailable values must remain null, never 0.
        $this->assertSame('INSUFFICIENT_DATA', $counterfactual['status']);
        $this->assertSame('NOT_VERIFIED', $counterfactual['provenance']);
        $this->assertSame(1, $counterfactual['invalid_position_count']);
        $this->assertNull($counterfactual['realized_scope_net_pnl']);
        $this->assertNull($counterfactual['realized_allocated_slippage_drag']);
        $this->assertNull($counterfactual['hypothetical_net_pnl_zero_slippage']);
        $this->assertNull($counterfactual['raw_unallocated_counterfactual_net_pnl']);
    }

    public function test_partial_close_service_counterfactual_fails_closed(): void
    {
        $this->accountService->allPrimaryAccounts();
        $account = PaperAccountRecord::query()->firstOrFail();

        $signalId = Uuid::uuid4()->toString();
        $this->createSignal($signalId);

        $positionId = Uuid::uuid4()->toString();
        $openedAt = CarbonImmutable::parse('2026-10-15 10:00:00', 'UTC');
        $this->createPosition($positionId, $account->id, $signalId, $openedAt, [
            'status' => 'PARTIALLY_CLOSED',
            'initial_quantity' => '0.021',
            'remaining_quantity' => '0.011',
            'net_pnl' => '4.99',
            'gross_pnl' => '0.010',
            'fees' => '0.020',
            'funding' => '0',
            'closed_at' => null,
        ]);

        // Contract fixture: entry 0.021 / TP1 exit 0.010, entry drag 0.021, exit drag 0.003.
        $this->createExecution($positionId, $openedAt, [
            'side' => 'BUY',
            'type' => 'ENTRY',
            'quantity' => '0.021',
            'price' => '100.00',
            'slippage' => '1.0',
            'commission' => '0.010',
        ]);
        $this->createExecution($positionId, $openedAt->addMinutes(30), [
            'side' => 'SELL',
            'type' => 'TAKE_PROFIT_1',
            'quantity' => '0.010',
            'price' => '101.00',
            'slippage' => '0.3',
            'commission' => '0.010',
            'gross_pnl' => '0.010',
        ]);

        $this->service->generate(['idempotency_key' => 'partial-close-key', 'accounting_scope' => 'FULL_LIFECYCLE']);

        $counterfactual = $this->service->getLatestSummary('FULL_LIFECYCLE')['counterfactual'];

        $this->assertSame('PARTIAL_CLOSE_UNRESOLVED', $counterfactual['status']);
        $this->assertSame('NOT_VERIFIED', $counterfactual['provenance']);
        $this->assertSame('PARTIAL_CLOSE_UNRESOLVED', $counterfactual['realized_pnl_scope_status']);
        $this->assertSame(1, $counterfactual['partial_position_count']);
        // The proportional drag is a pure quantity attribution and is still available.
        $this->assertSame('0.013', $counterfactual['realized_allocated_slippage_drag']);
        // The whole net_pnl of a partial close is NOT treated as realized-scope PnL.
        $this->assertNull($counterfactual['realized_scope_net_pnl']);
        $this->assertNull($counterfactual['hypothetical_net_pnl_zero_slippage']);
        // The raw all-positions figure may stay available for diagnostics, but it
        // is explicitly labelled unverified and is not a realized-scope result.
        $this->assertSame('5.014', $counterfactual['raw_unallocated_counterfactual_net_pnl']);
        $this->assertSame('NOT_VERIFIED_SCOPE_MISMATCH', $counterfactual['raw_counterfactual_status']);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function createPosition(string $positionId, string $accountId, string $signalId, CarbonImmutable $openedAt, array $overrides = []): void
    {
        VirtualPositionRecord::query()->create(array_merge([
            'id' => $positionId,
            'paper_account_id' => $accountId,
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
        ], $overrides));
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function createExecution(string $positionId, CarbonImmutable $at, array $overrides = []): void
    {
        VirtualExecutionRecord::query()->create(array_merge([
            'id' => Uuid::uuid4()->toString(),
            'position_id' => $positionId,
            'side' => 'BUY',
            'type' => 'ENTRY',
            'quantity' => '1.0',
            'price' => '100000.00',
            'gross_pnl' => '0.00',
            'commission' => '10.00',
            'slippage' => '20.00',
            'market_event_at' => $at,
            'registered_at' => $at,
            'price_source' => 'NEXT_CANDLE_OPEN',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:'.$positionId.':'.$at->format('U.u').':'.($overrides['type'] ?? 'ENTRY'),
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ], $overrides));
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
