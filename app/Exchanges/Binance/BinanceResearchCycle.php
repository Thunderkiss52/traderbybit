<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Contracts\SignalRepository;
use DomainException;

/** Owner-operated PAPER/RESEARCH cycle; never places real exchange orders. */
final readonly class BinanceResearchCycle
{
    public function __construct(
        private StrategyRegistry $registry,
        private StrategyInputFactory $inputs,
        private SignalRepository $signals,
        private PaperProcessingService $paper,
    ) {}

    public function run(string $symbol, bool $execute = false): array
    {
        if (! in_array($symbol, (array) config('market_data.symbols', []), true)) {
            throw new DomainException('Unconfigured Binance market symbol.');
        }
        $at = $this->inputs->latestDecisionTime('binance', 'linear', $symbol);
        if ($at === null) {
            return ['exchange_id' => 'binance', 'symbol' => $symbol, 'status' => 'NO_CLOSED_DECISION_CANDLE', 'signals' => 0, 'processed' => 0];
        }
        // An ingestion cycle may lag behind the wall clock. Never replay old
        // decisions as new paper trades or manufacture a forward signal.
        if ($at->getTimestamp() > time() || time() - $at->getTimestamp() > 300) {
            return ['exchange_id' => 'binance', 'symbol' => $symbol, 'status' => 'STALE_OR_FUTURE_DECISION_CANDLE', 'signals' => 0, 'processed' => 0];
        }
        $primary = (array) config('paper.primary_strategies', []);
        $accounts = null;
        if ($execute) {
            // A denied PAPER request must not write signals before discovering
            // that a trading account, cost parameter or approval is missing.
            if (config('exchanges.binance.paper_execution_approved') !== true) {
                throw new DomainException('Binance PAPER execution is disabled.');
            }
            $accounts = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('is_primary', true)
                ->get()->keyBy('primary_strategy_id');
            if ($accounts->count() !== count($primary)) {
                throw new DomainException('Binance 13-account population is incomplete.');
            }
            foreach ($primary as $id) {
                $account = $accounts->get($id);
                if ($account === null) {
                    throw new DomainException('Binance account missing.');
                }
                if (in_array($id, (array) config('research.forward_paper.signal_only_strategies', []), true)
                    || $id === 'funding_open_interest_squeeze') {
                    continue;
                }
                BinancePaperSafety::assertExecutable($account);
            }
        }
        $results = [];
        foreach ($primary as $id) {
            $strategy = $this->registry->get((string) $id);
            $peer = $id === 'btc_eth_lead_lag' ? StrategyInputFactory::peerSymbol($symbol) : null;
            $input = $this->inputs->make('binance', 'linear', $symbol, $at, $peer);
            $decision = $strategy->evaluate($input);
            if ($decision->exchange !== 'binance' || $decision->symbol !== $symbol) {
                throw new DomainException('Strategy produced a cross-exchange signal.');
            }
            $this->signals->createIfAbsent($decision);
            $results[] = ['strategy_id' => $id, 'side' => $decision->side->value, 'signal_id' => $decision->id];
        }
        $processed = 0;
        if ($execute) {
            foreach ($primary as $id) {
                if (in_array($id, (array) config('research.forward_paper.signal_only_strategies', []), true)
                    || $id === 'funding_open_interest_squeeze') {
                    continue;
                }
                $processed += (int) $this->paper->process($accounts->get($id)->id, $symbol)['events_processed'];
            }
        }

        return [
            'exchange_id' => 'binance', 'symbol' => $symbol, 'at_utc' => $at->format(DATE_ATOM),
            'status' => $execute ? 'PAPER_PROCESSED' : 'SIGNAL_RESEARCH_ONLY',
            'signals' => count($results), 'processed_events' => $processed,
            'strategy_signals' => $results,
        ];
    }
}
