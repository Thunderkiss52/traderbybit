<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ResearchResource;
use App\Research\Application\LedgerReconciliationService;
use App\Research\Application\StrategyHealthService;
use App\Research\Application\SystemFreshnessService;
use App\Research\Infrastructure\Persistence\ReconciliationRunRecord;
use Illuminate\Http\Request;

final class ResearchHealthController extends Controller
{
    private function ok(mixed $data): ResearchResource
    {
        return new ResearchResource($data);
    }

    public function overview(Request $request, StrategyHealthService $health): ResearchResource
    {
        $period = $request->string('period', '24h')->toString();

        return $this->ok($health->overview($period));
    }

    public function strategyFunnel(Request $request, StrategyHealthService $health): ResearchResource
    {
        $period = $request->string('period', '7d')->toString();

        return $this->ok($health->overview($period));
    }

    public function reconciliationRuns(Request $request): ResearchResource
    {
        $perPage = max(1, min(100, $request->integer('per_page', 10)));
        $runs = ReconciliationRunRecord::query()
            ->latest('completed_at')
            ->paginate($perPage);

        return $this->ok($runs);
    }

    public function reconcile(Request $request, LedgerReconciliationService $service): ResearchResource
    {
        $accountId = $request->string('account_id')->toString();
        if ($accountId !== '') {
            $result = $service->reconcileAccount($accountId);
        } else {
            $result = $service->reconcileAll();
        }

        return $this->ok($result);
    }

    public function dataFreshness(SystemFreshnessService $freshness): ResearchResource
    {
        return $this->ok($freshness->check());
    }
}
