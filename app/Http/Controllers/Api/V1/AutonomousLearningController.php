<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ResearchResource;
use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\LearningPredictorService;
use App\Research\Application\PromotionPolicyService;
use App\Research\Application\StrategyEvolutionService;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            $settings = $autonomy->getSettings();
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
            Log::error('AutonomousLearningController overview failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load autonomous learning overview: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function autonomySettings(AutonomyPermissionService $autonomy): ResearchResource|JsonResponse
    {
        try {
            return $this->ok($autonomy->getSettings());
        } catch (Throwable $e) {
            Log::error('AutonomousLearningController autonomySettings failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load autonomy settings: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function updateAutonomySettings(Request $request, AutonomyPermissionService $autonomy): ResearchResource|JsonResponse
    {
        try {
            $capabilities = (array) $request->input('capabilities', []);
            $limits = (array) $request->input('limits', []);

            return $this->ok($autonomy->updateSettings($capabilities, $limits, 'USER_OPERATOR'));
        } catch (Throwable $e) {
            Log::error('AutonomousLearningController updateAutonomySettings failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update autonomy settings: '.$e->getMessage(),
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
            Log::error('AutonomousLearningController killSwitch failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle kill switch: '.$e->getMessage(),
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
            Log::error('AutonomousLearningController candidates failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch candidate strategies: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function generateCandidate(Request $request, StrategyEvolutionService $evolution): ResearchResource|JsonResponse
    {
        try {
            $parentStrategyId = $request->string('parent_strategy_id', 'trend_pullback')->toString();
            $seed = $request->has('seed') ? $request->integer('seed') : null;

            return $this->ok($evolution->generateCandidate($parentStrategyId, $seed));
        } catch (Throwable $e) {
            Log::error('AutonomousLearningController generateCandidate failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate strategy candidate: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function evaluateCandidate(string $id, Request $request, PromotionPolicyService $policy): ResearchResource|JsonResponse
    {
        try {
            $runId = $request->input('validation_run_id') ?? $request->input('run_id');

            return $this->ok($policy->evaluateCandidate($id, is_string($runId) ? $runId : null));
        } catch (Throwable $e) {
            Log::error("AutonomousLearningController evaluateCandidate failed for {$id}: ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to evaluate candidate: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function promoteCandidate(string $id, Request $request, PromotionPolicyService $policy): ResearchResource|JsonResponse
    {
        try {
            $actor = $request->string('actor', 'USER_OPERATOR')->toString();

            return $this->ok($policy->promoteCandidate($id, $actor));
        } catch (Throwable $e) {
            Log::error("AutonomousLearningController promoteCandidate failed for {$id}: ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to promote candidate: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function rollbackCandidate(string $id, Request $request, PromotionPolicyService $policy): ResearchResource|JsonResponse
    {
        try {
            $reason = $request->string('reason', 'Manual rollback requested by operator')->toString();
            $actor = $request->string('actor', 'USER_OPERATOR')->toString();

            return $this->ok($policy->rollbackCandidate($id, $reason, $actor));
        } catch (Throwable $e) {
            Log::error("AutonomousLearningController rollbackCandidate failed for {$id}: ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to rollback candidate: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function models(): ResearchResource|JsonResponse
    {
        try {
            $records = LearningModelArtifactRecord::query()
                ->latest('created_at')
                ->get();

            return $this->ok($records);
        } catch (Throwable $e) {
            Log::error('AutonomousLearningController models failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch model artifacts: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function trainModel(Request $request, LearningPredictorService $trainer): ResearchResource|JsonResponse
    {
        try {
            $event = $request->string('target_event', 'TP_BEFORE_SL')->toString();
            $symbol = $request->string('symbol', 'BTCUSDT')->toString();

            $artifact = $trainer->trainModel($event, $symbol);

            return $this->ok($artifact ?? ['status' => 'INSUFFICIENT_DATA', 'message' => 'Minimum 10 matured observations required to train model.']);
        } catch (Throwable $e) {
            Log::error('AutonomousLearningController trainModel failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to train model: '.$e->getMessage(),
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
            Log::error('AutonomousLearningController auditTrail failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch audit trail: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
