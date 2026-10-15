<?php

declare(strict_types=1);

namespace App\Backtest\Application;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PerformanceMetricCalculator
{
    public function calculate(array $trades, array $snapshots, array $config): array
    {
        $closed = array_values(array_filter($trades, fn (array $trade): bool => isset($trade['closed_at'])));
        $wins = array_values(array_filter($closed, fn (array $trade): bool => $this->d($trade['net_pnl'])->isPositive()));
        $losses = array_values(array_filter($closed, fn (array $trade): bool => $this->d($trade['net_pnl'])->isNegative()));
        $grossProfit = $this->sum($wins, 'net_pnl');
        $grossLoss = $this->sum($losses, 'net_pnl')->abs();
        $net = $this->sum($closed, 'net_pnl');
        $fees = $this->sum($closed, 'fees');
        $funding = $this->sum($closed, 'funding');
        $averageR = $this->average(array_values(array_filter(array_map(fn (array $t): ?string => $t['r_multiple'] ?? null, $closed), fn (?string $v): bool => $v !== null)));
        $returns = $this->returns($snapshots);
        [$sharpe, $sortino] = $this->riskAdjusted($returns, (int) $config['periods_per_year']);
        $holding = array_map(fn (array $t): string => (string) ($t['holding_seconds'] ?? 0), $closed);

        return [
            'total_trades' => count($closed),
            'winning_trades' => count($wins),
            'losing_trades' => count($losses),
            'win_rate' => $this->ratio(count($wins), count($closed)),
            'loss_rate' => $this->ratio(count($losses), count($closed)),
            'net_pnl' => (string) $net,
            'gross_profit' => (string) $grossProfit,
            'gross_loss' => (string) $grossLoss,
            'profit_factor' => $grossLoss->isZero() ? null : (string) $grossProfit->dividedBy($grossLoss, 12, RoundingMode::HalfEven),
            'profit_factor_reason' => $grossLoss->isZero() ? 'no_negative_net_pnl' : null,
            'average_r' => $averageR,
            'expectancy' => count($closed) === 0 ? null : (string) $net->dividedBy(count($closed), 12, RoundingMode::HalfEven),
            'maximum_drawdown_percent' => $this->maximumDrawdown($snapshots),
            'sharpe_ratio' => $sharpe,
            'sortino_ratio' => $sortino,
            'returns_interval_minutes' => (int) $config['interval_minutes'],
            'risk_free_rate' => (string) $config['risk_free_rate'],
            'exposure_percent' => $this->ratio(count(array_filter($snapshots, fn (array $s): bool => (bool) ($s['exposed'] ?? false))), count($snapshots)),
            'average_holding_seconds' => $this->average($holding),
            'maximum_consecutive_losses' => $this->maximumConsecutive($closed, false),
            'maximum_consecutive_wins' => $this->maximumConsecutive($closed, true),
            'fees' => (string) $fees,
            'funding' => (string) $funding,
            'ambiguous_trades' => count(array_filter($closed, fn (array $t): bool => (bool) ($t['ambiguous_execution'] ?? false))),
            'incomplete_funding_trades' => count(array_filter($closed, fn (array $t): bool => ! (bool) ($t['funding_complete'] ?? true))),
            'breakdowns' => [
                'symbol' => $this->breakdown($closed, 'symbol'),
                'side' => $this->breakdown($closed, 'side'),
                'quality_class' => $this->breakdown($closed, 'quality_class'),
                'strategy_version' => $this->breakdown($closed, 'strategy_version'),
            ],
        ];
    }

    private function breakdown(array $trades, string $key): array
    {
        $groups = [];
        foreach ($trades as $trade) {
            $groups[(string) ($trade[$key] ?? 'UNKNOWN')][] = $trade;
        }
        ksort($groups);
        foreach ($groups as $name => $items) {
            $net = $this->sum($items, 'net_pnl');
            $groups[$name] = ['trades' => count($items), 'net_pnl' => (string) $net, 'win_rate' => $this->ratio(count(array_filter($items, fn (array $t): bool => $this->d($t['net_pnl'])->isPositive())), count($items))];
        }

        return $groups;
    }

    private function returns(array $snapshots): array
    {
        $returns = [];
        for ($i = 1; $i < count($snapshots); $i++) {
            $previous = $this->d($snapshots[$i - 1]['equity']);
            if (! $previous->isZero()) {
                $returns[] = (string) $this->d($snapshots[$i]['equity'])->minus($previous)->dividedBy($previous, 24, RoundingMode::HalfEven);
            }
        }

        return $returns;
    }

    private function riskAdjusted(array $returns, int $periods): array
    {
        if (count($returns) < 2) {
            return [null, null];
        }
        $mean = $this->d((string) $this->average($returns));
        $variance = BigDecimal::zero();
        $downside = BigDecimal::zero();
        foreach ($returns as $return) {
            $delta = $this->d($return)->minus($mean);
            $variance = $variance->plus($delta->multipliedBy($delta));
            if ($this->d($return)->isNegative()) {
                $downside = $downside->plus($this->d($return)->multipliedBy($return));
            }
        }
        $standard = $variance->dividedBy(count($returns) - 1, 24, RoundingMode::HalfEven)->sqrt(24, RoundingMode::HalfEven);
        $downsideDeviation = $downside->dividedBy(count($returns), 24, RoundingMode::HalfEven)->sqrt(24, RoundingMode::HalfEven);
        $annualizer = BigDecimal::of((string) $periods)->sqrt(24, RoundingMode::HalfEven);
        $sharpe = $standard->isZero() ? null : (string) $mean->dividedBy($standard, 12, RoundingMode::HalfEven)->multipliedBy($annualizer);
        $sortino = $downsideDeviation->isZero() ? null : (string) $mean->dividedBy($downsideDeviation, 12, RoundingMode::HalfEven)->multipliedBy($annualizer);

        return [$sharpe, $sortino];
    }

    private function maximumDrawdown(array $snapshots): string
    {
        $peak = BigDecimal::zero();
        $maximum = BigDecimal::zero();
        foreach ($snapshots as $snapshot) {
            $equity = $this->d($snapshot['equity']);
            if ($equity->isGreaterThan($peak)) {
                $peak = $equity;
            }
            if (! $peak->isZero()) {
                $drawdown = $peak->minus($equity)->dividedBy($peak, 24, RoundingMode::HalfEven)->multipliedBy(100);
                if ($drawdown->isGreaterThan($maximum)) {
                    $maximum = $drawdown;
                }
            }
        }

        return (string) $maximum;
    }

    private function maximumConsecutive(array $trades, bool $wins): int
    {
        $maximum = $current = 0;
        foreach ($trades as $trade) {
            $match = $wins ? $this->d($trade['net_pnl'])->isPositive() : $this->d($trade['net_pnl'])->isNegative();
            $current = $match ? $current + 1 : 0;
            $maximum = max($maximum, $current);
        }

        return $maximum;
    }

    private function sum(array $items, string $key): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($items as $item) {
            $sum = $sum->plus((string) ($item[$key] ?? '0'));
        }

        return $sum;
    }

    private function average(array $values): ?string
    {
        if ($values === []) {
            return null;
        }
        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            $sum = $sum->plus((string) $value);
        }

        return (string) $sum->dividedBy(count($values), 12, RoundingMode::HalfEven);
    }

    private function ratio(int $part, int $total): ?string
    {
        return $total === 0 ? null : (string) BigDecimal::of($part)->multipliedBy(100)->dividedBy($total, 8, RoundingMode::HalfEven);
    }

    private function d(string|int $value): BigDecimal
    {
        return BigDecimal::of((string) $value);
    }
}
