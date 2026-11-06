<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use App\Exchanges\Application\ExchangeStrategyCompatibilityService;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\Strategy\Application\StrategyRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManagementController
{
    public function index(Request $request, StrategyRegistry $registry): JsonResponse
    {
        $rows = DB::table('strategy_definitions')->orderBy('strategy_id')->orderByDesc('version')->paginate(25);

        return response()->json(['data' => $rows, 'builtin' => array_values(array_filter($registry->metadata(), fn ($s) => ! str_starts_with($s['id'], 'user_')))]);
    }

    public function parse(Request $request, AuthoringParser $parser, HostedAuthoring $hosted): JsonResponse
    {
        $data = $request->validate(['description' => 'required|string|max:4000', 'provider' => 'sometimes|in:deterministic,hosted', 'consent' => 'sometimes|boolean']);

        return response()->json(['data' => ($data['provider'] ?? 'deterministic') === 'hosted' ? $hosted->parse($data['description'], $data['consent'] ?? false) : $parser->parse($data['description'])]);
    }

    public function drafts(): JsonResponse
    {
        return response()->json(['data' => DB::table('strategy_drafts')->orderByDesc('updated_at')->orderBy('id')->paginate(25)]);
    }

    public function draft(string $draft): JsonResponse
    {
        $row = DB::table('strategy_drafts')->find($draft);
        abort_if($row === null, 404);

        return response()->json(['data' => $row]);
    }

    public function saveDraft(Request $request, DraftService $drafts): JsonResponse
    {
        $data = $request->validate(['id' => 'required|uuid', 'revision' => 'nullable|integer|min:1', 'definition' => 'required|array|max:32', 'description' => 'nullable|string|max:4000']);

        return response()->json(['data' => $drafts->save($data['id'], $data['revision'] ?? null, $data['definition'], $data['description'] ?? null)]);
    }

    public function publishDraft(Request $request, string $draft, DraftService $drafts): JsonResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'checksum' => 'required|string|size:64', 'interpretation_confirmed' => 'required|accepted']);

        return response()->json(['data' => $drafts->publish($draft, $data['revision'], $data['checksum'])], 201);
    }

    public function datasets(Request $request): JsonResponse
    {
        $data = $request->validate(['exchange' => 'sometimes|string|max:32']);

        return response()->json(['data' => DB::table('historical_dataset_pins')->when(isset($data['exchange']), fn ($q) => $q->where('exchange', $data['exchange']))->orderByDesc('pinned_at')->orderBy('id')->paginate(25)]);
    }

    public function schema(): JsonResponse
    {
        return response()->json(['data' => DefinitionSchema::document(), 'hosted_enabled' => config('strategy_builder.hosted.enabled') === true]);
    }

    public function validate(Request $request, DefinitionValidator $validator): JsonResponse
    {
        $data = $request->validate(['definition' => 'required|array']);
        $definition = $validator->validate($data['definition']);

        return response()->json(['data' => ['valid' => true, 'checksum' => StrategyRegistry::hash($definition), 'paper_eligible' => false, 'technical_profile_supported' => EvaluationProfile::paperBlockers($definition) === [], 'limitations' => EvaluationProfile::paperBlockers($definition), 'approval_required' => true]]);
    }

    public function store(Request $request, DefinitionStore $store): JsonResponse
    {
        $data = $request->validate(['definition' => 'required|array', 'interpretation_confirmed' => 'required|accepted']);

        return response()->json(['data' => $store->save($data['definition'], 'configured-research-owner')], 201);
    }

    public function show(int $id): JsonResponse
    {
        $row = DB::table('strategy_definitions')->find($id);
        abort_if($row === null, 404);

        return response()->json(['data' => $row, 'audit' => DB::table('strategy_definition_events')->where('definition_id', $id)->orderBy('id')->get(), 'workflow_audit' => DB::table('strategy_workflow_events')->where('definition_id', $id)->orderBy('id')->get(), 'accounts' => DB::table('paper_accounts')->where('is_primary', true)->where('primary_strategy_id', $row->strategy_id)->get()]);
    }

    public function eligibility(int $id, PaperGovernance $governance): JsonResponse
    {
        abort_unless(DB::table('strategy_definitions')->where('id', $id)->exists(), 404);

        return response()->json(['data' => $governance->eligibility($id)]);
    }

    public function approval(Request $request, int $id, PaperGovernance $governance): JsonResponse
    {
        abort_unless(DB::table('strategy_definitions')->where('id', $id)->exists(), 404);
        $eligible = $governance->eligibility($id);
        if (! $eligible['paper_eligible']) {
            DB::table('strategy_definition_events')->insert(['definition_id' => $id, 'action' => 'PAPER_APPROVAL_REJECTED', 'actor' => 'configured-research-owner', 'details' => json_encode(['reasons' => $eligible['reasons']]), 'created_at' => now('UTC')]);

            return response()->json(['error' => ['code' => 'PAPER_INELIGIBLE', 'message' => 'PAPER eligibility/parity is incomplete: '.implode(', ', $eligible['reasons'])]], 422);
        }
        $data = $request->validate(['checksum' => 'required|string|size:64', 'reference' => 'required|string|min:8|max:160', 'confirm_primary' => 'required|accepted']);

        return response()->json(['data' => $governance->approve($id, $data['checksum'], $data['reference'])]);
    }

    public function certify(Request $request, int $id, PaperGovernance $governance): JsonResponse
    {
        $data = $request->validate(['run_id' => 'required|string|size:64']);
        try {
            return response()->json(['data' => $governance->certify($id, $data['run_id'])]);
        } catch (\RuntimeException $error) {
            throw ValidationException::withMessages(['evidence' => 'Dataset or pinned evaluator is unavailable; parity certification was not granted.']);
        }
    }

    public function activate(Request $request, int $id, PaperGovernance $governance): JsonResponse
    {
        $data = $request->validate(['checksum' => 'required|string|size:64', 'reference' => 'required|string|min:8|max:160', 'confirm_activation' => 'required|accepted', 'confirm_version_handover' => 'sometimes|boolean']);

        return response()->json(['data' => $governance->activate($id, $data['checksum'], $data['reference'], $data['confirm_version_handover'] ?? false)]);
    }

    public function lifecycle(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:RESEARCH_ENABLED,REJECTED,ARCHIVED', 'reference' => 'required|string|min:8|max:160']);
        $row = DB::transaction(function () use ($id, $data): object {
            $row = DB::table('strategy_definitions')->where('id', $id)->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_if($data['status'] === 'RESEARCH_ENABLED' && $row->status === 'ACTIVE_PAPER', 409, 'Archive or reject the active version explicitly before changing its lifecycle.');
            if ($row->status !== $data['status']) {
                DB::table('strategy_definitions')->where('id', $id)->update(['status' => $data['status']]);
                if (in_array($data['status'], ['ARCHIVED', 'REJECTED'], true)) {
                    DB::table('strategy_primary_registrations')->where('definition_id', $id)->update(['enabled' => false, 'updated_at' => now('UTC')]);
                    DB::table('strategy_shadow_subscriptions')->where('definition_id', $id)->update(['enabled' => false, 'updated_at' => now('UTC')]);
                }
                DB::table('strategy_workflow_events')->insert(['definition_id' => $id, 'action' => 'OWNER_LIFECYCLE_TRANSITION', 'actor' => 'configured-research-owner', 'details' => json_encode(['from' => $row->status, 'to' => $data['status'], 'reference' => $data['reference']]), 'created_at' => now('UTC')]);
            }

            return DB::table('strategy_definitions')->find($id);
        }, 3);

        return response()->json(['data' => $row]);
    }

    public function backtest(Request $request, int $id, HistoricalResearch $research): JsonResponse
    {
        $data = $request->validate(['exchange' => 'required|string|max:32', 'symbol' => 'required|string|max:24', 'from' => 'required|date', 'to' => 'required|date|after:from', 'capital' => 'required|string|regex:/^[0-9]{1,9}(\\.[0-9]{1,8})?$/D', 'dataset_pin_id' => 'sometimes|uuid']);

        return response()->json(['data' => $research->run($id, $data)]);
    }

    public function runs(int $id): JsonResponse
    {
        return response()->json(['data' => DB::table('strategy_definition_runs')->where('definition_id', $id)->orderByDesc('created_at')->paginate(10)]);
    }

    public function optimize(Request $request, int $id, OptimizationService $research): JsonResponse
    {
        $data = $request->validate([
            'exchange' => 'required|string|max:32', 'symbol' => 'required|string|max:24',
            'from' => 'required|date', 'to' => 'required|date|after:from', 'capital' => 'required|string|regex:/^[0-9]{1,9}(\.[0-9]{1,8})?$/D',
            'options' => 'sometimes|array:max_candidates,max_windows,train_hours,validation_hours,test_hours,step_hours,seed,start_shadow',
            'options.max_candidates' => 'sometimes|integer|min:1|max:32', 'options.max_windows' => 'sometimes|integer|min:1|max:3',
            'options.train_hours' => 'sometimes|integer|min:1|max:168', 'options.validation_hours' => 'sometimes|integer|min:1|max:168',
            'options.test_hours' => 'sometimes|integer|min:1|max:168', 'options.step_hours' => 'sometimes|integer|min:1|max:168',
            'options.seed' => 'sometimes|integer|min:0|max:2147483647', 'options.start_shadow' => 'sometimes|boolean',
        ]);
        $options = $data['options'] ?? [];
        unset($data['options']);
        $experiment = $research->create($id, $data, $options);
        if ($experiment->status === 'QUEUED') {
            OptimizationJob::dispatch($experiment->id)->afterCommit();
        }

        return response()->json(['data' => $experiment], 202);
    }

    public function optimizations(int $id): JsonResponse
    {
        return response()->json(['data' => DB::table('strategy_optimization_experiments')->where('definition_id', $id)->orderByDesc('created_at')->paginate(10)]);
    }

    public function optimization(string $experiment): JsonResponse
    {
        $row = DB::table('strategy_optimization_experiments')->find($experiment);
        abort_if($row === null, 404);

        return response()->json(['data' => $row, 'candidates' => DB::table('strategy_optimization_candidates')->where('experiment_id', $experiment)->orderBy('id')->get()]);
    }

    public function cancelOptimization(string $experiment): JsonResponse
    {
        $row = DB::transaction(function () use ($experiment): object {
            $row = DB::table('strategy_optimization_experiments')->where('id', $experiment)->lockForUpdate()->first();
            abort_if($row === null, 404);
            if (in_array($row->status, ['QUEUED', 'RUNNING'], true)) {
                $status = $row->status === 'QUEUED' ? 'CANCELLED' : 'CANCEL_REQUESTED';
                DB::table('strategy_optimization_experiments')->where('id', $experiment)->update(['status' => $status, 'updated_at' => now('UTC')]);
                DB::table('strategy_workflow_events')->insert(['definition_id' => $row->definition_id, 'actor' => 'configured-research-owner', 'action' => 'OPTIMIZATION_CANCELLED', 'details' => json_encode(['experiment_id' => $experiment, 'status' => $status]), 'created_at' => now('UTC')]);
            }

            return DB::table('strategy_optimization_experiments')->find($experiment);
        }, 3);

        return response()->json(['data' => $row]);
    }

    public function shadow(Request $request, int $id, ShadowService $shadows): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);

        return response()->json(['data' => $shadows->set($id, $data['enabled'])]);
    }

    public function shadows(int $id): JsonResponse
    {
        return response()->json(['data' => DB::table('strategy_shadow_evaluations')->where('definition_id', $id)->orderByDesc('decision_at')->paginate(25), 'subscription' => DB::table('strategy_shadow_subscriptions')->where('definition_id', $id)->first()]);
    }

    public function fund(Request $request, int $id, string $account, AccountAuthorization $authorization): JsonResponse
    {
        $data = $request->validate(['amount' => 'required|string|max:30', 'reference' => 'required|string|regex:/^[A-Za-z0-9._:-]{8,160}$/D', 'confirm_virtual_allocation' => 'required|accepted']);

        return response()->json(['data' => $authorization->fund($id, $account, $data['amount'], $data['reference'])]);
    }

    public function authorizeAccount(Request $request, int $id, string $account, AccountAuthorization $authorization): JsonResponse
    {
        $data = $request->validate(['reference' => 'required|string|regex:/^[A-Za-z0-9._:-]{8,160}$/D', 'confirm_account_authorization' => 'required|accepted']);

        return response()->json(['data' => $authorization->authorize($id, $account, $data['reference'])]);
    }

    public function exchanges(int $id, ExchangeStrategyCompatibilityService $compatibility): JsonResponse
    {
        $strategyId = DB::table('strategy_definitions')->where('id', $id)->value('strategy_id');
        abort_if($strategyId === null, 404, 'Strategy definition not found.');

        return response()->json([
            'data' => $compatibility->reconcileStrategy((string) $strategyId),
        ]);
    }

    /**
     * Explicit owner assignment of a strategy to an exchange venue.
     * Invariant: Never auto-activates strategies or spawns paper accounts.
     */
    public function assignExchange(int $id, Request $request, ExchangeStrategyCompatibilityService $compatibility): JsonResponse
    {
        $strategy = DB::table('strategy_definitions')->where('id', $id)->first();
        abort_if($strategy === null, 404, 'Strategy definition not found.');

        $validated = $request->validate([
            'exchange_id' => 'required|string|exists:exchanges,id',
            'market_type' => 'sometimes|string|in:spot,perpetual,futures,options',
            'assigned_by' => 'sometimes|string|max:64',
        ]);

        $exchangeId = strtolower(trim($validated['exchange_id']));
        $marketType = strtolower(trim($validated['market_type'] ?? 'perpetual'));
        $assignedBy = trim($validated['assigned_by'] ?? 'owner');
        $now = now('UTC')->format('Y-m-d H:i:s.uP');

        DB::table('strategy_exchange_assignments')->updateOrInsert(
            [
                'strategy_id' => (string) $strategy->strategy_id,
                'exchange_id' => $exchangeId,
                'market_type' => $marketType,
            ],
            [
                'assigned_by' => $assignedBy,
                'assigned_at' => $now,
                'is_active' => true,
                'metadata' => json_encode(['definition_id' => $id, 'version' => $strategy->version], JSON_THROW_ON_ERROR),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        // Run compatibility check for the assigned pair
        $exchangeRecord = ExchangeRecord::query()->find($exchangeId);
        $compatRecord = null;
        if ($exchangeRecord) {
            $compatRecord = $compatibility->evaluateAndPersist($exchangeRecord, [
                'id' => $strategy->strategy_id,
                'version' => 'v'.$strategy->version,
                'definition' => json_decode($strategy->definition_json, true),
            ]);
        }

        return response()->json([
            'data' => [
                'strategy_id' => $strategy->strategy_id,
                'exchange_id' => $exchangeId,
                'market_type' => $marketType,
                'assigned_at' => $now,
                'compatibility' => $compatRecord ? [
                    'status' => $compatRecord->status,
                    'reasons' => $compatRecord->reasons,
                ] : null,
                'message' => "Strategy '{$strategy->strategy_id}' explicitly assigned to {$exchangeId} ({$marketType}). Requires manual paper authorization before live paper trading.",
            ],
        ]);
    }

    /**
     * List all explicit exchange assignments for a strategy.
     */
    public function assignedExchanges(int $id): JsonResponse
    {
        $strategy = DB::table('strategy_definitions')->where('id', $id)->first();
        abort_if($strategy === null, 404, 'Strategy definition not found.');

        $assignments = DB::table('strategy_exchange_assignments')
            ->where('strategy_id', (string) $strategy->strategy_id)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'data' => $assignments,
        ]);
    }
}
