<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ResearchResource;
use App\Research\Application\CounterfactualAnalyzerService;
use App\Research\Application\DecisionReplayService;
use App\Research\Application\PortfolioCorrelationService;
use App\Research\Application\ProbabilityCalibrationService;
use App\Research\Application\ResearchRecommendationService;
use App\Research\Application\ResearchReportService;
use App\Research\Application\ShadowExperimentService;
use App\Research\Application\StrategyDegradationService;
use App\Research\Infrastructure\Persistence\CounterfactualEvaluationRecord;
use App\Research\Infrastructure\Persistence\ResearchRecommendationRecord;
use App\Strategy\Application\StrategyRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ResearchIntelligenceController extends Controller
{
    private function ok(mixed $data): ResearchResource
    {
        return new ResearchResource($data);
    }

    public function opportunities(Request $request, CounterfactualAnalyzerService $analyzer): ResearchResource|JsonResponse
    {
        try {
            $perPage = max(1, min(100, $request->integer('per_page', 10)));
            $strategyId = $request->string('strategy_id')->toString() ?: null;

            $report = $analyzer->filterEffectivenessReport($strategyId);
            $records = CounterfactualEvaluationRecord::query()
                ->when($strategyId, fn ($q) => $q->where('strategy_id', $strategyId))
                ->latest('decision_time')
                ->paginate($perPage);

            return $this->ok([
                'summary' => $report,
                'evaluations' => $records,
            ]);
        } catch (Throwable $e) {
            Log::error('ResearchIntelligenceController opportunities failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve opportunities: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function opportunityDetail(string $id): ResearchResource|JsonResponse
    {
        try {
            return $this->ok(CounterfactualEvaluationRecord::query()->findOrFail($id));
        } catch (Throwable $e) {
            Log::error("ResearchIntelligenceController opportunityDetail failed for {$id}: ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => "Opportunity record {$id} not found",
            ], Response::HTTP_NOT_FOUND);
        }
    }

    public function replay(Request $request, string $evaluationId, DecisionReplayService $service): ResearchResource|JsonResponse
    {
        try {
            $mode = $request->string('mode', 'AS_OF_DECISION')->toString();

            return $this->ok($service->replay($evaluationId, $mode));
        } catch (Throwable $e) {
            Log::error("ResearchIntelligenceController replay failed for {$evaluationId}: ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reconstruct decision replay: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function calibration(Request $request, ProbabilityCalibrationService $service): ResearchResource|JsonResponse
    {
        try {
            $symbol = $request->string('symbol')->toString() ?: null;

            return $this->ok($service->evaluateCalibration($symbol));
        } catch (Throwable $e) {
            Log::error('ResearchIntelligenceController calibration failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to evaluate probability calibration: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function startShadow(string $id, ShadowExperimentService $service): ResearchResource|JsonResponse
    {
        try {
            return $this->ok($service->startExperiment($id));
        } catch (Throwable $e) {
            Log::error("ResearchIntelligenceController startShadow failed for {$id}: ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start shadow experiment: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function stopShadow(string $id, ShadowExperimentService $service): ResearchResource|JsonResponse
    {
        try {
            return $this->ok($service->stopExperiment($id));
        } catch (Throwable $e) {
            Log::error("ResearchIntelligenceController stopShadow failed for {$id}: ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to stop shadow experiment: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function degradation(Request $request, StrategyDegradationService $service, StrategyRegistry $registry): ResearchResource|JsonResponse
    {
        try {
            $horizon = $request->string('horizon', '14d')->toString();
            $strategyId = $request->string('strategy_id')->toString() ?: null;
            $symbol = $request->string('symbol')->toString() ?: null;

            if ($strategyId) {
                $snap = $service->evaluate($strategyId, $horizon, $symbol);

                return $this->ok($snap);
            }

            $snapshots = [];
            foreach ($registry->metadata() as $meta) {
                $snapshots[] = $service->evaluate($meta['id'], $horizon, $symbol);
            }

            return $this->ok([
                'horizon' => $horizon,
                'strategies' => $snapshots,
            ]);
        } catch (Throwable $e) {
            Log::error('ResearchIntelligenceController degradation failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to evaluate strategy degradation: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function correlations(Request $request, PortfolioCorrelationService $service): ResearchResource|JsonResponse
    {
        try {
            $horizon = $request->string('horizon', '14d')->toString();

            return $this->ok($service->evaluate($horizon));
        } catch (Throwable $e) {
            Log::error('ResearchIntelligenceController correlations failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to evaluate correlations: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function recommendations(ResearchRecommendationService $service): ResearchResource|JsonResponse
    {
        try {
            $service->generateRecommendations();
            $records = ResearchRecommendationRecord::query()
                ->where('status', 'OPEN')
                ->latest('created_at')
                ->get();

            return $this->ok($records);
        } catch (Throwable $e) {
            Log::error('ResearchIntelligenceController recommendations failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recommendations: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function dismissRecommendation(string $id): ResearchResource|JsonResponse
    {
        try {
            $rec = ResearchRecommendationRecord::query()->findOrFail($id);
            $rec->update(['status' => 'DISMISSED']);

            return $this->ok($rec->fresh());
        } catch (Throwable $e) {
            Log::error("ResearchIntelligenceController dismissRecommendation failed for {$id}: ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to dismiss recommendation: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function acceptRecommendation(string $id): ResearchResource|JsonResponse
    {
        try {
            $rec = ResearchRecommendationRecord::query()->findOrFail($id);
            $rec->update(['status' => 'ACCEPTED']);

            return $this->ok($rec->fresh());
        } catch (Throwable $e) {
            Log::error("ResearchIntelligenceController acceptRecommendation failed for {$id}: ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to accept recommendation: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function report(Request $request, ResearchReportService $service): ResearchResource|JsonResponse
    {
        try {
            $period = $request->string('period', 'daily')->toString();

            return $this->ok($service->generateReport($period));
        } catch (Throwable $e) {
            Log::error('ResearchIntelligenceController report failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate report: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
