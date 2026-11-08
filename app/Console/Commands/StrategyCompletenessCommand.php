<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Strategy\Application\StrategyCompletenessService;
use Illuminate\Console\Command;

final class StrategyCompletenessCommand extends Command
{
    protected $signature = 'strategy:completeness {--json : Output matrix as raw JSON}';

    protected $description = 'Evaluate the STRATEGY x EXCHANGE x EXECUTION x RESEARCH x API x UI x ANALYTICS completeness matrix';

    public function handle(StrategyCompletenessService $service): int
    {
        $matrix = $service->buildMatrix();

        if ($this->option('json')) {
            $this->line(json_encode($matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('=== STRATEGY COMPLETENESS MATRIX ===');
        $this->line("Strategies: {$matrix['total_strategies']} | Exchanges: {$matrix['total_exchanges']} | Topology: {$matrix['provisioned_pairs']}/{$matrix['total_topology_pairs']} provisioned ({$matrix['active_pairs']} active)");
        $this->newLine();

        $rows = [];
        foreach ($matrix['strategies'] as $s) {
            $bybit = $s['exchanges']['bybit']['account_status'] ?? 'NONE';
            $binance = $s['exchanges']['binance']['account_status'] ?? 'NONE';
            $rows[] = [
                $s['strategy_id'],
                $s['version'],
                $bybit,
                $binance,
                $s['execution_eligible'] ? 'YES' : 'NO',
                $s['observations_24h'],
            ];
        }

        $this->table(['Strategy', 'Version', 'Bybit Account', 'Binance Account', 'Eligible', '24h Obs'], $rows);

        return self::SUCCESS;
    }
}
