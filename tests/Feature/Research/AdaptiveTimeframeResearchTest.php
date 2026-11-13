<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\MarketData\Domain\Interval;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\AdaptiveTimeframeResearchService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdaptiveTimeframeResearchTest extends TestCase
{
    use RefreshDatabase;

    private AdaptiveTimeframeResearchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AdaptiveTimeframeResearchService;
    }

    public function test_all_seven_supported_intervals_are_analyzed_causally(): void
    {
        $symbol = 'BTCUSDT';
        $baseTime = CarbonImmutable::parse('2026-10-01T00:00:00Z');
        $this->travelTo($baseTime->addDays(21));

        // Seed candles for all 7 intervals: 1, 5, 15, 30, 60, 240, D
        $intervals = AdaptiveTimeframeResearchService::SUPPORTED_INTERVALS;
        self::assertCount(7, $intervals);

        foreach ($intervals as $interval) {
            for ($i = 0; $i < 20; $i++) {
                $seconds = Interval::parse($interval)->seconds();
                $openTime = $baseTime->addSeconds($i * $seconds);
                CandleRecord::query()->create([
                    'exchange' => 'bybit',
                    'category' => 'linear',
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'open_time' => $openTime,
                    'open' => '50000.0',
                    'high' => '50500.0',
                    'low' => '49800.0',
                    'close' => (string) (50000.0 + ($i * 10)),
                    'volume' => '100.0',
                    'turnover' => '5000000.0',
                    'is_closed' => true,
                    'created_at' => $openTime->addSeconds($seconds),
                    'updated_at' => $openTime->addSeconds($seconds),
                ]);
            }
        }

        $analysis = $this->service->analyzeTimeframes($symbol, lookbackBars: 20);

        self::assertSame($symbol, $analysis['symbol']);
        self::assertCount(7, $analysis['intervals']);

        foreach ($intervals as $interval) {
            $data = $analysis['intervals'][$interval];
            self::assertSame('ACTIVE_RESEARCH', $data['status']);
            self::assertSame(20, $data['sample_bars']);
            self::assertGreaterThan(0.0, $data['mean_atr_pct']);
            self::assertGreaterThan(0.0, $data['mean_turnover']);
            self::assertNotEmpty($data['recommended_role']);
        }
    }

    public function test_timeframe_hierarchy_validation(): void
    {
        // Valid hierarchy: 240m context >= 30m setup >= 15m decision >= 5m trigger
        $validConfig = $this->service->evaluateConfiguration('trend_pullback', 'BTCUSDT', [
            'context_tf' => '240',
            'setup_tf' => '30',
            'decision_tf' => '15',
            'trigger_tf' => '5',
        ]);

        self::assertTrue($validConfig['hierarchy_valid']);
        self::assertSame('CONFIG_VERIFIED', $validConfig['evaluation_status']);
        self::assertTrue($validConfig['causality_enforced']);

        // Invalid hierarchy: decision TF (60m) is larger than context TF (15m)
        $invalidConfig = $this->service->evaluateConfiguration('trend_pullback', 'BTCUSDT', [
            'context_tf' => '15',
            'setup_tf' => '30',
            'decision_tf' => '60',
            'trigger_tf' => '5',
        ]);

        self::assertFalse($invalidConfig['hierarchy_valid']);
        self::assertSame('INVALID_HIERARCHY', $invalidConfig['evaluation_status']);
    }

    public function test_other_venue_category_symbol_and_source_interval_cannot_contaminate_default_analysis(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-05T01:00:00Z');
        $this->travelTo($asOf);
        foreach (['00:00', '00:15'] as $time) {
            $open = CarbonImmutable::parse('2026-10-05T'.$time.':00Z');
            $this->candle($open);
        }
        $before = $this->service->analyzeTimeframes(lookbackBars: 2);
        foreach (['00:00', '00:15'] as $time) {
            $open = CarbonImmutable::parse('2026-10-05T'.$time.':00Z');
            foreach ([['binance', 'linear', 'BTCUSDT', '15'], ['bybit', 'spot', 'BTCUSDT', '15'], ['bybit', 'linear', 'ETHUSDT', '15'], ['bybit', 'linear', 'BTCUSDT', '5']] as [$exchange, $category, $symbol, $interval]) {
                $this->candle($open, $exchange, $category, $symbol, $interval, '9000000');
            }
        }
        $after = $this->service->analyzeTimeframes(lookbackBars: 2);

        self::assertSame($before['intervals']['15'], $after['intervals']['15']);
        self::assertSame(2, $after['intervals']['15']['sample_bars']);
        self::assertSame(100.0, $after['intervals']['15']['mean_turnover']);
    }

    public function test_explicit_scope_is_preserved_without_fallback_and_has_versioned_provenance(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-05T01:00:00Z');
        $this->travelTo($asOf->addDay());
        foreach (['00:00', '00:15'] as $time) {
            $open = CarbonImmutable::parse('2026-10-05T'.$time.':00Z');
            $this->candle($open);
            $this->candle($open, 'binance', 'spot', turnover: '500');
        }
        $analysis = $this->service->analyzeTimeframes(exchange: 'binance', category: 'spot', asOf: $asOf);
        self::assertSame('binance', $analysis['exchange']);
        self::assertSame('spot', $analysis['category']);
        self::assertSame($asOf->toIso8601String(), $analysis['as_of']);
        self::assertSame(AdaptiveTimeframeResearchService::VERSION, $analysis['analysis_version']);
        self::assertSame(500.0, $analysis['intervals']['15']['mean_turnover']);
        $missing = $this->service->analyzeTimeframes(exchange: 'okx', asOf: $asOf);
        self::assertSame('INSUFFICIENT_DATA', $missing['intervals']['15']['status']);
        self::assertSame(0, $missing['intervals']['15']['sample_bars']);
    }

    #[DataProvider('unavailableCandleCases')]
    public function test_economically_unclosed_or_unavailable_candles_cannot_change_as_of_evidence(string $case): void
    {
        $asOf = CarbonImmutable::parse('2026-10-05T01:00:00Z');
        $this->travelTo($asOf);
        foreach (['00:00', '00:15'] as $time) {
            $this->candle(CarbonImmutable::parse('2026-10-05T'.$time.':00Z'));
        }
        $before = $this->service->analyzeTimeframes(lookbackBars: 100);
        $open = match ($case) {
            'future_close' => $asOf->subMinutes(10),
            'future_open' => $asOf->addMinutes(15),
            default => $asOf->subMinutes(30),
        };
        $this->candle($open, turnover: '9000000', createdAt: $case === 'late_creation' ? $asOf->addSecond() : $open,
            updatedAt: in_array($case, ['late_creation', 'late_restatement'], true) ? $asOf->addSecond() : $open,
            isClosed: $case !== 'not_closed');
        $after = $this->service->analyzeTimeframes(lookbackBars: 100);
        self::assertSame($before['intervals'], $after['intervals']);
        self::assertSame(2, $after['intervals']['15']['sample_bars']);
    }

    public static function unavailableCandleCases(): array
    {
        return array_map(fn (string $case): array => [$case], ['future_close', 'future_open', 'late_creation', 'late_restatement', 'not_closed']);
    }

    #[DataProvider('sourceIntervals')]
    public function test_a_candle_closing_exactly_at_as_of_is_eligible(string $interval): void
    {
        $asOf = CarbonImmutable::parse('2026-10-05T01:00:00Z');
        $seconds = Interval::parse($interval)->seconds();
        $this->candle($asOf->subSeconds($seconds * 2), interval: $interval);
        $this->candle($asOf->subSeconds($seconds), interval: $interval, updatedAt: $asOf);
        $analysis = $this->service->analyzeTimeframes(asOf: $asOf);
        self::assertSame(2, $analysis['intervals'][$interval]['sample_bars']);
        self::assertSame('ACTIVE_RESEARCH', $analysis['intervals'][$interval]['status']);
    }

    public static function sourceIntervals(): array
    {
        return array_map(fn (string $interval): array => [$interval], AdaptiveTimeframeResearchService::SUPPORTED_INTERVALS);
    }

    #[DataProvider('invalidLookbackSizes')]
    public function test_lookback_is_bounded_before_reading_candles(int $lookback): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->analyzeTimeframes(lookbackBars: $lookback);
    }

    public static function invalidLookbackSizes(): array
    {
        return [[-1], [0], [1], [1001], [PHP_INT_MAX]];
    }

    private function candle(DateTimeImmutable $open, string $exchange = 'bybit', string $category = 'linear', string $symbol = 'BTCUSDT', string $interval = '15', string $turnover = '100', ?DateTimeImmutable $createdAt = null, ?DateTimeImmutable $updatedAt = null, bool $isClosed = true): void
    {
        CandleRecord::query()->create([
            'exchange' => $exchange, 'category' => $category, 'symbol' => $symbol, 'interval' => $interval,
            'open_time' => $open, 'open' => '100', 'high' => '110', 'low' => '90', 'close' => '100',
            'volume' => '1', 'turnover' => $turnover, 'is_closed' => $isClosed,
            'created_at' => $createdAt ?? $open, 'updated_at' => $updatedAt ?? $open,
        ]);
    }
}
