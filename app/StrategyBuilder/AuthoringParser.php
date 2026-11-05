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
        $text = strtr($text, ['создай стратегию для ' => 'create a strategy for ', 'войти в лонг когда ' => 'enter long when ', 'войти в шорт когда ' => 'enter short when ', 'пересекает снизу вверх' => 'crosses above', 'пересекает сверху вниз' => 'crosses below', ' и ' => ' and ', 'ниже' => 'below', 'выше' => 'above', 'стоп-лосс' => 'stop loss', 'тейк-профит' => 'take profit', 'риск' => 'risk', 'таймфрейм' => 'timeframe', 'подтверди ema ' => 'confirm ema ', ' на 1h' => ' on 1h', 'rsi is ' => 'rsi ', 'stop loss is ' => 'stop loss ', 'take profit is ' => 'take profit ', 'maximum risk per trade is ' => 'risk ', ' and risk ' => ' risk ']);
        $text = str_replace(' and risk ', ' risk ', $text);
        $text = preg_replace('/^create a ([a-z0-9]{3,24}) strategy/', 'create a strategy for $1', $text);
        $text = preg_replace('/(?<=\d),(?=\d)/', '.', $text);
        $text = preg_replace('/[,;]|\.(?=\s|$)/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', trim($text));
        $pattern = '/^create a strategy for (?<symbol>[a-z0-9]{3,24}) enter (?<direction>long|short) when ema (?<fast>\d+) crosses (?<cross>above|below) ema (?<slow>\d+) and rsi (?<comparison>below|above) (?<rsi>\d+(?:\.\d+)?) (?:confirm ema (?<hfast>\d+) (?<hcomparison>above|below) ema (?<hslow>\d+) on 1h )?stop loss (?<stop>\d+(?:\.\d+)?) atr take profit (?<take>\d+(?:\.\d+)?) atr risk (?<risk>\d+(?:\.\d+)?)% timeframe (?<tf>5|15|30|60)m$/D';
        if (! preg_match($pattern, $text, $m)) {
            throw ValidationException::withMessages(['description' => 'Unsupported or ambiguous description. Specify EMA crossover, RSI threshold, ATR stop/target, risk and primary timeframe using the documented grammar. A higher-timeframe confirmation must name both EMA periods and comparison.']);
        }
        $d = ['schema_version' => 2, 'id' => 'user_ema_rsi', 'version' => 1, 'symbols' => [strtoupper($m['symbol'])], 'exchanges' => ['bybit'], 'timeframe' => $m['tf'],
            'indicators' => ['fast' => ['type' => 'EMA', 'period' => (int) $m['fast'], 'timeframe' => $m['tf']], 'slow' => ['type' => 'EMA', 'period' => (int) $m['slow'], 'timeframe' => $m['tf']], 'rsi' => ['type' => 'RSI', 'period' => 14, 'timeframe' => $m['tf']]],
            'entry' => [$m['direction'] === 'long' ? 'BUY' : 'SELL' => ['and' => [['op' => $m['cross'] === 'above' ? 'crosses_above' : 'crosses_below', 'left' => 'fast', 'right' => 'slow'], ['op' => $m['comparison'] === 'below' ? 'lt' : 'gt', 'left' => 'rsi', 'right' => (float) $m['rsi']]]]],
            'funding' => ['enabled' => true, 'interval_hours' => 8], 'stop_atr' => (float) $m['stop'], 'take_atr' => (float) $m['take'], 'risk_percent' => (float) $m['risk'], 'timeout_bars' => 48, 'cooldown_bars' => 0];
        if (($m['hfast'] ?? '') !== '') {
            $d['indicators']['confirm_fast'] = ['type' => 'EMA', 'period' => (int) $m['hfast'], 'timeframe' => '60'];
            $d['indicators']['confirm_slow'] = ['type' => 'EMA', 'period' => (int) $m['hslow'], 'timeframe' => '60'];
            $side = array_key_first($d['entry']);
            $d['entry'][$side]['and'][] = ['op' => $m['hcomparison'] === 'above' ? 'gt' : 'lt', 'left' => 'confirm_fast', 'right' => 'confirm_slow'];
        }

        return ['definition' => $this->validator->validate($d), 'assumptions' => ['RSI period 14', 'Bybit linear research', '48-bar timeout', 'No cooldown', 'ATR period 14', 'Funding modeled on an explicit 8-hour calendar; complete rate/mark history is required'], 'confirmation_required' => true];
    }
}
