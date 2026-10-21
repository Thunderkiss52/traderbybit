<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\CostMfeMaeService;
use App\Research\Domain\ResearchCostAttributionRecord;
use App\Research\Domain\ResearchMfeMaeRecord;
use App\Research\Exceptions\IdempotencyConflictException;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * PROFIT-1 / P-4 acceptance coverage (§9.3).
 */
final class P4CostMfeMaeTest extends TestCase
{
    use RefreshDatabase;

    private int $signalSequence = 1;

    public function test_generation_is_idempotent_and_never_mutates_the_paper_ledger(): void
    {
        $account = $this->primaryAccount();
        $this->seedPosition($account, [
            'id' => '11111111-1111-4111-8111-111111111111',
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 02:00:00+00:00',
        ]);
        $this->seedCandles();

        $accountsBefore = DB::table('paper_accounts')->count();
        $positionsBefore = DB::table('virtual_positions')->count();

        $generation = $this->service()->generate([
            'idempotency_key' => 'p4-idempotent',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);

        self::assertSame('SUCCEEDED', $generation->status);
        self::assertSame($accountsBefore, DB::table('paper_accounts')->count(), 'P-4 must not create, merge or delete paper accounts.');
        self::assertSame($positionsBefore, DB::table('virtual_positions')->count(), 'P-4 must not mutate paper positions.');

        $repeat = $this->service()->generate([
            'idempotency_key' => 'p4-idempotent',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);
        self::assertSame($generation->id, $repeat->id, 'The same idempotency key and payload must return the same generation.');

        $this->expectException(IdempotencyConflictException::class);
        $this->service()->generate([
            'idempotency_key' => 'p4-idempotent',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-03T00:00:00Z',
        ]);
    }

    public function test_observed_mfe_and_mae_use_closed_candles_of_the_holding_interval_only(): void
    {
        $account = $this->primaryAccount();
        $position = $this->seedPosition($account, [
            'id' => '22222222-2222-4222-8222-222222222222',
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 01:00:00+00:00',
            'entry_price' => '100',
            'stop_loss' => '90',
            'take_profit_1' => '120',
            'initial_quantity' => '2',
        ]);
        $this->seedCandles();

        $generation = $this->service()->generate([
            'idempotency_key' => 'p4-mfe-mae',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);

        $record = ResearchMfeMaeRecord::query()
            ->where('generation_id', $generation->id)
            ->where('position_id', $position->id)
            ->firstOrFail();

        self::assertSame('COMPUTED', $record->status);
        self::assertTrue(BigDecimal::of((string) $record->mfe_price)->isEqualTo(BigDecimal::of('10.000000000000')));
        self::assertTrue(BigDecimal::of((string) $record->mae_price)->isEqualTo(BigDecimal::of('5.000000000000')));
        self::assertTrue(BigDecimal::of((string) $record->mfe_usdt)->isEqualTo(BigDecimal::of('20.000000000000')));
        self::assertTrue(BigDecimal::of((string) $record->mae_usdt)->isEqualTo(BigDecimal::of('10.000000000000')));
        self::assertTrue(BigDecimal::of((string) $record->mfe_r)->isEqualTo(BigDecimal::of('1.000000000000')));
        self::assertTrue(BigDecimal::of((string) $record->mae_r)->isEqualTo(BigDecimal::of('0.500000000000')));
        self::assertFalse((bool) $record->ambiguous_path);
        self::assertNotNull($record->mfe_candle_id);
        self::assertNotNull($record->source_candle_ids);
    }

    public function test_pre_entry_distribution_excludes_observations_that_matured_after_the_entry(): void
    {
        $account = $this->primaryAccount();

        $early = $this->seedPosition($account, [
            'id' => '33333333-3333-4333-8333-333333333333',
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 01:00:00+00:00',
        ]);

        $late = $this->seedPosition($account, [
            'id' => '44444444-4444-4444-8444-444444444444',
            'opened_at' => '2026-01-01 04:00:00+00:00',
            'closed_at' => '2026-01-01 05:00:00+00:00',
        ]);

        $this->seedCandles();

        $generation = $this->service()->generate([
            'idempotency_key' => 'p4-pre-entry',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);

        $preEntry = $generation->metadata['pre_entry'];

        $earlyEstimate = $preEntry[$early->id];
        self::assertSame('INSUFFICIENT_DATA', $earlyEstimate['status']);
        self::assertSame('NO_MATURED_PRIOR_OBSERVATIONS', $earlyEstimate['reason']);

        $lateEstimate = $preEntry[$late->id];
        self::assertSame('COMPUTED', $lateEstimate['status']);
        self::assertSame(1, $lateEstimate['sample_count']);
        self::assertTrue($lateEstimate['no_future_data_proof']);
        self::assertSame(
            '2026-01-01T01:00:00+00:00',
            $lateEstimate['max_included_matured_at'],
            'Only observations matured before the candidate entry may be used.'
        );
    }

    public function test_cost_attribution_reconciles_gross_minus_fees_plus_funding(): void
    {
        $account = $this->primaryAccount();
        $position = $this->seedPosition($account, [
            'id' => '55555555-5555-4555-8555-555555555555',
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 02:00:00+00:00',
            'gross_pnl' => '10',
            'fees' => '0.3',
            'net_pnl' => '9.8',
        ]);

        $this->seedExecution($position, 'ENTRY', 'BUY', '1', '100', '0.000000000000', '0.200000000000', '0');
        $this->seedExecution($position, 'TAKE_PROFIT_1', 'SELL', '1', '110', '0.000000000000', '0.100000000000', '10');
        $this->seedFunding($position, '0.100000000000');
        $this->seedCandles();

        $generation = $this->service()->generate([
            'idempotency_key' => 'p4-cost-attribution',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);

        $record = ResearchCostAttributionRecord::query()
            ->where('generation_id', $generation->id)
            ->where('dimension', 'strategy')
            ->firstOrFail();

        $metrics = $record->metrics;
        self::assertTrue($metrics['reconciles_gross_minus_fees_plus_funding']);
        self::assertTrue(BigDecimal::of((string) $metrics['commission_total'])->isEqualTo(BigDecimal::of('0.300000000000')));
        self::assertTrue(BigDecimal::of((string) $metrics['funding_signed'])->isEqualTo(BigDecimal::of('0.100000000000')));
        self::assertTrue(BigDecimal::of((string) $metrics['net_pnl'])->isEqualTo(BigDecimal::of('9.800000000000')));
        self::assertSame(2, $metrics['execution_count']);
        self::assertArrayHasKey('ENTRY', $metrics['cost_by_role']);
        self::assertArrayHasKey('FINAL_EXIT', $metrics['cost_by_role']);
    }

    public function test_a_position_without_candles_is_reported_as_null_metrics_not_zero(): void
    {
        $account = $this->primaryAccount();
        $this->seedPosition($account, [
            'id' => '66666666-6666-4666-8666-666666666666',
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 02:00:00+00:00',
        ]);

        $generation = $this->service()->generate([
            'idempotency_key' => 'p4-no-candles',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);

        $record = ResearchMfeMaeRecord::query()
            ->where('generation_id', $generation->id)
            ->firstOrFail();

        self::assertSame('NO_ELIGIBLE_CANDLES', $record->status);
        self::assertNull($record->mfe_price);
        self::assertNull($record->mae_price);
        self::assertNull($record->mfe_r);
        self::assertSame(0, (int) $record->candle_count);
    }

    private function service(): CostMfeMaeService
    {
        return $this->app->make(CostMfeMaeService::class);
    }

    private function primaryAccount(): PaperAccountRecord
    {
        $accounts = $this->app->make(PaperAccountService::class)->allPrimaryAccounts();

        return $accounts->first();
    }

    private function seedPosition(PaperAccountRecord $account, array $attributes = []): VirtualPositionRecord
    {
        $openedAt = $attributes['opened_at'] ?? '2026-01-01 00:00:00+00:00';
        $closedAt = array_key_exists('closed_at', $attributes) ? $attributes['closed_at'] : '2026-01-01 02:00:00+00:00';

        return VirtualPositionRecord::query()->create(array_merge([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'signal_id' => $this->seedSignal($openedAt)->id,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'CLOSED_TP',
            'entry_price' => '100',
            'stop_loss' => '90',
            'take_profit_1' => '120',
            'take_profit_2' => '130',
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
    }

    private function seedExecution(VirtualPositionRecord $position, string $type, string $side, string $quantity, string $price, string $slippage, string $commission, string $grossPnl): VirtualExecutionRecord
    {
        return VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $position->id,
            'type' => $type,
            'side' => $side,
            'quantity' => $quantity,
            'price' => $price,
            'gross_pnl' => $grossPnl,
            'commission' => $commission,
            'slippage' => $slippage,
            'market_event_at' => $position->opened_at,
            'registered_at' => $position->opened_at,
            'price_source' => 'closed_5m_ohlc',
            'idempotency_key' => $position->id.':'.$type.':'.$quantity,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
    }

    private function seedFunding(VirtualPositionRecord $position, string $amount): PositionFundingEventRecord
    {
        $fundingEvent = MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => (string) $position->symbol,
            'funding_at' => $position->opened_at,
            'rate' => '0.0001',
            'mark_price' => (string) $position->entry_price,
        ]);

        return PositionFundingEventRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $position->id,
            'market_funding_event_id' => $fundingEvent->id,
            'quantity' => $position->initial_quantity,
            'mark_price' => $position->entry_price,
            'rate' => '0.0001',
            'amount' => $amount,
            'funding_at' => $position->opened_at,
        ]);
    }

    private function seedCandles(): void
    {
        $base = CarbonImmutable::parse('2026-01-01 00:00:00+00:00');

        for ($i = 0; $i < 24; $i++) {
            $openTime = $base->addMinutes($i * 5);

            CandleRecord::query()->create([
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'interval' => '5',
                'open_time' => $openTime->toIso8601String(),
                'open' => '100',
                'high' => '110',
                'low' => '95',
                'close' => '105',
                'volume' => '1',
                'turnover' => '1',
                'is_closed' => true,
            ]);
        }
    }

    private function seedSignal(string $openedAt): SignalRecord
    {
        $decisionTime = CarbonImmutable::parse($openedAt, 'UTC');

        return SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => '80',
            'decision_time' => $decisionTime->toIso8601String(),
            'candle_close_time' => $decisionTime->toIso8601String(),
            'expires_at' => $decisionTime->addHours(4)->toIso8601String(),
            'entry_low' => '99',
            'entry_high' => '101',
            'stop_loss' => '90',
            'take_profit_1' => '120',
            'take_profit_2' => '130',
            'risk_reward_1' => '2',
            'risk_reward_2' => '3',
            'model_risk_percent' => '1',
            'maximum_holding_minutes' => '240',
            'reasons' => '[]',
            'risks' => '[]',
            'indicator_snapshot' => '{}',
            'strategy_id' => 'p4-test-'.($this->signalSequence++),
            'strategy_version' => '1.0.0',
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);
    }
}
