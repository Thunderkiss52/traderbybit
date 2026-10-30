<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PatternDiscovery\Application\CandlestickPatternDetector;
use App\PatternDiscovery\Application\MarketStructurePatternDetector;
use App\PatternDiscovery\Application\PatternMatchService;
use App\PatternDiscovery\Application\PatternOccurrenceOutcomeService;
use App\PatternDiscovery\Infrastructure\Persistence\CandlestickPatternOccurrenceRecord;
use App\Research\Application\ModelAdvisoryService;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Strategy\Contracts\SignalRepository;
use App\Strategy\Exceptions\StrategyObservationActiveException;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\StrategyInput;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class StrategyObservationService
{
    public function __construct(
        private StrategyRegistry $strategies,
        private StrategyInputFactory $inputs,
        private LevelInteractionAnalyzer $levels,
        private LevelProbabilityService $probabilities,
        private CandlestickPatternDetector $candlesticks,
        private MarketStructurePatternDetector $marketStructures,
        private PatternOccurrenceOutcomeService $patternOutcomes,
        private PatternMatchService $patternMatches,
        private SignalRepository $signals,
        private CacheFactory $cache,
        private ?ModelAdvisoryService $advisory = null,
    ) {}

    public function observe(string $symbol, ?DateTimeImmutable $at = null, string $exchange = 'bybit', string $category = 'linear'): array
    {
        $at ??= $this->inputs->latestDecisionTime($exchange, $category, $symbol)
            ?? throw new RuntimeException("No closed 15m candle is available for {$symbol}.");
        $lock = $this->cache->lock("strategy-observations:{$exchange}:{$category}:{$symbol}:{$at->format('U.u')}", 120);
        if (! $lock->get()) {
            throw StrategyObservationActiveException::forSlot("{$exchange}:{$category}:{$symbol}", $at);
        }

        try {
            $input = $this->inputs->make($exchange, $category, $symbol, $at);
            $this->patternOutcomes->resolve($symbol, $at);
            $this->recordCandlestickPatterns($input);
            $levelInteraction = $this->levels->analyze($input);
            $levelInteraction['historical_forecast'] = $this->probabilities->record($input, $levelInteraction);
            $observations = [];
            $baselineDecision = null;
            foreach ($this->strategies->all() as $strategy) {
                $strategyInput = $strategy->id() === 'btc_eth_lead_lag'
                    ? $this->inputs->make($exchange, $category, $symbol, $at, StrategyInputFactory::peerSymbol($symbol))
                    : $input;
                $decision = $strategy->evaluate($strategyInput);
                if ($strategy->id() === 'baseline') {
                    if ($this->advisory !== null) {
                        $decision = $this->advisory->advise($decision, $this->extractFeatures($input));
                    }
                    $baselineDecision = $decision;
                }
                $this->signals->createIfAbsent($decision);
                $configurationHash = $this->configurationHash($strategy->parameters());

                // Safeguard 3: Check for existing canonical observation before generating or inserting
                $existingObs = StrategyObservationRecord::query()
                    ->where('exchange', $exchange)
                    ->where('category', $category)
                    ->where('symbol', $symbol)
                    ->where('strategy_id', $strategy->id())
                    ->where('strategy_version', $strategy->version())
                    ->where('decision_time', $decision->decisionTime)
                    ->whereNull('superseded_by')
                    ->first();

                if ($existingObs !== null) {
                    if ($existingObs->configuration_hash !== $configurationHash) {
                        Log::warning('Configuration hash conflict detected for canonical strategy observation slot without version bump.', [
                            'observation_id' => (string) $existingObs->id,
                            'slot' => "{$exchange}|{$category}|{$symbol}|{$strategy->id()}|{$strategy->version()}",
                            'existing_hash' => $existingObs->configuration_hash,
                            'incoming_hash' => $configurationHash,
                            'decision_time' => (string) $decision->decisionTime,
                        ]);
                    }
                    $observations[] = $existingObs;

                    continue;
                }

                $id = Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', [
                    'traderbybit:strategy-observation', $exchange, $category, $symbol,
                    $strategy->id(), $strategy->version(), $at->format('U.u'),
                ]))->toString();
                $analysis = [
                    'level_interaction' => $levelInteraction,
                    'indicator_snapshot' => $decision->indicatorSnapshot,
                    'diagnostics' => $this->diagnostics($decision),
                ];
                if ($decision->pricePlan !== null) {
                    $analysis['candidate_plan'] = [
                        'entry_low' => $decision->pricePlan->entryLow,
                        'entry_high' => $decision->pricePlan->entryHigh,
                        'stop_loss' => $decision->pricePlan->stopLoss,
                        'take_profit_1' => $decision->pricePlan->takeProfit1,
                        'take_profit_2' => $decision->pricePlan->takeProfit2,
                        'risk_reward_1' => $decision->pricePlan->riskReward1,
                        'risk_reward_2' => $decision->pricePlan->riskReward2,
                    ];
                }
                StrategyObservationRecord::query()->insertOrIgnore([[
                    'id' => $id,
                    'exchange' => $exchange,
                    'category' => $category,
                    'symbol' => $symbol,
                    'strategy_id' => $strategy->id(),
                    'strategy_version' => $strategy->version(),
                    'configuration_hash' => $configurationHash,
                    'side' => $decision->side->value,
                    'quality_class' => $decision->qualityClass?->value,
                    'score' => $decision->score,
                    'decision_time' => $decision->decisionTime,
                    'reasons' => json_encode($decision->reasons, JSON_THROW_ON_ERROR),
                    'analysis' => json_encode($analysis, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]]);
                $observation = StrategyObservationRecord::query()->findOrFail($id);
                if ($observation->analysis === null || ! isset($observation->analysis['diagnostics'])) {
                    $observation->update(['analysis' => array_replace_recursive($observation->analysis ?? [], $analysis)]);
                }
                $observations[] = $observation;
            }

            // Evaluate active promoted candidate strategies so they receive paper signals and executions
            $promotedCandidates = LearningCandidateStrategyRecord::query()
                ->where('status', 'PAPER_PROMOTED')
                ->get();

            foreach ($promotedCandidates as $candidate) {
                try {
                    $candParams = (array) $candidate->parameters;
                    $candParams['version'] = $candidate->candidate_version;
                    $candStrategy = $this->strategies->make($candidate->parent_strategy_id, $candParams);
                    $candDecision = $candStrategy->evaluate($input);
                    $this->signals->createIfAbsent($candDecision);

                    $candConfigHash = $this->configurationHash($candParams);

                    // Safeguard 3: Check for existing canonical candidate observation
                    $existingCand = StrategyObservationRecord::query()
                        ->where('exchange', $exchange)
                        ->where('category', $category)
                        ->where('symbol', $symbol)
                        ->where('strategy_id', $candStrategy->id())
                        ->where('strategy_version', $candidate->candidate_version)
                        ->where('decision_time', $candDecision->decisionTime)
                        ->whereNull('superseded_by')
                        ->first();

                    if ($existingCand !== null) {
                        if ($existingCand->configuration_hash !== $candConfigHash) {
                            Log::warning('Configuration hash conflict detected for canonical candidate observation slot without version bump.', [
                                'observation_id' => (string) $existingCand->id,
                                'slot' => "{$exchange}|{$category}|{$symbol}|{$candStrategy->id()}|{$candidate->candidate_version}",
                                'existing_hash' => $existingCand->configuration_hash,
                                'incoming_hash' => $candConfigHash,
                                'decision_time' => (string) $candDecision->decisionTime,
                            ]);
                        }
                        $observations[] = $existingCand;

                        continue;
                    }

                    $candObsId = Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', [
                        'traderbybit:strategy-observation', $exchange, $category, $symbol,
                        $candStrategy->id(), $candidate->candidate_version, $at->format('U.u'),
                    ]))->toString();

                    $candAnalysis = [
                        'level_interaction' => $levelInteraction,
                        'indicator_snapshot' => $candDecision->indicatorSnapshot,
                        'diagnostics' => $this->diagnostics($candDecision),
                    ];
                    if ($candDecision->pricePlan !== null) {
                        $candAnalysis['candidate_plan'] = [
                            'entry_low' => $candDecision->pricePlan->entryLow,
                            'entry_high' => $candDecision->pricePlan->entryHigh,
                            'stop_loss' => $candDecision->pricePlan->stopLoss,
                            'take_profit_1' => $candDecision->pricePlan->takeProfit1,
                            'take_profit_2' => $candDecision->pricePlan->takeProfit2,
                            'risk_reward_1' => $candDecision->pricePlan->riskReward1,
                            'risk_reward_2' => $candDecision->pricePlan->riskReward2,
                        ];
                    }

                    StrategyObservationRecord::query()->insertOrIgnore([[
                        'id' => $candObsId,
                        'exchange' => $exchange,
                        'category' => $category,
                        'symbol' => $symbol,
                        'strategy_id' => $candStrategy->id(),
                        'strategy_version' => $candidate->candidate_version,
                        'configuration_hash' => $candConfigHash,
                        'side' => $candDecision->side->value,
                        'quality_class' => $candDecision->qualityClass?->value,
                        'score' => $candDecision->score,
                        'decision_time' => $candDecision->decisionTime,
                        'reasons' => json_encode($candDecision->reasons, JSON_THROW_ON_ERROR),
                        'analysis' => json_encode($candAnalysis, JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]]);

                    $candObs = StrategyObservationRecord::query()->find($candObsId);
                    if ($candObs !== null) {
                        $observations[] = $candObs;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Candidate evaluation failed.', ['exception_type' => $e::class]);
                }
            }

            if ($baselineDecision !== null) {
                $this->patternMatches->process($baselineDecision);
            }

            return $observations;
        } finally {
            $lock->release();
        }
    }

    private function extractFeatures(StrategyInput $input): array
    {
        $latest = $input->entry->latest();
        if ($latest === null) {
            return [];
        }
        $close = (float) $latest->close;
        $high = (float) $latest->high;
        $low = (float) $latest->low;
        $hlSpread = $close > 0 ? (($high - $low) / $close) * 100 : 0.0;
        $atr = (float) ($latest->atr ?? 0.0);
        $recentVol = $close > 0 ? ($atr / $close) * 100 : 0.0;
        $rsi = (float) ($latest->rsi ?? 50.0);
        $volRatio = (float) ($latest->relativeVolume ?? 1.0);

        return [
            'hl_spread_pct' => $hlSpread,
            'recent_volatility' => $recentVol,
            'rsi_14' => $rsi,
            'volume_ratio' => $volRatio,
        ];
    }

    private function recordCandlestickPatterns(StrategyInput $input): void
    {
        foreach (['60' => $input->trend, '30' => $input->setup, '15' => $input->entry, '5' => $input->confirmation] as $timeframe => $state) {
            $bars = array_slice($state->bars, -80);
            $payload = array_map(static fn ($bar): array => [
                'open' => $bar->open, 'high' => $bar->high, 'low' => $bar->low, 'close' => $bar->close,
            ], $bars);
            $patterns = array_merge(
                array_map(static fn (array $pattern): array => $pattern + ['type' => 'CANDLESTICK'], $this->candlesticks->detect(array_slice($payload, -3))),
                array_map(static fn (array $pattern): array => $pattern + ['type' => 'MARKET_STRUCTURE'], $this->marketStructures->detect($payload)),
            );
            foreach ($patterns as $pattern) {
                $detectedAt = $bars[array_key_last($bars)]->closeTime;
                $formationIndex = isset($pattern['formation_index'])
                    ? (int) $pattern['formation_index']
                    : max(0, count($bars) - (int) $pattern['bars']);
                $first = $bars[$formationIndex];
                $identity = implode('|', [$input->exchange, $input->category, $input->symbol, $timeframe, $pattern['name'], $pattern['direction'], $pattern['version'], $detectedAt->format('U.u')]);
                CandlestickPatternOccurrenceRecord::query()->insertOrIgnore([[
                    'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:candlestick:'.$identity)->toString(),
                    'exchange' => $input->exchange, 'category' => $input->category, 'symbol' => $input->symbol,
                    'timeframe' => $timeframe, 'pattern_name' => $pattern['name'], 'direction' => $pattern['direction'],
                    'pattern_type' => $pattern['type'],
                    'version' => $pattern['version'], 'bar_count' => $pattern['bars'],
                    'formation_time' => $first->openTime, 'detected_at' => $detectedAt,
                    'evidence' => json_encode(['bars' => array_slice($payload, $formationIndex), 'conditions' => $pattern['evidence'] ?? []], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]]);
            }
        }
    }

    private function diagnostics(SignalDecision $decision): array
    {
        $primary = $decision->side->value === 'SKIP' ? ($decision->reasons[0] ?? 'unspecified_strategy_rejection') : null;

        return [
            'decision' => $decision->side->value,
            'signal_score' => $decision->score / 100,
            'primary_rejection_reason' => $primary,
            'secondary_rejection_reasons' => $primary === null ? [] : array_values(array_slice($decision->reasons, 1)),
            'blocking_condition' => $primary !== null && count($decision->reasons) === 1 ? $primary : null,
            'risk_approved' => null,
            'paper_execution' => $decision->side->value === 'SKIP' ? 'NOT_ATTEMPTED' : 'PENDING_RISK',
        ];
    }

    public function observeRecent(string $symbol, int $hours = 24, int $maximumCandles = 96, string $exchange = 'bybit', string $category = 'linear'): int
    {
        $through = $this->inputs->latestDecisionTime($exchange, $category, $symbol);
        if ($through === null) {
            return 0;
        }
        $expected = collect($this->strategies->metadata())
            ->map(fn (array $strategy): string => $strategy['id'].'|'.$strategy['configuration_hash'])
            ->all();
        $existing = StrategyObservationRecord::query()
            ->where('exchange', $exchange)->where('category', $category)->where('symbol', $symbol)
            ->where('decision_time', '>', $through->modify("-{$hours} hours"))->where('decision_time', '<=', $through)
            ->get(['strategy_id', 'configuration_hash', 'decision_time', 'analysis'])
            ->groupBy(fn (StrategyObservationRecord $row): string => $row->decision_time->format('U.u'))
            ->map(fn ($rows): array => $rows->filter(fn (StrategyObservationRecord $row): bool => $row->analysis !== null && isset($row->analysis['diagnostics']))
                ->map(fn (StrategyObservationRecord $row): string => $row->strategy_id.'|'.$row->configuration_hash)->all());
        $decisionTimes = CandleRecord::query()
            ->where('exchange', $exchange)->where('category', $category)->where('symbol', $symbol)
            ->where('interval', '15')->where('is_closed', true)
            ->where('open_time', '>=', $through->modify("-{$hours} hours"))
            ->where('open_time', '<', $through)
            ->latest('open_time')->limit(96)->pluck('open_time');
        $processed = 0;
        foreach ($decisionTimes as $openTime) {
            $at = $openTime->toDateTimeImmutable()->modify('+15 minutes');
            $recorded = $existing->get($at->format('U.u'), []);
            if (array_diff($expected, $recorded) === []) {
                continue;
            }
            $this->observe($symbol, $at, $exchange, $category);
            $processed++;
            if ($processed >= $maximumCandles) {
                break;
            }
        }

        return $processed;
    }

    private function configurationHash(array $parameters): string
    {
        $sort = function (mixed &$value) use (&$sort): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as &$item) {
                $sort($item);
            }
            unset($item);
            if (! array_is_list($value)) {
                ksort($value);
            }
        };
        $sort($parameters);

        return hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));
    }
}
