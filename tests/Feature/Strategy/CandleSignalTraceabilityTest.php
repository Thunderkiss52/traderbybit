<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Strategy\Domain\PricePlan;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Infrastructure\Persistence\EloquentSignalRepository;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class CandleSignalTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_signal_is_automatically_linked_to_closed_candle(): void
    {
        $openTime = CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC');
        $closeTime = $openTime->addMinutes(15)->toDateTimeImmutable();

        // 1. Create closed 15m candle in market_candles
        $candle = CandleRecord::query()->create([
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $openTime,
            'open' => '65000.00',
            'high' => '65500.00',
            'low' => '64900.00',
            'close' => '65400.00',
            'volume' => '120.5',
            'turnover' => '7800000.00',
            'is_closed' => true,
        ]);

        $decision = new SignalDecision(
            id: Uuid::uuid4()->toString(),
            exchange: 'binance',
            category: 'linear',
            symbol: 'BTCUSDT',
            timeframe: '15m',
            decisionTime: $closeTime,
            candleCloseTime: $closeTime,
            side: SignalSide::Buy,
            qualityClass: QualityClass::A,
            score: 95,
            pricePlan: new PricePlan('65400.00', '65450.00', '64800.00', '66600.00', '67800.00', '2.00', '4.00'),
            modelRiskPercent: '0.0100',
            expiresAt: (new DateTimeImmutable)->modify('+1 hour'),
            maximumHoldingMinutes: 120,
            reasons: ['trend_continuation', 'ema_alignment'],
            risks: ['high_volatility'],
            indicatorSnapshot: ['rsi' => 55.4, 'strategy_id' => 'baseline'],
            strategyVersion: '1.0.0',
            schemaVersion: '1.0.0',
        );

        $repo = new EloquentSignalRepository;
        $persisted = $repo->createIfAbsent($decision);

        $this->assertSame($candle->id, $persisted->candleId);

        $record = SignalRecord::query()->findOrFail($decision->id);
        $this->assertEquals($candle->id, $record->candle_id);
        $this->assertInstanceOf(CandleRecord::class, $record->candle);
        $this->assertEquals($candle->id, $record->candle->id);
    }

    public function test_signal_without_matching_candle_remains_safe_with_null_candle_id(): void
    {
        $closeTime = new DateTimeImmutable('2026-11-08 14:15:00');

        $decision = new SignalDecision(
            id: Uuid::uuid4()->toString(),
            exchange: 'bybit',
            category: 'linear',
            symbol: 'ETHUSDT',
            timeframe: '15m',
            decisionTime: $closeTime,
            candleCloseTime: $closeTime,
            side: SignalSide::Skip,
            qualityClass: null,
            score: 0,
            pricePlan: null,
            modelRiskPercent: null,
            expiresAt: $closeTime->modify('+15 minutes'),
            maximumHoldingMinutes: 15,
            reasons: ['adx_below_threshold'],
            risks: [],
            indicatorSnapshot: ['strategy_id' => 'baseline'],
            strategyVersion: '1.0.0',
            schemaVersion: '1.0.0',
        );

        $repo = new EloquentSignalRepository;
        $persisted = $repo->createIfAbsent($decision);

        $this->assertNull($persisted->candleId);

        $record = SignalRecord::query()->findOrFail($decision->id);
        $this->assertNull($record->candle_id);
        $this->assertNull($record->candle);
    }
}
