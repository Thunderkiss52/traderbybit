<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Exchanges\Application\ExchangeStrategyCompatibilityService;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\Exchanges\Infrastructure\Persistence\ExchangeStrategyCompatibilityRecord;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Research\Jobs\EvaluateStrategyCandidateJob;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;

/** Bounded autonomous research intake. Financial activation requires verified prospective evidence. */
final readonly class PortfolioResearchLifecycleService
{
    public function __construct(
        private ExchangeStrategyCompatibilityService $compatibility,
        private AutonomyPermissionService $autonomy,
        private StrategyEvolutionService $evolution,
    ) {}

    public function process(int $limit = 8): array
    {
        $evaluated = 0;
        $scheduled = 0;
        $strategies = collect($this->compatibility->discoverStrategies())->keyBy('id');
        $registered = PrimaryStrategies::ids();
        $budget = max(1, min(32, $limit));
        $current = ExchangeStrategyCompatibilityRecord::query()->where(function ($query) use ($strategies, $registered): void {
            foreach ($registered as $id) {
                $strategy = $strategies->get($id);
                if ($strategy !== null) {
                    $query->orWhere(fn ($pair) => $pair->where('strategy_id', $id)->where('strategy_version', $strategy['version']));
                }
            }
        })->orderBy('evaluated_at')->orderBy('exchange_id')->orderBy('strategy_id')->get();
        $known = $current->keyBy(fn ($pair) => $pair->exchange_id.'|'.$pair->strategy_id);
        $pairs = [];
        foreach (ExchangeRecord::query()->orderBy('id')->get() as $exchange) {
            foreach ($registered as $id) {
                if ($strategies->has($id) && ! $known->has($exchange->id.'|'.$id)) {
                    $pairs[] = (object) ['exchange_id' => $exchange->id, 'strategy_id' => $id];
                    if (count($pairs) >= $budget) {
                        break 2;
                    }
                }
            }
        }
        $pairs = array_merge($pairs, $current->take($budget - count($pairs))->all());
        foreach ($pairs as $pair) {
            $exchange = ExchangeRecord::query()->find($pair->exchange_id);
            $strategy = $strategies->get($pair->strategy_id);
            if ($exchange === null || $strategy === null) {
                continue;
            }
            $record = $this->compatibility->evaluateAndPersist($exchange, $strategy);
            $blockers = (array) ($record->diagnostics['missing_requirements'] ?? []);
            $account = PaperAccountRecord::query()->where('exchange_id', $exchange->id)
                ->where('primary_strategy_id', $pair->strategy_id)->where('is_primary', true)->first();
            if ($account !== null && in_array($account->status, ['BLOCKED', 'HALTED'], true)
                && JournalEventRecord::query()->where('paper_account_id', $account->id)->where('event_type', 'DRAWDOWN_ENTRY_BLOCKED')->exists()) {
                $blockers[] = 'RISK_BLOCK_PRESERVED: Research cannot reactivate this portfolio.';
            }
            if (! in_array($exchange->id, ['bybit', 'binance'], true)) {
                $blockers[] = 'AUTONOMOUS_RESEARCH_RUNNER_UNSUPPORTED: Current candidate validation runner supports Bybit and Binance only.';
            } elseif ($record->research_eligible && $scheduled < 2
                && $this->autonomy->isAllowed('discover_hypotheses', false)
                && $this->autonomy->isAllowed('tune_parameters', false)
                && in_array($pair->strategy_id, $this->evolution->families(), true)) {
                $candidate = LearningCandidateStrategyRecord::query()->where('parent_strategy_id', $pair->strategy_id)
                    ->where('search_metadata->registered_version', $strategy['version'])->where('search_metadata->exchange', $exchange->id)->exists();
                $active = LearningCandidateStrategyRecord::query()->where('status', 'RESEARCH_ONLY')->count();
                if (! $candidate && $active < max(1, (int) config('research.evolution.max_total_active', 8))) {
                    try {
                        $candidate = $this->evolution->generateCandidate($pair->strategy_id, exchange: $exchange->id);
                        $candidate->update(['search_metadata' => array_merge((array) $candidate->search_metadata, ['registered_version' => $strategy['version']])]);
                        EvaluateStrategyCandidateJob::dispatch($candidate->id);
                        $scheduled++;
                    } catch (\InvalidArgumentException|\RuntimeException $error) {
                        $blockers[] = 'AUTONOMOUS_RESEARCH_SCHEDULING_FAILED: '.$error::class;
                    }
                }
            }
            // An eligible feed is not research robustness or prospective shadow evidence.
            $blockers[] = 'VERIFIED_SHADOW_AND_PROMOTION_EVIDENCE_REQUIRED: Eligibility alone cannot activate PAPER.';
            $diagnostics = (array) $record->diagnostics;
            $diagnostics['autonomous_lifecycle'] = [
                'stage' => 'RESEARCH', 'evaluated_at' => now('UTC')->toIso8601String(),
                'blockers' => array_values(array_unique($blockers)),
                'portfolio_status' => $account?->status,
                'funded' => $account !== null && BigDecimal::of((string) $account->starting_balance)->isPositive(),
                'automatic_activation_applied' => false,
            ];
            $record->update(['diagnostics' => $diagnostics]);
            $evaluated++;
        }

        return ['evaluated' => $evaluated, 'research_scheduled' => $scheduled, 'paper_activated' => 0];
    }
}
