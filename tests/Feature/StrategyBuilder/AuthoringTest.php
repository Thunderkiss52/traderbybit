<?php

declare(strict_types=1);

namespace Tests\Feature\StrategyBuilder;

use App\StrategyBuilder\AuthoringParser;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AuthoringTest extends TestCase
{
    public function test_russian_and_english_produce_identical_definitions_without_llm(): void
    {
        $en = 'Create a strategy for BTCUSDT. Enter long when EMA 20 crosses above EMA 50 and RSI below 65. Stop Loss 1.5 ATR. Take Profit 3 ATR. Risk 0.5%. Timeframe 15m.';
        $ru = 'Создай стратегию для BTCUSDT. Войти в лонг когда EMA 20 пересекает снизу вверх EMA 50 и RSI ниже 65. Стоп-лосс 1.5 ATR. Тейк-профит 3 ATR. Риск 0.5%. Таймфрейм 15m.';
        self::assertSame(app(AuthoringParser::class)->parse($en), app(AuthoringParser::class)->parse($ru));
        self::assertTrue(app(AuthoringParser::class)->parse($en)['confirmation_required']);
    }

    public function test_ambiguous_description_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(AuthoringParser::class)->parse('Buy when the trend looks bullish and optimize everything');
    }
}
