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
                $this->accounts->strategyForward($strategy->id(), $strategy->version(), $strategy->parameters());
            }
        }

        $processed = [];
        PaperAccountRecord::query()->whereIn('mode', ['RESEARCH_FORWARD_PAPER', 'STRATEGY_FORWARD_PAPER'])->where('status', 'ACTIVE')->orderBy('id')->each(function (PaperAccountRecord $account) use (&$processed): void {
            $strategy = $this->registry->make($account->strategy_id, $account->strategy_parameters ?? $this->registry->get($account->strategy_id)->parameters());
            foreach ((array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']) as $symbol) {
                $at = $this->inputs->latestDecisionTime('bybit', 'linear', $symbol);
                if ($at === null) {
                    continue;
                } $this->signals->createIfAbsent($strategy->evaluate($this->inputs->make('bybit', 'linear', $symbol, $at)));
                $processed[] = $this->paper->process($account->id, $symbol);
            }
        });

        return $processed;
    }
}
