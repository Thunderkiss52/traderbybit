<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Backtest\Application\BacktestCurveService;
use App\Backtest\Infrastructure\Persistence\BacktestEquitySnapshotRecord;
use App\Backtest\Infrastructure\Persistence\BacktestOptimizationRecord;
use App\Backtest\Infrastructure\Persistence\BacktestReportRecord;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\Backtest\Infrastructure\Persistence\BacktestSegmentRecord;
use App\Backtest\Infrastructure\Persistence\BacktestTradeRecord;
use App\Backtest\Jobs\RunBacktestJob;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\BacktestStoreRequest;
use App\Http\Requests\V1\MarketSeriesRequest;
use App\Http\Resources\V1\ResearchResource;
use App\Indicators\Application\IndicatorEngine;
use App\MarketData\Domain\Candle;
use App\MarketData\Domain\Interval;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\DailyPerformanceService;
use App\PaperTrading\Application\PerformanceStatisticsService;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\PatternDiscovery\Infrastructure\Persistence\CandlestickPatternOccurrenceRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class ResearchController extends Controller
{
    private function ok(mixed $data): ResearchResource
    {
        return new ResearchResource($data);
    }

    private function symbol(string $symbol): string
    {
        $symbol = strtoupper($symbol);
        abort_unless(in_array($symbol, config('ui.symbols'), true), 404, 'Unsupported symbol.');

        return $symbol;
    }

    /** Keep default ledgers small even when clients omit pagination arguments. */
    private function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 10)));
    }

    public function dashboard(PerformanceStatisticsService $statistics, StrategyRegistry $strategies): ResearchResource
    {
        $payload = Cache::remember('paper:dashboard:full_payload:v3', 10, function () use ($statistics, $strategies): array {
            $account = PaperAccountRecord::query()->oldest()->first();
            $contexts = collect(config('ui.symbols'))->map(
                static fn (string $symbol) => MarketContextRecord::query()->where('symbol', $symbol)->latest('observed_at')->first()
            )->filter()->values();
            $skipReasons = Cache::remember('paper:dashboard:skip_reasons_24h', 60, function (): array {
                $recentSkips = SignalRecord::query()->where('side', 'SKIP')
                    ->where('decision_time', '>=', now('UTC')->subDay())
                    ->whereIn('symbol', config('ui.symbols'))
                    ->latest('decision_time')
                    ->get(['reasons']);
                $skipCounts = [];
                foreach ($recentSkips as $skip) {
                    foreach ($skip->reasons ?? [] as $reason) {
                        if (is_string($reason) && ! str_starts_with($reason, 'regime_')) {
                            $skipCounts[$reason] = ($skipCounts[$reason] ?? 0) + 1;
                        }
                    }
                }
                arsort($skipCounts);

                return array_slice($skipCounts, 0, 8, true);
            });
            $recentDecisions = SignalRecord::query()->whereIn('symbol', config('ui.symbols'))
                ->latest('decision_time')->latest('id')->limit(8)
                ->get(['id', 'symbol', 'side', 'decision_time', 'reasons', 'indicator_snapshot'])
                ->map(static fn (SignalRecord $decision): array => [
                    'id' => $decision->id,
                    'symbol' => $decision->symbol,
                    'side' => $decision->side,
                    'decision_time' => $decision->decision_time,
                    'reasons' => $decision->reasons,
                    'level_interaction' => data_get($decision->indicator_snapshot, 'level_interaction'),
                ]);

            $signals = SignalRecord::query()->whereIn('side', ['BUY', 'SELL'])->latest('decision_time')->latest('id')->limit(8)->get(['id', 'symbol', 'side', 'quality_class', 'score', 'entry_low', 'entry_high', 'stop_loss', 'take_profit_1', 'take_profit_2', 'expires_at', 'reasons', 'decision_time'])->map(fn ($s) => $s->toArray())->all();
            $positions = VirtualPositionRecord::query()->whereNull('closed_at')->latest('opened_at')->limit(10)->get(['id', 'symbol', 'side', 'status', 'entry_price', 'net_pnl', 'unrealized_pnl'])->map(fn ($p) => $p->toArray())->all();

            return [
                'market' => $contexts->map(fn ($c) => $c->toArray())->values()->all(),
                'account' => $account?->toArray(),
                'portfolio' => $account ? array_merge($statistics->accountSummary($account), [
                    'open_positions' => VirtualPositionRecord::query()->where('paper_account_id', $account->id)->whereNull('closed_at')->count(),
                    'pending_entry_signals' => PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->where('status', 'PENDING_ENTRY')->count(),
                ]) : null,
                'signals' => $signals,
                'recent_decisions' => $recentDecisions,
                'skip_reasons_24h' => $skipReasons,
                'strategy_observations_24h' => Cache::remember('paper:dashboard:strategy_observations_24h', 60, fn () => $this->strategyObservations($strategies)),
                'strategy_portfolios' => collect($strategies->metadata())->map(function (array $strategy): array {
                    $account = PaperAccountRecord::query()
                        ->where('primary_strategy_id', $strategy['id'])
                        ->first()
                        ?? PaperAccountRecord::query()
                            ->where('strategy_id', $strategy['id'])
                            ->whereIn('mode', ['FORWARD_PAPER', 'STRATEGY_FORWARD_PAPER', 'RESEARCH_FORWARD_PAPER'])
                            ->latest('created_at')
                            ->first();

                    if ($account === null && $strategy['id'] === 'baseline') {
                        $account = PaperAccountRecord::query()
                            ->where(fn ($q) => $q->where('strategy_id', 'baseline')->orWhereNull('strategy_id'))
                            ->whereIn('mode', ['FORWARD_PAPER', 'STRATEGY_FORWARD_PAPER'])
                            ->oldest('created_at')
                            ->first();
                    }

                    $latestObs = StrategyObservationRecord::query()
                        ->where('strategy_id', $strategy['id'])
                        ->latest('decision_time')
                        ->first(['side', 'decision_time', 'reasons']);

                    $activeModel = LearningModelArtifactRecord::query()
                        ->where('target_event', 'TP_BEFORE_SL')
                        ->where('status', 'ACTIVE')
                        ->first();
                    $shadowModel = $activeModel === null
                        ? LearningModelArtifactRecord::query()
                            ->where('target_event', 'TP_BEFORE_SL')
                            ->whereIn('status', ['SHADOW', 'STANDBY', 'CANDIDATE'])
                            ->first()
                        : null;
                    $mlStatus = $activeModel !== null ? 'ML_ACTIVE' : ($shadowModel !== null ? 'ML_SHADOW' : 'ML_NOT_TRAINED');

                    $accountStatus = ($account && $account->status === 'ACTIVE') ? 'ACCOUNT_ACTIVE' : ($account ? (string) $account->status : 'ACCOUNT_NOT_PROVISIONED');
                    $strategyStatus = 'STRATEGY_ENABLED';
                    $evaluationStatus = $latestObs !== null ? 'STRATEGY_EVALUATING' : 'AWAITING_FIRST_CYCLE';
                    $paperStatus = $account ? 'PAPER_EXECUTION_OPERATIONAL' : 'ACCOUNT_NOT_PROVISIONED';

                    $openPositionsCount = $account ? VirtualPositionRecord::query()->where('paper_account_id', $account->id)->whereNull('closed_at')->count() : 0;
                    $closedPositionsCount = $account ? VirtualPositionRecord::query()->where('paper_account_id', $account->id)->whereNotNull('closed_at')->count() : 0;

                    return [
                        'id' => $account ? (string) $account->id : 'strategy:'.$strategy['id'],
                        'name' => $account ? (string) $account->name : 'Forward Paper '.$strategy['id'],
                        'mode' => $account ? (string) ($account->mode ?: 'STRATEGY_FORWARD_PAPER') : 'STRATEGY_FORWARD_PAPER',
                        'strategy_id' => $strategy['id'],
                        'strategy_version' => $account && $account->strategy_version ? (string) $account->strategy_version : (string) $strategy['version'],
                        'status' => $account ? (string) ($account->status ?: 'ACTIVE') : 'ACTIVE',
                        'account_status' => $accountStatus,
                        'strategy_status' => $strategyStatus,
                        'evaluation_status' => $evaluationStatus,
                        'ml_status' => $mlStatus,
                        'paper_execution_status' => $paperStatus,
                        'current_equity' => $account ? (string) $account->current_equity : (string) config('backtest.starting_balance', '10000'),
                        'realized_pnl' => $account ? (string) $account->realized_pnl : '0',
                        'unrealized_pnl' => $account ? (string) $account->unrealized_pnl : '0',
                        'open_positions' => $openPositionsCount,
                        'closed_positions' => $closedPositionsCount,
                        'last_evaluation_at' => $latestObs?->decision_time?->toISOString(),
                        'latest_decision' => $latestObs ? [
                            'side' => $latestObs->side,
                            'decision_time' => $latestObs->decision_time->toISOString(),
                            'reasons' => $latestObs->reasons,
                        ] : null,
                    ];
                })->values()->all(),
                'paper_execution_health' => [
                    'execution_failures_24h' => JournalEventRecord::query()->where('event_type', 'EXECUTION_FAILED')->where('occurred_at', '>=', now('UTC')->subDay())->count(),
                    'last_journal_event_at' => JournalEventRecord::query()->max('occurred_at'),
                    'latest_strategy_evaluation_at' => StrategyObservationRecord::query()->max('decision_time'),
                ],
                'positions' => $positions,
                'performance' => $account ? Cache::remember('paper:dashboard:performance:'.$account->id, 60, fn () => $statistics->calculate($account->id)->metrics) : null,
                'research_settings' => [
                    'starting_balance' => config('backtest.starting_balance'),
                    'strategy_version' => config('strategy.version'),
                    'execution_model_version' => config('paper.execution_version'),
                ],
            ];
        });

        return $this->ok($payload);
    }

    private function strategyObservations(StrategyRegistry $strategies): array
    {
        $since = now('UTC')->subDay();

        return collect($strategies->metadata())->map(function (array $strategy) use ($since): array {
            $query = StrategyObservationRecord::query()
                ->where('strategy_id', $strategy['id'])
                ->where(fn ($q) => $q->where('configuration_hash', $strategy['configuration_hash'])->orWhereNull('configuration_hash'))
                ->where('decision_time', '>=', $since);
            $counts = (clone $query)->selectRaw('side, COUNT(*) AS aggregate')->groupBy('side')->pluck('aggregate', 'side');
            $latest = (clone $query)->latest('decision_time')->latest('id')->first(['symbol', 'side', 'decision_time', 'reasons', 'analysis']);
            $symbols = collect((array) config('ui.symbols'))->map(function (string $symbol) use ($query): array {
                $symbolQuery = (clone $query)->where('symbol', $symbol);
                $symbolCounts = (clone $symbolQuery)->selectRaw('side, COUNT(*) AS aggregate')->groupBy('side')->pluck('aggregate', 'side');

                return [
                    'symbol' => $symbol,
                    'buy' => (int) ($symbolCounts['BUY'] ?? 0),
                    'sell' => (int) ($symbolCounts['SELL'] ?? 0),
                    'skip' => (int) ($symbolCounts['SKIP'] ?? 0),
                    'total' => (int) $symbolCounts->sum(),
                    'latest' => (clone $symbolQuery)->latest('decision_time')->latest('id')
                        ->first(['side', 'decision_time', 'reasons', 'analysis']),
                ];
            })->values()->all();
            $skipReasons = [];
            $skipRows = (clone $query)->where('side', 'SKIP')->get(['reasons']);
            foreach ($skipRows as $observation) {
                foreach ($observation->reasons ?? [] as $reason) {
                    if (is_string($reason) && ! str_starts_with($reason, 'regime_')) {
                        $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
                    }
                }
            }
            arsort($skipReasons);

            return [
                'strategy_id' => $strategy['id'],
                'strategy_version' => $strategy['version'],
                'description' => $strategy['description'],
                'timeframes' => $strategy['timeframes'],
                'buy' => (int) ($counts['BUY'] ?? 0),
                'sell' => (int) ($counts['SELL'] ?? 0),
                'skip' => (int) ($counts['SKIP'] ?? 0),
                'total' => (int) $counts->sum(),
                'latest' => $latest,
                'symbols' => $symbols,
                'top_skip_reasons' => array_slice($skipReasons, 0, 3, true),
            ];
        })->values()->all();
    }

    public function candles(MarketSeriesRequest $r, string $symbol): ResearchResource
    {
        return $this->ok($this->marketRows($r, $this->symbol($symbol)));
    }

    public function indicators(MarketSeriesRequest $r, string $symbol, IndicatorEngine $engine): ResearchResource
    {
        $rows = $this->marketRows($r, $this->symbol($symbol));

        return $this->ok($this->indicatorPayload($rows, (string) $r->integer('interval', 15), $engine));
    }

    public function analysis(MarketSeriesRequest $r, string $symbol, IndicatorEngine $engine): ResearchResource
    {
        $symbol = $this->symbol($symbol);
        $interval = (string) $r->integer('interval', 15);
        $limit = $r->integer('limit', 300);
        $viewMode = (string) $r->input('view_mode', 'today');

        if (! $r->filled('to') && ! $r->filled('from')) {
            $cacheKey = "market:analysis:v5:{$symbol}:{$interval}:{$viewMode}:{$limit}";

            return $this->ok(Cache::remember($cacheKey, 10, fn (): array => $this->buildAnalysisPayload($r, $symbol, $interval, $engine)));
        }

        return $this->ok($this->buildAnalysisPayload($r, $symbol, $interval, $engine));
    }

    private function buildAnalysisPayload(MarketSeriesRequest $r, string $symbol, string $interval, IndicatorEngine $engine): array
    {
        $rows = $this->marketRows($r, $symbol);
        $through = $rows->isEmpty() ? null : $rows->last()->open_time->addMinutes((int) $interval);
        $levelObservation = StrategyObservationRecord::query()->where('symbol', $symbol)
            ->when($through, fn ($query) => $query->where('decision_time', '<=', $through))
            ->latest('decision_time')->first(['analysis']);

        return [
            'candles' => $rows,
            'indicators' => $this->indicatorPayload($rows, $interval, $engine),
            'context' => MarketContextRecord::query()->where('symbol', $symbol)
                ->when($through, fn ($query) => $query->where('observed_at', '<=', $through))
                ->latest('observed_at')->first(),
            'level_interaction' => data_get($levelObservation, 'analysis.level_interaction'),
            'candlestick_patterns' => CandlestickPatternOccurrenceRecord::query()->where('symbol', $symbol)
                ->where('timeframe', $interval)
                ->when($rows->isNotEmpty(), fn ($query) => $query->where('detected_at', '>=', $rows->first()->open_time)
                    ->where('detected_at', '<=', $rows->last()->open_time->addMinutes((int) $interval)))
                ->latest('detected_at')->limit(100)->get(['id', 'pattern_name', 'pattern_type', 'direction', 'formation_time', 'detected_at', 'timeframe', 'version']),
            'signals' => SignalRecord::query()->where('symbol', $symbol)->where('timeframe', $interval)
                ->when($rows->isNotEmpty(), fn ($query) => $query->where('decision_time', '>=', $rows->first()->open_time)
                    ->where('decision_time', '<=', $through))
                ->latest('decision_time')->latest('id')->limit(10)
                ->get(['id', 'symbol', 'side', 'quality_class', 'decision_time', 'candle_close_time', 'entry_low', 'entry_high', 'stop_loss', 'take_profit_1', 'take_profit_2']),
        ];
    }

    private function marketRows(MarketSeriesRequest $request, string $symbol): Collection
    {
        $symbol = strtoupper($symbol);
        $interval = (string) $request->integer('interval', 15);
        $limit = min(1000, max(1, $request->integer('limit', 300)));

        $viewMode = $request->input('view_mode');
        if ($viewMode === null) {
            $viewMode = $limit > 300 ? '7d' : 'today';
        }

        $now = CarbonImmutable::now('UTC');

        if ($request->filled('from') && $request->filled('to')) {
            $fromTime = new DateTimeImmutable((string) $request->string('from'));
            $toTime = new DateTimeImmutable((string) $request->string('to'));
        } elseif ($viewMode === '7d') {
            if ($request->filled('to')) {
                $toTime = new DateTimeImmutable((string) $request->string('to'));
                $fromTime = $toTime->modify('-7 days');
            } else {
                $toTime = $now;
                $fromTime = $now->subDays(7)->startOfDay();
            }
        } else { // 'today'
            if ($request->filled('to')) {
                $toTime = new DateTimeImmutable((string) $request->string('to'));
                if ($toTime->format('H:i:s') === '00:00:00') {
                    $fromTime = $toTime->modify('-1 day')->setTime(0, 0, 0);
                } else {
                    $fromTime = $toTime->setTime(0, 0, 0);
                }
            } else {
                $toTime = $now;
                $fromTime = $now->startOfDay();
            }
        }

        $fetcher = function () use ($symbol, $interval, $limit, $fromTime, $toTime, $request): Collection {
            $query = CandleRecord::query()->where([
                'symbol' => $symbol,
                'interval' => $interval,
                'is_closed' => true,
            ])
                ->where('open_time', '>=', $fromTime);

            if ($request->filled('to')) {
                $query->where('open_time', '<', $toTime);
            } else {
                $query->where('open_time', '<=', $toTime);
            }

            return $query->latest('open_time')
                ->limit($limit)
                ->get(['id', 'exchange', 'category', 'symbol', 'interval', 'open_time', 'open', 'high', 'low', 'close', 'volume', 'turnover', 'is_closed', 'updated_at'])
                ->reverse()
                ->values();
        };

        if (! $request->filled('to') && ! $request->filled('from')) {
            $cacheKey = "market:candles:v4:{$symbol}:{$interval}:{$viewMode}:{$limit}:".$fromTime->format('Ymd');

            return Cache::remember($cacheKey, 5, $fetcher);
        }

        return $fetcher();
    }

    private function indicatorPayload(Collection $rows, string $interval, IndicatorEngine $engine): array
    {
        if ($rows->isEmpty()) {
            return ['times' => [], 'series' => null];
        }

        $i = Interval::parse($interval);
        $candles = $rows->map(fn ($x) => new Candle($x->exchange, $x->category, $x->symbol, $i, $x->open_time->getTimestamp() * 1000, $x->open, $x->high, $x->low, $x->close, $x->volume, $x->turnover, true))->all();
        $version = implode(':', [
            $rows->first()->id,
            $rows->last()->id,
            $rows->count(),
            $rows->max('updated_at')?->getTimestampMs(),
        ]);
        $series = Cache::remember('market:indicators:'.sha1($version), now()->addMinutes(5), function () use ($engine, $candles) {
            $calculated = $engine->calculate($candles);

            return json_decode(json_encode($calculated, JSON_THROW_ON_ERROR), true);
        });

        return ['times' => $rows->pluck('open_time'), 'series' => $series];
    }

    public function context(string $symbol): ResearchResource
    {
        return $this->ok(MarketContextRecord::query()->where('symbol', $this->symbol($symbol))->latest('observed_at')->first());
    }

    public function signals(Request $r): ResearchResource
    {
        $q = SignalRecord::query()->select([
            'signals.id', 'signals.symbol', 'signals.category', 'signals.timeframe', 'signals.side', 'signals.quality_class', 'signals.score',
            'signals.decision_time', 'signals.candle_close_time', 'signals.expires_at', 'signals.entry_low', 'signals.entry_high',
            'signals.stop_loss', 'signals.take_profit_1', 'signals.take_profit_2', 'signals.reasons', 'signals.risks',
            'signals.strategy_id', 'signals.strategy_version',
        ]);
        foreach (['symbol', 'side', 'quality_class', 'strategy_id', 'strategy_version'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->string($f));
            }
        }
        if ($r->filled('search')) {
            $q->where('symbol', 'like', '%'.strtoupper($r->string('search')).'%');
        }

        $paginator = $q->latest('decision_time')->latest('id')->paginate($this->perPage($r));
        $signalIds = collect($paginator->items())->pluck('id')->filter()->values();

        if ($signalIds->isNotEmpty()) {
            $riskDecisions = RiskDecisionRecord::query()
                ->whereIn('signal_id', $signalIds)
                ->latest('decided_at')
                ->get(['id', 'signal_id', 'allowed'])
                ->keyBy('signal_id');

            $paperStates = PaperSignalStateRecord::query()
                ->whereIn('signal_id', $signalIds)
                ->latest('updated_at')
                ->get(['id', 'signal_id', 'status'])
                ->keyBy('signal_id');

            $positions = VirtualPositionRecord::query()
                ->whereIn('signal_id', $signalIds)
                ->latest('opened_at')
                ->get(['id', 'signal_id'])
                ->keyBy('signal_id');

            $paginator->through(function (SignalRecord $sig) use ($riskDecisions, $paperStates, $positions): array {
                $arr = $sig->toArray();
                $arr['risk_approved'] = $riskDecisions->get($sig->id)?->allowed;
                $arr['paper_execution_status'] = $paperStates->get($sig->id)?->status;
                $arr['linked_position_id'] = $positions->get($sig->id)?->id;

                return $arr;
            });
        }

        return $this->ok($paginator);
    }

    public function signal(string $id): ResearchResource
    {
        return $this->ok(SignalRecord::query()->findOrFail($id));
    }

    public function positions(Request $r): ResearchResource
    {
        $q = VirtualPositionRecord::query();
        if ($r->filled('account')) {
            $q->where('paper_account_id', $r->string('account'));
        }
        if ($r->filled('symbol')) {
            $q->where('symbol', $this->symbol($r->string('symbol')));
        }
        if ($r->filled('search')) {
            $q->where('symbol', 'like', '%'.strtoupper($r->string('search')).'%');
        }

        $page = $q->latest('opened_at')->latest('id')->paginate($this->perPage($r));
        $symbols = collect($page->items())->pluck('symbol')->unique()->filter()->values();
        $latestPrices = $symbols->mapWithKeys(
            static fn (string $symbol) => [
                $symbol => Cache::remember("market:latest_close:5m:{$symbol}", 5, static function () use ($symbol): ?array {
                    $c = CandleRecord::query()->where('symbol', $symbol)->where('interval', '5')->where('is_closed', true)->latest('open_time')->first(['symbol', 'close']);

                    return $c !== null ? ['symbol' => (string) $c->symbol, 'close' => (string) $c->close] : null;
                }),
            ]
        );
        $page->through(function (VirtualPositionRecord $position) use ($latestPrices): array {
            $referenceAt = $position->closed_at ?? $position->last_processed_event_at ?? $position->opened_at;
            $openedAtTs = $position->opened_at instanceof \DateTimeInterface ? $position->opened_at->getTimestamp() : 0;
            $refTs = $referenceAt instanceof \DateTimeInterface ? $referenceAt->getTimestamp() : $openedAtTs;
            $maxHoldingTs = $position->maximum_holding_until instanceof \DateTimeInterface ? $position->maximum_holding_until->getTimestamp() : $refTs;
            $priceData = $latestPrices->get($position->symbol);
            $currentRefPrice = is_array($priceData) ? ($priceData['close'] ?? null) : ($priceData?->close ?? null);

            return array_merge($position->toArray(), [
                'current_reference_price' => $currentRefPrice,
                'duration_seconds' => max(0, $refTs - $openedAtTs),
                'maximum_holding_remaining_seconds' => max(0, $maxHoldingTs - $refTs),
            ]);
        });

        return $this->ok($page);
    }

    public function position(Request $request, string $id): ResearchResource
    {
        $p = VirtualPositionRecord::query()->findOrFail($id);

        return $this->ok([
            'mode' => 'FORWARD_PAPER',
            'position' => $p,
            'signal' => SignalRecord::query()->find($p->signal_id),
            'executions' => VirtualExecutionRecord::query()->where('position_id', $id)->latest('market_event_at')->latest('id')->paginate($this->perPage($request), ['id', 'type', 'quantity', 'price', 'commission', 'slippage', 'gross_pnl', 'market_event_at', 'price_source', 'ambiguous_execution'], 'executions_page'),
            'funding_events' => PositionFundingEventRecord::query()->where('position_id', $id)->latest('funding_at')->latest('id')->paginate($this->perPage($request), ['id', 'rate', 'amount', 'funding_at'], 'funding_page'),
            'journal' => JournalEventRecord::query()->where('position_id', $id)->latest('occurred_at')->latest('sequence')->paginate($this->perPage($request), ['sequence', 'id', 'event_type', 'occurred_at'], 'journal_page'),
        ]);
    }

    public function journalAccounts(): ResearchResource
    {
        return $this->ok(PaperAccountRecord::query()->orderBy('name')->limit(50)->get(['id', 'name', 'mode', 'strategy_id', 'status']));
    }

    public function journal(Request $r): ResearchResource
    {
        $validated = $r->validate([
            'account' => ['nullable', 'uuid'],
            'period_days' => ['nullable', 'integer', 'in:1,7,14,30'],
            'event_type' => ['nullable', 'string', 'max:40'],
        ]);
        $days = (int) ($validated['period_days'] ?? 7);
        $q = JournalEventRecord::query()->where('occurred_at', '>=', now('UTC')->subDays($days));
        if (! empty($validated['account'])) {
            $q->where('paper_account_id', $validated['account']);
        }
        if (! empty($validated['event_type'])) {
            $q->where('event_type', $validated['event_type']);
        }

        // Avoid a full-history COUNT(*) and eager statistics calculation on the journal request.
        return $this->ok($q->latest('occurred_at')->latest('sequence')->simplePaginate($this->perPage($r), ['sequence', 'id', 'paper_account_id', 'position_id', 'signal_id', 'event_type', 'occurred_at']));
    }

    public function statistics(Request $r, PerformanceStatisticsService $s): ResearchResource
    {
        $a = $r->string('account')->toString() ?: PaperAccountRecord::query()->value('id');
        if (! $a) {
            return $this->ok(['metrics' => null, 'equity' => [], 'equity_pagination' => ['current_page' => 1, 'last_page' => 1], 'breakdowns' => [], 'period_pnl' => []]);
        }

        $report = Cache::remember('paper:statistics:v2:'.$a, 60, function () use ($s, $a): array {
            $calculated = $s->calculate($a);

            return ['metrics' => $calculated->metrics, 'data_quality' => $calculated->dataQuality];
        });

        $equityPage = max(1, $r->integer('equity_page', 1));
        $rPage = max(1, $r->integer('r_page', 1));
        $periodPage = max(1, $r->integer('period_page', 1));

        $cacheKey = "paper:statistics:response:v4:{$a}:{$equityPage}:{$rPage}:{$periodPage}";

        $payload = Cache::remember($cacheKey, 30, function () use ($a, $report, $equityPage, $rPage, $periodPage): array {
            $closed = VirtualPositionRecord::query()->where('paper_account_id', $a)->whereNotNull('closed_at');
            $group = static fn (string $field) => (clone $closed)
                ->selectRaw($field.', COUNT(*) AS trades, SUM(net_pnl) AS net_pnl, SUM(CASE WHEN net_pnl > 0 THEN 1 ELSE 0 END) AS winning_trades')
                ->groupBy($field)->orderBy($field)->get();

            $equity = EquitySnapshotRecord::query()->where('paper_account_id', $a)
                ->latest('recorded_at')->latest('id')->paginate(100, ['recorded_at', 'equity', 'drawdown_percent'], 'equity_page', $equityPage);
            $rMultiples = (clone $closed)->whereNotNull('r_multiple')->latest('closed_at')
                ->paginate(10, ['id', 'r_multiple'], 'r_page', $rPage);

            return [
                'metrics' => $report['metrics'],
                'data_quality' => $report['data_quality'],
                'equity' => collect($equity->items())->reverse()->values(),
                'equity_pagination' => ['current_page' => $equity->currentPage(), 'last_page' => $equity->lastPage(), 'total' => $equity->total()],
                'breakdowns' => [
                    'symbols' => $group('symbol'), 'sides' => $group('side'), 'quality_classes' => $group('quality_class'),
                    'r_distribution' => collect($rMultiples->items())->pluck('r_multiple')->reverse()->values(),
                ],
                'r_pagination' => ['current_page' => $rMultiples->currentPage(), 'last_page' => $rMultiples->lastPage(), 'total' => $rMultiples->total()],
                'period_pnl' => $this->periodPnl((clone $closed)->oldest('closed_at')->cursor(['closed_at', 'net_pnl']), $periodPage),
            ];
        });

        return $this->ok($payload);
    }

    public function dailyPerformance(Request $request, DailyPerformanceService $service): ResearchResource
    {
        $account = $request->string('account')->toString() ?: PaperAccountRecord::query()->value('id');
        if (! $account) {
            return $this->ok(['summary' => null, 'days' => ['data' => [], 'meta' => ['current_page' => 1, 'per_page' => 10, 'total' => 0, 'last_page' => 1]]]);
        }
        $timezone = $request->string('timezone', 'UTC')->toString();
        abort_unless(in_array($timezone, timezone_identifiers_list(), true), 422, 'Invalid timezone.');
        $symbol = $request->string('symbol')->toString();
        if ($symbol !== '' && strtoupper($symbol) !== 'ALL') {
            $symbol = $this->symbol($symbol);
        } else {
            $symbol = null;
        }

        $page = max(1, $request->integer('page', 1));
        $perPage = $this->perPage($request);
        $cacheKey = "paper:daily_performance:v1:{$account}:{$timezone}:".($symbol ?? 'ALL').":{$page}:{$perPage}";

        return $this->ok(Cache::remember($cacheKey, 15, fn () => $service->report(
            $account,
            $timezone,
            $symbol,
            $page,
            $perPage,
        )));
    }

    /** Aggregate with a cursor, returning only ten recent periods to the client. */
    private function periodPnl(iterable $positions, int $page): array
    {
        $formats = ['daily' => 'Y-m-d', 'weekly' => 'o-\WW', 'monthly' => 'Y-m'];
        $buckets = ['daily' => [], 'weekly' => [], 'monthly' => []];
        foreach ($positions as $position) {
            foreach ($formats as $name => $format) {
                $key = $position->closed_at->format($format);
                $buckets[$name][$key] = ($buckets[$name][$key] ?? BigDecimal::zero())->plus($position->net_pnl);
            }
        }
        $result = [];
        foreach ($buckets as $name => $byPeriod) {
            ksort($byPeriod);
            $running = BigDecimal::zero();
            $items = [];
            foreach ($byPeriod as $period => $net) {
                $running = $running->plus($net);
                $items[] = ['period' => $period, 'net_pnl' => (string) $net, 'cumulative_net_pnl' => (string) $running];
            }
            $items = array_reverse($items); // newest first for consistent pagination
            $result[$name] = [
                'data' => array_reverse(array_slice($items, ($page - 1) * 10, 10)),
                'current_page' => $page, 'last_page' => max(1, (int) ceil(count($items) / 10)),
            ];
        }

        return $result;
    }

    public function risk(Request $r): ResearchResource
    {
        $a = $r->string('account')->toString() ?: PaperAccountRecord::query()->value('id');
        $decisions = $a ? RiskDecisionRecord::query()->where('paper_account_id', $a)
            ->latest('decided_at')->latest('id')->paginate($this->perPage($r), ['id', 'signal_id', 'allowed', 'reasons', 'decided_at']) : null;

        return $this->ok([
            'account' => $a ? PaperAccountRecord::query()->find($a) : null,
            'decisions' => $decisions ?? ['data' => [], 'current_page' => 1, 'last_page' => 1],
            'limits' => config('paper.portfolio'),
        ]);
    }

    public function backtests(Request $r): ResearchResource
    {
        return $this->ok(BacktestRunRecord::query()->latest('created_at')->latest('id')->paginate($this->perPage($r),
            ['id', 'type', 'status', 'from_at', 'to_at', 'symbols', 'strategy_version', 'progress', 'created_at', 'completed_at']));
    }

    public function backtest(Request $request, string $id): ResearchResource
    {
        $run = BacktestRunRecord::query()->findOrFail($id);

        return $this->ok([
            'run' => $run->only(['id', 'type', 'status', 'progress', 'strategy_version', 'from_at', 'to_at', 'metrics']),
            'segments' => BacktestSegmentRecord::query()->where('backtest_run_id', $id)
                ->orderBy('window_index')->paginate($this->perPage($request),
                    ['id', 'kind', 'window_index', 'from_at', 'to_at', 'status', 'metrics']),
        ]);
    }

    public function report(Request $request, string $id): ResearchResource
    {
        $run = BacktestRunRecord::query()->findOrFail($id);
        $trades = BacktestTradeRecord::query()->where('backtest_run_id', $id)
            ->latest('closed_at')->latest('id')->paginate($this->perPage($request),
                ['id', 'symbol', 'side', 'quality_class', 'opened_at', 'closed_at', 'entry_price', 'exit_price', 'net_pnl', 'r_multiple', 'close_reason', 'ambiguous_execution', 'funding_complete']);
        $equity = BacktestEquitySnapshotRecord::query()->where('backtest_run_id', $id)
            ->latest('recorded_at')->latest('id')->paginate(100, ['recorded_at', 'equity', 'drawdown_percent'], 'equity_page', max(1, $request->integer('equity_page', 1)));

        return $this->ok([
            'run' => ['id' => $run->id, 'status' => $run->status, 'metrics' => $run->metrics, 'progress' => $run->progress],
            // Report JSON can contain entire simulation traces: never include it in list responses.
            'reports' => BacktestReportRecord::query()->where('backtest_run_id', $id)->get(['id', 'kind']),
            'trades' => $trades,
            'equity' => collect($equity->items())->reverse()->values(),
            'equity_pagination' => ['current_page' => $equity->currentPage(), 'last_page' => $equity->lastPage(), 'total' => $equity->total()],
        ]);
    }

    public function backtestTrades(Request $request, string $id): ResearchResource
    {
        BacktestRunRecord::query()->findOrFail($id, ['id']);

        return $this->ok(BacktestTradeRecord::query()->where('backtest_run_id', $id)
            ->latest('closed_at')->latest('id')->paginate($this->perPage($request),
                ['id', 'symbol', 'side', 'quality_class', 'opened_at', 'closed_at', 'entry_price', 'exit_price', 'net_pnl', 'r_multiple', 'close_reason', 'ambiguous_execution', 'funding_complete']));
    }

    public function backtestEquity(Request $request, string $id, BacktestCurveService $curves): ResearchResource
    {
        $run = BacktestRunRecord::query()->findOrFail($id, ['id', 'status', 'updated_at']);
        $maximum = max(3, min(1000, $request->integer('max_points', 1000)));
        $calculate = fn (): array => $curves->get($id, $maximum);
        $curve = $run->status === 'COMPLETED'
            ? Cache::remember("backtest:curve:{$id}:{$run->updated_at->getTimestamp()}:{$maximum}", now()->addDay(), $calculate)
            : $calculate();

        return $this->ok($curve);
    }

    public function backtestOptimizations(Request $request, string $id): ResearchResource
    {
        BacktestRunRecord::query()->findOrFail($id, ['id']);

        return $this->ok(BacktestOptimizationRecord::query()->where('backtest_run_id', $id)
            ->latest('window_index')->latest('combination_index')->latest('id')
            ->paginate($this->perPage($request), ['id', 'window_index', 'combination_index', 'objective_score', 'selected', 'created_at']));
    }

    public function store(BacktestStoreRequest $r): JsonResponse
    {
        $v = $r->validated();
        RunBacktestJob::dispatch($v['symbols'], $v['from'].'T00:00:00Z', $v['to'].'T00:00:00Z', $v['mode'] === 'walk_forward');

        return response()->json([
            'schema_version' => '1.0.0',
            'research_mode' => true,
            'data' => ['status' => 'QUEUED'],
        ], 202);
    }
}
