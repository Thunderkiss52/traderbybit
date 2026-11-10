<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ResearchResource;
use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\LearningPredictorService;
use App\Research\Application\PromotionPolicyService;
use App\Research\Application\StrategyEvolutionService;
use App\Research\Domain\ResearchPromotionControlRecord;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use App\Research\Jobs\EvaluateStrategyCandidateJob;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AutonomousLearningController extends Controller
{
    private function ok(mixed $data): ResearchResource
    {
        return new ResearchResource($data);
    }

    public function overview(AutonomyPermissionService $autonomy): ResearchResource|JsonResponse
    {
        try {
            $settings = $autonomy->getSettings(persist: false);
            $totalSnapshots = LearningFeatureSnapshotRecord::query()->count();
            $maturedSnapshots = LearningFeatureSnapshotRecord::query()->where('label_status', 'MATURED')->count();
            $activeModels = LearningModelArtifactRecord::query()->where('status', 'ACTIVE')->count();
            $totalCandidates = LearningCandidateStrategyRecord::query()->count();
            $promotedCandidates = LearningCandidateStrategyRecord::query()->where('status', 'PAPER_PROMOTED')->count();

            return $this->ok([
                'settings' => $settings,
                'feature_store' => [
                    'total_snapshots' => $totalSnapshots,
                    'matured_snapshots' => $maturedSnapshots,
                    'schema_version' => '1.0.0',
                ],
                'models' => [
                    'active_models_count' => $activeModels,
                ],
                'candidates' => [
                    'total' => $totalCandidates,
                    'promoted_to_paper' => $promotedCandidates,
                ],
                'operating_mode' => 'PAPER_TRADING_RESEARCH_ONLY',
            ]);
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function autonomySettings(AutonomyPermissionService $autonomy): ResearchResource|JsonResponse
    {
        try {
            $settings = $autonomy->getSettings(persist: false);
            $learningActive = (bool) $settings->kill_switch_active;

            $researchActive = false;
            $latestControl = ResearchPromotionControlRecord::query()
                ->whereIn('action', [
                    'KILL_SWITCH_ACTIVATED',
                    'KILL_SWITCH_RELEASED',
                ])
                ->orderByDesc('sequence')
                ->first();
            if ($latestControl !== null && (string) $latestControl->action === 'KILL_SWITCH_ACTIVATED') {
                $researchActive = true;
            }

            $effectiveKillSwitch = $learningActive || $researchActive;

            $rawCaps = (array) $settings->capabilities;
            $effectiveCaps = [];
            foreach ($rawCaps as $cap => $rawVal) {
                $allowed = $autonomy->isAllowed($cap, persist: false);
                $reason = null;
                if (! $allowed) {
                    if (in_array($cap, ['change_hard_risk_limits', 'live_exchange_trading'], true)) {
                        $reason = 'ABSOLUTE_SYSTEM_INVARIANT';
                    } elseif ($effectiveKillSwitch) {
                        $reason = 'KILL_SWITCH_ACTIVE';
                    } elseif (! $rawVal) {
                        $reason = 'EXPLICITLY_DISABLED_BY_OPERATOR';
                    }
                }
                $effectiveCaps[$cap] = [
                    'configured' => (bool) $rawVal,
                    'effective' => $allowed,
                    'reason' => $reason,
                ];
            }

            $data = $settings->toArray();
            $data['kill_switch_active'] = $effectiveKillSwitch;
            $data['effective_capabilities'] = $effectiveCaps;
            $data['authorities'] = [
                'learning_autonomy_settings' => $learningActive,
                'research_promotion_control' => $researchActive,
            ];

            return $this->ok($data);
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function updateAutonomySettings(Request $request, AutonomyPermissionService $autonomy): ResearchResource|JsonResponse
    {
        $request->validate([
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['boolean'],
            'limits' => ['sometimes', 'array'],
            'limits.paper_initial_capital' => ['sometimes', 'string', 'regex:/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,12})?$/D'],
        ]);
        try {
            $capabilities = (array) $request->input('capabilities', []);
            $limits = (array) $request->input('limits', []);

            return $this->ok($autonomy->updateSettings($capabilities, $limits, 'USER_OPERATOR'));
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function killSwitch(Request $request, AutonomyPermissionService $autonomy): ResearchResource|JsonResponse
    {
        try {
            $active = (bool) $request->boolean('active', true);
            $reason = (string) $request->input('reason', 'Operator kill-switch triggered');

            return $this->ok($autonomy->toggleKillSwitch($active, 'USER_OPERATOR', $reason));
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function candidates(Request $request): ResearchResource|JsonResponse
    {
        try {
            $perPage = max(1, min(100, $request->integer('per_page', 10)));
            $status = $request->string('status')->toString() ?: null;
            $strategyId = $request->string('parent_strategy_id')->toString() ?: null;

            $records = LearningCandidateStrategyRecord::query()
                ->when($status, fn ($q) => $q->where('status', $status))
                ->when($strategyId, fn ($q) => $q->where('parent_strategy_id', $strategyId))
                ->latest('created_at')
                ->paginate($perPage);

            return $this->ok($records);
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function generateCandidate(Request $request, StrategyEvolutionService $evolution): ResearchResource|JsonResponse
    {
        $validated = $request->validate(['exchange' => ['sometimes', 'string']]);
        try {
            $parentStrategyId = $request->string('parent_strategy_id', 'trend_pullback')->toString();
            $seed = $request->has('seed') ? $request->integer('seed') : null;

            return $this->ok($evolution->generateCandidate($parentStrategyId, $seed, $validated['exchange'] ?? 'bybit'));
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function validateCandidate(string $id, Request $request, AutonomyPermissionService $autonomy): ResearchResource|JsonResponse
    {
        try {
            $candidate = LearningCandidateStrategyRecord::query()->findOrFail($id);

            $isEligible = $candidate->status === 'RESEARCH_ONLY'
                || ($candidate->status === 'REJECTED' && ($candidate->validation_gates['failure_reason'] ?? null) === 'missing_validation_evidence');

            if (! $isEligible) {
                return response()->json([
                    'success' => false,
                    'message' => "Candidate cannot be scheduled for validation in current status: {$candidate->status}.",
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $settings = $autonomy->getSettings(persist: false);
            if ((bool) ($settings['kill_switch_active'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Autonomy kill-switch is active. Validation scheduling is paused.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $metadata = (array) ($candidate->search_metadata ?? []);
            $evalStatus = $metadata['evaluation_status'] ?? null;

            // Idempotency: if validation job is already queued or running, return current state
            if (in_array($evalStatus, ['QUEUED', 'RUNNING'], true)) {
                return $this->ok($candidate);
            }

            $maxAttempts = max(1, (int) config('research.evolution.max_evaluation_attempts', 3));
            $attempts = (int) ($metadata['evaluation_attempts'] ?? 0);
            if ($attempts >= $maxAttempts) {
                return response()->json([
                    'success' => false,
                    'message' => "Candidate exceeded maximum validation attempts ({$maxAttempts}).",
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $metadata['evaluation_status'] = 'QUEUED';
            $metadata['validation_status'] = 'QUEUED';
            $metadata['evaluation_scheduled_at'] = CarbonImmutable::now('UTC')->toIso8601String();

            $candidate->update([
                'status' => 'RESEARCH_ONLY',
                'search_metadata' => $metadata,
                'validation_gates' => [
                    'all_gates_passed' => false,
                    'status' => 'VALIDATION_IN_PROGRESS',
                    'failure_reason' => 'validation_in_progress',
                    'detail' => 'Validation job scheduled and queued on research queue.',
                    'scheduled_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                ],
            ]);

            $actor = $request->string('actor', 'USER_OPERATOR')->toString();
            $autonomy->recordEvent(
                actor: $actor,
                action: 'CANDIDATE_VALIDATION_SCHEDULED',
                targetType: 'CANDIDATE_STRATEGY',
                targetId: $candidate->id,
                previousState: $candidate->status,
                newState: $candidate->status,
                reason: 'Validation backtest job scheduled for strategy candidate.',
                evidence: ['candidate_id' => $candidate->id, 'attempt' => $attempts + 1]
            );

            EvaluateStrategyCandidateJob::dispatch($candidate->id);

            return $this->ok($candidate->fresh());
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function evaluateCandidate(string $id, Request $request, PromotionPolicyService $policy): ResearchResource|JsonResponse
    {
        try {
            $runId = $request->input('validation_run_id') ?? $request->input('run_id');

            return $this->ok($policy->evaluateCandidate($id, is_string($runId) ? $runId : null));
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function promoteCandidate(string $id, Request $request, PromotionPolicyService $policy): ResearchResource|JsonResponse
    {
        try {
            $actor = $request->string('actor', 'USER_OPERATOR')->toString();

            return $this->ok($policy->promoteCandidate($id, $actor));
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function rollbackCandidate(string $id, Request $request, PromotionPolicyService $policy): ResearchResource|JsonResponse
    {
        try {
            $reason = $request->string('reason', 'Manual rollback requested by operator')->toString();
            $actor = $request->string('actor', 'USER_OPERATOR')->toString();

            return $this->ok($policy->rollbackCandidate($id, $reason, $actor));
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function models(Request $request): ResearchResource|JsonResponse
    {
        try {
            $exchange = $request->query('exchange');
            $query = LearningModelArtifactRecord::query()->latest('created_at');
            if (is_string($exchange) && $exchange !== '' && $exchange !== 'ALL') {
                $query->where('parameters->exchange', strtolower($exchange));
            }

            if ($request->has('per_page') || $request->has('page')) {
                $perPage = max(1, min(100, $request->integer('per_page', 20)));
                $paginated = $query->paginate($perPage);

                return $this->ok($paginated);
            }

            $records = $query->get();

            return $this->ok($records);
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function trainModel(Request $request, LearningPredictorService $trainer): ResearchResource|JsonResponse
    {
        $request->validate(['exchange' => ['sometimes', 'string']]);
        try {
            $event = $request->string('target_event', 'TP_BEFORE_SL')->toString();
            $symbol = $request->string('symbol', 'BTCUSDT')->toString();

            $exchange = $request->string('exchange', 'bybit')->toString();
            $artifact = $trainer->trainModel($event, $symbol, $exchange);

            return $this->ok($artifact ?? ['status' => 'INSUFFICIENT_DATA', 'message' => 'Minimum 10 matured observations required to train model.']);
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function auditTrail(Request $request): ResearchResource|JsonResponse
    {
        try {
            $perPage = max(1, min(100, $request->integer('per_page', 15)));

            $records = AutonomyApprovalEventRecord::query()
                ->latest('created_at')
                ->paginate($perPage);

            return $this->ok($records);
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
