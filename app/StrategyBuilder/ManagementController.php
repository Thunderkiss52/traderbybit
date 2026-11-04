<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use App\Strategy\Application\StrategyRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ManagementController
{
    public function index(Request $request, StrategyRegistry $registry): JsonResponse
    {
        $rows = DB::table('strategy_definitions')->orderBy('strategy_id')->orderByDesc('version')->paginate(25);

        return response()->json(['data' => $rows, 'builtin' => $registry->metadata()]);
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

    public function schema(): JsonResponse
    {
        return response()->json(['data' => DefinitionSchema::document(), 'hosted_enabled' => config('strategy_builder.hosted.enabled') === true]);
    }

    public function validate(Request $request, DefinitionValidator $validator): JsonResponse
    {
        $data = $request->validate(['definition' => 'required|array']);
        $definition = $validator->validate($data['definition']);

        return response()->json(['data' => ['valid' => true, 'checksum' => StrategyRegistry::hash($definition), 'paper_eligible' => false, 'limitations' => ['PAPER_PARITY_UNVERIFIED', 'FUNDING_NOT_MODELED']]]);
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

        return response()->json(['data' => $row, 'audit' => DB::table('strategy_definition_events')->where('definition_id', $id)->orderBy('id')->get(), 'accounts' => []]);
    }

    public function eligibility(int $id): JsonResponse
    {
        abort_unless(DB::table('strategy_definitions')->where('id', $id)->exists(), 404);

        return response()->json(['data' => ['paper_eligible' => false, 'owner_authorized' => false, 'reasons' => ['PAPER_PARITY_UNVERIFIED', 'FUNDING_NOT_MODELED', 'NO_PRIMARY_REGISTRATION']]]);
    }

    public function approval(int $id): JsonResponse
    {
        abort_unless(DB::table('strategy_definitions')->where('id', $id)->exists(), 404);
        DB::table('strategy_definition_events')->insert(['definition_id' => $id, 'action' => 'PAPER_APPROVAL_REJECTED', 'actor' => 'configured-research-owner', 'details' => json_encode(['reason' => 'PAPER_PARITY_UNVERIFIED']), 'created_at' => now('UTC')]);

        return response()->json(['error' => ['code' => 'PAPER_INELIGIBLE', 'message' => 'Go/PAPER execution parity is unverified; no account was provisioned or activated.']], 422);
    }

    public function backtest(Request $request, int $id, HistoricalResearch $research): JsonResponse
    {
        $data = $request->validate(['exchange' => 'required|string|max:32', 'symbol' => 'required|string|max:24', 'from' => 'required|date', 'to' => 'required|date|after:from', 'capital' => 'required|string|regex:/^[0-9]{1,9}(\\.[0-9]{1,8})?$/D']);

        return response()->json(['data' => $research->run($id, $data)]);
    }

    public function runs(int $id): JsonResponse
    {
        return response()->json(['data' => DB::table('strategy_definition_runs')->where('definition_id', $id)->orderByDesc('created_at')->paginate(10)]);
    }
}
