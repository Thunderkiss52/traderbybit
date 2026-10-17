<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Contracts\SignalRepository;

final readonly class StrategyResearchService
{
    public function __construct(private StrategyRegistry $registry, private StrategyInputFactory $inputs, private SignalRepository $signals, private PaperProcessingService $paper, private PaperAccountService $accounts) {}

    public function updateForwardPortfolios(): array
    {
        if ((bool) config('research.forward_paper.automatic_accounts', true)) {
            foreach ((array) config('research.forward_paper.enabled_strategies', []) as $strategyId) {
                $strategy = $this->registry->get((string) $strategyId);
                try {
                    $this->accounts->strategyForward($strategy->id(), $strategy->version(), $strategy->parameters());
                } catch (\DomainException $error) {
                    // Continue managing existing accounts; surface the versioning
                    // problem in logs rather than silently mixing strategies.
                    report($error);
                }
            }
        }

        $processed = [];
        $metadata = collect($this->registry->metadata())->keyBy('id');
        $enabled = (array) config('research.forward_paper.enabled_strategies', []);
        foreach (PaperAccountRecord::query()->whereIn('mode', ['RESEARCH_FORWARD_PAPER', 'STRATEGY_FORWARD_PAPER'])->whereIn('status', ['ACTIVE', 'BLOCKED', 'HALTED'])->orderBy('id')->cursor() as $account) {
            try {
                // Previous versions/configs remain readable and must exit existing positions,
                // but they may not silently start new trades after a version rotation.
                $current = $metadata->get($account->strategy_id);
                $allowNew = $account->status === 'ACTIVE'
                    && ($account->mode === 'RESEARCH_FORWARD_PAPER' || (in_array($account->strategy_id, $enabled, true)
                        && $current !== null && $current['version'] === $account->strategy_version
                        && $current['configuration_hash'] === $account->configuration_hash));
                $strategy = $allowNew
                    ? $this->registry->make($account->strategy_id, $account->strategy_parameters ?? $this->registry->get($account->strategy_id)->parameters())
                    : null;
                foreach ((array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']) as $symbol) {
                    $at = $this->inputs->latestDecisionTime('bybit', 'linear', $symbol);
                    if ($at === null) {
                        continue;
                    }
                    if ($strategy !== null) {
                        $this->signals->createIfAbsent($strategy->evaluate($this->inputs->make('bybit', 'linear', $symbol, $at)));
                    }
                    $processed[] = $this->paper->process($account->id, $symbol, allowNewEntries: $allowNew);
                }
            } catch (\Throwable $error) {
                report($error);
            }
        }

        return $processed;
    }
}
