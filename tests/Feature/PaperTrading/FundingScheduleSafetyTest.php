<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FundingScheduleSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function createSignal(string $exchange, string $symbol, string $side): SignalRecord
    {
        $at = new DateTimeImmutable('2026-10-26T23:30:00Z');

        return SignalRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'side' => $side,
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
            'decision_time' => $at,
            'candle_close_time' => $at,
            'expires_at' => $at->modify('+24 hours'),
            'maximum_holding_minutes' => 720,
            'model_risk_percent' => '0.50',
            'reasons' => ['test_fixture'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['close' => '60000']],
            'contract' => ['research_mode' => true],
            'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
    }

    public function test_expected_funding_boundaries_returns_null_when_exchange_or_symbol_is_uncertified(): void
    {
        $lifecycle = app(PositionLifecycleService::class);
        $from = new DateTimeImmutable('2026-10-27T00:00:00Z');
        $to = new DateTimeImmutable('2026-10-27T12:00:00Z');

        // Missing exchange/symbol
        $this->assertNull($lifecycle->expectedFundingBoundaries($from, $to, null, null));

        // Unknown symbol with no historical events or config
        $this->assertNull($lifecycle->expectedFundingBoundaries($from, $to, 'bybit', 'UNKNOWN_ALT_USDT'));
    }

    public function test_expected_funding_boundaries_resolves_standard_eight_hour_schedule(): void
    {
        $lifecycle = app(PositionLifecycleService::class);
        $from = new DateTimeImmutable('2026-10-27T01:00:00Z');
        $to = new DateTimeImmutable('2026-10-27T17:00:00Z');

        $boundaries = $lifecycle->expectedFundingBoundaries($from, $to, 'bybit', 'BTCUSDT');
        $this->assertNotNull($boundaries);
        $this->assertCount(2, $boundaries);
        $this->assertSame('2026-10-27T08:00:00+00:00', $boundaries[0]->format(DATE_ATOM));
        $this->assertSame('2026-10-27T16:00:00+00:00', $boundaries[1]->format(DATE_ATOM));
    }

    public function test_expected_funding_boundaries_resolves_dynamic_four_hour_schedule_from_continuous_events(): void
    {
        $lifecycle = app(PositionLifecycleService::class);

        // Ingest continuous 4-hour events for SOLUSDT
        $times = [
            '2026-10-27T00:00:00Z',
            '2026-10-27T04:00:00Z',
            '2026-10-27T08:00:00Z',
            '2026-10-27T12:00:00Z',
            '2026-10-27T16:00:00Z',
        ];
        foreach ($times as $t) {
            MarketFundingEventRecord::query()->create([
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => 'SOLUSDT',
                'funding_at' => new DateTimeImmutable($t),
                'rate' => '0.000100000000',
                'mark_price' => '150.000000000000',
            ]);
        }

        $from = new DateTimeImmutable('2026-10-27T01:00:00Z');
        $to = new DateTimeImmutable('2026-10-27T11:00:00Z');

        $boundaries = $lifecycle->expectedFundingBoundaries($from, $to, 'binance', 'SOLUSDT');
        $this->assertNotNull($boundaries);
        $this->assertCount(2, $boundaries);
        $this->assertSame('2026-10-27T04:00:00+00:00', $boundaries[0]->format(DATE_ATOM));
        $this->assertSame('2026-10-27T08:00:00+00:00', $boundaries[1]->format(DATE_ATOM));
    }

    public function test_evaluate_funding_completeness_fails_closed_when_schedule_is_uncertified(): void
    {
        $lifecycle = app(PositionLifecycleService::class);

        // Ingest event for uncertified symbol
        MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'UNKNOWN_ALT_USDT',
            'funding_at' => new DateTimeImmutable('2026-10-27T12:00:00Z'),
            'rate' => '0.0001',
            'mark_price' => '10',
        ]);

        $account = app(PaperAccountService::class)->default();
        $signal = $this->createSignal('bybit', 'UNKNOWN_ALT_USDT', 'BUY');

        $position = VirtualPositionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange_id' => 'bybit',
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'symbol' => 'UNKNOWN_ALT_USDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'CLOSED_SL',
            'entry_price' => '10.0',
            'stop_loss' => '9.0',
            'take_profit_1' => '11.0',
            'take_profit_2' => '12.0',
            'qty_step' => '0.001',
            'initial_quantity' => '1.0',
            'remaining_quantity' => '0.0',
            'risk_capital' => '1.0',
            'gross_pnl' => '-1.0',
            'net_pnl' => '-1.0',
            'fees' => '0.0',
            'funding' => '0.0',
            'r_multiple' => '-1.0',
            'opened_at' => new DateTimeImmutable('2026-10-27T10:00:00Z'),
            'maximum_holding_until' => new DateTimeImmutable('2026-10-27T14:00:00Z'),
            'closed_at' => new DateTimeImmutable('2026-10-27T11:00:00Z'),
            'execution_model' => 'BAR_HIGH_LOW',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'funding_complete' => true, // will be reset to false
        ]);

        $result = $lifecycle->evaluateFundingCompleteness($position);
        $this->assertFalse($result);
        $this->assertFalse($position->fresh()->funding_complete);
    }

    public function test_evaluate_funding_completeness_rejects_arbitrary_thirty_minute_offset(): void
    {
        $lifecycle = app(PositionLifecycleService::class);

        // Scheduled boundary for BTCUSDT is 08:00:00 UTC.
        // If an ingested event is timestamped 25 minutes late (08:25:00 UTC),
        // the old arbitrary ±30-minute tolerance accepted it, but safe verification rejects it.
        $mfe = MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'funding_at' => new DateTimeImmutable('2026-10-27T08:25:00Z'),
            'rate' => '0.0001',
            'mark_price' => '60000',
        ]);

        $account = app(PaperAccountService::class)->default();
        $signal = $this->createSignal('bybit', 'BTCUSDT', 'BUY');

        $position = VirtualPositionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange_id' => 'bybit',
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'CLOSED_TP',
            'entry_price' => '60000.0',
            'stop_loss' => '59000.0',
            'take_profit_1' => '61000.0',
            'take_profit_2' => '62000.0',
            'qty_step' => '0.001',
            'initial_quantity' => '0.01',
            'remaining_quantity' => '0.0',
            'risk_capital' => '100.0',
            'gross_pnl' => '200.0',
            'net_pnl' => '200.0',
            'fees' => '0.0',
            'funding' => '0.0',
            'r_multiple' => '2.0',
            'opened_at' => new DateTimeImmutable('2026-10-27T07:00:00Z'),
            'maximum_holding_until' => new DateTimeImmutable('2026-10-27T12:00:00Z'),
            'closed_at' => new DateTimeImmutable('2026-10-27T09:00:00Z'),
            'execution_model' => 'BAR_HIGH_LOW',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'funding_complete' => false,
        ]);

        PositionFundingEventRecord::query()->create([
            'id' => (string) Str::uuid(),
            'position_id' => $position->id,
            'market_funding_event_id' => $mfe->id,
            'quantity' => '0.01',
            'mark_price' => '60000',
            'rate' => '0.0001',
            'amount' => '-0.06',
            'funding_at' => new DateTimeImmutable('2026-10-27T08:25:00Z'),
        ]);

        $result = $lifecycle->evaluateFundingCompleteness($position);
        $this->assertFalse($result);
        $this->assertFalse($position->fresh()->funding_complete);
    }
}
