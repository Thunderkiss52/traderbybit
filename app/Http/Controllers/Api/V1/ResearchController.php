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
use App\Exchanges\ExchangeRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\BacktestStoreRequest;
use App\Http\Requests\V1\MarketSeriesRequest;
use App\Http\Resources\V1\ResearchResource;
use App\Indicators\Application\IndicatorEngine;
use App\MarketData\Domain\Candle;
use App\MarketData\Domain\Interval;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\AccountUnblockService;
use App\PaperTrading\Application\DailyPerformanceService;
use App\PaperTrading\Application\DirectionalPerformanceService;
use App\PaperTrading\Application\PaperSizingSettings;
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
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ResearchController extends Controller
{
    private function notFound(string $resource, string $id): JsonResponse
    {
        return response()->json([
            'schema_version' => '1.0.0',
            'research_mode' => true,
            'error' => [
                'code' => 'not_found',
                'message' => "{$resource} {$id} not found",
            ],
        ], Response::HTTP_NOT_FOUND);
    }

    private function badRequest(string $code, string $message): JsonResponse
    {
        return response()->json([
            'schema_version' => '1.0.0',
            'research_mode' => true,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], Response::HTTP_BAD_REQUEST);
    }

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

    private function resolveExchange(Request $request, bool $allowAll = true): string
    {
        $exchange = $request->header('X-Exchange') ?: $request->string('exchange')->toString();
        if ($exchange === '') {
            return 'bybit';
        }
        if ($allowAll && $exchange === 'all') {
            return 'all';
        }
        $registered = app(ExchangeRegistry::class)->registeredIds();

        return in_array($exchange, $registered, true) ? $exchange : 'bybit';
    }

    private function resolveSymbol(Request $request): ?string
    {
        $symbol = $request->header('X-Symbol') ?: $request->string('symbol')->toString();
        $instrument = $request->header('X-Instrument') ?: $request->string('instrument')->toString();

        if ($symbol !== '' && $symbol !== 'all' && $symbol !== 'ALL') {
            $sym = strtoupper($symbol);
            if (in_array($sym, config('ui.symbols', ['BTCUSDT', 'ETHUSDT']), true)) {
                return $sym;
            }
        }

        if ($instrument === 'BTC' || strtoupper($instrument) === 'BTCUSDT') {
            return 'BTCUSDT';
        }
        if ($instrument === 'ETH' || strtoupper($instrument) === 'ETHUSDT') {
            return 'ETHUSDT';
        }

        return null;
    }

    public function dashboard(Request $request, PerformanceStatisticsService $statistics, StrategyRegistry $strategies): ResearchResource
    {
        $exchange = $this->resolveExchange($request, true);
        $symbol = $this->resolveSymbol($request);
        $requestedAccount = $request->string('account')->toString();

        $cacheAccountKey = $requestedAccount !== '' ? $requestedAccount : 'default';
        $cacheSymbolKey = $symbol ?? 'all';
        $cacheKey = "paper:dashboard:full_payload:{$exchange}:{$cacheSymbolKey}:{$cacheAccountKey}:v12";
        $payload = Cache::remember($cacheKey, 10, function () use ($statistics, $strategies, $exchange, $symbol, $cacheSymbolKey, $requestedAccount, $request): array {
            $primaryAccountsQuery = PaperAccountRecord::query()->where('is_primary', true);
            if ($exchange !== 'all') {
                $primaryAccountsQuery->where('exchange_id', $exchange);
            }
            $primaryAccounts = $primaryAccountsQuery->orderBy('exchange_id')->orderBy('name')->get();
            if ($primaryAccounts->isEmpty()) {
                $primaryAccounts = PaperAccountRecord::query()
                    ->when($exchange !== 'all', fn ($q) => $q->where('exchange_id', $exchange))
                    ->orderBy('exchange_id')->orderBy('name')->get();
            }

            // Aggregate metrics across all matching primary accounts
            $totalStarting = BigDecimal::zero();
            $totalRealized = BigDecimal::zero();
            $totalUnrealized = BigDecimal::zero();
            $totalReservedRisk = BigDecimal::zero();
            $totalCurrentEquity = BigDecimal::zero();

            foreach ($primaryAccounts as $acc) {
                $totalStarting = $totalStarting->plus(BigDecimal::of((string) ($acc->starting_balance ?? '0')));
                $totalRealized = $totalRealized->plus(BigDecimal::of((string) ($acc->realized_pnl ?? '0')));
                $totalUnrealized = $totalUnrealized->plus(BigDecimal::of((string) ($acc->unrealized_pnl ?? '0')));
                $totalReservedRisk = $totalReservedRisk->plus(BigDecimal::of((string) ($acc->reserved_risk ?? '0')));
                $totalCurrentEquity = $totalCurrentEquity->plus(BigDecimal::of((string) ($acc->current_equity ?? '0')));
            }

            $totalNet = $totalRealized->plus($totalUnrealized);
            $totalBalance = $totalStarting->plus($totalRealized);
            $totalReturn = $totalStarting->isZero()
                ? null
                : (string) $totalNet->multipliedBy(100)->dividedBy($totalStarting, 8, RoundingMode::HalfEven);
            $availableCapital = (string) $totalCurrentEquity->minus($totalReservedRisk);

            $openPositionsCount = VirtualPositionRecord::query()
                ->when($exchange !== 'all', fn ($q) => $q->where('exchange_id', $exchange))
                ->when($symbol !== null, fn ($q) => $q->where('symbol', $symbol))
                ->whereNull('closed_at')->count();

            $pendingSignalsCount = PaperSignalStateRecord::query()
                ->when($exchange !== 'all', fn ($q) => $q->where('exchange_id', $exchange))
                ->where('status', 'PENDING_ENTRY')->count();

            $aggregatePortfolio = [
                'scope' => 'aggregate',
                'is_aggregate' => true,
                'exchange_id' => $exchange,
                'accounts_count' => $primaryAccounts->count(),
                'starting_balance' => (string) $totalStarting,
                'current_balance' => (string) $totalBalance,
                'current_equity' => (string) $totalCurrentEquity,
                'total_net_pnl' => (string) $totalNet,
                'realized_pnl' => (string) $totalRealized,
                'unrealized_pnl' => (string) $totalUnrealized,
                'available_virtual_capital' => $availableCapital,
                'reserved_risk' => (string) $totalReservedRisk,
                'total_return_percent' => $totalReturn,
                'maximum_drawdown_percent' => (string) ($primaryAccounts->max('maximum_drawdown_percent') ?? '0'),
                'open_positions' => $openPositionsCount,
                'pending_entry_signals' => $pendingSignalsCount,
            ];

            $isAggregateView = ($requestedAccount === 'all' || ($requestedAccount === '' && $exchange === 'all'));
            $selectedAccount = null;

            if (! $isAggregateView) {
                if ($requestedAccount !== '') {
                    $selectedAccount = PaperAccountRecord::query()
                        ->when($exchange !== 'all', fn ($q) => $q->where('exchange_id', $exchange))
                        ->where('id', $requestedAccount)->first();
                }
                if ($selectedAccount === null && $requestedAccount === '' && $exchange !== 'all') {
                    $selectedAccount = PaperAccountRecord::query()->where('exchange_id', $exchange)->oldest()->first();
                }
            }

            $accountPortfolio = $selectedAccount ? array_merge($statistics->accountSummary($selectedAccount), [
                'scope' => 'account',
                'is_aggregate' => false,
                'account_id' => $selectedAccount->id,
                'account_name' => $selectedAccount->name,
                'strategy_id' => $selectedAccount->primary_strategy_id ?? $selectedAccount->strategy_id,
                'exchange_id' => $selectedAccount->exchange_id,
                'status' => $selectedAccount->status,
                'open_positions' => VirtualPositionRecord::query()
                    ->where('paper_account_id', $selectedAccount->id)
                    ->when($symbol !== null, fn ($q) => $q->where('symbol', $symbol))
                    ->whereNull('closed_at')->count(),
                'pending_entry_signals' => PaperSignalStateRecord::query()->where('paper_account_id', $selectedAccount->id)->where('status', 'PENDING_ENTRY')->count(),
            ]) : null;

            $activePortfolio = $isAggregateView ? $aggregatePortfolio : ($accountPortfolio ?? $aggregatePortfolio);

            $contextSymbols = $symbol !== null ? [$symbol] : config('ui.symbols');
            $contexts = collect($contextSymbols)->map(
                static fn (string $sym) => MarketContextRecord::query()
                    ->when($exchange !== 'all', fn ($q) => $q->where('exchange', $exchange))
                    ->where('symbol', $sym)->latest('observed_at')->first()
            )->filter()->values();

            $skipSymbols = $symbol !== null ? [$symbol] : config('ui.symbols');
            $skipReasons = Cache::remember("paper:dashboard:skip_reasons_24h:{$exchange}:{$cacheSymbolKey}", 60, function () use ($exchange, $skipSymbols): array {
                $recentSkips = SignalRecord::query()
                    ->when($exchange !== 'all', fn ($q) => $q->where('exchange', $exchange))
                    ->where('side', 'SKIP')
                    ->where('decision_time', '>=', now('UTC')->subDay())
                    ->whereIn('symbol', $skipSymbols)
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

            $decisionSymbols = $symbol !== null ? [$symbol] : config('ui.symbols');
            $recentDecisions = SignalRecord::query()
                ->when($exchange !== 'all', fn ($q) => $q->where('exchange', $exchange))
                ->whereIn('symbol', $decisionSymbols)
                ->latest('decision_time')->latest('id')->limit(8)
                ->get(['id', 'symbol', 'side', 'decision_time', 'reasons', 'indicator_snapshot'])
                ->map(static fn (SignalRecord $decision): array => [
                    'id' => (string) $decision->id,
                    'symbol' => (string) $decision->symbol,
                    'side' => (string) $decision->side,
                    'decision_time' => $decision->decision_time?->toISOString(),
                    'reasons' => is_array($decision->reasons) ? $decision->reasons : (array) $decision->reasons,
                    'level_interaction' => data_get($decision->indicator_snapshot, 'level_interaction'),
                ])->values()->all();

            $signals = SignalRecord::query()
                ->when($exchange !== 'all', fn ($q) => $q->where('exchange', $exchange))
                ->when($symbol !== null, fn ($q) => $q->where('symbol', $symbol))
                ->whereIn('side', ['BUY', 'SELL'])
                ->latest('decision_time')->latest('id')->limit(8)
                ->get(['id', 'symbol', 'side', 'quality_class', 'score', 'entry_low', 'entry_high', 'stop_loss', 'take_profit_1', 'take_profit_2', 'expires_at', 'reasons', 'decision_time'])
                ->map(fn ($s) => $s->toArray())->all();

            $positionsQuery = VirtualPositionRecord::query()->whereNull('closed_at');
            if ($exchange !== 'all') {
                $positionsQuery->where('exchange_id', $exchange);
            }
            if ($symbol !== null) {
                $positionsQuery->where('symbol', $symbol);
            }
            if ($selectedAccount !== null) {
                $positionsQuery->where('paper_account_id', $selectedAccount->id);
            }
            $positions = $positionsQuery->latest('opened_at')->limit(10)->get(['id', 'symbol', 'side', 'status', 'entry_price', 'net_pnl', 'unrealized_pnl'])->map(fn ($p) => $p->toArray())->all();

            return [
                'market' => $contexts->map(fn ($c) => $c->toArray())->values()->all(),
                'account' => $selectedAccount?->toArray(),
                'portfolio' => $activePortfolio,
                'aggregate_portfolio' => $aggregatePortfolio,
                'account_portfolio' => $accountPortfolio,
                'accounts' => $primaryAccounts->map(fn (PaperAccountRecord $a): array => [
                    'id' => (string) $a->id,
                    'name' => (string) $a->name,
                    'strategy_id' => (string) ($a->primary_strategy_id ?? $a->strategy_id ?? ''),
                    'exchange_id' => (string) $a->exchange_id,
                    'status' => (string) $a->status,
                    'starting_balance' => (string) $a->starting_balance,
                    'current_equity' => (string) $a->current_equity,
                ])->values()->all(),
                'selected_account_id' => $selectedAccount?->id,
                'is_aggregate' => $isAggregateView,
                'signals' => $signals,
                'recent_decisions' => $recentDecisions,
                'skip_reasons_24h' => $skipReasons,
                'filters' => [
                    'exchange' => $exchange,
                    'symbol' => $symbol ?? 'ALL',
                    'instrument' => $request->header('X-Instrument') ?: $request->string('instrument')->toString() ?: 'all',
                ],
                'strategy_observations_24h' => Cache::remember("paper:dashboard:strategy_observations_24h:{$exchange}:{$cacheSymbolKey}:v7", 60, fn () => $this->strategyObservations($strategies, $exchange, $symbol)),
                'strategy_portfolios' => collect($strategies->metadata())->map(function (array $strategy) use ($exchange): array {
                    $account = PaperAccountRecord::query()
                        ->where('primary_strategy_id', $strategy['id'])
                        ->when($exchange !== 'all', fn ($q) => $q->where('exchange_id', $exchange))
                        ->first()
                        ?? PaperAccountRecord::query()
                            ->where('strategy_id', $strategy['id'])
                            ->when($exchange !== 'all', fn ($q) => $q->where('exchange_id', $exchange))
                            ->whereIn('mode', ['FORWARD_PAPER', 'STRATEGY_FORWARD_PAPER', 'RESEARCH_FORWARD_PAPER'])
                            ->latest('created_at')
                            ->first();

                    if ($account === null && $strategy['id'] === 'baseline') {
                        $account = PaperAccountRecord::query()
                            ->where('exchange_id', $exchange)->where(fn ($q) => $q->where('strategy_id', 'baseline')->orWhereNull('strategy_id'))
                            ->whereIn('mode', ['FORWARD_PAPER', 'STRATEGY_FORWARD_PAPER'])
                            ->oldest('created_at')
                            ->first();
                    }

                    $latestObs = StrategyObservationRecord::query()
                        ->where('exchange', $exchange)
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
                    $paperStatus = $account ? ($account->status === 'ACTIVE' ? 'PAPER_EXECUTION_OPERATIONAL' : (string) $account->status) : 'ACCOUNT_NOT_PROVISIONED';

                    $openPositionsCount = $account ? VirtualPositionRecord::query()->where('paper_account_id', $account->id)->whereNull('closed_at')->count() : 0;
                    $closedPositionsCount = $account ? VirtualPositionRecord::query()->where('paper_account_id', $account->id)->whereNotNull('closed_at')->count() : 0;

                    return [
                        'id' => $account ? (string) $account->id : null,
                        'name' => $account ? (string) $account->name : 'Forward Paper '.$strategy['id'],
                        'mode' => $account ? (string) ($account->mode ?: 'STRATEGY_FORWARD_PAPER') : null,
                        'strategy_id' => $strategy['id'],
                        'strategy_version' => $account && $account->strategy_version ? (string) $account->strategy_version : (string) $strategy['version'],
                        'status' => $account ? (string) ($account->status ?: 'UNPROVISIONED') : 'UNPROVISIONED',
                        'account_status' => $accountStatus,
                        'strategy_status' => $strategyStatus,
                        'evaluation_status' => $evaluationStatus,
                        'ml_status' => $mlStatus,
                        'paper_execution_status' => $paperStatus,
                        'current_equity' => $account ? (string) $account->current_equity : null,
                        'realized_pnl' => $account ? (string) $account->realized_pnl : null,
                        'unrealized_pnl' => $account ? (string) $account->unrealized_pnl : null,
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
                'paper_execution_health' => (function () use ($exchange): array {
                    $accounts = PaperAccountRecord::query()->where('exchange_id', $exchange)->where('is_primary', true)->get();
                    $provisionedCount = $accounts->count();
                    $activeCount = $accounts->where('status', 'ACTIVE')->count();
                    $blockedCount = $accounts->where('status', 'BLOCKED')->count();
                    $execFailures = JournalEventRecord::query()->where('exchange_id', $exchange)->where('event_type', 'EXECUTION_FAILED')->where('occurred_at', '>=', now('UTC')->subDay())->count();
                    $lastJournalAt = JournalEventRecord::query()->where('exchange_id', $exchange)->max('occurred_at');
                    $latestEval = StrategyObservationRecord::query()->where('exchange', $exchange)->max('decision_time');
                    $latestCandle = CandleRecord::query()->where('exchange', $exchange)->where('interval', '5')->where('is_closed', true)->max('open_time');

                    $nowSeconds = now('UTC')->getTimestamp();
                    $evalAgeSeconds = $latestEval ? $nowSeconds - (new DateTimeImmutable((string) $latestEval))->getTimestamp() : null;
                    $candleAgeSeconds = $latestCandle ? $nowSeconds - (new DateTimeImmutable((string) $latestCandle))->getTimestamp() : null;

                    $configuredSymbols = (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
                    $activeStrategies = $accounts->where('status', 'ACTIVE')->pluck('primary_strategy_id')->filter()->values()->all();

                    $candleMaxes = CandleRecord::query()
                        ->where('exchange', $exchange)
                        ->where('interval', '5')
                        ->where('is_closed', true)
                        ->whereIn('symbol', $configuredSymbols)
                        ->groupBy('symbol')
                        ->selectRaw('symbol, MAX(open_time) as latest_open')
                        ->pluck('latest_open', 'symbol');

                    $evalMaxes = StrategyObservationRecord::query()
                        ->where('exchange', $exchange)
                        ->whereIn('strategy_id', $activeStrategies)
                        ->groupBy('strategy_id')
                        ->selectRaw('strategy_id, MAX(decision_time) as latest_decision')
                        ->pluck('latest_decision', 'strategy_id');

                    $stalledSymbols = [];
                    foreach ($configuredSymbols as $sym) {
                        $symLatest = $candleMaxes->get($sym);
                        if ($symLatest === null) {
                            $stalledSymbols[] = $sym;
                        } else {
                            $age = $nowSeconds - (new DateTimeImmutable((string) $symLatest))->getTimestamp();
                            if ($age > 2700) {
                                $stalledSymbols[] = $sym;
                            }
                        }
                    }

                    $stalledStrategies = [];
                    foreach ($activeStrategies as $stratId) {
                        $stratLatest = $evalMaxes->get($stratId);
                        if ($stratLatest === null) {
                            $stalledStrategies[] = $stratId;
                        } else {
                            $age = $nowSeconds - (new DateTimeImmutable((string) $stratLatest))->getTimestamp();
                            if ($age > 2700) {
                                $stalledStrategies[] = $stratId;
                            }
                        }
                    }

                    $status = 'HEALTHY';
                    $expectedStrategiesCount = count(PrimaryStrategies::ids($exchange));
                    if ($provisionedCount < $expectedStrategiesCount) {
                        $status = 'TOPOLOGY_INCOMPLETE';
                    } elseif ($blockedCount === $provisionedCount) {
                        $status = 'AWAITING_ACTIVATION';
                    } elseif ($latestEval === null) {
                        $status = 'AWAITING_FIRST_CYCLE';
                    } elseif (! empty($stalledSymbols)) {
                        $status = 'MARKET_DATA_STALLED';
                    } elseif (! empty($stalledStrategies)) {
                        $status = 'SCHEDULER_STALLED';
                    } elseif ($execFailures > 0) {
                        $status = 'DEGRADED';
                    }

                    return [
                        'execution_failures_24h' => $execFailures,
                        'last_journal_event_at' => $lastJournalAt,
                        'latest_strategy_evaluation_at' => $latestEval,
                        'latest_candle_at' => $latestCandle,
                        'evaluation_age_seconds' => $evalAgeSeconds,
                        'candle_age_seconds' => $candleAgeSeconds,
                        'provisioned_accounts' => $provisionedCount,
                        'active_accounts' => $activeCount,
                        'blocked_accounts' => $blockedCount,
                        'stalled_symbols' => $stalledSymbols,
                        'stalled_strategies' => $stalledStrategies,
                        'system_status' => $status,
                    ];
                })(),
                'positions' => $positions,
                'performance' => $selectedAccount
                    ? Cache::remember('paper:dashboard:performance:'.$selectedAccount->id, 60, fn () => $statistics->calculate($selectedAccount->id)->metrics)
                    : Cache::remember("paper:dashboard:performance:aggregate:{$exchange}", 60, fn () => $statistics->calculateForAccounts($primaryAccounts->pluck('id')->all(), $exchange)->metrics),
                'exchange_scope' => $exchange,
                'research_settings' => [
                    'starting_balance' => config('backtest.starting_balance'),
                    'strategy_version' => config('strategy.version'),
                    'execution_model_version' => config('paper.execution_version'),
                ],
            ];
        });

        return $this->ok($payload);
    }

    private function strategyObservations(StrategyRegistry $strategies, string $exchange = 'bybit', ?string $symbol = null): array
    {
        $since = now('UTC')->subDay();

        return collect($strategies->metadata())->map(function (array $strategy) use ($since, $exchange, $symbol): array {
            $query = StrategyObservationRecord::query();
            if ($exchange !== 'all') {
                $query->where('exchange', $exchange);
            }
            if ($symbol !== null) {
                $query->where('symbol', $symbol);
            }
            $query->where('strategy_id', $strategy['id'])
                ->where(fn ($q) => $q->where('configuration_hash', $strategy['configuration_hash'])->orWhereNull('configuration_hash'))
                ->where('decision_time', '>=', $since);
            $counts = (clone $query)->selectRaw('side, COUNT(*) AS aggregate')->groupBy('side')->pluck('aggregate', 'side');
            $latest = (clone $query)->latest('decision_time')->latest('id')->first(['symbol', 'side', 'decision_time', 'reasons', 'analysis']);
            $latestDto = $latest ? [
                'symbol' => (string) $latest->symbol,
                'side' => (string) $latest->side,
                'decision_time' => $latest->decision_time?->toISOString(),
                'reasons' => is_array($latest->reasons) ? $latest->reasons : (array) $latest->reasons,
                'analysis' => is_array($latest->analysis) ? $latest->analysis : (array) $latest->analysis,
            ] : null;
            $targetSymbols = $symbol !== null ? [$symbol] : (array) config('ui.symbols');
            $symbols = collect($targetSymbols)->map(function (string $sym) use ($query): array {
                $symbolQuery = (clone $query)->where('symbol', $sym);
                $symbolCounts = (clone $symbolQuery)->selectRaw('side, COUNT(*) AS aggregate')->groupBy('side')->pluck('aggregate', 'side');
                $symLatest = (clone $symbolQuery)->latest('decision_time')->latest('id')
                    ->first(['side', 'decision_time', 'reasons', 'analysis']);

                return [
                    'symbol' => $sym,
                    'buy' => (int) ($symbolCounts['BUY'] ?? 0),
                    'sell' => (int) ($symbolCounts['SELL'] ?? 0),
                    'skip' => (int) ($symbolCounts['SKIP'] ?? 0),
                    'total' => (int) $symbolCounts->sum(),
                    'latest' => $symLatest ? [
                        'side' => (string) $symLatest->side,
                        'decision_time' => $symLatest->decision_time?->toISOString(),
                        'reasons' => is_array($symLatest->reasons) ? $symLatest->reasons : (array) $symLatest->reasons,
                        'analysis' => is_array($symLatest->analysis) ? $symLatest->analysis : (array) $symLatest->analysis,
                    ] : null,
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
                'latest' => $latestDto,
                'symbols' => $symbols,
                'top_skip_reasons' => array_slice($skipReasons, 0, 3, true),
            ];
        })->values()->all();
    }

    public function candles(MarketSeriesRequest $r, string $symbol): ResearchResource
    {
        $exchange = $this->resolveExchange($r);

        return $this->ok($this->marketRows($r, $this->symbol($symbol), $exchange));
    }

    public function indicators(MarketSeriesRequest $r, string $symbol, IndicatorEngine $engine): ResearchResource
    {
        $exchange = $this->resolveExchange($r);
        $rows = $this->marketRows($r, $this->symbol($symbol), $exchange);

        return $this->ok($this->indicatorPayload($rows, (string) $r->integer('interval', 15), $engine, $exchange));
    }

    public function analysis(MarketSeriesRequest $r, string $symbol, IndicatorEngine $engine): ResearchResource
    {
        $exchange = $this->resolveExchange($r);
        $symbol = $this->symbol($symbol);
        $interval = (string) $r->integer('interval', 15);
        $limit = $r->integer('limit', 300);
        $viewMode = (string) $r->input('view_mode', 'today');

        if (! $r->filled('to') && ! $r->filled('from')) {
            $cacheKey = "market:analysis:v7:{$exchange}:{$symbol}:{$interval}:{$viewMode}:{$limit}";

            return $this->ok(Cache::remember($cacheKey, 10, fn (): array => $this->buildAnalysisPayload($r, $symbol, $interval, $engine, $exchange)));
        }

        return $this->ok($this->buildAnalysisPayload($r, $symbol, $interval, $engine, $exchange));
    }

    /**
     * Builds a payload that only contains scalars and arrays.
     *
     * The result is cached, and the cache stores refuse to unserialise PHP objects
     * (config/cache.php serializable_classes = false), so every nested model must be
     * converted to a plain array before it reaches the cache.
     */
    private function buildAnalysisPayload(MarketSeriesRequest $r, string $symbol, string $interval, IndicatorEngine $engine, string $exchange = 'bybit'): array
    {
        $rows = $this->marketRows($r, $symbol, $exchange);
        $through = $rows->isEmpty() ? null : $rows->last()->open_time->addMinutes((int) $interval);
        $levelObservation = StrategyObservationRecord::query()->where('exchange', $exchange)->where('symbol', $symbol)
            ->when($through, fn ($query) => $query->where('decision_time', '<=', $through))
            ->latest('decision_time')->first(['analysis']);
        $context = MarketContextRecord::query()->where('exchange', $exchange)->where('symbol', $symbol)
            ->when($through, fn ($query) => $query->where('observed_at', '<=', $through))
            ->latest('observed_at')->first();

        return [
            'candles' => $rows->map(static fn (CandleRecord $candle): array => $candle->toArray())->values()->all(),
            'indicators' => $this->indicatorPayload($rows, $interval, $engine, $exchange),
            'context' => $context?->toArray(),
            'level_interaction' => data_get($levelObservation, 'analysis.level_interaction'),
            'candlestick_patterns' => CandlestickPatternOccurrenceRecord::query()->where('exchange', $exchange)->where('symbol', $symbol)
                ->where('timeframe', $interval)
                ->when($rows->isNotEmpty(), fn ($query) => $query->where('detected_at', '>=', $rows->first()->open_time)
                    ->where('detected_at', '<=', $rows->last()->open_time->addMinutes((int) $interval)))
                ->latest('detected_at')->limit(100)->get(['id', 'pattern_name', 'pattern_type', 'direction', 'formation_time', 'detected_at', 'timeframe', 'version'])
                ->map(static fn (CandlestickPatternOccurrenceRecord $pattern): array => $pattern->toArray())->values()->all(),
            'signals' => SignalRecord::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('timeframe', $interval)
                ->when($rows->isNotEmpty(), fn ($query) => $query->where('decision_time', '>=', $rows->first()->open_time)
                    ->where('decision_time', '<=', $through))
                ->latest('decision_time')->latest('id')->limit(10)
                ->get(['id', 'symbol', 'side', 'quality_class', 'decision_time', 'candle_close_time', 'entry_low', 'entry_high', 'stop_loss', 'take_profit_1', 'take_profit_2'])
                ->map(static fn (SignalRecord $signal): array => $signal->toArray())->values()->all(),
        ];
    }

    private function marketRows(MarketSeriesRequest $request, string $symbol, string $exchange = 'bybit'): Collection
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

        $fetcher = function () use ($symbol, $interval, $limit, $fromTime, $toTime, $request, $exchange): Collection {
            $query = CandleRecord::query()->where([
                'exchange' => $exchange,
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
            $cacheKey = "market:candles:v6:{$exchange}:{$symbol}:{$interval}:{$viewMode}:{$limit}:".$fromTime->format('Ymd');
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return $this->hydrateCandles($cached);
            }

            $rows = $fetcher();

            Cache::put($cacheKey, $rows->map(static fn (CandleRecord $candle): array => $candle->getAttributes())->all(), 5);

            return $rows;
        }

        return $fetcher();
    }

    /**
     * Restores candle models from a cached payload of raw attributes.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function hydrateCandles(array $rows): Collection
    {
        return CandleRecord::query()->hydrate($rows);
    }

    private function indicatorPayload(Collection $rows, string $interval, IndicatorEngine $engine, string $exchange = 'bybit'): array
    {
        if ($rows->isEmpty()) {
            return ['times' => [], 'series' => null];
        }

        $i = Interval::parse($interval);
        $candles = $rows->map(fn ($x) => new Candle($x->exchange, $x->category, $x->symbol, $i, $x->open_time->getTimestamp() * 1000, $x->open, $x->high, $x->low, $x->close, $x->volume, $x->turnover, true))->all();
        $version = implode(':', [
            $exchange,
            $rows->first()->id,
            $rows->last()->id,
            $rows->count(),
            $rows->max('updated_at')?->getTimestampMs(),
        ]);
        $series = Cache::remember("market:indicators:{$exchange}:".sha1($version), now()->addMinutes(5), function () use ($engine, $candles) {
            $calculated = $engine->calculate($candles);

            return json_decode(json_encode($calculated, JSON_THROW_ON_ERROR), true);
        });

        return [
            'times' => $rows->pluck('open_time')
                ->map(static fn (mixed $openTime): ?string => $openTime instanceof CarbonInterface ? $openTime->toISOString() : null)
                ->values()->all(),
            'series' => $series,
        ];
    }

    public function context(Request $r, string $symbol): ResearchResource
    {
        $exchange = $this->resolveExchange($r);

        return $this->ok(MarketContextRecord::query()->where('exchange', $exchange)->where('symbol', $this->symbol($symbol))->latest('observed_at')->first());
    }

    public function signals(Request $r): ResearchResource
    {
        $q = SignalRecord::query()->select([
            'signals.id', 'signals.symbol', 'signals.category', 'signals.timeframe', 'signals.side', 'signals.quality_class', 'signals.score',
            'signals.decision_time', 'signals.candle_close_time', 'signals.expires_at', 'signals.entry_low', 'signals.entry_high',
            'signals.stop_loss', 'signals.take_profit_1', 'signals.take_profit_2', 'signals.reasons', 'signals.risks',
            'signals.strategy_id', 'signals.strategy_version',
        ]);
        $exchange = $this->resolveExchange($r, true);
        if ($exchange !== '' && $exchange !== 'all') {
            $q->where('exchange', $exchange);
        }
        $symbol = $this->resolveSymbol($r);
        if ($symbol !== null) {
            $q->where('symbol', $symbol);
        }
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
            $accountFilter = $r->filled('account') ? $r->string('account')->toString() : null;
            $exchangeFilter = $this->resolveExchange($r, true);

            $riskDecisions = RiskDecisionRecord::query()
                ->whereIn('signal_id', $signalIds)
                ->when($accountFilter, fn ($query) => $query->where('paper_account_id', $accountFilter))
                ->when(! $accountFilter && $exchangeFilter && $exchangeFilter !== 'all', fn ($query) => $query->where('exchange_id', $exchangeFilter))
                ->latest('decided_at')
                ->latest('id')
                ->get(['id', 'signal_id', 'allowed'])
                ->groupBy('signal_id')
                ->map(fn ($group) => $group->first());

            $paperStates = PaperSignalStateRecord::query()
                ->whereIn('signal_id', $signalIds)
                ->when($accountFilter, fn ($query) => $query->where('paper_account_id', $accountFilter))
                ->when(! $accountFilter && $exchangeFilter && $exchangeFilter !== 'all', fn ($query) => $query->where('exchange_id', $exchangeFilter))
                ->latest('updated_at')
                ->latest('id')
                ->get(['id', 'signal_id', 'status'])
                ->groupBy('signal_id')
                ->map(fn ($group) => $group->first());

            $positions = VirtualPositionRecord::query()
                ->whereIn('signal_id', $signalIds)
                ->when($accountFilter, fn ($query) => $query->where('paper_account_id', $accountFilter))
                ->when(! $accountFilter && $exchangeFilter && $exchangeFilter !== 'all', fn ($query) => $query->where('exchange_id', $exchangeFilter))
                ->latest('opened_at')
                ->latest('id')
                ->get(['id', 'signal_id'])
                ->groupBy('signal_id')
                ->map(fn ($group) => $group->first());

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

    public function signal(string $id): ResearchResource|JsonResponse
    {
        if (! Str::isUuid($id)) {
            return $this->notFound('Signal', $id);
        }

        $signal = SignalRecord::query()->find($id);
        if ($signal === null) {
            return $this->notFound('Signal', $id);
        }

        return $this->ok($signal);
    }

    public function positions(Request $r): ResearchResource
    {
        $q = VirtualPositionRecord::query();
        $exchange = $this->resolveExchange($r, true);
        if ($exchange !== '' && $exchange !== 'all') {
            $q->where('exchange_id', $exchange);
        }
        if ($r->filled('account')) {
            $q->where('paper_account_id', (string) $r->string('account'));
        }
        $symbol = $this->resolveSymbol($r);
        if ($symbol !== null) {
            $q->where('symbol', $symbol);
        } elseif ($r->filled('symbol')) {
            $q->where('symbol', $this->symbol((string) $r->string('symbol')));
        }
        if ($r->filled('search')) {
            $q->where('symbol', 'like', '%'.strtoupper((string) $r->string('search')).'%');
        }

        $page = $q->latest('opened_at')->latest('id')->paginate($this->perPage($r));
        $symbols = collect($page->items())->pluck('symbol')->unique()->filter()->values();
        $latestPrices = $symbols->mapWithKeys(
            static fn (string $sym) => [
                $sym => Cache::remember('market:latest_close:5m:'.($exchange === 'all' ? 'any' : $exchange).":{$sym}", 5, static function () use ($sym, $exchange): ?array {
                    $cQuery = CandleRecord::query()->where('symbol', $sym)->where('interval', '5')->where('is_closed', true);
                    if ($exchange !== 'all') {
                        $cQuery->where('exchange', $exchange);
                    }
                    $c = $cQuery->latest('open_time')->first(['symbol', 'close']);

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

    public function position(Request $request, string $id): ResearchResource|JsonResponse
    {
        if (! Str::isUuid($id)) {
            return $this->notFound('Position', $id);
        }

        $p = VirtualPositionRecord::query()->find($id);
        if ($p === null) {
            return $this->notFound('Position', $id);
        }

        return $this->ok([
            'mode' => 'FORWARD_PAPER',
            'position' => $p,
            'signal' => SignalRecord::query()->find($p->signal_id),
            'executions' => VirtualExecutionRecord::query()->where('position_id', $id)->latest('market_event_at')->latest('id')->paginate($this->perPage($request), ['id', 'type', 'quantity', 'price', 'commission', 'slippage', 'gross_pnl', 'market_event_at', 'price_source', 'ambiguous_execution'], 'executions_page'),
            'funding_events' => PositionFundingEventRecord::query()->where('position_id', $id)->latest('funding_at')->latest('id')->paginate($this->perPage($request), ['id', 'rate', 'amount', 'funding_at'], 'funding_page'),
            'journal' => JournalEventRecord::query()->where('position_id', $id)->latest('occurred_at')->latest('sequence')->paginate($this->perPage($request), ['sequence', 'id', 'event_type', 'occurred_at'], 'journal_page'),
        ]);
    }

    public function journalAccounts(Request $r): ResearchResource
    {
        $exchange = $this->resolveExchange($r, true);

        return $this->ok(PaperAccountRecord::query()
            ->when($exchange !== 'all', fn ($q) => $q->where('exchange_id', $exchange))
            ->orderBy('exchange_id')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'mode', 'strategy_id', 'primary_strategy_id', 'exchange_id', 'status']));
    }

    public function paperAccounts(Request $r): ResearchResource|JsonResponse
    {
        $exchange = $r->header('X-Exchange') ?: $r->string('exchange')->toString();
        $query = PaperAccountRecord::query();
        $registered = app(ExchangeRegistry::class)->registeredIds();
        if ($exchange !== '' && $exchange !== 'all') {
            if (! in_array($exchange, $registered, true)) {
                return $this->badRequest('unsupported_exchange', "Exchange '{$exchange}' is not registered.");
            }
            $query->where('exchange_id', $exchange);
        }

        return $this->ok($query->orderBy('exchange_id')->orderBy('name')->get([
            'id', 'name', 'mode', 'strategy_id', 'primary_strategy_id', 'exchange_id',
            'status', 'starting_balance', 'current_equity', 'realized_pnl', 'unrealized_pnl',
            'reserved_risk', 'maximum_drawdown_percent', 'current_drawdown_percent', 'is_primary',
        ]));
    }

    public function unblockPaperAccount(string $id, Request $request, AccountUnblockService $unblockService): ResearchResource|JsonResponse
    {
        $account = PaperAccountRecord::query()->find($id);
        if ($account === null) {
            return $this->notFound('account_not_found', "Account [{$id}] was not found.");
        }

        $reason = $request->string('reason')->toString() ?: null;
        $operator = $request->string('operator')->toString() ?: 'operator';

        $updated = $unblockService->unblock($id, $reason, $operator);

        return $this->ok([
            'account' => $updated,
            'message' => "Account {$account->name} successfully unblocked and returned to active paper execution.",
        ]);
    }

    public function getAccountSizing(string $id): ResearchResource|JsonResponse
    {
        $account = PaperAccountRecord::query()->find($id);
        if ($account === null) {
            return $this->notFound('account_not_found', "Account [{$id}] was not found.");
        }

        return $this->ok([
            'account_id' => $account->id,
            'name' => $account->name,
            'exchange_id' => $account->exchange_id,
            'strategy_id' => $account->strategy_id,
            'configured_sizing_settings' => $account->sizing_settings,
            'effective_sizing_settings' => PaperSizingSettings::effectiveForAccount($account->sizing_settings),
            'default_sizing_settings' => PaperSizingSettings::defaultSettings(),
        ]);
    }

    public function updateAccountSizing(string $id, Request $request): ResearchResource|JsonResponse
    {
        $account = PaperAccountRecord::query()->find($id);
        if ($account === null) {
            return $this->notFound('account_not_found', "Account [{$id}] was not found.");
        }

        $payload = $request->all();
        if (empty($payload)) {
            $account->update(['sizing_settings' => null]);
        } else {
            try {
                $normalized = PaperSizingSettings::normalize($payload);
                $account->update(['sizing_settings' => $normalized]);
            } catch (\DomainException $e) {
                return $this->badRequest('invalid_sizing_settings', $e->getMessage());
            }
        }

        $account->refresh();

        return $this->ok([
            'account_id' => $account->id,
            'configured_sizing_settings' => $account->sizing_settings,
            'effective_sizing_settings' => PaperSizingSettings::effectiveForAccount($account->sizing_settings),
        ]);
    }

    public function getEffectiveAccountSizing(string $id, Request $request): ResearchResource|JsonResponse
    {
        $account = PaperAccountRecord::query()->find($id);
        if ($account === null) {
            return $this->notFound('account_not_found', "Account [{$id}] was not found.");
        }

        $symbol = $request->query('symbol', 'BTCUSDT');
        $effectiveSettings = PaperSizingSettings::effectiveForAccount($account->sizing_settings);
        $costModel = config("exchanges.{$account->exchange_id}.cost_model", []);

        return $this->ok([
            'account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'symbol' => $symbol,
            'effective_sizing_settings' => $effectiveSettings,
            'current_equity' => (string) $account->current_equity,
            'exchange_cost_model' => $costModel,
        ]);
    }

    public function journal(Request $r): ResearchResource|JsonResponse
    {
        $validated = $r->validate([
            'account' => ['nullable', 'uuid'],
            'period_days' => ['nullable', 'integer', 'in:1,7,14,30'],
            'event_type' => ['nullable', 'string', 'max:40'],
        ]);
        $days = (int) ($validated['period_days'] ?? 7);
        $q = JournalEventRecord::query()->where('occurred_at', '>=', now('UTC')->subDays($days));
        $exchange = $r->header('X-Exchange') ?: $r->string('exchange')->toString();
        $registered = app(ExchangeRegistry::class)->registeredIds();
        if ($exchange !== '' && $exchange !== 'all') {
            if (! in_array($exchange, $registered, true)) {
                return $this->badRequest('unsupported_exchange', "Exchange '{$exchange}' is not registered.");
            }
            $q->where('exchange_id', $exchange);
        }
        if (! empty($validated['account'])) {
            $q->where('paper_account_id', $validated['account']);
        }
        if (! empty($validated['event_type'])) {
            $q->where('event_type', $validated['event_type']);
        }

        // Avoid a full-history COUNT(*) and eager statistics calculation on the journal request.
        return $this->ok($q->latest('occurred_at')->latest('sequence')->simplePaginate($this->perPage($r), ['sequence', 'id', 'paper_account_id', 'position_id', 'signal_id', 'event_type', 'payload', 'occurred_at']));
    }

    public function statistics(Request $r, PerformanceStatisticsService $s): ResearchResource
    {
        $exchange = $this->resolveExchange($r, true);
        $symbol = $this->resolveSymbol($r);
        $accounts = PaperAccountRecord::query()
            ->when($exchange !== 'all', fn ($q) => $q->where('exchange_id', $exchange))
            ->orderBy('exchange_id')->orderBy('name')->get();
        $requested = $r->string('account')->toString();
        $account = null;
        if ($requested !== '') {
            $account = $accounts->firstWhere('id', $requested);
        }
        if ($account === null) {
            $account = $accounts->first();
        }
        $a = $account?->id;
        if (! $a) {
            return $this->ok([
                'account' => null,
                'accounts' => [],
                'metrics' => null,
                'equity' => [],
                'equity_pagination' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100],
                'breakdowns' => [
                    'symbols' => [],
                    'sides' => [],
                    'quality_classes' => [],
                    'r_distribution' => [],
                ],
                'r_pagination' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 10],
                'period_pnl' => [
                    'daily' => ['data' => [], 'current_page' => 1, 'last_page' => 1, 'total' => 0],
                    'weekly' => ['data' => [], 'current_page' => 1, 'last_page' => 1, 'total' => 0],
                    'monthly' => ['data' => [], 'current_page' => 1, 'last_page' => 1, 'total' => 0],
                ],
            ]);
        }

        $report = Cache::remember('paper:statistics:report:v5:'.$a, 60, function () use ($s, $a): array {
            $calculated = $s->calculate($a);

            return [
                'metrics' => is_array($calculated->metrics) ? $calculated->metrics : (array) $calculated->metrics,
                'data_quality' => is_array($calculated->dataQuality) ? $calculated->dataQuality : (array) $calculated->dataQuality,
            ];
        });

        $equityPage = max(1, $r->integer('equity_page', 1));
        $rPage = max(1, $r->integer('r_page', 1));
        $periodPage = max(1, $r->integer('period_page', 1));

        $cacheSymbol = $symbol ?? 'all';
        $cacheKey = "paper:statistics:response:v6:{$a}:{$equityPage}:{$rPage}:{$periodPage}:{$cacheSymbol}";

        $payload = Cache::remember($cacheKey, 30, function () use ($a, $symbol, $report, $equityPage, $rPage, $periodPage): array {
            $closed = VirtualPositionRecord::query()->where('paper_account_id', $a)->whereNotNull('closed_at');
            if ($symbol !== null) {
                $closed->where('symbol', $symbol);
            }
            $group = static fn (string $field): array => (clone $closed)
                ->selectRaw($field.', COUNT(*) AS trades, SUM(net_pnl) AS net_pnl, SUM(CASE WHEN net_pnl > 0 THEN 1 ELSE 0 END) AS winning_trades')
                ->groupBy($field)->orderBy($field)->get()
                ->map(static fn ($record): array => [
                    $field => (string) ($record->{$field} ?? ''),
                    'trades' => (int) ($record->trades ?? 0),
                    'net_pnl' => (string) ($record->net_pnl ?? '0'),
                    'winning_trades' => (int) ($record->winning_trades ?? 0),
                ])->values()->all();

            $equity = EquitySnapshotRecord::query()->performance()->where('paper_account_id', $a)
                ->latest('recorded_at')->latest('id')->paginate(100, ['recorded_at', 'equity', 'drawdown_percent'], 'equity_page', $equityPage);
            $rMultiples = (clone $closed)->whereNotNull('r_multiple')->latest('closed_at')
                ->paginate(10, ['id', 'r_multiple'], 'r_page', $rPage);

            $equityItems = array_values(array_map(static fn ($item): array => [
                'recorded_at' => $item instanceof EquitySnapshotRecord
                    ? ($item->recorded_at ? $item->recorded_at->toISOString() : '')
                    : (string) ($item['recorded_at'] ?? ''),
                'equity' => $item instanceof EquitySnapshotRecord
                    ? (string) $item->equity
                    : (string) ($item['equity'] ?? '0'),
                'drawdown_percent' => $item instanceof EquitySnapshotRecord
                    ? (string) $item->drawdown_percent
                    : (string) ($item['drawdown_percent'] ?? '0'),
            ], array_reverse($equity->items())));

            $rDistribution = array_values(array_map(
                static fn ($item): ?string => $item instanceof VirtualPositionRecord
                    ? ($item->r_multiple !== null ? (string) $item->r_multiple : null)
                    : (is_array($item) && isset($item['r_multiple']) ? (string) $item['r_multiple'] : null),
                array_reverse($rMultiples->items())
            ));

            return [
                'metrics' => $report['metrics'],
                'data_quality' => $report['data_quality'],
                'equity' => $equityItems,
                'equity_pagination' => [
                    'current_page' => (int) $equity->currentPage(),
                    'last_page' => (int) $equity->lastPage(),
                    'total' => (int) $equity->total(),
                    'per_page' => (int) $equity->perPage(),
                ],
                'breakdowns' => [
                    'symbols' => $group('symbol'),
                    'sides' => $group('side'),
                    'quality_classes' => $group('quality_class'),
                    'r_distribution' => $rDistribution,
                ],
                'r_pagination' => [
                    'current_page' => (int) $rMultiples->currentPage(),
                    'last_page' => (int) $rMultiples->lastPage(),
                    'total' => (int) $rMultiples->total(),
                    'per_page' => (int) $rMultiples->perPage(),
                ],
                'period_pnl' => $this->periodPnl((clone $closed)->oldest('closed_at')->cursor(['closed_at', 'net_pnl']), $periodPage),
            ];
        });

        $payload['filters'] = [
            'exchange' => $exchange,
            'symbol' => $symbol ?? 'ALL',
        ];

        $payload['account'] = [
            'id' => $account->id,
            'name' => $account->name,
            'exchange_id' => $account->exchange_id,
            'strategy_id' => $account->primary_strategy_id ?? $account->strategy_id,
            'status' => $account->status,
            'starting_balance' => (string) $account->starting_balance,
            'current_equity' => (string) $account->current_equity,
            'realized_pnl' => (string) $account->realized_pnl,
            'unrealized_pnl' => (string) $account->unrealized_pnl,
        ];
        $payload['accounts'] = $accounts->map(fn ($acc) => [
            'id' => (string) $acc->id,
            'name' => (string) $acc->name,
            'exchange_id' => (string) $acc->exchange_id,
            'strategy_id' => (string) ($acc->primary_strategy_id ?? $acc->strategy_id ?? ''),
            'status' => (string) $acc->status,
        ])->values()->all();

        return $this->ok($payload);
    }

    public function dailyPerformance(Request $request, DailyPerformanceService $service): ResearchResource
    {
        $exchange = $request->header('X-Exchange') ?: $request->string('exchange', 'bybit')->toString();
        $registered = app(ExchangeRegistry::class)->registeredIds();
        if (! in_array($exchange, $registered, true)) {
            $exchange = 'bybit';
        }
        $account = $request->string('account')->toString() ?: PaperAccountRecord::query()->where('exchange_id', $exchange)->value('id');
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
                $closedAt = $position instanceof VirtualPositionRecord
                    ? $position->closed_at
                    : CarbonImmutable::parse($position['closed_at']);
                $netPnl = $position instanceof VirtualPositionRecord
                    ? $position->net_pnl
                    : $position['net_pnl'];
                $key = $closedAt->format($format);
                $buckets[$name][$key] = ($buckets[$name][$key] ?? BigDecimal::zero())->plus($netPnl);
            }
        }
        $result = [];
        foreach ($buckets as $name => $byPeriod) {
            ksort($byPeriod);
            $running = BigDecimal::zero();
            $items = [];
            foreach ($byPeriod as $period => $net) {
                $running = $running->plus($net);
                $items[] = [
                    'period' => (string) $period,
                    'net_pnl' => (string) $net,
                    'cumulative_net_pnl' => (string) $running,
                ];
            }
            $items = array_reverse($items); // newest first for consistent pagination
            $slice = array_slice($items, ($page - 1) * 10, 10);
            $result[$name] = [
                'data' => array_values(array_reverse($slice)),
                'current_page' => $page,
                'last_page' => max(1, (int) ceil(count($items) / 10)),
                'total' => count($items),
            ];
        }

        return $result;
    }

    public function risk(Request $r, DirectionalPerformanceService $directional): ResearchResource
    {
        $exchange = $this->resolveExchange($r, true);
        $symbol = $this->resolveSymbol($r);
        $accounts = PaperAccountRecord::query()
            ->when($exchange !== 'all', fn ($q) => $q->where('exchange_id', $exchange))
            ->orderBy('exchange_id')->orderBy('name')->get();
        $requested = $r->string('account')->toString();
        $account = null;
        if ($requested !== '' && $requested !== 'all') {
            $account = $accounts->firstWhere('id', $requested);
        }
        if ($account === null && $requested !== 'all' && $exchange !== 'all') {
            $account = $accounts->first();
        }

        $query = RiskDecisionRecord::query();
        if ($exchange !== 'all') {
            $query->where('exchange_id', $exchange);
        }
        if ($account !== null) {
            $query->where('paper_account_id', $account->id);
        }
        if ($symbol !== null) {
            $query->whereHas('signal', fn ($q) => $q->where('symbol', $symbol));
        }

        // Apply filters: quality, risk, allowed
        if ($r->has('quality') && $r->string('quality')->toString() !== '' && $r->string('quality')->toString() !== 'all') {
            $q = strtoupper($r->string('quality')->toString());
            if ($q === 'UNKNOWN') {
                $query->where(function (Builder $b): void {
                    $b->whereNull('signal_quality')->orWhere('signal_quality', 'UNKNOWN');
                });
            } else {
                $query->where('signal_quality', $q);
            }
        }

        if ($r->has('risk') && $r->string('risk')->toString() !== '' && $r->string('risk')->toString() !== 'all') {
            $riskFilter = strtoupper($r->string('risk')->toString());
            if ($riskFilter === 'UNKNOWN') {
                $query->where(function (Builder $b): void {
                    $b->whereNull('trade_risk')->orWhere('trade_risk', 'UNKNOWN');
                });
            } else {
                $query->where('trade_risk', $riskFilter);
            }
        }

        if ($r->has('allowed') && $r->string('allowed')->toString() !== '' && $r->string('allowed')->toString() !== 'all') {
            $allowedFilter = filter_var($r->string('allowed')->toString(), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($allowedFilter !== null) {
                $query->where('allowed', $allowedFilter);
            }
        }

        $decisions = $query->latest('decided_at')->latest('id')
            ->paginate($this->perPage($r), ['id', 'paper_account_id', 'signal_id', 'signal_quality', 'trade_risk', 'allowed', 'reasons', 'snapshot', 'decided_at']);

        // Directional performance analysis
        $criteria = [
            'exchange_id' => $exchange !== 'all' ? $exchange : null,
            'account_id' => $account?->id,
            'symbol' => $symbol,
        ];
        $directionalAnalysis = $directional->analyze($criteria);

        // Top rejection reasons aggregated
        $rejectionStatsQuery = RiskDecisionRecord::query()
            ->when($exchange !== 'all', fn ($q) => $q->where('exchange_id', $exchange))
            ->when($symbol !== null, fn ($q) => $q->whereHas('signal', fn ($s) => $s->where('symbol', $symbol)))
            ->where('allowed', false);
        if ($account !== null) {
            $rejectionStatsQuery->where('paper_account_id', $account->id);
        }
        $rejectionDecisions = $rejectionStatsQuery->latest('decided_at')->limit(500)->get(['reasons']);
        $rejectionCounts = [];
        foreach ($rejectionDecisions as $rd) {
            $reasons = is_array($rd->reasons) ? $rd->reasons : (json_decode((string) $rd->reasons, true) ?? []);
            foreach ($reasons as $reason) {
                $rejectionCounts[$reason] = ($rejectionCounts[$reason] ?? 0) + 1;
            }
        }
        arsort($rejectionCounts);

        $gatePolicyConfig = array_merge([
            'enabled' => true,
            'enforce_expectancy' => true,
            'min_sample_size' => 30,
            'max_account_drawdown_percent' => '15.0',
            'matrix' => [
                'HIGH_LOW' => 'ELIGIBLE',
                'HIGH_MEDIUM' => 'CONDITIONALLY_ELIGIBLE',
                'HIGH_HIGH' => 'REJECTED',
                'MEDIUM_LOW' => 'CONDITIONALLY_ELIGIBLE',
                'MEDIUM_MEDIUM' => 'CONDITIONALLY_ELIGIBLE',
                'MEDIUM_HIGH' => 'REJECTED',
                'LOW_ANY' => 'OBSERVATION_ONLY',
                'UNKNOWN_ANY' => 'REJECTED',
            ],
        ], config('paper.quality_risk_gate') ?? []);

        return $this->ok([
            'account' => $account,
            'filters' => [
                'exchange' => $exchange,
                'symbol' => $symbol ?? 'ALL',
            ],
            'accounts' => $accounts->map(fn ($acc) => [
                'id' => (string) $acc->id,
                'name' => (string) $acc->name,
                'exchange_id' => (string) $acc->exchange_id,
                'strategy_id' => (string) ($acc->primary_strategy_id ?? $acc->strategy_id ?? ''),
                'status' => (string) $acc->status,
                'current_equity' => (string) $acc->current_equity,
                'reserved_risk' => (string) $acc->reserved_risk,
                'current_drawdown_percent' => (string) $acc->current_drawdown_percent,
            ])->values()->all(),
            'decisions' => $decisions,
            'gate_policy' => $gatePolicyConfig,
            'directional_performance' => $directionalAnalysis,
            'rejection_reasons' => $rejectionCounts,
            'limits' => config('paper.portfolio'),
        ]);
    }

    public function backtests(Request $r): ResearchResource
    {
        return $this->ok(BacktestRunRecord::query()->latest('created_at')->latest('id')->paginate($this->perPage($r),
            ['id', 'type', 'status', 'from_at', 'to_at', 'symbols', 'strategy_version', 'progress', 'created_at', 'completed_at']));
    }

    public function backtest(Request $request, string $id): ResearchResource|JsonResponse
    {
        if (! Str::isUuid($id)) {
            return $this->notFound('Backtest', $id);
        }

        $run = BacktestRunRecord::query()->find($id);
        if ($run === null) {
            return $this->notFound('Backtest', $id);
        }

        return $this->ok([
            'run' => $run->only(['id', 'type', 'status', 'progress', 'strategy_version', 'from_at', 'to_at', 'metrics']),
            'segments' => BacktestSegmentRecord::query()->where('backtest_run_id', $id)
                ->orderBy('window_index')->paginate($this->perPage($request),
                    ['id', 'kind', 'window_index', 'from_at', 'to_at', 'status', 'metrics']),
        ]);
    }

    public function report(Request $request, string $id): ResearchResource|JsonResponse
    {
        if (! Str::isUuid($id)) {
            return $this->notFound('Backtest', $id);
        }

        $run = BacktestRunRecord::query()->find($id);
        if ($run === null) {
            return $this->notFound('Backtest', $id);
        }

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

    public function backtestTrades(Request $request, string $id): ResearchResource|JsonResponse
    {
        if (! Str::isUuid($id)) {
            return $this->notFound('Backtest', $id);
        }

        $run = BacktestRunRecord::query()->find($id);
        if ($run === null) {
            return $this->notFound('Backtest', $id);
        }

        return $this->ok(BacktestTradeRecord::query()->where('backtest_run_id', $id)
            ->latest('closed_at')->latest('id')->paginate($this->perPage($request),
                ['id', 'symbol', 'side', 'quality_class', 'opened_at', 'closed_at', 'entry_price', 'exit_price', 'net_pnl', 'r_multiple', 'close_reason', 'ambiguous_execution', 'funding_complete']));
    }

    public function backtestEquity(Request $request, string $id, BacktestCurveService $curves): ResearchResource|JsonResponse
    {
        if (! Str::isUuid($id)) {
            return $this->notFound('Backtest', $id);
        }

        $run = BacktestRunRecord::query()->find($id);
        if ($run === null) {
            return $this->notFound('Backtest', $id);
        }

        $maximum = max(3, min(1000, $request->integer('max_points', 1000)));
        $calculate = fn (): array => $curves->get($id, $maximum);
        $curve = $run->status === 'COMPLETED'
            ? Cache::remember("backtest:curve:{$id}:{$run->updated_at->getTimestamp()}:{$maximum}", now()->addDay(), $calculate)
            : $calculate();

        return $this->ok($curve);
    }

    public function backtestOptimizations(Request $request, string $id): ResearchResource|JsonResponse
    {
        if (! Str::isUuid($id)) {
            return $this->notFound('Backtest', $id);
        }

        $run = BacktestRunRecord::query()->find($id);
        if ($run === null) {
            return $this->notFound('Backtest', $id);
        }

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
