<?php

declare(strict_types=1);

namespace Tests\Unit\Backtest;

use App\Backtest\Application\StrategySignalEvaluator;
use PHPUnit\Framework\TestCase;

final class StrategySignalEvaluatorCacheLifecycleTest extends TestCase
{
    public function test_reset_clears_a_cache_on_a_reused_evaluator(): void
    {
        $evaluator = (new \ReflectionClass(StrategySignalEvaluator::class))->newInstanceWithoutConstructor();
        $cache = new \ReflectionProperty(StrategySignalEvaluator::class, 'inputCache');
        $cache->setValue($evaluator, ['old-input' => new \stdClass]);

        $evaluator->resetInputCache();

        self::assertSame([], $cache->getValue($evaluator));
    }
}
