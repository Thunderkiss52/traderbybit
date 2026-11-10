<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PatternDiscovery\Application\AsOfMarketContextService;
use App\PatternDiscovery\Application\MarketFeatureExtractor;
use App\PatternDiscovery\Application\PatternMatcher;
use App\PatternDiscovery\Infrastructure\Persistence\CandlestickPatternOccurrenceRecord;
use App\PatternDiscovery\Infrastructure\Persistence\PatternMatchRecord;
use App\PatternDiscovery\Infrastructure\Persistence\PatternRecord;
use App\PatternDiscovery\Infrastructure\Persistence\PatternRunRecord;
use App\PatternDiscovery\Jobs\DiscoverPatternsJob;
use App\Strategy\Infrastructure\Persistence\LevelInteractionForecastRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class PatternResearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'direction' => 'sometimes|in:BUY,SELL', 'status' => 'sometimes|in:DISCOVERED,VALIDATING,VALIDATED,REJECTED,STALE,INCONCLUSIVE', 'symbol' => 'sometimes|in:BTCUSDT,ETHUSDT']);
        $configurationHash = hash('sha256', json_encode((array) config('patterns'), JSON_THROW_ON_ERROR));
        $query = PatternRecord::query()->join('research_pattern_runs as runs', 'runs.id', '=', 'research_patterns.pattern_run_id')
            ->when(($filters['status'] ?? null) === 'STALE', fn ($q) => $q->where('runs.configuration_hash', '!=', $configurationHash))
            ->when(isset($filters['status']) && $filters['status'] !== 'STALE', fn ($q) => $q->where('research_patterns.status', $filters['status'])->where('runs.configuration_hash', $configurationHash))
            ->when($filters['direction'] ?? null, fn ($q, $v) => $q->where('research_patterns.direction', $v))
            ->when($filters['symbol'] ?? null, fn ($q, $v) => $q->where('runs.symbol', $v))
            ->select('research_patterns.*', 'runs.symbol', 'runs.configuration_hash as run_configuration_hash')
            ->orderByDesc('research_patterns.created_at')->orderByDesc('research_patterns.id');
        $page = $query->paginate((int) ($filters['per_page'] ?? 10));
        $items = collect($page->items())->map(function (PatternRecord $pattern) use ($configurationHash): array {
            $attributes = $pattern->toArray();
            if (($attributes['run_configuration_hash'] ?? null) !== $configurationHash) {
                $attributes['status'] = 'STALE';
            }
            unset($attributes['run_configuration_hash']);

            return $attributes;
        })->all();

        return response()->json(['data' => $items, 'meta' => ['current_page' => $page->currentPage(),
            'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function show(string $id): JsonResponse
    {
        $pattern = Str::isUuid($id) ? PatternRecord::query()->find($id) : null;
        abort_if($pattern === null, 404, "Pattern [{$id}] not found.");

        return response()->json(['data' => $pattern]);
    }

    public function classical(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'symbol' => 'sometimes|in:BTCUSDT,ETHUSDT', 'timeframe' => 'sometimes|in:5,15,30,60',
            'pattern' => 'sometimes|string|max:48',
            'type' => 'sometimes|in:CANDLESTICK,MARKET_STRUCTURE',
        ]);
        $query = CandlestickPatternOccurrenceRecord::query()
            ->when($filters['symbol'] ?? null, fn ($q, $value) => $q->where('symbol', $value))
            ->when($filters['timeframe'] ?? null, fn ($q, $value) => $q->where('timeframe', $value))
            ->when($filters['pattern'] ?? null, fn ($q, $value) => $q->where('pattern_name', strtoupper($value)))
            ->when($filters['type'] ?? null, fn ($q, $value) => $q->where('pattern_type', $value))
            ->latest('detected_at')->latest('id');
        $page = $query->paginate((int) ($filters['per_page'] ?? 10));
        $statistics = CandlestickPatternOccurrenceRecord::query()
            ->when($filters['symbol'] ?? null, fn ($q, $value) => $q->where('symbol', $value))
            ->when($filters['timeframe'] ?? null, fn ($q, $value) => $q->where('timeframe', $value))
            ->when($filters['type'] ?? null, fn ($q, $value) => $q->where('pattern_type', $value))
            ->selectRaw("pattern_type, pattern_name, direction, COUNT(*) AS observations, SUM(CASE WHEN outcome IS NOT NULL THEN 1 ELSE 0 END) AS resolved, SUM(CASE WHEN outcome = 'SUCCESS' THEN 1 ELSE 0 END) AS successes, AVG(forward_return) AS average_forward_return")
            ->groupBy('pattern_type', 'pattern_name', 'direction')->orderByDesc('observations')->limit(50)->get()
            ->map(function ($row): array {
                $resolved = (int) $row->resolved;
                $successes = (int) $row->successes;
                $directional = $row->direction !== 'NEUTRAL';
                [$low, $high] = $directional && $resolved > 0 ? $this->wilson($successes, $resolved, (float) config('patterns.confidence_level_z', 1.96)) : [null, null];

                return ['pattern_type' => $row->pattern_type, 'pattern_name' => $row->pattern_name,
                    'direction' => $row->direction, 'observations' => (int) $row->observations,
                    'resolved' => $resolved, 'successes' => $successes,
                    'success_rate' => $directional && $resolved > 0 ? $successes / $resolved : null,
                    'confidence_low' => $low, 'confidence_high' => $high,
                    'validation_status' => ! $directional ? 'DIRECTIONAL_OUTCOMES' : ($resolved >= 30 ? 'RESEARCH_BASELINE' : 'INSUFFICIENT_DATA'),
                    'average_forward_return' => $row->average_forward_return];
            })->values();

        return response()->json(['data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'last_page' => $page->lastPage(), 'total' => $page->total(), 'statistics' => $statistics,
        ]]);
    }

    public function levelForecasts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'symbol' => 'sometimes|in:BTCUSDT,ETHUSDT', 'event' => 'sometimes|string|max:48',
        ]);
        $base = LevelInteractionForecastRecord::query()
            ->when($filters['symbol'] ?? null, fn ($query, $value) => $query->where('symbol', $value))
            ->when($filters['event'] ?? null, fn ($query, $value) => $query->where('event', $value));
        $page = (clone $base)->latest('triggered_at')->latest('id')->paginate((int) ($filters['per_page'] ?? 10));
        $resolved = (clone $base)->whereNotNull('brier_score');
        $window = max(10, (int) config('strategy.level_interaction.calibration_window', 50));
        $recentBrier = (clone $resolved)->latest('outcome_at')->limit($window)->avg('brier_score');
        $priorBrier = (clone $resolved)->latest('outcome_at')->skip($window)->limit($window)->avg('brier_score');
        $drift = $recentBrier !== null && $priorBrier !== null ? (float) $recentBrier - (float) $priorBrier : null;
        $calibration = [];
        for ($bucket = 0; $bucket < 10; $bucket++) {
            $lower = $bucket / 10;
            $upper = ($bucket + 1) / 10;
            $query = (clone $resolved)->where('success_probability', '>=', $lower)
                ->where('success_probability', $bucket === 9 ? '<=' : '<', $upper);
            $count = $query->count();
            if ($count > 0) {
                $calibration[] = [
                    'forecast_bucket' => sprintf('%.1f-%.1f', $lower, $upper), 'count' => $count,
                    'mean_forecast' => (string) $query->avg('success_probability'),
                    'observed_success_rate' => (string) ((clone $query)->whereIn('outcome', ['SUCCESS', 'BOUNCE', 'BREAKOUT'])->count() / $count),
                ];
            }
        }

        return response()->json(['data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            'calibration' => ['resolved_forecasts' => (clone $resolved)->count(), 'mean_brier_score' => (clone $resolved)->avg('brier_score'),
                'recent_brier_score' => $recentBrier, 'prior_brier_score' => $priorBrier, 'brier_drift' => $drift,
                'degradation_detected' => $drift !== null && $drift > (float) config('strategy.level_interaction.calibration_drift_threshold', 0.05),
                'reliability_buckets' => $calibration],
        ]]);
    }

    public function patternMatches(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'symbol' => 'sometimes|in:BTCUSDT,ETHUSDT', 'status' => 'sometimes|in:PENDING,RESOLVED,AMBIGUOUS,UNRESOLVED',
        ]);
        $page = PatternMatchRecord::query()->join('research_patterns as patterns', 'patterns.id', '=', 'research_pattern_matches.pattern_id')
            ->when($filters['symbol'] ?? null, fn ($query, $value) => $query->where('research_pattern_matches.symbol', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('research_pattern_matches.status', $value))
            ->latest('research_pattern_matches.matched_at')->latest('research_pattern_matches.id')
            ->select('research_pattern_matches.*', 'patterns.terms', 'patterns.version as pattern_version')
            ->paginate((int) ($filters['per_page'] ?? 10));

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(),
            'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            'research_only' => true, 'trading_action' => false]]);
    }

    public function runs(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $page = PatternRunRecord::query()->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 10));

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(),
            'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            'readiness' => $this->readiness()]]);
    }

    private function readiness(): array
    {
        $maximum = max(1, (int) config('patterns.maximum_decisions', 800));
        $required = min($maximum, max(1, (int) config('research.automatic.minimum_pattern_decisions', 700)));
        $holdingSeconds = max(1, (int) config('patterns.holding_bars_5m', 48)) * 300;

        return collect((array) config('patterns.allowed_symbols', ['BTCUSDT', 'ETHUSDT']))
            ->map(function (string $symbol) use ($holdingSeconds, $maximum, $required): array {
                $latest = CandleRecord::query()->where([
                    'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
                    'interval' => '5', 'is_closed' => true,
                ])->latest('open_time')->first(['open_time']);
                $to = $latest?->open_time->addMinutes(5);
                $decisions = SignalRecord::query()->where([
                    'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'strategy_id' => 'baseline',
                ]);
                $collected = $to === null ? 0 : (clone $decisions)->where('decision_time', '<=', $to)->count();
                $eligible = $to === null ? 0 : (clone $decisions)
                    ->where('decision_time', '<=', $to->subSeconds($holdingSeconds))->count();
                $eligible = min($maximum, $eligible);

                return [
                    'symbol' => $symbol, 'collected_decisions' => $collected,
                    'eligible_decisions' => $eligible, 'minimum_required' => $required,
                    'remaining_decisions' => max(0, $required - $eligible),
                    'progress_percent' => min(100, round($eligible / $required * 100, 1)),
                    'ready' => $eligible >= $required,
                ];
            })->values()->all();
    }

    public function queue(Request $request): JsonResponse
    {
        $data = $request->validate(['symbol' => 'required|in:BTCUSDT,ETHUSDT',
            'from' => 'required|date', 'to' => 'required|date|after:from']);
        $start = new \DateTimeImmutable($data['from'], new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($data['to'], new \DateTimeZone('UTC'));
        if ($end->getTimestamp() > time() || $end->getTimestamp() - $start->getTimestamp() > 90 * 86400) {
            return response()->json(['error' => ['code' => 'invalid_research_window', 'message' => 'Select a past window of at most 90 days']], 422);
        }
        $config = (array) config('patterns');
        $id = Uuid::uuid4()->toString();
        $record = PatternRunRecord::query()->create(['id' => $id, 'status' => 'QUEUED', 'symbol' => $data['symbol'],
            'from_at' => $start, 'to_at' => $end, 'parameters' => $config,
            'configuration_hash' => hash('sha256', json_encode($config, JSON_THROW_ON_ERROR))]);
        DiscoverPatternsJob::dispatch($id);

        return response()->json(['data' => ['id' => $record->id, 'status' => 'QUEUED']], 202);
    }

    public function latestMatches(Request $request, MarketFeatureExtractor $extractor, PatternMatcher $matcher, AsOfMarketContextService $contexts): JsonResponse
    {
        $data = $request->validate(['symbol' => 'nullable|in:BTCUSDT,ETHUSDT']);
        $symbol = $data['symbol'] ?? 'BTCUSDT';
        $signal = SignalRecord::query()->where('symbol', $symbol)->where('strategy_id', 'baseline')->orderByDesc('decision_time')->first();
        if ($signal === null) {
            return response()->json(['data' => [], 'meta' => ['point_in_time' => null]]);
        }
        $previousRecord = SignalRecord::query()->where('exchange', $signal->exchange)
            ->where('category', $signal->category)->where('symbol', $signal->symbol)
            ->where('strategy_id', 'baseline')
            ->where('decision_time', '<', $signal->decision_time)
            ->orderByDesc('decision_time')->first(['decision_time', 'reasons', 'indicator_snapshot']);
        $previous = $previousRecord === null ? null : [
            'decision_timestamp' => $previousRecord->decision_time->getTimestamp(),
            'reason' => $previousRecord->reasons[0] ?? 'none',
            'snapshot' => (array) $previousRecord->indicator_snapshot,
        ];
        $context = $contexts->at($signal->exchange, $signal->category, $signal->symbol, $signal->decision_time->toDateTimeImmutable());
        $features = $extractor->extract((array) $signal->indicator_snapshot,
            $signal->decision_time->getTimestamp(), $previous, $context);
        $patterns = PatternRecord::query()->join('research_pattern_runs as runs', 'runs.id', '=', 'research_patterns.pattern_run_id')
            ->where('runs.symbol', $symbol)->where('research_patterns.status', 'VALIDATED')
            ->where('runs.configuration_hash', hash('sha256', json_encode((array) config('patterns'), JSON_THROW_ON_ERROR)))
            ->orderByDesc('research_patterns.created_at')->limit(100)->get(['research_patterns.*']);
        $matches = $patterns->filter(fn ($pattern) => $matcher->matches((array) $pattern->terms, $features['features']))->take(10)->values()->all();

        return response()->json(['data' => $matches, 'meta' => ['point_in_time' => $signal->decision_time->toISOString(),
            'research_only' => true, 'not_trading_signals' => true]]);
    }

    private function wilson(int $successes, int $sample, float $z): array
    {
        $p = $successes / $sample;
        $denominator = 1 + ($z * $z / $sample);
        $center = ($p + ($z * $z / (2 * $sample))) / $denominator;
        $margin = $z * sqrt(($p * (1 - $p) / $sample) + ($z * $z / (4 * $sample * $sample))) / $denominator;

        return [max(0.0, $center - $margin), min(1.0, $center + $margin)];
    }
}
