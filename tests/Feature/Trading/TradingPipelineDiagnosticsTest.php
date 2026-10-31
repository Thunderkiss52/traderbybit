<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use App\Trading\Application\TradingPipelineDiagnosticsService;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class TradingPipelineDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    public function test_diagnostics_correctly_classifies_status_anomalies_vs_genuinely_stale(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T21:30:00Z');
        $at1 = new DateTimeImmutable('2026-10-31T21:00:00Z');
        $at2 = new DateTimeImmutable('2026-10-31T21:15:00Z');

        // Slot 1: Event marked STALE, BUT all 13 canonical observations exist -> STATUS_ANOMALY
        $staleEventWithObservations = TradingPipelineEventRecord::query()->create([
            'event_key' => 'binance:BTCUSDT:15:'.$at1->format(DATE_ATOM).':'.TradingPipelineEventRecord::STAGE_CANDLE_CLOSED,
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'stage' => TradingPipelineEventRecord::STAGE_CANDLE_CLOSED,
            'candle_close_at' => $at1,
            'status' => TradingPipelineEventRecord::STATUS_STALE,
            'payload' => ['candle_close_to_event_ms' => 45.2],
            'attempts' => 1,
            'last_error' => 'Expired outside 300s freshness window',
            'created_at' => CarbonImmutable::parse('2026-10-31T21:00:01Z'),
        ]);

        $strategies = (array) config('paper.primary_strategies', []);
        foreach ($strategies as $strategyId) {
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', [
                'traderbybit:strategy-observation', 'binance', 'linear', 'BTCUSDT',
                $strategyId, '1.0.0', $at1->format('U.u'),
            ]))->toString();

            StrategyObservationRecord::query()->create([
                'id' => $id,
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'strategy_id' => $strategyId,
                'strategy_version' => '1.0.0',
                'configuration_hash' => hash('sha256', $strategyId),
                'side' => 'SKIP',
                'quality_class' => 'A',
                'score' => 50,
                'decision_time' => $at1,
                'reasons' => ['test' => true],
                'analysis' => ['diagnostics' => ['ok' => true]],
            ]);
        }

        // Slot 2: Event marked STALE with 0 observations -> GENUINELY_STALE
        $genuinelyStaleEvent = TradingPipelineEventRecord::query()->create([
            'event_key' => 'binance:BTCUSDT:15:'.$at2->format(DATE_ATOM).':'.TradingPipelineEventRecord::STAGE_CANDLE_CLOSED,
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'stage' => TradingPipelineEventRecord::STAGE_CANDLE_CLOSED,
            'candle_close_at' => $at2,
            'status' => TradingPipelineEventRecord::STATUS_STALE,
            'payload' => ['candle_close_to_event_ms' => 52.1],
            'attempts' => 1,
            'last_error' => 'Expired outside 300s freshness window',
            'created_at' => CarbonImmutable::parse('2026-10-31T21:15:01Z'),
        ]);

        $diagnostics = new TradingPipelineDiagnosticsService;
        $report = $diagnostics->inspect('binance', 'BTCUSDT', 10);

        self::assertSame('binance', $report['exchange']);
        self::assertSame('BTCUSDT', $report['symbol']);
        self::assertSame(2, $report['inspected_events_count']);
        self::assertSame(1, $report['summary']['status_anomalies']);
        self::assertSame(1, $report['summary']['genuinely_stale']);
        self::assertSame(0, $report['summary']['genuinely_completed']);

        // Verify latency calculation
        self::assertNotNull($report['latency_metrics']['avg_candle_close_to_event_ms']);
        self::assertSame(48.65, $report['latency_metrics']['avg_candle_close_to_event_ms']);

        // Verify individual record classifications
        $eventMap = collect($report['events'])->keyBy('event_key');
        self::assertStringStartsWith('STATUS_ANOMALY', (string) $eventMap->get($staleEventWithObservations->event_key)['classification']);
        self::assertStringStartsWith('GENUINELY_STALE', (string) $eventMap->get($genuinelyStaleEvent->event_key)['classification']);
    }
}
