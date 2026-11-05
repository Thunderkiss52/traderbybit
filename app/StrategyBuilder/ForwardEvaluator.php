<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use DateTimeImmutable;

final readonly class ForwardEvaluator
{
    public function __construct(private GoEngine $engine, private MarketCandleConflictService $integrity) {}

    public function evaluate(array $definition, string $exchange, string $symbol, DateTimeImmutable $at, ?string $engineChecksum): array
    {
        $window = EvaluationProfile::window($definition);
        $series = [];
        $reference = null;
        foreach (['5', '15', '30', '60'] as $tf) {
            $seconds = (int) $tf * 60;
            $start = (new DateTimeImmutable('@'.(intdiv($at->getTimestamp(), $seconds) * $seconds)))->modify('-'.(($window + 2) * $seconds).' seconds');
            $rows = CandleRecord::query()->where(['exchange' => $exchange, 'category' => 'linear', 'symbol' => $symbol, 'interval' => $tf, 'is_closed' => true])->where('open_time', '>=', $start)->where('open_time', '<=', $at->modify("-{$seconds} seconds"))->orderBy('open_time')->get();
            $report = $this->integrity->integrityReport($exchange, $symbol, $tf, $start, $at);
            if ($report['status'] !== 'VALID') {
                throw new \RuntimeException('Candle integrity unavailable: '.($report['reason_code'] ?? $report['status']));
            }
            $series[$tf] = $rows->map(fn ($c) => ['exchange' => $c->exchange, 'category' => $c->category, 'symbol' => $c->symbol, 'interval' => (string) $c->interval, 'open_time' => $c->open_time->toIso8601String(), 'open' => $c->open, 'high' => $c->high, 'low' => $c->low, 'close' => $c->close, 'volume' => $c->volume, 'turnover' => $c->turnover, 'is_closed' => true])->all();
            if ($tf === $definition['timeframe']) {
                $last = $rows->last();
                if ($last === null || $last->open_time->getTimestamp() + $seconds !== $at->getTimestamp()) {
                    throw new \RuntimeException('Latest decision candle is missing or stale.');
                }
                $reference = $last->close;
            }
        }
        $input = ['definition' => $definition, 'mode' => 'signal', 'exchange' => $exchange, 'symbol' => $symbol, 'series' => $series, 'from' => $at->format(DATE_ATOM), 'as_of' => $at->format(DATE_ATOM)];
        $result = $this->engine->run($input, $engineChecksum);
        foreach (['5', '15', '30', '60'] as $tf) {
            $report = $this->integrity->integrityReport($exchange, $symbol, $tf, new DateTimeImmutable($series[$tf][0]['open_time'] ?? $at->format(DATE_ATOM)), $at);
            if ($report['status'] !== 'VALID') {
                throw new \RuntimeException('Candle integrity changed during evaluation.');
            }
        }

        return $result + ['reference_price' => $reference];
    }
}
