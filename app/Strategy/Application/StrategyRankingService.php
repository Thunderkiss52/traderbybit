<?php

declare(strict_types=1);

namespace App\Strategy\Application;

final readonly class StrategyRankingService
{
    public function __construct(private array $rules) {}

    public function rank(?array $metrics): array
    {
        if ($metrics === null) {
            return ['status' => 'NOT_EVALUATED', 'score' => null, 'warnings' => ['no_completed_out_of_sample_experiment']];
        }
        $trades = (int) ($metrics['total_trades'] ?? 0);
        $pf = $metrics['profit_factor'] ?? null;
        $dd = $metrics['maximum_drawdown_percent'] ?? null;
        $expectancy = $metrics['expectancy'] ?? null;
        $warnings = [];
        if ($trades < (int) $this->rules['minimum_trades']) {
            $warnings[] = 'insufficient_trade_sample';
        } if ($pf === null) {
            $warnings[] = 'profit_factor_unavailable';
        }
        $passes = $trades >= (int) $this->rules['minimum_trades'] && $pf !== null && (float) $pf >= (float) $this->rules['profit_factor_min'] && $dd !== null && (float) $dd <= (float) $this->rules['max_drawdown_percent'] && $expectancy !== null && (float) $expectancy > 0;
        if ($warnings !== []) {
            return ['status' => 'INCONCLUSIVE', 'score' => null, 'warnings' => $warnings];
        }

        return ['status' => $passes ? 'CANDIDATE' : 'REJECTED', 'score' => $passes ? (string) ((float) $pf / (1 + max(0, (float) $dd))) : null, 'warnings' => []];
    }
}
