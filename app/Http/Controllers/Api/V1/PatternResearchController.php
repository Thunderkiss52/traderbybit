<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\PatternDiscovery\Infrastructure\Persistence\PatternRecord;
use App\PatternDiscovery\Infrastructure\Persistence\PatternRunRecord;
use App\PatternDiscovery\Jobs\DiscoverPatternsJob;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\PatternDiscovery\Application\MarketFeatureExtractor;
use App\PatternDiscovery\Application\PatternMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

final class PatternResearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'direction' => 'sometimes|in:BUY,SELL', 'status' => 'sometimes|in:VALIDATED,INCONCLUSIVE', 'symbol' => 'sometimes|in:BTCUSDT,ETHUSDT']);
        $query = PatternRecord::query()->join('research_pattern_runs as runs', 'runs.id', '=', 'research_patterns.pattern_run_id')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('research_patterns.status', $v))
            ->when($filters['direction'] ?? null, fn ($q, $v) => $q->where('research_patterns.direction', $v))
            ->when($filters['symbol'] ?? null, fn ($q, $v) => $q->where('runs.symbol', $v))
            ->select('research_patterns.*', 'runs.symbol')
            ->orderByDesc('research_patterns.created_at')->orderByDesc('research_patterns.id');
        $page = $query->paginate((int) ($filters['per_page'] ?? 10));

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(),
            'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function show(string $id): JsonResponse
    {
        $pattern = PatternRecord::query()->findOrFail($id);

        return response()->json(['data' => $pattern]);
    }

    public function runs(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $page = PatternRunRecord::query()->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 10));

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(),
            'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
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

    public function latestMatches(Request $request, MarketFeatureExtractor $extractor, PatternMatcher $matcher): JsonResponse
    {
        $data = $request->validate(['symbol' => 'required|in:BTCUSDT,ETHUSDT']);
        $signal = SignalRecord::query()->where('symbol', $data['symbol'])->orderByDesc('decision_time')->first();
        if ($signal === null) {
            return response()->json(['data' => [], 'meta' => ['point_in_time' => null]]);
        }
        $previousRecord = SignalRecord::query()->where('exchange', $signal->exchange)
            ->where('category', $signal->category)->where('symbol', $signal->symbol)
            ->where('decision_time', '<', $signal->decision_time)
            ->orderByDesc('decision_time')->first(['decision_time', 'reasons', 'indicator_snapshot']);
        $previous = $previousRecord === null ? null : [
            'decision_timestamp' => $previousRecord->decision_time->getTimestamp(),
            'reason' => $previousRecord->reasons[0] ?? 'none',
            'snapshot' => (array) $previousRecord->indicator_snapshot,
        ];
        $contextRecord = MarketContextRecord::query()->where('exchange', $signal->exchange)
            ->where('category', $signal->category)->where('symbol', $signal->symbol)
            ->where('observed_at', '<=', $signal->decision_time)
            ->orderByDesc('observed_at')->first(['observed_at', 'funding_rate', 'open_interest_change']);
        $context = $contextRecord === null ? null : [
            'observed_at' => $contextRecord->observed_at->getTimestamp(),
            'funding_rate' => $contextRecord->funding_rate,
            'open_interest_change' => $contextRecord->open_interest_change,
        ];
        $features = $extractor->extract((array) $signal->indicator_snapshot,
            $signal->decision_time->getTimestamp(), $previous, $context);
        $patterns = PatternRecord::query()->join('research_pattern_runs as runs', 'runs.id', '=', 'research_patterns.pattern_run_id')
            ->where('runs.symbol', $data['symbol'])->where('research_patterns.status', 'VALIDATED')
            ->orderByDesc('research_patterns.created_at')->limit(100)->get(['research_patterns.*']);
        $matches = $patterns->filter(fn ($pattern) => $matcher->matches((array) $pattern->terms, $features['features']))->take(10)->values()->all();

        return response()->json(['data' => $matches, 'meta' => ['point_in_time' => $signal->decision_time->toISOString(),
            'research_only' => true, 'not_trading_signals' => true]]);
    }
}
