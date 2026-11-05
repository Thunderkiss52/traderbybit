<?php

declare(strict_types=1);

namespace App\HistoricalData;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class HistoricalController
{
    public function preview(Request $request, DownloadPlanner $planner): JsonResponse
    {
        return response()->json(['data' => $planner->plan($request->all())]);
    }

    public function store(Request $request, BatchRepository $repository, ReadModel $view): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9._:-]{8,160}$/D', $key), 422, 'A stable Idempotency-Key header is required.');

        return response()->json(['data' => $view->batch($repository->create($request->all(), $key)->id)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return response()->json(['data' => DB::table('historical_download_batches')->select(['id', 'status', 'intended_use', 'acknowledged_at', 'created_at', 'updated_at'])->orderByDesc('created_at')->orderBy('id')->paginate(10)]);
    }

    public function show(string $batch, ReadModel $view): JsonResponse
    {
        return response()->json(['data' => $view->batch($batch)]);
    }

    public function entries(Request $request, string $batch): JsonResponse
    {
        abort_unless(DB::table('historical_download_batches')->where('id', $batch)->exists(), 404);
        $data = $request->validate(['page' => 'sometimes|integer|min:1|max:100000', 'status' => 'sometimes|string|max:40']);

        return response()->json(['data' => DB::table('historical_download_entries')->where('batch_id', $batch)->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))->select(['id', 'family', 'symbol', 'interval', 'period', 'filename', 'status', 'download_status', 'verification_status', 'import_status', 'sha256', 'imported_rows', 'duplicate_rows', 'conflict_rows', 'attempts', 'checkpoint', 'quality', 'error_code', 'diagnostics', 'available_at', 'downloaded_at', 'imported_at'])->orderBy('symbol')->orderBy('period')->orderBy('family')->orderBy('interval')->orderBy('id')->paginate(25)]);
    }

    public function control(Request $request, string $batch, string $action, BatchControl $control, ReadModel $view): JsonResponse
    {
        $data = $request->validate(['reference' => 'sometimes|string|min:8|max:160', 'terms_version' => 'sometimes|string|max:80']);
        $row = $control->change($batch, $action, $data['reference'] ?? null, $data['terms_version'] ?? null);
        if ($row->status === 'ACTIVE') {
            AcquisitionJob::dispatch($batch)->afterCommit();
        }

        return response()->json(['data' => $view->batch($batch)]);
    }

    public function validateCoverage(string $batch, CoverageValidator $coverage): JsonResponse
    {
        return response()->json(['data' => $coverage->validate($batch)]);
    }

    public function audit(Request $request, string $batch): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        abort_unless(DB::table('historical_download_batches')->where('id', $batch)->exists(), 404);

        return response()->json(['data' => DB::table('historical_acquisition_events')->where('batch_id', $batch)->orderByDesc('id')->paginate(25)]);
    }

    public function strategies(ResearchService $research): JsonResponse
    {
        return response()->json(['data' => array_map(function ($s) {
            unset($s['parameters']);

            return $s;
        }, $research->catalog())]);
    }

    public function research(Request $request, string $batch, ResearchService $research, ReadModel $view): JsonResponse
    {
        $run = $research->create($batch, $request->all());
        if ($run->status === 'QUEUED') {
            ResearchReplayJob::dispatch($run->id)->afterCommit();
        }

        return response()->json(['data' => $view->run($run->id)], 202);
    }

    public function runs(Request $request, string $batch): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        abort_unless(DB::table('historical_download_batches')->where('id', $batch)->exists(), 404);

        return response()->json(['data' => DB::table('historical_research_runs')->where('batch_id', $batch)->select(['id', 'status', 'strategy_id', 'strategy_version', 'dataset_pin', 'configuration_hash', 'error_code', 'created_at'])->orderByDesc('created_at')->orderBy('id')->paginate(10)]);
    }

    public function run(string $run, ReadModel $view): JsonResponse
    {
        return response()->json(['data' => $view->run($run)]);
    }

    public function cancel(string $run, ResearchService $research, ReadModel $view): JsonResponse
    {
        $research->cancel($run);

        return response()->json(['data' => $view->run($run)]);
    }

    public function artifacts(Request $request, string $run, string $artifact, ReadModel $view): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        abort_unless($view->run($run)['status'] === 'COMPLETED', 409, 'Research artifacts are not verified yet.');
        $table = match ($artifact) {
            'trades' => 'historical_research_trades', 'equity' => 'historical_research_equity', 'executions' => 'historical_research_executions'
        };

        return response()->json(['data' => DB::table($table)->where('run_id', $run)->orderBy('id')->paginate(100)]);
    }

    public function export(Request $request, string $run, LearningExport $export): JsonResponse
    {
        $data = $request->validate(['partition' => 'required|in:train,validation,holdout', 'limit' => 'sometimes|integer|min:1|max:10000', 'after' => 'sometimes|integer|min:0']);

        return response()->json(['data' => $export->partition($run, $data['partition'], $data['limit'] ?? 1000, $data['after'] ?? 0)]);
    }

    public function walkForward(Request $request, string $batch, LearningExport $export, ResearchService $research): JsonResponse
    {
        $data = $request->validate(['research' => 'required|array', 'options' => 'sometimes|array:train_hours,validation_hours,test_hours,step_hours,max_windows']);
        $windows = $export->walkForward($batch, $data['research'], $data['options'] ?? [], $research);
        foreach ($windows as $window) {
            foreach ($window as $segment) {
                ResearchReplayJob::dispatch($segment['run_id'])->afterCommit();
            }
        }

        return response()->json(['data' => $windows], 202);
    }
}
