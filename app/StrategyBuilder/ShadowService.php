<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use App\Research\Application\AutonomyPermissionService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Domain\StrategyInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Forward signal observations only: no account, order, balance or ledger authority. */
final readonly class ShadowService
{
    public function __construct(private ForwardEvaluator $forward, private AutonomyPermissionService $autonomy) {}

    public function set(int $id, bool $enabled): object
    {
        $row = DB::table('strategy_definitions')->find($id);
        abort_if($row === null, 404);
        $d = json_decode($row->definition, true);
        if ($enabled) {
            abort_unless($this->autonomy->isAllowed('launch_shadow'), 409, 'Shadow research is disabled or killed.');
            abort_if($d['schema_version'] !== 2 || $d['cooldown_bars'] !== 0 || in_array($row->status, ['ARCHIVED', 'REJECTED'], true), 422, 'Forward shadow requires a validated v2 profile without stateful signal cooldown.');
        }

        return DB::transaction(function () use ($id, $enabled): object {
            DB::statement("SET LOCAL lock_timeout = '5000ms'");
            DB::select('SELECT pg_advisory_xact_lock(20261105, 10002)');
            $row = DB::table('strategy_shadow_subscriptions')->where('definition_id', $id)->lockForUpdate()->first();
            if ($row !== null && (bool) $row->enabled === $enabled) {
                return $row;
            }
            if ($enabled) {
                abort_unless($this->autonomy->isAllowed('launch_shadow'), 409, 'Shadow research is disabled or killed.');
                abort_if(DB::table('strategy_shadow_subscriptions')->where('enabled', true)->count() >= $this->autonomy->getSettings()->max_active_shadow_experiments, 409, 'Active shadow experiment limit reached.');
            }
            DB::table('strategy_shadow_subscriptions')->updateOrInsert(['definition_id' => $id], ['enabled' => $enabled, 'from_at' => now('UTC'), 'created_at' => $row->created_at ?? now('UTC'), 'updated_at' => now('UTC')]);
            DB::table('strategy_workflow_events')->insert(['definition_id' => $id, 'action' => $enabled ? 'FORWARD_SHADOW_STARTED' : 'FORWARD_SHADOW_STOPPED', 'actor' => 'configured-research-owner', 'details' => json_encode(['account_created' => false, 'forward_only' => true]), 'created_at' => now('UTC')]);

            return DB::table('strategy_shadow_subscriptions')->where('definition_id', $id)->first();
        }, 5);
    }

    public function observe(StrategyInput $input): array
    {
        if (! Schema::hasTable('strategy_shadow_subscriptions')) {
            return [];
        }
        $subscriptions = DB::table('strategy_shadow_subscriptions as s')->join('strategy_definitions as d', 'd.id', '=', 's.definition_id')->where('s.enabled', true)->where('s.from_at', '<=', $input->decisionTime)->get(['s.definition_id', 's.from_at', 'd.definition', 'd.checksum', 'd.engine_checksum']);
        if ($subscriptions->isEmpty() || ! $this->autonomy->isAllowed('launch_shadow', false)) {
            return [];
        }
        $rows = [];
        foreach ($subscriptions as $subscription) {
            $d = json_decode($subscription->definition, true);
            if (! in_array($input->exchange, $d['exchanges'], true) || ! in_array($input->symbol, $d['symbols'], true)) {
                continue;
            }
            $id = StrategyRegistry::hash([$subscription->checksum, $input->exchange, $input->symbol, $input->decisionTime->format(DATE_ATOM)]);
            if (DB::table('strategy_shadow_evaluations')->where('id', $id)->exists()) {
                continue;
            }
            try {
                if ($input->validationErrors !== []) {
                    throw new \RuntimeException('Input validation failed.');
                }
                $result = $this->forward->evaluate($d, $input->exchange, $input->symbol, $input->decisionTime, $subscription->engine_checksum);
                $status = $result['signals'] === [] ? 'SKIP' : 'OBSERVED';
            } catch (\Throwable $e) {
                Log::warning('Go DSL shadow evaluation unavailable.', ['definition_id' => $subscription->definition_id, 'exchange' => $input->exchange, 'symbol' => $input->symbol, 'exception_type' => $e::class]);
                $result = ['reason' => 'FORWARD_DATA_OR_ENGINE_UNAVAILABLE', 'performance' => 'UNVERIFIED'];
                $status = 'UNKNOWN';
            }
            $row = ['id' => $id, 'definition_id' => $subscription->definition_id, 'exchange' => $input->exchange, 'symbol' => $input->symbol, 'decision_at' => $input->decisionTime, 'status' => $status, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')];
            DB::table('strategy_shadow_evaluations')->insertOrIgnore($row);
            $rows[] = $row;
        }

        return $rows;
    }
}
