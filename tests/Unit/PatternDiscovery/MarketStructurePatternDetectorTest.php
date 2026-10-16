<?php

declare(strict_types=1);

namespace Tests\Unit\PatternDiscovery;

use App\PatternDiscovery\Application\MarketStructurePatternDetector;
use PHPUnit\Framework\TestCase;

final class MarketStructurePatternDetectorTest extends TestCase
{
    private MarketStructurePatternDetector $detector;

    protected function setUp(): void
    {
        $config = (require dirname(__DIR__, 3).'/config/patterns.php')['market_structure'];
        $this->detector = new MarketStructurePatternDetector($config);
    }

    public function test_detects_double_top_only_when_second_pivot_is_confirmed(): void
    {
        $bars = array_fill(0, 14, $this->bar(100, 102, 99, 101));
        $bars[3] = $this->bar(103, 110, 102, 104);
        $bars[7] = $this->bar(101, 102, 97, 100);
        $bars[11] = $this->bar(103, 110.2, 102, 104);

        self::assertNotContains('DOUBLE_TOP', $this->names(array_slice($bars, 0, 13)));
        self::assertContains('DOUBLE_TOP', $this->names($bars));
    }

    public function test_detects_false_breakout_and_liquidity_sweep_without_future_bars(): void
    {
        $base = array_fill(0, 12, $this->bar(100, 101, 99, 100));
        $breakout = [...$base, $this->bar(100, 104, 100, 103)];
        self::assertNotContains('FALSE_BREAKOUT', $this->names($breakout));

        $failed = [...$breakout, $this->bar(102, 102.5, 99.5, 100)];
        self::assertContains('FALSE_BREAKOUT', $this->names($failed));

        $sweep = [...$base, $this->bar(100, 101, 99, 100), $this->bar(100, 103, 99.5, 100)];
        self::assertContains('LIQUIDITY_SWEEP', $this->names($sweep));
    }

    private function bar(float $open, float $high, float $low, float $close): array
    {
        return compact('open', 'high', 'low', 'close');
    }

    private function names(array $bars): array
    {
        return array_column($this->detector->detect($bars), 'name');
    }
}
