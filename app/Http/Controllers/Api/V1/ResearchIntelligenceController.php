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
use Illuminate\Support\Facades\Cache;
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
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function opportunityDetail(string $id): ResearchResource|JsonResponse
    {
        try {
            return $this->ok(CounterfactualEvaluationRecord::query()->findOrFail($id));
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

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

            return $this->ok($service->replay($evaluationId, $mode, $request->boolean('persist')));
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function calibration(Request $request, ProbabilityCalibrationService $service): ResearchResource|JsonResponse
    {
        try {
            $symbol = $request->string('symbol')->toString() ?: null;

            return $this->ok($service->evaluateCalibration($symbol));
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function startShadow(string $id, ShadowExperimentService $service): ResearchResource|JsonResponse
    {
        try {
            return $this->ok($service->startExperiment($id));
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function stopShadow(string $id, ShadowExperimentService $service): ResearchResource|JsonResponse
    {
        try {
            return $this->ok($service->stopExperiment($id));
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
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
                $snap = $service->evaluate($strategyId, $horizon, $symbol, persist: false);

                return $this->ok($snap);
            }

            $cacheKey = "research:degradation:all:{$horizon}:".($symbol ?? 'all');
            $snapshots = Cache::remember($cacheKey, 30, function () use ($registry, $service, $horizon, $symbol) {
                $res = [];
                foreach ($registry->metadata() as $meta) {
                    $res[] = $service->evaluate($meta['id'], $horizon, $symbol, persist: false);
                }

                return $res;
            });

            return $this->ok([
                'horizon' => $horizon,
                'strategies' => $snapshots,
            ]);
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function correlations(Request $request, PortfolioCorrelationService $service): ResearchResource|JsonResponse
    {
        try {
            $horizon = $request->string('horizon', '14d')->toString();

            $data = Cache::remember(
                "research:correlations:{$horizon}",
                30,
                fn () => $service->evaluate($horizon, persist: false)
            );

            return $this->ok($data);
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function recommendations(Request $request, ResearchRecommendationService $service): ResearchResource|JsonResponse
    {
        try {
            $query = ResearchRecommendationRecord::query();

            $status = $request->string('status')->toString();
            if ($status !== '') {
                $query->where('status', $status);
            } else {
                $query->where('status', 'OPEN');
            }

            $category = $request->string('category')->toString();
            if ($category !== '') {
                $query->where('category', $category);
            } else {
                $query->where('category', '!=', 'ALPHA_IMPROVEMENT');
            }

            $strategyId = $request->string('strategy_id')->toString();
            if ($strategyId !== '') {
                $query->where('strategy_id', $strategyId);
            }

            $perPage = max(1, min(100, $request->integer('per_page', 10)));
            $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

            return $this->ok($paginator);
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function generateRecommendations(ResearchRecommendationService $service): ResearchResource
    {
        return $this->ok($service->generateRecommendations());
    }

    public function dismissRecommendation(string $id): ResearchResource|JsonResponse
    {
        try {
            $rec = ResearchRecommendationRecord::query()->findOrFail($id);
            $rec->update(['status' => 'DISMISSED']);

            return $this->ok($rec->fresh());
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function acceptRecommendation(string $id): ResearchResource|JsonResponse
    {
        try {
            $rec = ResearchRecommendationRecord::query()->findOrFail($id);
            if ($rec->category === 'ALPHA_IMPROVEMENT') {
                return response()->json(['success' => false, 'code' => 'LEGACY_EVIDENCE_INELIGIBLE'], 422);
            }
            $rec->update(['status' => 'ACCEPTED']);

            return $this->ok($rec->fresh());
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function report(Request $request, ResearchReportService $service): ResearchResource|JsonResponse
    {
        try {
            $period = $request->string('period', 'daily')->toString();

            $report = Cache::remember(
                "research:report:{$period}",
                30,
                fn () => $service->generateReport($period)
            );

            return $this->ok($report);
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
