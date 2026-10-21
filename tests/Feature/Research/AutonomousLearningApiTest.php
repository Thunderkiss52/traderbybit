<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\PaperTrading\Application\PaperAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AutonomousLearningApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_learning_overview_and_autonomy_settings_endpoints(): void
    {
        $res = $this->getJson('/api/v1/research/learning/overview');
        $res->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'settings',
                    'feature_store',
                    'models',
                    'candidates',
                    'operating_mode',
                ],
            ])
            ->assertJsonPath('data.operating_mode', 'PAPER_TRADING_RESEARCH_ONLY');

        $settingsRes = $this->getJson('/api/v1/research/learning/autonomy-settings');
        $settingsRes->assertOk()
            ->assertJsonPath('data.kill_switch_active', false);

        $updateRes = $this->postJson('/api/v1/research/learning/autonomy-settings', [
            'capabilities' => ['auto_promote_paper' => true],
            'limits' => ['max_active_shadow_experiments' => 3],
        ]);
        $updateRes->assertOk()
            ->assertJsonPath('data.capabilities.auto_promote_paper', true)
            ->assertJsonPath('data.max_active_shadow_experiments', 3);

        $killRes = $this->postJson('/api/v1/research/learning/kill-switch', [
            'active' => true,
            'reason' => 'Emergency risk test',
        ]);
        $killRes->assertOk()
            ->assertJsonPath('data.kill_switch_active', true);
    }

    public function test_candidate_lifecycle_api_endpoints(): void
    {
        $this->app->make(PaperAccountService::class)->default();

        // 1. Generate candidate
        $genRes = $this->postJson('/api/v1/research/learning/candidates/generate', [
            'parent_strategy_id' => 'trend_pullback',
            'seed' => 4567,
        ]);
        $genRes->assertOk()
            ->assertJsonPath('data.status', 'RESEARCH_ONLY')
            ->assertJsonPath('data.parent_strategy_id', 'trend_pullback');

        $candidateId = $genRes->json('data.id');

        // 2. Create completed backtest run for validation evidence
        $passingRun = BacktestRunRecord::query()->create([
            'id' => (string) Str::uuid(),
            'fingerprint' => 'fp-'.Str::random(16),
            'symbols' => ['BTCUSDT'],
            'strategy_config' => ['id' => 'trend_pullback'],
            'risk_config' => [],
            'execution_config' => [],
            'strategy_version' => '1.3.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'backtest_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'dataset_checksum' => 'checksum-'.Str::random(16),
            'status' => 'COMPLETED',
            'type' => 'SPLIT',
            'from_at' => now()->subDays(30),
            'to_at' => now(),
            'metrics' => [
                'test' => [
                    'total_trades' => 45,
                    'average_r' => 0.40,
                    'profit_factor' => 1.85,
                    'win_rate' => 0.58,
                    'maximum_drawdown_percent' => 6.5,
                ],
                'all_segments' => ['total_trades' => 120],
                'test_is_out_of_sample' => true,
            ],
            'started_at' => now()->subHours(1),
            'completed_at' => now(),
        ]);

        // 2. Evaluate candidate using immutable run ID
        $evalRes = $this->postJson('/api/v1/research/learning/candidates/'.$candidateId.'/evaluate', [
            'validation_run_id' => $passingRun->id,
        ]);
        $evalRes->assertOk()
            ->assertJsonPath('data.status', 'PAPER_ELIGIBLE')
            ->assertJsonPath('data.validation_gates.all_gates_passed', true);

        // 3. Promote candidate
        $promoteRes = $this->postJson('/api/v1/research/learning/candidates/'.$candidateId.'/promote', [
            'actor' => 'USER_OPERATOR',
        ]);
        $promoteRes->assertOk()
            ->assertJsonPath('data.status', 'PAPER_PROMOTED');

        // 4. Rollback candidate
        $rollbackRes = $this->postJson('/api/v1/research/learning/candidates/'.$candidateId.'/rollback', [
            'reason' => 'Observed performance divergence',
        ]);
        $rollbackRes->assertOk()
            ->assertJsonPath('data.status', 'ROLLED_BACK');

        // 5. Query candidate list
        $listRes = $this->getJson('/api/v1/research/learning/candidates');
        $listRes->assertOk()
            ->assertJsonPath('data.total', 1);

        // 6. Audit trail
        $auditRes = $this->getJson('/api/v1/research/learning/audit-trail');
        $auditRes->assertOk()
            ->assertJsonStructure(['data' => ['data', 'total']]);
    }

    public function test_models_endpoint(): void
    {
        $res = $this->getJson('/api/v1/research/learning/models');
        $res->assertOk();

        $trainRes = $this->postJson('/api/v1/research/learning/models/train', [
            'target_event' => 'TP_BEFORE_SL',
            'symbol' => 'BTCUSDT',
        ]);
        $trainRes->assertOk();
    }
}
