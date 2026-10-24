<?php

declare(strict_types=1);

namespace App\Audit\Application;

use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Explains zero trades without changing any strategy threshold. */
final readonly class StrategyFunnelService
{
    public function __construct(private StrategyRegistry $strategies) {}

    public function report(int $days = 7): array
    {
        if (! in_array($days, [1, 7, 14, 30], true)) {
            throw new \InvalidArgumentException('Invalid funnel window.');
        }

        return Cache::remember('audit:funnel:v2:'.$days, 60, function () use ($days): array {
            $since = now('UTC')->subDays($days);
            $rows = [];
            foreach ($this->strategies->metadata() as $strategy) {
                $base = StrategyObservationRecord::query()
                    ->where('strategy_id', $strategy['id'])
                    ->where('strategy_version', $strategy['version'])
                    ->where('configuration_hash', $strategy['configuration_hash'])
                    ->where('decision_time', '>=', $since);
                $sides = (clone $base)->selectRaw('side, COUNT(*) AS n')->groupBy('side')->pluck('n', 'side');
                $reasons = [];
                foreach ((clone $base)->where('side', 'SKIP')->cursor(['reasons']) as $observation) {
                    $primary = collect($observation->reasons ?? [])
                        ->first(fn ($reason): bool => is_string($reason) && ! str_starts_with($reason, 'regime_'));
                    $primary = is_string($primary) ? $primary : 'unspecified';
                    $reasons[$primary] = ($reasons[$primary] ?? 0) + 1;
                }
                arsort($reasons);
                $accounts = DB::table('paper_accounts')->where('exchange_id', 'bybit')->where('strategy_id', $strategy['id'])
                    ->where('strategy_version', $strategy['version'])->get(['id', 'mode', 'configuration_hash']);
                $riskApproved = $riskRejected = $opened = $closed = 0;
                foreach ($accounts as $account) {
                    if ($account->configuration_hash !== $strategy['configuration_hash'] && $account->mode === 'STRATEGY_FORWARD_PAPER') {
                        continue;
                    }
                    $risk = DB::table('risk_decisions')->where('paper_account_id', $account->id)->where('decided_at', '>=', $since);
                    $riskApproved += (clone $risk)->where('allowed', true)->count();
                    $riskRejected += (clone $risk)->where('allowed', false)->count();
                    $positions = DB::table('virtual_positions')->where('paper_account_id', $account->id);
                    $opened += (clone $positions)->where('opened_at', '>=', $since)->count();
                    $closed += (clone $positions)->where('closed_at', '>=', $since)->count();
                }
                $rows[] = [
                    'strategy_id' => $strategy['id'], 'strategy_version' => $strategy['version'],
                    'buy' => (int) ($sides['BUY'] ?? 0), 'sell' => (int) ($sides['SELL'] ?? 0),
                    'skip' => (int) ($sides['SKIP'] ?? 0),
                    'actual_decisions' => (int) $sides->sum(),
                    'execution_mode' => in_array($strategy['id'], (array) config('research.forward_paper.signal_only_strategies', []), true) ? 'SIGNAL_ONLY' : 'PAPER_EXECUTION',
                    'risk_approved' => $riskApproved, 'risk_rejected' => $riskRejected,
                    'opened' => $opened, 'closed' => $closed,
                    'primary_skip_blockers' => array_slice($reasons, 0, 3, true),
                    'note' => 'Decisions and fills are different observation types; no trade quota is enforced.',
                ];
            }

            return ['days' => $days, 'exchange_scope' => 'bybit', 'strategies' => $rows];
        });
    }
}
