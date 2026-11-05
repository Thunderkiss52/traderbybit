<?php

declare(strict_types=1);

namespace App\Exchanges\Console;

use App\Exchanges\Application\ExchangeStrategyCompatibilityService;
use Illuminate\Console\Command;
use Throwable;

final class ReconcileCompatibilityCommand extends Command
{
    protected $signature = 'compatibility:reconcile
        {--exchange= : Reconcile a specific exchange identifier}
        {--strategy= : Reconcile a specific strategy identifier}
        {--force : Force re-evaluation}';

    protected $description = 'Reconcile dynamic Exchange x Strategy compatibility and eligibility matrix';

    public function handle(ExchangeStrategyCompatibilityService $service): int
    {
        $this->info('Starting Exchange x Strategy compatibility reconciliation...');

        try {
            $exchange = $this->option('exchange');
            $strategy = $this->option('strategy');
            $force = (bool) $this->option('force');

            if ($exchange !== null && is_string($exchange) && trim($exchange) !== '') {
                $this->line("Evaluating exchange: {$exchange}");
                $result = $service->reconcileExchange(trim($exchange), $force);
                $this->info("Reconciled exchange '{$exchange}' across {$result['strategies_evaluated']} strategies.");
                $this->table(
                    ['Strategy', 'Version', 'Status', 'Research', 'Shadow', 'PAPER'],
                    array_map(fn ($r) => [
                        $r['strategy_id'],
                        $r['strategy_version'],
                        $r['status'],
                        $r['research_eligible'] ? 'YES' : 'NO',
                        $r['shadow_eligible'] ? 'YES' : 'NO',
                        $r['paper_eligible'] ? 'YES' : 'NO',
                    ], $result['results'])
                );

                return self::SUCCESS;
            }

            if ($strategy !== null && is_string($strategy) && trim($strategy) !== '') {
                $this->line("Evaluating strategy: {$strategy}");
                $result = $service->reconcileStrategy(trim($strategy), $force);
                $this->info("Reconciled strategy '{$strategy}' across {$result['exchanges_evaluated']} exchanges.");
                $this->table(
                    ['Exchange', 'Status', 'Research', 'Shadow', 'PAPER'],
                    array_map(fn ($r) => [
                        $r['exchange_id'],
                        $r['status'],
                        $r['research_eligible'] ? 'YES' : 'NO',
                        $r['shadow_eligible'] ? 'YES' : 'NO',
                        $r['paper_eligible'] ? 'YES' : 'NO',
                    ], $result['results'])
                );

                return self::SUCCESS;
            }

            $summary = $service->reconcileAll($force);
            $this->info("Compatibility reconciliation completed: {$summary['exchanges_evaluated']} exchanges x {$summary['strategies_evaluated']} strategies ({$summary['evaluations_count']} evaluations).");

            $statusRows = [];
            foreach ($summary['statuses'] as $status => $count) {
                $statusRows[] = [$status, $count];
            }
            $this->table(['Status', 'Count'], $statusRows);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Compatibility reconciliation failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
