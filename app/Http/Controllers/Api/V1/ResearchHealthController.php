<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ResearchResource;
use App\Research\Application\LedgerReconciliationService;
use App\Research\Application\SlotIntegrityService;
use App\Research\Application\StrategyHealthService;
use App\Research\Application\SystemFreshnessService;
use App\Research\Infrastructure\Persistence\ReconciliationRunRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ResearchHealthController extends Controller
{
    private function ok(mixed $data): ResearchResource
    {
        return new ResearchResource($data);
    }

    public function overview(Request $request, StrategyHealthService $health): ResearchResource|JsonResponse
    {
        try {
            $period = $request->string('period', '24h')->toString();

            return $this->ok($health->overview($period, persist: false));
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function strategyFunnel(Request $request, StrategyHealthService $health): ResearchResource|JsonResponse
    {
        try {
            $period = $request->string('period', '7d')->toString();

            return $this->ok($health->overview($period, persist: false));
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function reconciliationRuns(Request $request): ResearchResource|JsonResponse
    {
        try {
            $perPage = max(1, min(100, $request->integer('per_page', 10)));
            $runs = ReconciliationRunRecord::query()
                ->latest('completed_at')
                ->paginate($perPage);

            return $this->ok($runs);
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function reconcile(Request $request, LedgerReconciliationService $service): ResearchResource|JsonResponse
    {
        try {
            $accountId = $request->string('account_id')->toString();
            if ($accountId !== '') {
                $result = $service->reconcileAccount($accountId);
            } else {
                $result = $service->reconcileAll();
            }

            return $this->ok($result);
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function dataFreshness(Request $request, SystemFreshnessService $freshness): ResearchResource|JsonResponse
    {
        try {
            $exchange = $request->query('exchange');
            $exchange = is_string($exchange) && in_array($exchange, ['bybit', 'binance'], true) ? $exchange : null;
            $alertOnMissing = $request->boolean('alert_on_missing', false);

            return $this->ok($freshness->check($exchange, $alertOnMissing));
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function slotIntegrity(Request $request, SlotIntegrityService $service): ResearchResource|JsonResponse
    {
        try {
            $lookbackHours = (int) $request->input('hours', 4);
            $end = CarbonImmutable::now('UTC');
            $start = $end->subHours($lookbackHours);

            return $this->ok($service->auditCadence($start, $end));
        } catch (Throwable $e) {
            Log::error('Research API operation failed.', ['operation' => __METHOD__, 'exception_type' => $e::class]);

            return response()->json([
                'success' => false,
                'message' => 'Request failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function version(): JsonResponse
    {
        $file = base_path('build-metadata.json');
        $metadata = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $sha = $metadata['git_sha'] ?? null;
        $timestamp = $metadata['build_timestamp'] ?? null;
        $sha = is_string($sha) && preg_match('/^[a-f0-9]{40}$/D', $sha) ? $sha : null;
        $timestamp = is_string($timestamp) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $timestamp) ? $timestamp : null;

        return response()->json([
            'git_sha' => $sha,
            'build_timestamp' => $timestamp,
            'build_metadata_status' => $sha !== null && $timestamp !== null ? 'AVAILABLE' : 'UNKNOWN',
            'version' => config('strategy.version', '1.2.0'),
            'environment' => config('app.env', 'production'),
            'market_category' => 'linear',
            'symbols' => (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']),
            'active_strategies_count' => count((array) config('paper.primary_strategies', ['baseline'])),
            'paper_only' => true,
        ]);
    }
}
