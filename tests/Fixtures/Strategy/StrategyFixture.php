<?php

declare(strict_types=1);

namespace Tests\Fixtures\Strategy;

use App\Strategy\Domain\BarState;
use App\Strategy\Domain\MarketContext;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use App\Strategy\Domain\TimeframeState;
use DateTimeImmutable;

final class StrategyFixture
{
    public static function input(SignalSide $side = SignalSide::Buy, array $overrides = []): StrategyInput
    {
        $at = new DateTimeImmutable('2026-10-13T10:15:00Z');
        $buy = $side !== SignalSide::Sell;
        $hour = self::bar('2026-10-13T09:00:00Z', 60, $buy ? [
            'close' => '110', 'emaFast' => '105', 'emaSlow' => '100', 'adx' => '35', 'plusDi' => '30', 'minusDi' => '12',
        ] : [
            'close' => '90', 'emaFast' => '95', 'emaSlow' => '100', 'adx' => '35', 'plusDi' => '12', 'minusDi' => '30',
        ]);
        $entryPrevious = self::bar('2026-10-13T09:45:00Z', 15, $buy ? [
            'close' => '100', 'low' => '99.7', 'high' => '101', 'emaEntry' => '100', 'rsi' => '49', 'macd' => '-0.1', 'macdSignal' => '0', 'macdHistogram' => '-0.1', 'resistance' => '102',
        ] : [
            'close' => '100', 'low' => '99', 'high' => '100.3', 'emaEntry' => '100', 'rsi' => '51', 'macd' => '0.1', 'macdSignal' => '0', 'macdHistogram' => '0.1', 'support' => '98',
        ]);
        $entryNow = self::bar('2026-10-13T10:00:00Z', 15, $buy ? [
            'open' => '102.2', 'close' => '103.2', 'low' => '102', 'high' => '104', 'emaEntry' => '101', 'rsi' => '60', 'macd' => '0.2', 'macdSignal' => '0', 'macdHistogram' => '0.2', 'relativeVolume' => '1.8', 'support' => '101.8', 'resistance' => null,
        ] : [
            'open' => '97.8', 'close' => '96.8', 'low' => '96', 'high' => '98', 'emaEntry' => '99', 'rsi' => '40', 'macd' => '-0.2', 'macdSignal' => '0', 'macdHistogram' => '-0.2', 'relativeVolume' => '1.8', 'support' => null, 'resistance' => '98.2',
        ]);
        $confirmPrevious = self::bar('2026-10-13T10:05:00Z', 5, $buy ? ['macdHistogram' => '0.1'] : ['macdHistogram' => '-0.1']);
        $confirmNow = self::bar('2026-10-13T10:10:00Z', 5, $buy ? [
            'close' => '103.2', 'emaEntry' => '102', 'rsi' => '55', 'macdHistogram' => '0.2',
        ] : [
            'close' => '96.8', 'emaEntry' => '98', 'rsi' => '45', 'macdHistogram' => '-0.2',
        ]);
        $context = new MarketContext($at, $buy ? '103.1' : '96.75', $buy ? '103.2' : '96.8', '100000000', '0.1', '0.0001', '1000000', '0.01');

        return new StrategyInput(
            exchange: 'bybit', category: 'linear', symbol: 'BTCUSDT', decisionTime: $at,
            trend: $overrides['trend'] ?? new TimeframeState('60', [$hour], true),
            entry: $overrides['entry'] ?? new TimeframeState('15', [$entryPrevious, $entryNow], true),
            confirmation: $overrides['confirmation'] ?? new TimeframeState('5', [$confirmPrevious, $confirmNow], true),
            marketContext: array_key_exists('marketContext', $overrides) ? $overrides['marketContext'] : $context,
            validationErrors: $overrides['validationErrors'] ?? [],
        );
    }

    public static function bar(string $openTime, int $minutes, array $values = []): BarState
    {
        $v = array_replace([
            'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'turnover' => '200000',
            'emaEntry' => '100', 'emaFast' => '101', 'emaSlow' => '99', 'rsi' => '55', 'macd' => '0.1', 'macdSignal' => '0',
            'macdHistogram' => '0.1', 'atr' => '1', 'adx' => '25', 'plusDi' => '25', 'minusDi' => '15',
            'relativeVolume' => '1.2', 'averageTurnover' => '200000', 'support' => null, 'resistance' => null,
        ], $values);
        $open = new DateTimeImmutable($openTime);

        return new BarState($open, $open->modify("+{$minutes} minutes"), $v['open'], $v['high'], $v['low'], $v['close'], $v['turnover'], $v['emaEntry'], $v['emaFast'], $v['emaSlow'], $v['rsi'], $v['macd'], $v['macdSignal'], $v['macdHistogram'], $v['atr'], $v['adx'], $v['plusDi'], $v['minusDi'], $v['relativeVolume'], $v['averageTurnover'], $v['support'], $v['resistance']);
    }

    public static function config(): array
    {
        return require dirname(__DIR__, 3).'/config/strategy.php';
    }
}
