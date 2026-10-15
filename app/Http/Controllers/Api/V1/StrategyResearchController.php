<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Backtest\Application\BacktestRunService;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\Backtest\Infrastructure\Persistence\BacktestSegmentRecord;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ResearchResource;
use App\PaperTrading\Application\DailyPerformanceService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Application\ResearchPortfolioManager;
use App\Strategy\Application\StrategyComparisonService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\ResearchExperimentRecord;
use App\Strategy\Jobs\RunResearchExperimentJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

final class StrategyResearchController extends Controller
{
    public function strategies(Request $request, StrategyRegistry $registry): ResearchResource
    {
        $items = $registry->metadata();
        $page = max(1, $request->integer('page', 1));
        $per = max(1, min(100, $request->integer('per_page', 10)));
        $total = count($items);

        return new ResearchResource(['data' => array_slice($items, ($page - 1) * $per, $per), 'meta' => ['current_page' => $page, 'per_page' => $per, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $per))]]);
    }

    public function strategy(string $id, StrategyRegistry $registry): ResearchResource
    {
        try {
            $strategy = $registry->get($id);
        } catch (\InvalidArgumentException) {
            abort(404, 'Unknown strategy.');
        } $latest = ResearchExperimentRecord::query()->where('strategy_id', $id)->latest('created_at')->first();

        return new ResearchResource(['strategy' => collect($registry->metadata())->firstWhere('id', $id), 'latest_experiment' => $latest]);
    }

    public function experiments(Request $request): ResearchResource
    {
        $q = ResearchExperimentRecord::query();
        if ($request->filled('strategy_id')) {
            $q->where('strategy_id', $request->string('strategy_id'));
        }

        return new ResearchResource($q->latest('created_at')->latest('id')->paginate(max(1, min(100, $request->integer('per_page', 10))), ['id', 'strategy_id', 'strategy_version', 'symbols', 'evaluation_mode', 'status', 'backtest_run_id', 'created_at', 'completed_at']));
    }

    public function experiment(Request $request, string $id): ResearchResource
    {
        $experiment = ResearchExperimentRecord::query()->findOrFail($id);
        $segments = $experiment->backtest_run_id ? BacktestSegmentRecord::query()->where('backtest_run_id', $experiment->backtest_run_id)->orderBy('window_index')->orderBy('kind')->paginate(max(1, min(100, $request->integer('per_page', 10)))) : null;

        return new ResearchResource(['experiment' => $experiment, 'backtest' => $experiment->backtest_run_id ? BacktestRunRecord::query()->find($experiment->backtest_run_id, ['id', 'status', 'metrics', 'warnings', 'progress']) : null, 'segments' => $segments ?? ['data' => [], 'current_page' => 1, 'last_page' => 1, 'total' => 0]]);
    }

    public function leaderboard(StrategyComparisonService $comparison): ResearchResource
    {
        $rows = $comparison->latest();

        return new ResearchResource(['data' => $rows, 'meta' => ['current_page' => 1, 'per_page' => 10, 'total' => count($rows), 'last_page' => 1]]);
    }

    public function comparison(StrategyComparisonService $comparison): ResearchResource
    {
        return $this->leaderboard($comparison);
    }

    public function store(Request $request, StrategyRegistry $registry): JsonResponse
    {
        $validated = $request->validate(['strategy_id' => ['required', 'string'], 'symbols' => ['required', 'array', 'min:1', 'max:2'], 'symbols.*' => ['in:BTCUSDT,ETHUSDT'], 'from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from'], 'evaluation_mode' => ['required', 'in:split,walk_forward']]);
        try {
            $strategy = $registry->get($validated['strategy_id']);
        } catch (\InvalidArgumentException) {
            abort(422, 'Unknown strategy.');
        } $parameters = $strategy->parameters();
        $id = Uuid::uuid4()->toString();
        $hash = collect($registry->metadata())->firstWhere('id', $strategy->id())['configuration_hash'];
        $experiment = ResearchExperimentRecord::query()->create(['id' => $id, 'strategy_id' => $strategy->id(), 'strategy_version' => $strategy->version(), 'configuration_hash' => $hash, 'parameters' => $parameters, 'symbols' => array_values(array_unique($validated['symbols'])), 'from_at' => $validated['from'], 'to_at' => $validated['to'], 'evaluation_mode' => $validated['evaluation_mode'], 'status' => 'QUEUED', 'resource_budget' => config('research.queue'), 'tested_combinations' => 0]);
        RunResearchExperimentJob::dispatch($id);

        return response()->json(['schema_version' => '1.0.0', 'research_mode' => true, 'data' => $experiment], 202);
    }

    public function cancel(string $id, BacktestRunService $backtests): ResearchResource
    {
        $experiment = ResearchExperimentRecord::query()->findOrFail($id);
        abort_unless(in_array($experiment->status, ['QUEUED', 'RUNNING'], true), 409, 'Experiment is not cancellable.');
        $experiment->update(['status' => 'CANCEL_REQUESTED']);
        if ($experiment->backtest_run_id) {
            $backtests->requestCancellation($experiment->backtest_run_id);
        }

        return new ResearchResource(['id' => $id, 'status' => 'CANCEL_REQUESTED']);
    }

    public function approveForward(string $id, ResearchPortfolioManager $manager): ResearchResource
    {
        try {
            $account = $manager->approve($id);
        } catch (\DomainException $error) {
            abort(409, $error->getMessage());
        }

        return new ResearchResource(['account' => $account, 'mode' => 'RESEARCH_FORWARD_PAPER', 'real_trading' => false]);
    }

    public function dailyPerformance(Request $request, DailyPerformanceService $daily): ResearchResource
    {
        $timezone = $request->string('timezone', 'UTC')->toString();
        abort_unless(in_array($timezone, timezone_identifiers_list(), true), 422, 'Invalid timezone.');
        $symbol = strtoupper($request->string('symbol', 'ALL')->toString());
        abort_unless(in_array($symbol, ['ALL', 'BTCUSDT', 'ETHUSDT'], true), 422, 'Unsupported symbol.');
        $accounts = PaperAccountRecord::query()->where('mode', 'RESEARCH_FORWARD_PAPER')->latest('created_at')->latest('id')->paginate(max(1, min(100, $request->integer('per_page', 10))));
        $rows = collect($accounts->items())->map(fn (PaperAccountRecord $account): array => ['account' => $account->only(['id', 'name', 'strategy_id', 'strategy_version', 'current_equity']), 'performance' => $daily->report($account->id, $timezone, $symbol === 'ALL' ? null : $symbol, 1, 10)['summary']])->all();

        return new ResearchResource(['data' => $rows, 'meta' => ['current_page' => $accounts->currentPage(), 'per_page' => $accounts->perPage(), 'total' => $accounts->total(), 'last_page' => $accounts->lastPage()]]);
    }
}
