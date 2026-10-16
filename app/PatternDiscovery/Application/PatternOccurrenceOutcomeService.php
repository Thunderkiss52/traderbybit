<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PatternDiscovery\Infrastructure\Persistence\CandlestickPatternOccurrenceRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

final readonly class PatternOccurrenceOutcomeService
{
    public function __construct(private array $config) {}

    public function resolve(string $symbol, DateTimeImmutable $through): int
    {
        $resolved = 0;
        $horizon = max(1, (int) $this->config['horizon_bars']);
        $threshold = BigDecimal::of((string) $this->config['minimum_directional_return']);
        CandlestickPatternOccurrenceRecord::query()->where('symbol', $symbol)->whereNull('outcome')
            ->oldest('detected_at')->limit(200)->each(function (CandlestickPatternOccurrenceRecord $occurrence) use ($through, $horizon, $threshold, &$resolved): void {
                $minutes = (int) $occurrence->timeframe;
                $outcomeAt = $occurrence->detected_at->addMinutes($minutes * $horizon)->toDateTimeImmutable();
                if ($outcomeAt > $through) {
                    return;
                }
                $bars = CandleRecord::query()->where([
                    'exchange' => $occurrence->exchange, 'category' => $occurrence->category, 'symbol' => $occurrence->symbol,
                    'interval' => $occurrence->timeframe, 'is_closed' => true,
                ])->where('open_time', '>=', $occurrence->detected_at)->oldest('open_time')->limit($horizon)
                    ->get(['open_time', 'high', 'low', 'close']);
                if ($bars->count() !== $horizon) {
                    return;
                }
                $expected = $occurrence->detected_at;
                foreach ($bars as $bar) {
                    if ($bar->open_time->getTimestamp() !== $expected->getTimestamp()) {
                        return;
                    }
                    $expected = $expected->modify("+{$minutes} minutes");
                }
                $evidenceBars = $occurrence->evidence['bars'] ?? [];
                $entryValue = is_array($evidenceBars) && isset($evidenceBars[array_key_last($evidenceBars)]['close'])
                    ? $evidenceBars[array_key_last($evidenceBars)]['close'] : null;
                if (! is_numeric($entryValue) || BigDecimal::of((string) $entryValue)->isLessThanOrEqualTo(BigDecimal::zero())) {
                    return;
                }
                $entry = BigDecimal::of((string) $entryValue);
                $exit = BigDecimal::of($bars->last()->close);
                $return = $exit->minus($entry)->dividedBy($entry, 14, RoundingMode::HalfEven);
                $mfe = BigDecimal::zero();
                $mae = BigDecimal::zero();
                foreach ($bars as $bar) {
                    $up = BigDecimal::of($bar->high)->minus($entry)->dividedBy($entry, 14, RoundingMode::HalfEven);
                    $down = BigDecimal::of($bar->low)->minus($entry)->dividedBy($entry, 14, RoundingMode::HalfEven);
                    if ($up->isGreaterThan($mfe)) {
                        $mfe = $up;
                    }
                    if ($down->isLessThan($mae)) {
                        $mae = $down;
                    }
                }
                $outcome = $this->outcome($occurrence->direction, $return, $threshold);
                $occurrence->update(['outcome' => $outcome, 'outcome_at' => $outcomeAt,
                    'forward_return' => (string) $return->toScale(10, RoundingMode::HalfEven),
                    'mfe_return' => (string) $mfe->toScale(10, RoundingMode::HalfEven),
                    'mae_return' => (string) $mae->toScale(10, RoundingMode::HalfEven)]);
                $resolved++;
            });

        return $resolved;
    }

    private function outcome(string $direction, BigDecimal $return, BigDecimal $threshold): string
    {
        if ($direction === 'BULLISH') {
            return $return->isGreaterThanOrEqualTo($threshold) ? 'SUCCESS'
                : ($return->isLessThanOrEqualTo($threshold->negated()) ? 'FAILURE' : 'UNRESOLVED');
        }
        if ($direction === 'BEARISH') {
            return $return->isLessThanOrEqualTo($threshold->negated()) ? 'SUCCESS'
                : ($return->isGreaterThanOrEqualTo($threshold) ? 'FAILURE' : 'UNRESOLVED');
        }

        return $return->isGreaterThanOrEqualTo($threshold) ? 'UP'
            : ($return->isLessThanOrEqualTo($threshold->negated()) ? 'DOWN' : 'FLAT');
    }
}
