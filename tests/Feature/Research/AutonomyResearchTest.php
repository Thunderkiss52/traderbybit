<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\AutonomyResearchService;
use App\Research\Domain\Autonomy\ChallengerExperiment;
use App\Research\Domain\Autonomy\PaperPromotionPolicy;
use App\Research\Domain\ResearchPromotionDecisionRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A0-A3 — acceptance coverage of the autonomous paper-research packages.
 */
final class AutonomyResearchTest extends TestCase
{
    use RefreshDatabase;

    private string $validToken = 'test-research-token-secret-12345';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.research_api_token' => $this->validToken]);
        $this->app->make(PaperAccountService::class)->allPrimaryAccounts();
    }

    public function test_the_registry_endpoint_exposes_the_candidates_and_keeps_the_flag_off(): void
    {
        $response = $this->getJson('/api/v1/research/autonomy/registry');

        $response->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('data.candidate_count', 5)
            ->assertJsonPath('data.paper_promotion_enabled', false)
            ->assertJsonPath('data.challenger_execution_enabled', true);

        self::assertNotEmpty($response->json('data.registry_hash'));
    }

    public function test_registering_a_hypothesis_is_idempotent_and_the_record_is_append_only(): void
    {
        $service = $this->service();

        $first = $service->registerHypothesis('liquidity_sweep_reversal');
        self::assertSame('REGISTERED', $first['status']);
        self::assertFalse($first['idempotent_replay']);

        $second = $service->registerHypothesis('liquidity_sweep_reversal');
        self::assertSame('ALREADY_REGISTERED', $second['status']);
        self::assertSame($first['registration_hash'], $second['registration_hash']);
        self::assertSame(1, DB::table('research_candidate_hypotheses')->count());
    }

    public function test_an_experiment_cannot_skip_the_data_check(): void
    {
        $result = $this->service()->recordExperimentTransition([
            'hypothesis_id' => 'liquidity_sweep_reversal',
            'from_state' => ChallengerExperiment::PROPOSED,
            'to_state' => ChallengerExperiment::REGISTERED,
        ]);

        self::assertSame('TRANSITION_REFUSED', $result['status']);
        self::assertFalse($result['recorded']);
        self::assertSame(0, DB::table('research_challenger_experiments')->count());
    }

    public function test_a_registered_experiment_records_its_transitions(): void
    {
        $service = $this->service();

        $dataCheck = $service->recordExperimentTransition([
            'hypothesis_id' => 'liquidity_sweep_reversal',
            'from_state' => ChallengerExperiment::PROPOSED,
            'to_state' => ChallengerExperiment::DATA_CHECK,
        ]);
        self::assertSame('RECORDED', $dataCheck['status']);

        $registered = $service->recordExperimentTransition([
            'hypothesis_id' => 'liquidity_sweep_reversal',
            'experiment_id' => $dataCheck['experiment_id'],
            'from_state' => ChallengerExperiment::DATA_CHECK,
            'to_state' => ChallengerExperiment::REGISTERED,
            'preregistration_hash' => 'prereg-1',
            'data_check_done' => true,
        ]);
        self::assertSame('RECORDED', $registered['status']);

        self::assertSame(2, DB::table('research_challenger_experiments')->count());
        self::assertSame(2, count($service->experiments($dataCheck['experiment_id'])['transitions']));
    }

    public function test_the_promotion_is_not_eligible_without_a_shadow_history(): void
    {
        config(['research.autonomy.paper_promotion_enabled' => true]);
        app(AutonomyPermissionService::class)->updateSettings(['auto_promote_paper' => true]);

        $verdict = $this->service()->evaluatePromotion([
            'evidence' => [
                'valid_utc_days' => 30,
                'ess' => 25,
                'train_observations' => 400,
                'oos_observations' => 150,
                'net_improvement_lcb' => '0.4',
                'max_drawdown_degradation_pct' => '0',
                'max_concentration_degradation' => '0',
            ],
        ]);

        self::assertSame(PaperPromotionPolicy::VERDICT_NOT_ELIGIBLE, $verdict['verdict']);
        self::assertSame(PaperPromotionPolicy::REASON_SHADOW_HISTORY_ABSENT, $verdict['reason']);
        self::assertFalse($verdict['applied']);
    }

    public function test_the_promotion_is_blocked_while_the_feature_flag_is_off(): void
    {
        app(AutonomyPermissionService::class)->updateSettings(['auto_promote_paper' => false]);
        $verdict = $this->service()->evaluatePromotion([
            'evidence' => [
                'valid_utc_days' => 30,
                'ess' => 25,
                'train_observations' => 400,
                'oos_observations' => 150,
                'shadow_observations' => 120,
                'shadow_days' => 15,
                'shadow_trades' => 60,
                'net_improvement_lcb' => '0.4',
                'max_drawdown_degradation_pct' => '0',
                'max_concentration_degradation' => '0',
            ],
        ]);

        self::assertSame(PaperPromotionPolicy::VERDICT_BLOCKED_FLAG_OFF, $verdict['verdict']);
        self::assertFalse($verdict['applied']);
        app(AutonomyPermissionService::class)->updateSettings(['auto_promote_paper' => true]);
    }

    public function test_a_recorded_promotion_decision_is_never_applied(): void
    {
        config(['research.autonomy.paper_promotion_enabled' => true]);
        app(AutonomyPermissionService::class)->updateSettings(['auto_promote_paper' => true]);

        $verdict = $this->service()->evaluatePromotion([
            'experiment_id' => 'ee989a20-ce2b-5d33-b9bd-996422155e9f',
            'hypothesis_id' => 'liquidity_sweep_reversal',
            'persist' => true,
            'evidence' => [
                'valid_utc_days' => 30,
                'ess' => 25,
                'train_observations' => 400,
                'oos_observations' => 150,
                'shadow_observations' => 120,
                'shadow_days' => 15,
                'shadow_trades' => 60,
                'net_improvement_lcb' => '0.4',
                'max_drawdown_degradation_pct' => '0',
                'max_concentration_degradation' => '0',
            ],
        ]);

        self::assertSame(PaperPromotionPolicy::VERDICT_ELIGIBLE, $verdict['verdict']);

        $record = ResearchPromotionDecisionRecord::query()->first();
        self::assertNotNull($record);
        self::assertFalse((bool) $record->applied, 'A research promotion decision is never applied.');
        self::assertTrue((bool) $record->feature_flag_enabled);
    }

    public function test_the_autonomy_packages_never_touch_the_paper_ledger(): void
    {
        $accountsBefore = DB::table('paper_accounts')->count();
        $positionsBefore = DB::table('virtual_positions')->count();
        $executionsBefore = DB::table('virtual_executions')->count();
        $configBefore = config('strategy');

        $service = $this->service();
        $service->registerHypothesis('volatility_compression_breakout');
        $service->recordExperimentTransition([
            'hypothesis_id' => 'volatility_compression_breakout',
            'from_state' => ChallengerExperiment::PROPOSED,
            'to_state' => ChallengerExperiment::DATA_CHECK,
        ]);
        $service->evaluatePromotion(['evidence' => []]);
        $service->proposeAllocations([
            'current_risk_capital' => ['alpha' => '100'],
            'volatility' => ['alpha' => 0.2],
            'total_risk_budget' => '100',
        ]);

        self::assertSame($accountsBefore, DB::table('paper_accounts')->count());
        self::assertSame($positionsBefore, DB::table('virtual_positions')->count());
        self::assertSame($executionsBefore, DB::table('virtual_executions')->count());
        self::assertSame($configBefore, config('strategy'));
    }

    public function test_the_write_endpoints_require_the_research_token(): void
    {
        $this->postJson('/api/v1/research/autonomy/experiments', [
            'hypothesis_id' => 'liquidity_sweep_reversal',
            'from_state' => ChallengerExperiment::PROPOSED,
            'to_state' => ChallengerExperiment::DATA_CHECK,
        ])->assertUnauthorized();

        $this->postJson('/api/v1/research/autonomy/promotions', [
            'evidence' => [],
        ])->assertUnauthorized();
    }

    public function test_the_experiment_endpoint_records_and_refuses_transitions(): void
    {
        $recorded = $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/autonomy/experiments', [
                'hypothesis_id' => 'liquidity_sweep_reversal',
                'from_state' => ChallengerExperiment::PROPOSED,
                'to_state' => ChallengerExperiment::DATA_CHECK,
            ]);

        $recorded->assertCreated()->assertJsonPath('status', 'RECORDED');

        $refused = $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/autonomy/experiments', [
                'hypothesis_id' => 'liquidity_sweep_reversal',
                'from_state' => ChallengerExperiment::PROPOSED,
                'to_state' => ChallengerExperiment::REGISTERED,
            ]);

        $refused->assertStatus(409)->assertJsonPath('status', 'TRANSITION_REFUSED');
    }

    public function test_the_allocation_proposal_is_research_only(): void
    {
        $result = $this->service()->proposeAllocations([
            'current_risk_capital' => ['alpha' => '100', 'beta' => '200'],
            'volatility' => ['alpha' => 0.2, 'beta' => 0.3],
            'correlation' => ['alpha|beta' => 0.5],
            'caps' => ['alpha' => 0.7, 'beta' => 0.7],
            'total_risk_budget' => '500',
        ]);

        self::assertTrue($result['research_only']);
        self::assertFalse($result['applied']);
        self::assertFalse($result['ledgers_merged']);
        self::assertFalse($result['total_risk_increased']);
    }

    public function test_the_kill_switch_blocks_the_promotion_and_is_recorded(): void
    {
        config(['research.autonomy.paper_promotion_enabled' => true]);
        app(AutonomyPermissionService::class)->updateSettings(['auto_promote_paper' => true]);

        $service = $this->service();
        self::assertFalse($service->killSwitchActive());

        $activation = $service->activateKillSwitch('OPERATOR_STOP', 'operator-1');
        self::assertSame('KILL_SWITCH_ACTIVATED', $activation['status']);
        self::assertTrue($service->killSwitchActive());

        $verdict = $service->evaluatePromotion([
            'evidence' => [
                'valid_utc_days' => 30,
                'ess' => 25,
                'train_observations' => 400,
                'oos_observations' => 150,
                'shadow_observations' => 120,
                'shadow_days' => 15,
                'shadow_trades' => 60,
                'net_improvement_lcb' => '0.4',
                'max_drawdown_degradation_pct' => '0',
                'max_concentration_degradation' => '0',
            ],
        ]);

        self::assertSame(PaperPromotionPolicy::VERDICT_BLOCKED_KILL_SWITCH, $verdict['verdict']);
        self::assertFalse($verdict['applied']);
        self::assertSame(1, DB::table('research_promotion_controls')->count());
    }

    public function test_the_kill_switch_release_is_refused_by_default(): void
    {
        $service = $this->service();
        $service->activateKillSwitch('OPERATOR_STOP');

        $release = $service->releaseKillSwitch('AUTHORISED');

        self::assertSame('RELEASE_REFUSED', $release['status']);
        self::assertTrue($service->killSwitchActive());
    }

    public function test_the_kill_switch_release_requires_the_explicit_authorisation_flag(): void
    {
        config(['research.autonomy.kill_switch_release_enabled' => true]);

        $service = $this->service();
        $service->activateKillSwitch('OPERATOR_STOP');
        $release = $service->releaseKillSwitch('AUTHORISED', 'operator-1');

        self::assertSame('KILL_SWITCH_RELEASED', $release['status']);
        self::assertFalse($service->killSwitchActive());
    }

    public function test_a_rollback_is_refused_when_operational_restoration_is_unsupported(): void
    {
        $decisionId = $this->persistedPromotionDecision();
        $positionsBefore = DB::table('virtual_positions')->count();
        $controlsBefore = DB::table('research_promotion_controls')->count();

        $rollback = $this->service()->rollbackPromotion([
            'promotion_decision_id' => $decisionId,
            'reason' => 'ROLLBACK_MAX_DRAWDOWN',
            'actor' => 'operator-1',
            'monitoring' => ['max_drawdown_pct' => 7.5],
        ]);

        self::assertSame('ROLLBACK_REFUSED', $rollback['status']);
        self::assertSame('OPERATIONAL_ROLLBACK_UNSUPPORTED', $rollback['reason']);
        self::assertFalse($rollback['operational_change']);
        self::assertFalse($rollback['paper_ledger_mutated']);
        self::assertFalse($rollback['applied']);
        self::assertContains('ROLLBACK_MAX_DRAWDOWN', $rollback['trigger_evaluation']['breached']);
        self::assertSame($positionsBefore, DB::table('virtual_positions')->count());
        self::assertSame($controlsBefore, DB::table('research_promotion_controls')->count());
        self::assertFalse((bool) ResearchPromotionDecisionRecord::query()->findOrFail($decisionId)->rollback_applied);
    }

    public function test_repeated_rollback_requests_are_idempotent_and_never_claim_success(): void
    {
        $decisionId = $this->persistedPromotionDecision();
        $controlsBefore = DB::table('research_promotion_controls')->count();
        $positionsBefore = DB::table('virtual_positions')->count();

        $writes = [];
        DB::listen(static function ($event) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });

        $first = $this->service()->rollbackPromotion(['promotion_decision_id' => $decisionId]);
        $second = $this->service()->rollbackPromotion(['promotion_decision_id' => $decisionId]);

        self::assertSame('ROLLBACK_REFUSED', $first['status']);
        self::assertSame('ROLLBACK_REFUSED', $second['status']);
        self::assertSame('OPERATIONAL_ROLLBACK_UNSUPPORTED', $second['reason']);
        self::assertSame([], $writes);
        self::assertSame($controlsBefore, DB::table('research_promotion_controls')->count());
        self::assertSame($positionsBefore, DB::table('virtual_positions')->count());
        self::assertFalse((bool) ResearchPromotionDecisionRecord::query()->findOrFail($decisionId)->rollback_applied);
    }

    private function persistedPromotionDecision(): string
    {
        config(['research.autonomy.paper_promotion_enabled' => true]);
        app(AutonomyPermissionService::class)->updateSettings(['auto_promote_paper' => true]);

        $verdict = $this->service()->evaluatePromotion([
            'experiment_id' => 'fa0ca78f-88b9-53f1-ba77-bae44e2c6689',
            'persist' => true,
            'evidence' => [
                'valid_utc_days' => 30,
                'ess' => 25,
                'train_observations' => 400,
                'oos_observations' => 150,
                'shadow_observations' => 120,
                'shadow_days' => 15,
                'shadow_trades' => 60,
                'net_improvement_lcb' => '0.4',
                'max_drawdown_degradation_pct' => '0',
                'max_concentration_degradation' => '0',
            ],
        ]);

        return (string) $verdict['record_id'];
    }

    public function test_a_rollback_of_an_unknown_decision_is_refused(): void
    {
        $result = $this->service()->rollbackPromotion([
            'promotion_decision_id' => '00000000-0000-0000-0000-000000000000',
        ]);

        self::assertSame('ROLLBACK_REFUSED', $result['status']);
        self::assertSame('PROMOTION_DECISION_NOT_FOUND', $result['reason']);
    }

    public function test_a_malformed_promotion_decision_id_is_refused_without_a_uuid_query(): void
    {
        $result = $this->service()->rollbackPromotion([
            'promotion_decision_id' => 'not-a-uuid',
        ]);

        self::assertSame('ROLLBACK_REFUSED', $result['status']);
        self::assertSame('PROMOTION_DECISION_NOT_FOUND', $result['reason']);
    }

    public function test_the_rollback_endpoint_reports_unsupported_restoration_as_a_conflict(): void
    {
        $decisionId = $this->persistedPromotionDecision();

        $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/autonomy/rollbacks', [
                'promotion_decision_id' => $decisionId,
                'monitoring' => ['max_drawdown_pct' => 7.5],
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'ROLLBACK_REFUSED')
            ->assertJsonPath('data.reason', 'OPERATIONAL_ROLLBACK_UNSUPPORTED')
            ->assertJsonPath('data.operational_change', false)
            ->assertJsonPath('data.paper_ledger_mutated', false);
    }

    public function test_the_control_endpoints_are_readable_and_write_protected(): void
    {
        $this->getJson('/api/v1/research/autonomy/promotion-controls')
            ->assertOk()
            ->assertJsonPath('data.kill_switch_active', false);

        $this->postJson('/api/v1/research/autonomy/kill-switch', [
            'action' => 'activate',
            'reason' => 'OPERATOR_STOP',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/research/autonomy/rollbacks', [
            'promotion_decision_id' => '00000000-0000-0000-0000-000000000000',
        ])->assertUnauthorized();
    }

    public function test_the_kill_switch_endpoint_activates_and_refuses_the_release(): void
    {
        $activated = $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/autonomy/kill-switch', [
                'action' => 'activate',
                'reason' => 'OPERATOR_STOP',
                'actor' => 'operator-1',
            ]);

        $activated->assertCreated()->assertJsonPath('status', 'KILL_SWITCH_ACTIVATED');

        $release = $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/autonomy/kill-switch', [
                'action' => 'release',
                'reason' => 'AUTHORISED',
            ]);

        $release->assertStatus(409)->assertJsonPath('status', 'RELEASE_REFUSED');
    }

    public function test_f19_autonomy_settings_reports_raw_flags_beside_effective_permissions_and_reasons(): void
    {
        $autonomy = app(AutonomyPermissionService::class);
        $autonomy->updateSettings([
            'auto_promote_paper' => true,
            'discover_hypotheses' => true,
        ]);

        // When kill switch is active, raw capabilities remain true, but effective capabilities become false with reason
        $autonomy->toggleKillSwitch(true, 'USER_OPERATOR', 'Emergency freeze');

        $res = $this->getJson('/api/v1/research/learning/autonomy-settings');
        $res->assertOk();

        // Raw configured value is preserved
        $res->assertJsonPath('data.capabilities.auto_promote_paper', true);
        $res->assertJsonPath('data.kill_switch_active', true);

        // Effective permission is false with KILL_SWITCH_ACTIVE reason
        $res->assertJsonPath('data.effective_capabilities.auto_promote_paper.configured', true);
        $res->assertJsonPath('data.effective_capabilities.auto_promote_paper.effective', false);
        $res->assertJsonPath('data.effective_capabilities.auto_promote_paper.reason', 'KILL_SWITCH_ACTIVE');

        // Absolute system invariants can never be effective under any circumstance
        $res->assertJsonPath('data.effective_capabilities.live_exchange_trading.effective', false);
        $res->assertJsonPath('data.effective_capabilities.live_exchange_trading.reason', 'ABSOLUTE_SYSTEM_INVARIANT');
        $res->assertJsonPath('data.effective_capabilities.change_hard_risk_limits.effective', false);
        $res->assertJsonPath('data.effective_capabilities.change_hard_risk_limits.reason', 'ABSOLUTE_SYSTEM_INVARIANT');
    }

    public function test_f20_dual_kill_switch_matrix_blocks_effectively_and_reports_both_authorities_without_writes(): void
    {
        $service = $this->service();
        $autonomy = app(AutonomyPermissionService::class);

        $combinations = [
            ['research' => false, 'learning' => false, 'expected' => false],
            ['research' => true,  'learning' => false, 'expected' => true],
            ['research' => false, 'learning' => true,  'expected' => true],
            ['research' => true,  'learning' => true,  'expected' => true],
        ];

        foreach ($combinations as $combo) {
            // Set learning kill switch
            $autonomy->toggleKillSwitch($combo['learning'], 'OPERATOR', 'Matrix test');

            // Set research promotion control kill switch
            if ($combo['research']) {
                $service->activateKillSwitch('Matrix test', 'OPERATOR');
            } else {
                DB::table('research_promotion_controls')
                    ->where('action', 'KILL_SWITCH_ACTIVATED')
                    ->delete();
            }

            $controlsBefore = DB::table('research_promotion_controls')->count();
            $settingsBefore = DB::table('autonomy_settings')->count();

            // GET research promotion controls
            $res1 = $this->getJson('/api/v1/research/autonomy/promotion-controls');
            $res1->assertOk()
                ->assertJsonPath('data.kill_switch_active', $combo['expected'])
                ->assertJsonPath('data.effective_kill_switch_active', $combo['expected'])
                ->assertJsonPath('data.authorities.research_promotion_control', $combo['research'])
                ->assertJsonPath('data.authorities.learning_autonomy_settings', $combo['learning']);

            // GET learning autonomy settings
            $res2 = $this->getJson('/api/v1/research/learning/autonomy-settings');
            $res2->assertOk()
                ->assertJsonPath('data.kill_switch_active', $combo['expected'])
                ->assertJsonPath('data.authorities.research_promotion_control', $combo['research'])
                ->assertJsonPath('data.authorities.learning_autonomy_settings', $combo['learning']);

            // Verify GET endpoints are strictly read-only (zero writes)
            self::assertSame($controlsBefore, DB::table('research_promotion_controls')->count());
            self::assertSame($settingsBefore, DB::table('autonomy_settings')->count());

            // Verify both services block autonomous promotions effectively
            self::assertSame($combo['expected'], $service->killSwitchActive());
            self::assertSame(! $combo['expected'], $autonomy->isAllowed('discover_hypotheses'));
        }
    }

    private function service(): AutonomyResearchService
    {
        return $this->app->make(AutonomyResearchService::class);
    }
}
