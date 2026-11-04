<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use Illuminate\Validation\ValidationException;

final readonly class AuthoringParser
{
    public function __construct(private DefinitionValidator $validator) {}

    public function parse(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['создай стратегию для ' => 'create a strategy for ', 'войти в лонг когда ' => 'enter long when ', 'пересекает снизу вверх' => 'crosses above', ' и ' => ' and ', 'ниже' => 'below', 'стоп-лосс' => 'stop loss', 'тейк-профит' => 'take profit', 'риск' => 'risk', 'таймфрейм' => 'timeframe', ',' => '.']);
        $pattern = '/^create a strategy for ([a-z0-9]{3,24})[.]? enter long when ema (\\d+) crosses above ema (\\d+) and rsi below (\\d+(?:\\.\\d+)?)[.]? stop loss (\\d+(?:\\.\\d+)?) atr[.]? take profit (\\d+(?:\\.\\d+)?) atr[.]? risk (\\d+(?:\\.\\d+)?)%[.]? timeframe (5|15|30|60)m[.]?$/D';
        if (! preg_match($pattern, preg_replace('/\\s+/u', ' ', $text), $m)) {
            throw ValidationException::withMessages(['description' => 'Unsupported or ambiguous description. Use the documented EMA/RSI grammar; no rules were invented.']);
        }
        $d = ['schema_version' => 1, 'id' => 'user_ema_rsi', 'version' => 1, 'symbols' => [strtoupper($m[1])], 'exchanges' => ['bybit'], 'timeframe' => $m[8],
            'indicators' => ['fast' => ['type' => 'EMA', 'period' => (int) $m[2], 'timeframe' => $m[8]], 'slow' => ['type' => 'EMA', 'period' => (int) $m[3], 'timeframe' => $m[8]], 'rsi' => ['type' => 'RSI', 'period' => 14, 'timeframe' => $m[8]]],
            'entry' => ['BUY' => ['and' => [['op' => 'crosses_above', 'left' => 'fast', 'right' => 'slow'], ['op' => 'lt', 'left' => 'rsi', 'right' => (float) $m[4]]]]],
            'stop_atr' => (float) $m[5], 'take_atr' => (float) $m[6], 'risk_percent' => (float) $m[7], 'timeout_bars' => 48, 'cooldown_bars' => 0];

        return ['definition' => $this->validator->validate($d), 'assumptions' => ['RSI period 14', 'Bybit linear research', '48-bar timeout', 'No cooldown', 'ATR period 14'], 'confirmation_required' => true];
    }
}
