<?php

declare(strict_types=1);

namespace Tests\Unit\PatternDiscovery;

use App\PatternDiscovery\Application\CandlestickPatternDetector;
use PHPUnit\Framework\TestCase;

final class CandlestickPatternDetectorTest extends TestCase
{
    private CandlestickPatternDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new CandlestickPatternDetector((require dirname(__DIR__, 3).'/config/patterns.php')['candlestick']);
    }

    public function test_detects_single_candle_geometry_patterns(): void
    {
        self::assertContains('DOJI', $this->names([['open' => '100', 'high' => '105', 'low' => '95', 'close' => '100.5']]));
        self::assertContains('HAMMER', $this->names([['open' => '100', 'high' => '102', 'low' => '90', 'close' => '101']]));
        self::assertContains('SHOOTING_STAR', $this->names([['open' => '101', 'high' => '112', 'low' => '99.5', 'close' => '100']]));
        self::assertContains('MARUBOZU', $this->names([['open' => '100', 'high' => '110', 'low' => '100', 'close' => '110']]));
    }

    public function test_detects_two_and_three_candle_patterns_only_after_confirmation_bar(): void
    {
        $engulfing = [
            ['open' => '105', 'high' => '106', 'low' => '99', 'close' => '101'],
            ['open' => '100', 'high' => '108', 'low' => '99', 'close' => '107'],
        ];
        self::assertNotContains('BULLISH_ENGULFING', $this->names(array_slice($engulfing, 0, 1)));
        self::assertContains('BULLISH_ENGULFING', $this->names($engulfing));

        $morningStar = [
            ['open' => '110', 'high' => '111', 'low' => '99', 'close' => '100'],
            ['open' => '100', 'high' => '102', 'low' => '98', 'close' => '100.5'],
            ['open' => '101', 'high' => '108', 'low' => '100', 'close' => '107'],
        ];
        self::assertNotContains('MORNING_STAR', $this->names(array_slice($morningStar, 0, 2)));
        self::assertContains('MORNING_STAR', $this->names($morningStar));
    }

    private function names(array $bars): array
    {
        return array_column($this->detector->detect($bars), 'name');
    }
}
