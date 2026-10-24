<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

final readonly class CorrelationService
{
    public function __construct(private array $config) {}

    public function between(string $left, string $right, DateTimeImmutable $at, string $exchange = 'bybit'): ?string
    {
        $window = (int) $this->config['correlation']['window'];
        $series = [];
        foreach ([$left, $right] as $symbol) {
            $rows = CandleRecord::query()->where('exchange', $exchange)->where('category', 'linear')->where('symbol', $symbol)
                ->where('interval', '60')->where('is_closed', true)->where('open_time', '<=', $at->modify('-1 hour'))
                ->latest('open_time')->limit($window + 1)->get(['open_time', 'close'])->reverse()->values();
            $returns = [];
            for ($i = 1; $i < $rows->count(); $i++) {
                $previous = BigDecimal::of($rows[$i - 1]->close);
                if (! $previous->isZero()) {
                    $returns[$rows[$i]->open_time->format('U')] = BigDecimal::of($rows[$i]->close)->minus($previous)->dividedBy($previous, 24, RoundingMode::HalfEven);
                }
            }
            $series[] = $returns;
        }
        $keys = array_values(array_intersect(array_keys($series[0]), array_keys($series[1])));
        if (count($keys) < $window) {
            return null;
        }
        $a = array_map(fn (string $key): string => (string) $series[0][$key], array_slice($keys, -$window));
        $b = array_map(fn (string $key): string => (string) $series[1][$key], array_slice($keys, -$window));

        return $this->pearson($a, $b);
    }

    public function pearson(array $a, array $b): ?string
    {
        if (count($a) !== count($b) || count($a) < 2) {
            return null;
        }
        $n = count($a);
        $sumA = BigDecimal::zero();
        $sumB = BigDecimal::zero();
        foreach ($a as $i => $value) {
            $sumA = $sumA->plus($value);
            $sumB = $sumB->plus($b[$i]);
        }
        $meanA = $sumA->dividedBy($n, 24, RoundingMode::HalfEven);
        $meanB = $sumB->dividedBy($n, 24, RoundingMode::HalfEven);
        $cov = BigDecimal::zero();
        $varianceA = BigDecimal::zero();
        $varianceB = BigDecimal::zero();
        foreach ($a as $i => $value) {
            $da = BigDecimal::of($value)->minus($meanA);
            $db = BigDecimal::of($b[$i])->minus($meanB);
            $cov = $cov->plus($da->multipliedBy($db));
            $varianceA = $varianceA->plus($da->multipliedBy($da));
            $varianceB = $varianceB->plus($db->multipliedBy($db));
        }
        if ($varianceA->isZero() || $varianceB->isZero()) {
            return null;
        }
        $denominator = $varianceA->multipliedBy($varianceB)->sqrt(24, RoundingMode::HalfEven);

        return (string) $cov->dividedBy($denominator, 12, RoundingMode::HalfEven);
    }
}
