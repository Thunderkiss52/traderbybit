<?php

declare(strict_types=1);

namespace Tests\Feature\StrategyBuilder;

use App\StrategyBuilder\DefinitionValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdvancedDefinitionTest extends TestCase
{
    public function test_advanced_features_and_typed_optimization_ranges_are_validated(): void
    {
        $d = DefinitionTest::example();
        $d['indicators']['strength'] = ['type' => 'ADX', 'period' => 14, 'timeframe' => '60'];
        $d['regime'] = ['op' => 'gte', 'left' => 'strength', 'right' => 20];
        $d['exit'] = ['BUY' => ['op' => 'crosses_below', 'left' => 'fast', 'right' => 'slow']];
        $d['partial_exit'] = ['atr' => 1.5, 'fraction' => 0.5];
        $d['funding'] = ['enabled' => true, 'interval_hours' => 8];
        $d['parameters'] = ['indicators.fast.period' => ['default' => 20, 'min' => 10, 'max' => 30, 'step' => 5]];
        self::assertSame($d, app(DefinitionValidator::class)->validate($d));
        $d['parameters']['indicators.fast.period']['max'] = 501;
        $this->expectException(ValidationException::class);
        app(DefinitionValidator::class)->validate($d);
    }
}
