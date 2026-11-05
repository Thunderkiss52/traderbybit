<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\HistoricalData\SourcePolicy;
use App\Indicators\Application\IndicatorEngine;
use App\Indicators\Exceptions\InvalidSeriesException;
use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Domain\Candle;
use App\MarketData\Domain\Interval;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Strategy\Contracts\MarketContextRepository;
use App\Strategy\Domain\BarState;
use App\Strategy\Domain\StrategyInput;
use App\Strategy\Domain\StrategyInputIntegrityException;
use App\Strategy\Domain\TimeframeState;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

final readonly class StrategyInputFactory
{
    public function __construct(
        private IndicatorEngine $indicators,
        private MarketContextRepository $contexts,
        private array $config,
        private MarketCandleConflictService $integrity,
    ) {}

    public static function peerSymbol(string $symbol): ?string
    {
        return match ($symbol) {
            'BTCUSDT' => 'ETHUSDT',
            'ETHUSDT' => 'BTCUSDT',
            default => null,
        };
    }

    public function latestDecisionTime(string $exchange, string $category, string $symbol): ?DateTimeImmutable
    {
        $record = CandleRecord::query()->where(compact('exchange', 'category', 'symbol'))->where('interval', (string) $this->config['decision_timeframe'])->where('is_closed', true)->latest('open_time')->first();

        return $record === null ? null : $record->open_time->toDateTimeImmutable()->modify('+15 minutes');
    }

    public function make(string $exchange, string $category, string $symbol, DateTimeImmutable $at, ?string $peerSymbol = null, string $intendedUse = 'FORWARD_PAPER'): StrategyInput
    {
        foreach (array_filter([$symbol, $peerSymbol], static fn ($value): bool => $value !== null) as $inputSymbol) {
            if (! in_array($exchange, (array) config('exchanges.enabled', []), true) || $category !== 'linear'
                || ! preg_match('/^[A-Z0-9]{4,32}$/D', $inputSymbol) || ! in_array($inputSymbol, (array) config('market_data.symbols', []), true)) {
                throw new StrategyInputIntegrityException('CANDLE_SCOPE_UNSUPPORTED', 'UNAVAILABLE', compact('exchange', 'category', 'symbol'));
            }
        }
        foreach (array_filter([$symbol, $peerSymbol]) as $inputSymbol) {
            try {
                app(SourcePolicy::class)->assertMarketUse($exchange, $inputSymbol, $at->modify('-'.((int) $this->config['history_limit'] * 3600).' seconds'), $at, $intendedUse);
            } catch (ValidationException) {
                throw new StrategyInputIntegrityException('SOURCE_LICENSE_USE_BLOCKED', 'UNAVAILABLE', compact('exchange', 'symbol', 'intendedUse'));
            }
        }
        $states = [];
        $errors = [];
        foreach ($this->config['timeframes'] as $role => $interval) {
            try {
                $states[$role] = $this->state($exchange, $category, $symbol, Interval::parse((string) $interval), $at);
                $latest = $states[$role]->latest();
                if ($latest === null) {
                    $errors[] = "missing_{$role}_history";

                    continue;
                }
                $lag = $at->getTimestamp() - $latest->closeTime->getTimestamp();
                if ($lag < 0) {
                    $errors[] = "future_{$role}_candle_rejected";
                } elseif ($lag > (int) $this->config['freshness']['max_lag_seconds'][(string) $interval]) {
                    $errors[] = "stale_{$role}_data";
                }
            } catch (InvalidSeriesException) {
                $states[$role] = new TimeframeState((string) $interval, [], false);
                $errors[] = "non_contiguous_{$role}_history";
            } catch (\PDOException) {
                throw new StrategyInputIntegrityException('CANDLE_STORAGE_UNAVAILABLE', 'UNAVAILABLE', compact('exchange', 'category', 'symbol', 'interval'));
            }
        }

        $peer = null;
        if ($peerSymbol !== null) {
            try {
                $peer = $this->state($exchange, $category, $peerSymbol, Interval::parse((string) $this->config['timeframes']['entry']), $at);
            } catch (InvalidSeriesException) {
                $peer = new TimeframeState((string) $this->config['timeframes']['entry'], [], false);
            } catch (\PDOException) {
                throw new StrategyInputIntegrityException('CANDLE_STORAGE_UNAVAILABLE', 'UNAVAILABLE', ['exchange' => $exchange, 'category' => $category, 'symbol' => $peerSymbol]);
            }
        }

        $this->assertIntegrity($exchange, $category, $symbol, $at, $states, $peerSymbol, $peer);
        try {
            $context = $this->contexts->latestAt($exchange, $category, $symbol, $at);
        } catch (\PDOException) {
            throw new StrategyInputIntegrityException('CANDLE_CONTEXT_STORAGE_UNAVAILABLE', 'UNAVAILABLE', compact('exchange', 'category', 'symbol'));
        }
        $input = new StrategyInput(
            exchange: $exchange, category: $category, symbol: $symbol, decisionTime: $at,
            trend: $states['trend'], setup: $states['setup'], entry: $states['entry'], confirmation: $states['confirmation'],
            marketContext: $context,
            validationErrors: array_values(array_unique($errors)),
            peerEntry: $peer,
        );
        // Recheck shared storage after all providers/computation, immediately before handing input to a caller.
        $this->assertIntegrity($exchange, $category, $symbol, $at, $states, $peerSymbol, $peer);

        return $input;
    }

    private function assertIntegrity(string $exchange, string $category, string $symbol, DateTimeImmutable $at, array $states, ?string $peerSymbol, ?TimeframeState $peer): void
    {
        foreach ([[$symbol, $states], ...($peer === null ? [] : [[$peerSymbol, [$peer]]])] as [$inputSymbol, $inputStates]) {
            foreach ($inputStates as $state) {
                $seconds = Interval::parse($state->interval)->seconds();
                $start = $state->bars[0]->openTime ?? $at->modify('-'.((int) $this->config['history_limit'] * $seconds).' seconds');
                $end = $state->latest()?->openTime ?? $at->modify('-'.$seconds.' seconds');
                $report = $this->integrity->integrityReport($exchange, $inputSymbol, $state->interval, $start, $end);
                if ($report['status'] !== 'VALID') {
                    throw new StrategyInputIntegrityException($report['reason_code'] ?? 'CANDLE_INTEGRITY_UNAVAILABLE', $report['status'], [
                        'exchange' => $exchange, 'category' => $category, 'symbol' => $inputSymbol, 'interval' => $state->interval,
                        'window_start' => $start->format(DATE_ATOM), 'window_end' => $end->format(DATE_ATOM),
                    ]);
                }
            }
        }
    }

    private function state(string $exchange, string $category, string $symbol, Interval $interval, DateTimeImmutable $at): TimeframeState
    {
        $latestOpen = $at->modify('-'.intdiv($interval->milliseconds(), 1000).' seconds');
        $records = CandleRecord::query()
            ->where(compact('exchange', 'category', 'symbol'))
            ->where('interval', $interval->value)
            ->where('is_closed', true)
            ->where('open_time', '<=', $latestOpen)
            ->latest('open_time')->limit((int) $this->config['history_limit'])->get()->reverse()->values();

        $candles = $records->map(fn (CandleRecord $record): Candle => new Candle(
            exchange: $record->exchange, category: $record->category, symbol: $record->symbol, interval: $interval,
            openTimeMs: $this->milliseconds($record->open_time), open: $record->open, high: $record->high, low: $record->low,
            close: $record->close, volume: $record->volume, turnover: $record->turnover, isClosed: (bool) $record->is_closed,
        ))->all();
        $series = $this->indicators->calculate($candles);
        $bars = [];
        foreach ($candles as $index => $candle) {
            $level = $series->supportResistance[$index] ?? null;
            $bars[] = new BarState(
                openTime: $candle->openTime,
                closeTime: $candle->openTime->modify('+'.intdiv($interval->milliseconds(), 1000).' seconds'),
                open: $candle->open, high: $candle->high, low: $candle->low, close: $candle->close, turnover: $candle->turnover,
                emaEntry: $series->emaEntry[$index], emaFast: $series->emaFast[$index], emaSlow: $series->emaSlow[$index], rsi: $series->rsi[$index],
                macd: $series->macd->macd[$index], macdSignal: $series->macd->signal[$index], macdHistogram: $series->macd->histogram[$index],
                atr: $series->atr[$index], adx: $series->adx->adx[$index], plusDi: $series->adx->plusDi[$index], minusDi: $series->adx->minusDi[$index],
                relativeVolume: $series->volume->relativeVolume[$index], averageTurnover: $series->volume->turnoverSma[$index],
                support: $level?->support, resistance: $level?->resistance,
                bollingerUpper: $series->bollingerBands->upper[$index], bollingerMiddle: $series->bollingerBands->middle[$index], bollingerLower: $series->bollingerBands->lower[$index],
                supportStrength: $level?->supportStrength ?? 0, resistanceStrength: $level?->resistanceStrength ?? 0,
            );
        }

        return new TimeframeState($interval->value, $bars, $series->isReadyForSignals());
    }

    private function milliseconds(DateTimeInterface $date): int
    {
        return ((int) $date->format('U')) * 1000 + intdiv((int) $date->format('u'), 1000);
    }
}
