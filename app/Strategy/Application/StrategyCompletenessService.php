<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Exchanges\ExchangeRegistry;
use App\PaperTrading\Application\PaperExecutionEligibilityService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Throwable;

final readonly class StrategyCompletenessService
{
    public function __construct(
        private StrategyRegistry $strategies,
        private ExchangeRegistry $exchanges,
    ) {}

    /**
     * Build the exhaustive completeness matrix:
     * STRATEGY × EXCHANGE × EXECUTION × RESEARCH × API × UI × ANALYTICS
     */
    public function buildMatrix(): array
    {
        $strategyList = $this->strategies->all();
        $strategyIds = array_map(fn ($s) => $s->id(), $strategyList);
        $exchangeIds = $this->exchanges->registeredIds();
        $metadataById = collect($this->strategies->metadata())->keyBy('id');

        $accounts = PaperAccountRecord::query()
            ->where('is_primary', true)
            ->get()
            ->groupBy(fn ($a) => $a->exchange_id.'|'.$a->primary_strategy_id);

        $observations24h = StrategyObservationRecord::query()
            ->where('decision_time', '>=', now('UTC')->subDay())
            ->groupBy('strategy_id')
            ->selectRaw('strategy_id, count(*) as count')
            ->pluck('count', 'strategy_id')
            ->all();

        $eligibility = app(PaperExecutionEligibilityService::class);
        $matrix = [];
        $totalCells = count($strategyIds) * count($exchangeIds);
        $provisionedCells = 0;
        $activeCells = 0;

        foreach ($strategyList as $strategy) {
            $id = $strategy->id();
            $meta = $metadataById->get($id, []);
            $strategyRow = [
                'strategy_id' => $id,
                'version' => $strategy->version(),
                'description' => $strategy->description(),
                'configuration_hash' => $meta['configuration_hash'] ?? null,
                'timeframes' => $meta['timeframes'] ?? ['trend' => '1h', 'setup' => '30m', 'entry' => '15m', 'confirmation' => '5m'],
                'execution_eligible' => $meta['execution_eligible'] ?? true,
                'observations_24h' => (int) ($observations24h[$id] ?? 0),
                'exchanges' => [],
            ];

            foreach ($exchangeIds as $exchangeId) {
                $key = $exchangeId.'|'.$id;
                $account = $accounts->get($key)?->first();

                $isProvisioned = $account !== null;
                $isActive = $account !== null && $account->status === 'ACTIVE';
                $entryBlockedReason = $account === null ? 'account_not_provisioned' : 'account_not_active';
                if ($isActive) {
                    try {
                        $entryBlockedReason = $eligibility->accountEntryRejectionReason($account);
                    } catch (Throwable) {
                        // An unavailable catalog, market model, or risk service can never certify an entry.
                        $entryBlockedReason = 'eligibility_check_unavailable';
                    }
                }

                if ($isProvisioned) {
                    $provisionedCells++;
                }
                if ($isActive) {
                    $activeCells++;
                }

                $strategyRow['exchanges'][$exchangeId] = [
                    'exchange_id' => $exchangeId,
                    'provisioned' => $isProvisioned,
                    'account_id' => $account?->id,
                    'account_status' => $account?->status ?? 'NOT_PROVISIONED',
                    'starting_balance' => $account ? (string) $account->starting_balance : '0',
                    'current_equity' => $account ? (string) $account->current_equity : '0',
                    'realized_pnl' => $account ? (string) $account->realized_pnl : '0',
                    'unrealized_pnl' => $account ? (string) $account->unrealized_pnl : '0',
                    'execution_eligible' => ($meta['execution_eligible'] ?? true) && $entryBlockedReason === null,
                    'execution_block_reason' => ($meta['execution_eligible'] ?? true) ? $entryBlockedReason : 'strategy_execution_disabled',
                    'api_visible' => true,
                    'ui_visible' => true,
                ];
            }

            $matrix[] = $strategyRow;
        }

        return [
            'as_of' => now('UTC')->toIso8601String(),
            'total_strategies' => count($strategyIds),
            'total_exchanges' => count($exchangeIds),
            'total_topology_pairs' => $totalCells,
            'provisioned_pairs' => $provisionedCells,
            'active_pairs' => $activeCells,
            'strategies' => $matrix,
        ];
    }
}
