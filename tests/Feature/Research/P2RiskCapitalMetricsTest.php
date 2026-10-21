<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\RiskCapitalMetricsService;
use App\Research\Domain\SlippageAttributionRecord;
use App\Research\Domain\StrategyRiskMetricRecord;
use App\Research\Exceptions\IdempotencyConflictException;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * PROFIT-1 P-2 golden and negative-control coverage.
 *
 * Reference: VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md §6, §8.2, §8.4.
 */
final class P2RiskCapitalMetricsTest extends TestCase
{
    use RefreshDatabase;

    private const STRATEGIES = [
        'baseline',
        'trend_pullback',
        'bollinger_mean_reversion',
        'donchian_atr_breakout',
        'regime_switching',
        'momentum',
        'support_resistance_bounce',
        'support_resistance_breakout',
    ];

    private int $signalSequence = 1;

    private function service(): RiskCapitalMetricsService
    {
        return new RiskCapitalMetricsService;
    }

    public function test_generation_reports_eight_accounts_separately_and_never_provisions_accounts(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $this->seedClosedPosition($accounts[1], [
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 02:00:00+00:00',
            'net_pnl' => '10',
            'gross_pnl' => '12',
            'fees' => '2',
            'risk_capital' => '100',
            'initial_quantity' => '1',
        ]);

        $before = DB::table('paper_accounts')->count();
        $generation = $this->service()->generate([
            'idempotency_key' => 'p2-eight-accounts',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);

        self::assertSame('SUCCEEDED', $generation->status);
        self::assertSame($before, DB::table('paper_accounts')->count(), 'P-2 generation must not create, merge or delete paper accounts.');

        $rows = StrategyRiskMetricRecord::query()->where('generation_id', $generation->id)->get();
        self::assertCount(8, $rows);
        self::assertCount(8, $rows->pluck('paper_account_id')->unique());
        $expected = self::STRATEGIES;
        sort($expected);
        self::assertSame($expected, $rows->pluck('strategy_id')->sort()->values()->all());
    }

    public function test_profit_factor_win_rate_and_payoff_use_exact_decimal_arithmetic(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $this->seedClosedPosition($accounts[1], [
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 02:00:00+00:00',
            'net_pnl' => '10',
            'gross_pnl' => '12',
            'fees' => '2',
            'risk_capital' => '100',
            'initial_quantity' => '1',
        ]);
        $this->seedClosedPosition($accounts[1], [
            'opened_at' => '2026-01-02 00:00:00+00:00',
            'closed_at' => '2026-01-02 02:00:00+00:00',
            'net_pnl' => '-5',
            'gross_pnl' => '-3',
            'fees' => '2',
            'risk_capital' => '100',
            'initial_quantity' => '1',
        ]);

        $row = $this->generateAndPick($accounts[1]->id);

        self::assertSame('2.00000000', $row->profit_factor);
        self::assertSame('0.500000', $row->win_rate);
        self::assertSame('2.00000000', $row->payoff_ratio);
        self::assertSame(2, $row->n_positions);
        self::assertSame(1, $row->n_wins);
        self::assertSame(1, $row->n_losses);
        self::assertSame(0, $row->n_flat);
    }

    public function test_undefined_profit_factor_is_null_with_reason_when_there_are_no_losses(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $this->seedClosedPosition($accounts[1], [
            'net_pnl' => '10', 'gross_pnl' => '12', 'fees' => '2', 'risk_capital' => '100', 'initial_quantity' => '1',
        ]);

        $row = $this->generateAndPick($accounts[1]->id);

        self::assertNull($row->profit_factor);
        self::assertSame('UNDEFINED_NO_LOSSES', $row->profit_factor_reason);
        self::assertSame('1.000000', $row->win_rate);
        self::assertNull($row->payoff_ratio);
        self::assertSame('UNDEFINED_NO_LOSING_POSITIONS', $row->payoff_ratio_reason);
    }

    public function test_win_rate_is_null_with_reason_when_there_are_no_wins_or_losses(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $this->seedClosedPosition($accounts[1], [
            'net_pnl' => '0', 'gross_pnl' => '0', 'fees' => '0', 'risk_capital' => '100', 'initial_quantity' => '1',
        ]);

        $row = $this->generateAndPick($accounts[1]->id);

        self::assertNull($row->win_rate);
        self::assertSame('UNDEFINED_NO_WINS_OR_LOSSES', $row->win_rate_reason);
        self::assertSame(1, $row->n_flat);
        // A flat position still has a defined (zero) net R: its risk capital denominator is positive.
        self::assertSame('0.00000000', $row->net_r_mean);
        self::assertSame('0.00000000', $row->net_r_median);
    }

    public function test_position_closed_after_the_cutoff_is_excluded_from_a_point_in_time_result(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        // Closed after the cutoff: its final realized PnL must not leak into a PIT result.
        $this->seedClosedPosition($accounts[1], [
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-05 00:00:00+00:00',
            'net_pnl' => '999',
            'gross_pnl' => '1001',
            'fees' => '2',
            'risk_capital' => '100',
            'initial_quantity' => '1',
        ]);

        $row = $this->generateAndPick($accounts[1]->id, ['as_of' => '2026-01-02T00:00:00Z']);

        self::assertSame(0, $row->n_positions, 'A position closed after as_of must not contribute to a point-in-time result.');
        self::assertSame(1, $row->exclusions_count);
        self::assertSame(1, $row->exclusion_reasons['OPEN_AT_CUTOFF'] ?? null);
        self::assertNull($row->net_r_mean);
    }

    public function test_open_position_is_excluded_with_an_explicit_reason(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $this->seedClosedPosition($accounts[1], [
            'closed_at' => null,
            'status' => 'OPEN',
            'skip_execution' => true,
            'net_pnl' => '0',
            'gross_pnl' => '0',
            'fees' => '1',
            'risk_capital' => '100',
            'initial_quantity' => '1',
        ]);

        $row = $this->generateAndPick($accounts[1]->id);

        self::assertSame(0, $row->n_positions);
        self::assertSame(1, $row->exclusion_reasons['OPEN_AT_CUTOFF'] ?? null);
        self::assertSame(1, $row->position_population['open_positions_at_cutoff']);
    }

    public function test_partially_closed_position_is_excluded_from_the_lifecycle_population(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $this->seedClosedPosition($accounts[1], [
            'closed_at' => null,
            'status' => 'PARTIALLY_CLOSED',
            'remaining_quantity' => '0.011',
            'net_pnl' => '5',
            'gross_pnl' => '6',
            'fees' => '1',
            'risk_capital' => '100',
            'initial_quantity' => '0.021',
        ]);

        $row = $this->generateAndPick($accounts[1]->id);

        self::assertSame(0, $row->n_positions);
        self::assertSame(1, $row->exclusion_reasons['PARTIALLY_CLOSED_AT_CUTOFF'] ?? null);
        self::assertSame(1, $row->position_population['partially_closed_positions_at_cutoff']);
    }

    public function test_window_scope_allocates_risk_capital_proportionally_to_the_closed_quantity(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $position = $this->seedClosedPosition($accounts[1], [
            'closed_at' => null,
            'status' => 'PARTIALLY_CLOSED',
            'remaining_quantity' => '0.011',
            'skip_execution' => true,
            'net_pnl' => '5',
            'gross_pnl' => '6',
            'fees' => '1',
            'risk_capital' => '100',
            'initial_quantity' => '0.021',
        ]);
        // 0.010 of 0.021 closed -> allocated risk capital = 100 * 0.010 / 0.021
        $this->seedExecution($position, [
            'type' => 'TAKE_PROFIT_1',
            'quantity' => '0.010',
            'price' => '100',
            'gross_pnl' => '6',
            'commission' => '1',
            'market_event_at' => '2026-01-05 00:00:00+00:00',
            'registered_at' => '2026-01-05 00:00:01+00:00',
        ]);

        $row = $this->generateAndPick($accounts[1]->id, [
            'accounting_scope' => 'WINDOW_FLOW',
            'window_start' => '2026-01-01T00:00:00Z',
            'window_end' => '2026-01-10T00:00:00Z',
        ]);

        self::assertSame(1, $row->n_positions);
        // net PnL in scope = 6 gross - 1 commission = 5 ; allocated risk = 100 * 0.010 / 0.021 = 47.619047619...
        self::assertSame('0.10500000', $row->net_r_mean);
    }

    public function test_cost_to_risk_ratio_requires_matched_scope_p1_attribution(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $position = $this->seedClosedPosition($accounts[1], [
            'net_pnl' => '10', 'gross_pnl' => '12', 'fees' => '2', 'funding' => '0', 'risk_capital' => '100', 'initial_quantity' => '1',
        ]);

        $row = $this->generateAndPick($accounts[1]->id);

        self::assertNull($row->cost_to_risk_ratio);
        self::assertStringContainsString('INCOMPLETE_P1_MATCHED_SCOPE', (string) $row->cost_to_risk_reason);

        SlippageAttributionRecord::query()->create([
            'generation_id' => (string) Uuid::uuid4(),
            'position_id' => $position->id,
            'paper_account_id' => $accounts[1]->id,
            'strategy_id' => 'trend_pullback',
            'symbol' => 'BTCUSDT',
            'position_side' => 'BUY',
            'entry_slippage_usdt' => '0',
            'partial_exit_slippage_usdt' => '0',
            'final_exit_slippage_usdt' => '0',
            'total_slippage_drag_usdt' => '4',
            'total_reference_notional' => '100',
            'weighted_slippage_bps' => '0',
            'fill_count' => 2,
            'ambiguous_fill_count' => 0,
            'provenance_status' => 'RECORDED_SIMULATOR_ADVERSE_RECONSTRUCTED',
            'algorithm_version' => '1.0.0',
        ]);

        $row = $this->generateAndPick($accounts[1]->id, ['idempotency_key' => 'p2-cost-to-risk-matched']);

        // (fees 2 + drag 4 - funding 0) / risk 100
        self::assertSame('0.06000000', $row->cost_to_risk_ratio);
        self::assertNull($row->cost_to_risk_reason);
        self::assertSame('RECORDED_SIMULATOR_ADVERSE_RECONSTRUCTED=1', $row->cost_to_risk_provenance);
        // The diagnostic must never alter ledger PnL.
        self::assertSame('10', $row->position_population['included'][0]['net_pnl']);
    }

    public function test_daily_realized_net_r_allocates_risk_capital_to_the_quantity_actually_closed(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $position = $this->seedClosedPosition($accounts[1], [
            'closed_at' => null,
            'status' => 'PARTIALLY_CLOSED',
            'remaining_quantity' => '0.011',
            'skip_execution' => true,
            'net_pnl' => '5',
            'gross_pnl' => '6',
            'fees' => '1',
            'risk_capital' => '100',
            'initial_quantity' => '0.021',
        ]);
        $this->seedExecution($position, [
            'type' => 'TAKE_PROFIT_1',
            'quantity' => '0.010',
            'price' => '100',
            'gross_pnl' => '6',
            'commission' => '1',
            'market_event_at' => '2026-01-05 00:00:00+00:00',
            'registered_at' => '2026-01-05 00:00:01+00:00',
        ]);

        $row = $this->generateAndPick($accounts[1]->id, [
            'accounting_scope' => 'WINDOW_FLOW',
            'window_start' => '2026-01-01T00:00:00Z',
            'window_end' => '2026-01-10T00:00:00Z',
        ]);

        $population = $row->position_population['included'][0];
        self::assertTrue(
            BigDecimal::of((string) $population['realized_quantity'])->isEqualTo(BigDecimal::of('0.010')),
            'Realized quantity must be exactly 0.010 regardless of the driver string scale.'
        );
        self::assertTrue(
            BigDecimal::of((string) $population['initial_quantity'])->isEqualTo(BigDecimal::of('0.021')),
            'Initial quantity must be exactly 0.021.'
        );
    }

    public function test_twae_caps_long_intervals_and_records_the_uncovered_gap(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $this->seedSnapshot($accounts[1], '2026-01-01 00:00:00+00:00', '1000');
        $this->seedSnapshot($accounts[1], '2026-01-05 00:00:00+00:00', '1200');

        $row = $this->generateAndPick($accounts[1]->id, ['max_carry_forward_age_seconds' => 86400]);

        // Interval is 4 days; only 1 day is carried forward, 3 days are an uncovered gap.
        self::assertSame(259200, $row->coverage_gap_seconds);
        self::assertSame('1000.000000000000', $row->twa_equity);
        self::assertSame(2, $row->equity_observations);
    }

    public function test_max_drawdown_is_derived_from_the_equity_snapshot_series(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $this->seedSnapshot($accounts[1], '2026-01-01 00:00:00+00:00', '1000');
        $this->seedSnapshot($accounts[1], '2026-01-01 01:00:00+00:00', '1200');
        $this->seedSnapshot($accounts[1], '2026-01-01 02:00:00+00:00', '900');

        $row = $this->generateAndPick($accounts[1]->id);

        // (1200 - 900) / 1200 = 25%
        self::assertSame('25.00000000', $row->max_drawdown_pct);
    }

    public function test_sample_sufficiency_is_insufficient_for_a_short_history(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $this->seedClosedPosition($accounts[1], [
            'net_pnl' => '10', 'gross_pnl' => '12', 'fees' => '2', 'risk_capital' => '100', 'initial_quantity' => '1',
        ]);

        $row = $this->generateAndPick($accounts[1]->id);

        self::assertSame('INSUFFICIENT_DATA', $row->sample_sufficiency);
        self::assertNotEmpty($row->sample_sufficiency_reasons);
        self::assertStringContainsString('INSUFFICIENT_CALENDAR_DAYS', $row->sample_sufficiency_reasons[0]);
    }

    public function test_generation_is_idempotent_and_rejects_a_conflicting_payload(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $payload = [
            'idempotency_key' => 'p2-idempotency',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ];

        $first = $this->service()->generate($payload);
        $second = $this->service()->generate($payload);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, DB::table('research_generations')->where('idempotency_key', 'p2-idempotency')->count());

        $this->expectException(IdempotencyConflictException::class);
        $this->service()->generate(array_merge($payload, ['as_of' => '2026-01-03T00:00:00Z']));
    }

    public function test_unsupported_accounting_scope_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->generate([
            'idempotency_key' => 'p2-bad-scope',
            'accounting_scope' => 'SOMETHING_ELSE',
        ]);
    }

    public function test_window_flow_scope_requires_a_half_open_interval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->generate([
            'idempotency_key' => 'p2-bad-window',
            'accounting_scope' => 'WINDOW_FLOW',
            'window_start' => '2026-01-05T00:00:00Z',
            'window_end' => '2026-01-01T00:00:00Z',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return Collection<int, PaperAccountRecord>
     */
    private function seedEightPrimaryAccounts(): Collection
    {
        $accounts = collect();
        foreach (self::STRATEGIES as $strategyId) {
            $accounts->push(PaperAccountRecord::query()->create([
                'id' => (string) Uuid::uuid4(),
                'name' => 'Forward Paper '.$strategyId,
                'status' => 'ACTIVE',
                'starting_balance' => '10000',
                'current_equity' => '10000',
                'peak_equity' => '10000',
                'realized_pnl' => '0',
                'unrealized_pnl' => '0',
                'reserved_risk' => '0',
                'maximum_drawdown_percent' => '0',
                'current_drawdown_percent' => '0',
                'mode' => 'STRATEGY_FORWARD_PAPER',
                'strategy_id' => $strategyId,
                'primary_strategy_id' => $strategyId,
                'is_primary' => true,
                'strategy_version' => '1.0.0',
            ]));
        }

        return $accounts;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedClosedPosition(PaperAccountRecord $account, array $attributes = []): VirtualPositionRecord
    {
        $openedAt = $attributes['opened_at'] ?? '2026-01-01 00:00:00+00:00';
        $closedAt = array_key_exists('closed_at', $attributes) ? $attributes['closed_at'] : '2026-01-01 02:00:00+00:00';
        $skipExecution = (bool) ($attributes['skip_execution'] ?? false);
        unset($attributes['skip_execution']);

        $position = VirtualPositionRecord::query()->create(array_merge([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'signal_id' => $this->seedSignal($openedAt)->id,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'CLOSED_TP',
            'entry_price' => '100',
            'stop_loss' => '90',
            'take_profit_1' => '110',
            'take_profit_2' => '120',
            'initial_quantity' => '1',
            'remaining_quantity' => '0',
            'qty_step' => '0.001',
            'risk_capital' => '100',
            'gross_pnl' => '0',
            'net_pnl' => '0',
            'unrealized_pnl' => '0',
            'fees' => '0',
            'funding' => '0',
            'r_multiple' => null,
            'opened_at' => $openedAt,
            'maximum_holding_until' => CarbonImmutable::parse($openedAt, 'UTC')->addHours(4)->toIso8601String(),
            'closed_at' => $closedAt,
            'last_processed_event_at' => $closedAt ?? $openedAt,
            'execution_model' => 'NEXT_CANDLE_OPEN',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ], $attributes));

        if (! $skipExecution) {
            $this->seedRealizedExecutions($position);
        }

        return $position;
    }

    /**
     * Recreates the source-of-truth execution rows for the realized quantity of a position so that
     * the recomputed net PnL matches the ledger without relying on the stored position net PnL.
     */
    private function seedRealizedExecutions(VirtualPositionRecord $position): void
    {
        $initialQuantity = BigDecimal::of((string) $position->initial_quantity);
        $remainingQuantity = BigDecimal::of((string) $position->remaining_quantity);
        $closedQuantity = $initialQuantity->minus($remainingQuantity);

        if ($closedQuantity->isLessThanOrEqualTo(BigDecimal::zero())) {
            return;
        }

        $eventAt = $position->closed_at?->toIso8601String()
            ?? CarbonImmutable::parse((string) $position->opened_at, 'UTC')->addHour()->toIso8601String();

        $this->seedExecution($position, [
            'type' => 'TAKE_PROFIT_2',
            'quantity' => (string) $closedQuantity,
            'price' => (string) $position->entry_price,
            'gross_pnl' => (string) $position->gross_pnl,
            'commission' => (string) $position->fees,
            'market_event_at' => $eventAt,
            'registered_at' => CarbonImmutable::parse($eventAt, 'UTC')->addSecond()->toIso8601String(),
        ]);
    }

    /**
     * Creates a real signal row so that the virtual position foreign key stays satisfied.
     */
    private function seedSignal(string $openedAt): SignalRecord
    {
        $decisionTime = CarbonImmutable::parse($openedAt, 'UTC');

        return SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 80,
            'decision_time' => $decisionTime->toIso8601String(),
            'candle_close_time' => $decisionTime->addMicroseconds($this->signalSequence++)->toIso8601String(),
            'entry_low' => '100',
            'entry_high' => '100',
            'stop_loss' => '90',
            'take_profit_1' => '110',
            'take_profit_2' => '120',
            'risk_reward_1' => '1',
            'risk_reward_2' => '2',
            'model_risk_percent' => '1',
            'expires_at' => $decisionTime->addHours(4)->toIso8601String(),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedExecution(VirtualPositionRecord $position, array $attributes): VirtualExecutionRecord
    {
        return VirtualExecutionRecord::query()->create(array_merge([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $position->id,
            'type' => 'TAKE_PROFIT_2',
            'side' => 'SELL',
            'quantity' => '1',
            'price' => '100',
            'gross_pnl' => '0',
            'commission' => '0',
            'slippage' => '0',
            'market_event_at' => '2026-01-01 02:00:00+00:00',
            'registered_at' => '2026-01-01 02:00:01+00:00',
            'price_source' => 'NEXT_CANDLE_OPEN',
            'ambiguous_execution' => false,
            'idempotency_key' => (string) Uuid::uuid4(),
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ], $attributes));
    }

    private function seedSnapshot(PaperAccountRecord $account, string $recordedAt, string $equity): void
    {
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'recorded_at' => $recordedAt,
            'equity' => $equity,
            'realized_pnl' => '0',
            'unrealized_pnl' => '0',
            'drawdown_percent' => '0',
            'source_event_key' => 'test:'.$account->id.':'.$recordedAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function generateAndPick(string $paperAccountId, array $overrides = []): StrategyRiskMetricRecord
    {
        $generation = $this->service()->generate(array_merge([
            'idempotency_key' => 'p2-'.Uuid::uuid4()->toString(),
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-10T00:00:00Z',
        ], $overrides));

        $row = StrategyRiskMetricRecord::query()
            ->where('generation_id', $generation->id)
            ->where('paper_account_id', $paperAccountId)
            ->first();

        self::assertNotNull($row, 'Expected a P-2 metric row for the seeded account.');

        return $row;
    }
}
