<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Application\StrategyObservationService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Contracts\SignalRepository;
use App\StrategyBuilder\PrimaryStrategies;
use App\Trading\Application\TradingPipelineEventService;
use DateTimeImmutable;
use DomainException;
use Throwable;

/** Owner-operated PAPER/RESEARCH cycle; never places real exchange orders. */
final readonly class BinanceResearchCycle
{
    public function __construct(
        private StrategyRegistry $registry,
        private StrategyInputFactory $inputs,
        private SignalRepository $signals,
        private PaperProcessingService $paper,
        private StrategyObservationService $observations,
        private ?TradingPipelineEventService $pipeline = null,
    ) {}

    public function run(string $symbol, bool $execute = false, ?DateTimeImmutable $at = null): array
    {
        if (! in_array($symbol, (array) config('market_data.symbols', []), true)) {
            throw new DomainException('Unconfigured Binance market symbol.');
        }

        $now = now('UTC')->getTimestamp();
        if ($at === null) {
            $at = $this->inputs->latestDecisionTime('binance', 'linear', $symbol);
            if ($at === null) {
                return ['exchange_id' => 'binance', 'symbol' => $symbol, 'status' => 'NO_CLOSED_DECISION_CANDLE', 'signals' => 0, 'processed' => 0];
            }
            // An ingestion cycle may lag behind the wall clock. Never replay old
            // decisions as new paper trades or manufacture a forward signal.
            if ($at->getTimestamp() > $now || $now - $at->getTimestamp() > 300) {
                return ['exchange_id' => 'binance', 'symbol' => $symbol, 'status' => 'STALE_OR_FUTURE_DECISION_CANDLE', 'signals' => 0, 'processed' => 0];
            }
        } else {
            if ($at->getTimestamp() > $now) {
                return ['exchange_id' => 'binance', 'symbol' => $symbol, 'status' => 'STALE_OR_FUTURE_DECISION_CANDLE', 'signals' => 0, 'processed' => 0];
            }
            if ($execute && ($now - $at->getTimestamp() > 300)) {
                return ['exchange_id' => 'binance', 'symbol' => $symbol, 'status' => 'STALE_OR_FUTURE_DECISION_CANDLE', 'signals' => 0, 'processed' => 0];
            }
        }

        $primary = PrimaryStrategies::ids('binance');
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
                throw new DomainException('Binance PRIMARY account population is incomplete.');
            }
            foreach ($primary as $id) {
                $account = $accounts->get($id);
                if ($account === null) {
                    throw new DomainException('Binance account missing.');
                }
                if (in_array($id, (array) config('research.forward_paper.signal_only_strategies', []), true)) {
                    continue;
                }
                BinancePaperSafety::assertExecutable($account);
            }
        }

        $observed = $this->observations->observe($symbol, $at, 'binance', 'linear');
        $pipelineService = $this->pipeline ?? (config('trading.pipeline_mode') === 'EVENT_DRIVEN' ? app(TradingPipelineEventService::class) : null);
        if ($pipelineService !== null && in_array($symbol, (array) config('trading.symbols', ['BTCUSDT', 'ETHUSDT']), true)) {
            try {
                $pipelineService->recordSignalsEvaluated('binance', $symbol, $at, count($observed));
            } catch (Throwable) {
                // Non-fatal if recording pipeline event fails
            }
        }
        $results = [];
        foreach ($observed as $obs) {
            $results[] = [
                'strategy_id' => $obs->strategy_id,
                'side' => (string) $obs->side,
                'signal_id' => (string) $obs->id,
                'observation_id' => (string) $obs->id,
            ];
        }

        $processed = 0;
        if ($execute) {
            foreach ($primary as $id) {
                if (in_array($id, (array) config('research.forward_paper.signal_only_strategies', []), true)) {
                    continue;
                }
                $account = $accounts->get($id);
                if ($account !== null) {
                    $processed += (int) $this->paper->process($account->id, $symbol)['events_processed'];
                }
            }
        }

        return [
            'exchange_id' => 'binance',
            'symbol' => $symbol,
            'at_utc' => $at->format(DATE_ATOM),
            'status' => $execute ? 'PAPER_PROCESSED' : 'SIGNAL_RESEARCH_ONLY',
            'signals' => count($results),
            'processed_events' => $processed,
            'strategy_signals' => $results,
        ];
    }
}
