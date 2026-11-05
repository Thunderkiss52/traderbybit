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

    public function test_explicit_higher_timeframe_and_short_rules_parse_without_invented_confirmations(): void
    {
        $en = 'Create a BTCUSDT strategy. Enter short when EMA 20 crosses below EMA 50 and RSI is above 35. Confirm EMA 50 below EMA 200 on 1h. Stop Loss is 1.5 ATR, Take Profit is 3 ATR, and maximum risk per trade is 0.5%. Timeframe 15m.';
        $ru = 'Создай стратегию для BTCUSDT. Войти в шорт когда EMA 20 пересекает сверху вниз EMA 50 и RSI выше 35. Подтверди EMA 50 ниже EMA 200 на 1h. Стоп-лосс 1,5 ATR. Тейк-профит 3 ATR. Риск 0,5%. Таймфрейм 15m.';
        $parsed = app(AuthoringParser::class)->parse($en)['definition'];
        self::assertSame($parsed, app(AuthoringParser::class)->parse($ru)['definition']);
        self::assertSame('60', $parsed['indicators']['confirm_slow']['timeframe']);
        self::assertSame('lt', $parsed['entry']['SELL']['and'][2]['op']);
        $this->expectException(ValidationException::class);
        app(AuthoringParser::class)->parse(str_replace('Confirm EMA 50 below EMA 200 on 1h.', 'Confirm the bearish trend using 1h.', $en));
    }
}
