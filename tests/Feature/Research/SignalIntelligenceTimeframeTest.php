<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Research\Application\SignalIntelligencePacketFactory;
use App\Research\Application\SignalIntelligenceService;
use App\Research\Infrastructure\Persistence\SignalOutcomeEvaluationRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SignalIntelligenceTimeframeTest extends TestCase
{
    use RefreshDatabase;

    public static function supportedTimeframes(): iterable
    {
        yield 'one minute' => ['1m', 1];
        yield 'three minutes' => ['3m', 3];
        yield 'five minutes' => ['5m', 5];
        yield 'fifteen minutes' => ['15m', 15];
        yield 'thirty minutes' => ['30m', 30];
        yield 'sixty minutes' => ['60m', 60];
        yield 'one hour' => ['1h', 60];
        foreach ([1, 3, 5, 15, 30, 60] as $minutes) {
            yield "numeric {$minutes}" => [(string) $minutes, $minutes];
        }
    }

    #[DataProvider('supportedTimeframes')]
    public function test_source_timeframe_and_evaluation_horizon_are_distinct(string $timeframe, int $expected): void
    {
        config(['paper.topology.auto_sync_enabled' => false, 'research.signal_intelligence.code_revision' => str_repeat('a', 40)]);
        $decision = CarbonImmutable::parse('2026-11-11T10:00:00Z');
        MarketContextRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'bid' => '99.99', 'ask' => '100.01', 'turnover_24h' => '100000000',
            'observed_at' => $decision->subSecond(), 'created_at' => $decision->subSecond(), 'updated_at' => $decision->subSecond(),
            'tick_size' => '0.01', 'qty_step' => '0.0001', 'min_order_qty' => '0.0001', 'max_order_qty' => '100', 'min_notional_value' => '1',
        ]);
        $signal = $this->signal($timeframe, $decision);

        $packet = app(SignalIntelligencePacketFactory::class)->evaluation($signal, [15], $decision->addHour());

        self::assertSame($expected, $packet['evaluation']['signal']['source_timeframe_minutes']);
        self::assertSame([15], $packet['evaluation']['horizons_minutes']);
    }

    public static function malformedTimeframes(): iterable
    {
        foreach (['15hours', '1hm', '01m', '1M', '2h', ' 5m'] as $timeframe) {
            yield $timeframe => [$timeframe];
        }
    }

    #[DataProvider('malformedTimeframes')]
    public function test_unsupported_timeframes_are_rejected(string $timeframe): void
    {
        $decision = CarbonImmutable::parse('2026-11-11T10:00:00Z');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Signal scope or evaluation horizons are unsupported.');
        app(SignalIntelligencePacketFactory::class)->evaluation($this->signal($timeframe, $decision), [15], $decision->addHour());
    }

    public function test_misattributed_historical_outcome_is_excluded_from_new_cohorts(): void
    {
        config(['paper.topology.auto_sync_enabled' => false, 'research.signal_intelligence.code_revision' => str_repeat('a', 40)]);
        $decision = CarbonImmutable::parse('2026-11-11T10:00:00Z');
        $asOf = $decision->addHour();
        MarketContextRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'bid' => '99.99', 'ask' => '100.01',
            'turnover_24h' => '100000000', 'observed_at' => $decision->subSecond(),
            'created_at' => $decision->subSecond(), 'updated_at' => $decision->subSecond(),
            'tick_size' => '0.01', 'qty_step' => '0.0001', 'min_order_qty' => '0.0001', 'max_order_qty' => '100', 'min_notional_value' => '1',
        ]);
        $signals = [];
        foreach (['1m', '1h', '1'] as $timeframe) {
            $signal = $this->signal($timeframe, $decision);
            $signal->fill(['id' => (string) Str::uuid(), 'quality_class' => 'A', 'score' => 90, 'entry_high' => '100',
                'stop_loss' => '98', 'take_profit_1' => '104', 'take_profit_2' => '106', 'expires_at' => $asOf,
                'updated_at' => $decision, 'maximum_holding_minutes' => 60, 'schema_version' => '1.0.0',
                'reasons' => [], 'risks' => [], 'contract' => []]);
            $signal->save();
            $signals[] = $signal;
        }
        $service = app(SignalIntelligenceService::class);
        $job = $service->scheduleSignal($signals[0], [15], $asOf);
        $scope = ['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0', 'source_timeframe_minutes' => 1, 'horizon_minutes' => 15,
            'direction' => 'BUY', 'regime' => 'UNCERTAIN', 'configuration_hash' => str_repeat('b', 64),
            'code_revision' => str_repeat('a', 40), 'label_policy_version' => 'HYPOTHETICAL_HORIZON_MARKOUT_V1',
            'data_source' => 'HYPOTHETICAL', 'horizon_anchor' => 'NEXT_MINUTE_OPEN', 'execution_hash' => str_repeat('c', 64)];
        $rows = [];
        foreach ($signals as $index => $signal) {
            $rows[] = SignalOutcomeEvaluationRecord::query()->create([
                'id' => (string) Str::uuid(), 'evaluation_key' => str_repeat((string) ($index + 1), 64),
                'signal_id' => $signal->id, 'research_job_id' => $job->job_id,
                'exchange' => $scope['exchange'], 'category' => $scope['category'], 'symbol' => $scope['symbol'],
                'strategy_id' => $scope['strategy_id'], 'strategy_version' => $scope['strategy_version'],
                'source_timeframe_minutes' => 1, 'horizon_minutes' => 15, 'direction' => 'BUY', 'regime' => 'UNCERTAIN',
                'data_source' => 'HYPOTHETICAL', 'policy_version' => $scope['label_policy_version'],
                'configuration_hash' => $scope['configuration_hash'], 'dataset_hash' => str_repeat('d', 64),
                'code_revision' => $scope['code_revision'], 'input_hash' => str_repeat((string) ($index + 3), 64),
                'execution_hash' => $scope['execution_hash'], 'status' => 'MATURED', 'reason' => 'TEST',
                'horizon_anchor' => $scope['horizon_anchor'], 'decision_at' => $decision, 'available_at' => $decision,
                'entry_at' => $decision, 'horizon_end_at' => $decision->addMinutes(15), 'evaluated_as_of' => $asOf,
                'configured_notional' => '5', 'gross_pnl' => '1', 'fees' => '0', 'funding' => '0', 'net_pnl' => '1',
                'net_return' => '0.2', 'result' => ['scope' => $scope], 'created_at' => $asOf,
            ]);
        }

        $cohort = $service->scheduleCohort($rows[0], $asOf);
        $observations = $cohort->payload['signal_intelligence']['cohort']['observations'];
        self::assertCount(2, $observations);
        self::assertEqualsCanonicalizing([$signals[0]->id, $signals[2]->id], array_column($observations, 'id'));
        self::assertSame(3, SignalOutcomeEvaluationRecord::query()->count());
        $this->getJson('/api/v1/research/profitability/signal-horizons?source_timeframe_minutes=1')
            ->assertOk()->assertJsonPath('invalid_source_timeframe_count', 1)->assertJsonPath('outcomes.total', 2);

        // Signals can be edited; a later change cannot retroactively authorize
        // historical cohort evidence, even if its current timeframe still matches.
        $signals[0]->timestamps = false;
        $signals[0]->update(['score' => 91, 'updated_at' => $job->created_at->addSecond()]);
        $afterEdit = $service->scheduleCohort($rows[0], $asOf);
        self::assertSame($signals[2]->id, $afterEdit->payload['signal_intelligence']['cohort']['observations'][0]['id']);
        self::assertCount(1, $afterEdit->payload['signal_intelligence']['cohort']['observations']);
        $this->getJson('/api/v1/research/profitability/signal-horizons?source_timeframe_minutes=1')
            ->assertOk()->assertJsonPath('invalid_source_timeframe_count', 2)->assertJsonPath('outcomes.total', 1);
    }

    private function signal(string $timeframe, CarbonImmutable $decision): SignalRecord
    {
        return new SignalRecord([
            'id' => '11111111-1111-4111-8111-111111111111', 'exchange' => 'bybit', 'category' => 'linear',
            'symbol' => 'BTCUSDT', 'timeframe' => $timeframe, 'strategy_id' => 'trend_pullback', 'strategy_version' => '1.0.0',
            'side' => 'BUY', 'entry_low' => '100', 'decision_time' => $decision, 'candle_close_time' => $decision,
            'created_at' => $decision, 'indicator_snapshot' => [],
        ]);
    }
}
