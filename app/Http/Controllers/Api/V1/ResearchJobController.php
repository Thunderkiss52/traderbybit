<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Research\Application\ResearchJobService;
use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
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
                'code_revision' => ['nullable', 'string', 'max:64'],
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
                'exchange' => ['nullable', 'string', 'max:32'],
                'exchange_id' => ['nullable', 'string', 'max:32'],
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
        } catch (ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['exchange' => $e->getMessage()]);
        } catch (Throwable $e) {
            // Do not log request payloads, Throwable objects or their messages:
            // research payloads can contain credentials or private input.
            Log::error('Research API operation failed.', [
                'operation' => __METHOD__,
                'exception_type' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function show(string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => "Research job {$id} not found",
            ], Response::HTTP_NOT_FOUND);
        }

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
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
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
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
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
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
