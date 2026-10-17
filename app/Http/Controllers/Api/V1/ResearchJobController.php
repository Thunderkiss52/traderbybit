<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Research\Application\ResearchJobService;
use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ResearchJobController extends Controller
{
    public function __construct(
        private readonly ResearchJobService $jobService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $query = ResearchJobRecord::query()->orderBy('requested_at_utc', 'desc');

            if ($request->filled('kind')) {
                $query->where('kind', strtoupper((string) $request->input('kind')));
            }

            if ($request->filled('status')) {
                $query->where('status', strtoupper((string) $request->input('status')));
            }

            if ($request->filled('strategy_id')) {
                $query->where('strategy_id', (string) $request->input('strategy_id'));
            }

            $perPage = min(100, max(5, (int) $request->input('per_page', 10)));
            $jobs = $query->simplePaginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $jobs->items(),
                'pagination' => [
                    'current_page' => $jobs->currentPage(),
                    'per_page' => $jobs->perPage(),
                    'has_more' => $jobs->hasMorePages(),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('ResearchJobController index failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve research jobs: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'kind' => ['required', 'string', Rule::in(ResearchJobService::VALID_KINDS)],
                'strategy_id' => ['nullable', 'string', 'max:64'],
                'strategy_version' => ['nullable', 'string', 'max:64'],
                'configuration_hash' => ['nullable', 'string', 'max:64'],
                'algorithm_version' => ['nullable', 'string', 'max:64'],
                'model_version' => ['nullable', 'string', 'max:64'],
                'dataset_id' => ['nullable', 'uuid'],
                'dataset_sha256' => ['nullable', 'string', 'size:64'],
                'as_of_utc' => ['nullable', 'date'],
                'training_cutoff_utc' => ['nullable', 'date'],
                'research_mode' => ['nullable', 'string', 'max:32'],
                'random_seed' => ['nullable', 'integer'],
                'limits' => ['nullable', 'array'],
                'limits.max_rows' => ['nullable', 'integer', 'min:1', 'max:10000000'],
                'limits.max_duration_s' => ['nullable', 'integer', 'min:1', 'max:7200'],
                'limits.max_memory_mib' => ['nullable', 'integer', 'min:64', 'max:4096'],
                'payload' => ['nullable', 'array'],
                'idempotency_key' => ['nullable', 'string', 'max:64'],
                'created_by' => ['nullable', 'string', 'max:64'],
            ]);

            $job = $this->jobService->submitJob($validated);

            return response()->json([
                'success' => true,
                'message' => 'Research task accepted for execution',
                'data' => [
                    'job_id' => $job->job_id,
                    'kind' => $job->kind,
                    'status' => $job->status,
                    'idempotency_key' => $job->idempotency_key,
                    'requested_at_utc' => $job->requested_at_utc?->toIso8601String(),
                ],
            ], Response::HTTP_ACCEPTED);
        } catch (Throwable $e) {
            Log::error('ResearchJobController store failed: '.$e->getMessage(), [
                'exception' => $e,
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit research job: '.$e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $job = ResearchJobRecord::query()->where('job_id', $id)->first();

            if ($job === null) {
                return response()->json([
                    'success' => false,
                    'message' => "Research job {$id} not found",
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => $job,
            ]);
        } catch (Throwable $e) {
            Log::error("ResearchJobController show failed for job {$id}: ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching job: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function cancel(string $id, Request $request): JsonResponse
    {
        try {
            $reason = (string) $request->input('reason', 'Cancelled via API');
            $cancelled = $this->jobService->cancelJob($id, $reason);

            if (! $cancelled) {
                return response()->json([
                    'success' => false,
                    'message' => "Job {$id} cannot be cancelled (already in terminal state or not found)",
                ], Response::HTTP_CONFLICT);
            }

            return response()->json([
                'success' => true,
                'message' => "Research job {$id} cancelled successfully",
            ]);
        } catch (Throwable $e) {
            Log::error("ResearchJobController cancel failed for job {$id}: ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Error cancelling job: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function capabilities(): JsonResponse
    {
        try {
            $routing = $this->jobService->getCapabilityRouting();

            return response()->json([
                'success' => true,
                'data' => $routing,
            ]);
        } catch (Throwable $e) {
            Log::error('ResearchJobController capabilities failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch capability routing: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
