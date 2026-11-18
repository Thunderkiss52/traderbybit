<?php

declare(strict_types=1);

namespace App\Audit\Application;

use App\Exchanges\ExchangeRegistry;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Explains zero trades without changing any strategy threshold. */
final readonly class StrategyFunnelService
{
    public function __construct(private StrategyRegistry $strategies) {}

    public function report(int $days = 7, string $exchange = 'bybit'): array
    {
        if (! in_array($days, [1, 7, 14, 30], true)) {
            throw new \InvalidArgumentException('Invalid funnel window.');
        }
        $registered = app(ExchangeRegistry::class)->registeredIds();
        if (! in_array($exchange, $registered, true)) {
            $exchange = 'bybit';
        }

        return Cache::remember("audit:funnel:v3:{$exchange}:{$days}", 120, function () use ($days, $exchange): array {
            $since = now('UTC')->subDays($days);
            $rows = [];
            foreach ($this->strategies->metadata() as $strategy) {
                $base = StrategyObservationRecord::query()
                    ->where('exchange', $exchange)
                    ->where('strategy_id', $strategy['id'])
                    ->where('strategy_version', $strategy['version'])
                    ->where('configuration_hash', $strategy['configuration_hash'])
                    ->where('decision_time', '>=', $since);
                $sides = (clone $base)->selectRaw('side, COUNT(*) AS n')->groupBy('side')->pluck('n', 'side');
                $reasons = [];
                foreach ((clone $base)->where('side', 'SKIP')->toBase()->cursor(['reasons']) as $observation) {
                    $decoded = is_array($observation->reasons)
                        ? $observation->reasons
                        : (is_string($observation->reasons) ? json_decode($observation->reasons, true) : []);
                    $primary = 'unspecified';
                    if (is_array($decoded)) {
                        foreach ($decoded as $reason) {
                            if (is_string($reason) && ! str_starts_with($reason, 'regime_')) {
                                $primary = $reason;
                                break;
                            }
                        }
                    }
                    $reasons[$primary] = ($reasons[$primary] ?? 0) + 1;
                }
                arsort($reasons);
                $accounts = DB::table('paper_accounts')->where('exchange_id', $exchange)->where('strategy_id', $strategy['id'])
                    ->where('strategy_version', $strategy['version'])->get(['id', 'mode', 'configuration_hash']);
                $riskApproved = $riskRejected = $opened = $closed = 0;
                foreach ($accounts as $account) {
                    if ($account->configuration_hash !== $strategy['configuration_hash'] && $account->mode === 'STRATEGY_FORWARD_PAPER') {
                        continue;
                    }
                    $riskCounts = DB::table('risk_decisions')
                        ->where('paper_account_id', $account->id)
                        ->where('decided_at', '>=', $since)
                        ->selectRaw('COUNT(*) FILTER (WHERE allowed = true) as approved, COUNT(*) FILTER (WHERE allowed = false) as rejected')
                        ->first();
                    $riskApproved += (int) ($riskCounts->approved ?? 0);
                    $riskRejected += (int) ($riskCounts->rejected ?? 0);

                    $posCounts = DB::table('virtual_positions')
                        ->where('paper_account_id', $account->id)
                        ->selectRaw('COUNT(*) FILTER (WHERE opened_at >= ?) as opened, COUNT(*) FILTER (WHERE closed_at >= ?) as closed', [$since, $since])
                        ->first();
                    $opened += (int) ($posCounts->opened ?? 0);
                    $closed += (int) ($posCounts->closed ?? 0);
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

            return ['days' => $days, 'exchange_scope' => $exchange, 'strategies' => $rows];
        });
    }
}
