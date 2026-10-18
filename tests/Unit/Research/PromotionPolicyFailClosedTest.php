<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\PromotionPolicyService;
use App\Research\Application\StrategyEvolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PromotionPolicyFailClosedTest extends TestCase
{
    use RefreshDatabase;

    private PromotionPolicyService $policy;

    private StrategyEvolutionService $evolution;

    private AutonomyPermissionService $autonomy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PaperAccountService::class)->default();
        $this->policy = $this->app->make(PromotionPolicyService::class);
        $this->evolution = new StrategyEvolutionService;
        $this->autonomy = $this->app->make(AutonomyPermissionService::class);
    }

    public function test_candidate_without_validation_run_fails_closed(): void
    {
        $candidate = $this->evolution->generateCandidate('trend_pullback', 101);

        $evaluated = $this->policy->evaluateCandidate($candidate->id);

        $this->assertSame('REJECTED', $evaluated->status);
        $this->assertFalse($evaluated->validation_gates['all_gates_passed']);
        $this->assertSame('missing_validation_evidence', $evaluated->validation_gates['failure_reason']);
    }

    public function test_candidate_with_uncompleted_backtest_run_fails_closed(): void
    {
        $candidate = $this->evolution->generateCandidate('trend_pullback', 102);

        $runningRun = BacktestRunRecord::query()->create([
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
            'status' => 'RUNNING', // Incomplete!
            'type' => 'SPLIT',
            'from_at' => now()->subDays(30),
            'to_at' => now(),
            'metrics' => null,
            'started_at' => now(),
        ]);

        $evaluated = $this->policy->evaluateCandidate($candidate->id, $runningRun->id);

        $this->assertSame('REJECTED', $evaluated->status);
        $this->assertFalse($evaluated->validation_gates['all_gates_passed']);
        $this->assertSame('incomplete_backtest_run', $evaluated->validation_gates['failure_reason']);
    }

    public function test_candidate_with_failing_metrics_fails_gates(): void
    {
        $candidate = $this->evolution->generateCandidate('trend_pullback', 103);

        $failingRun = BacktestRunRecord::query()->create([
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
                'total_trades' => 15, // < 30 threshold!
                'average_r' => -0.10, // negative expectancy!
                'profit_factor' => 0.85,
                'win_rate' => 0.35,
                'maximum_drawdown_percent' => 18.5, // > 12.0 threshold!
                'ece' => 0.25, // > 0.15 threshold!
            ],
            'started_at' => now()->subHours(1),
            'completed_at' => now(),
        ]);

        $evaluated = $this->policy->evaluateCandidate($candidate->id, $failingRun->id);

        $this->assertSame('REJECTED', $evaluated->status);
        $this->assertFalse($evaluated->validation_gates['all_gates_passed']);
        $this->assertFalse($evaluated->validation_gates['min_sample_size']['pass']);
        $this->assertFalse($evaluated->validation_gates['positive_expectancy']['pass']);
        $this->assertFalse($evaluated->validation_gates['max_drawdown']['pass']);
        $this->assertFalse($evaluated->validation_gates['calibration_error']['pass']);
    }

    public function test_candidate_with_passing_backtest_promotes_with_audit_trail(): void
    {
        $candidate = $this->evolution->generateCandidate('trend_pullback', 104);

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
                'total_trades' => 52,
                'average_r' => 0.45,
                'profit_factor' => 1.60,
                'win_rate' => 0.58,
                'maximum_drawdown_percent' => 6.8,
                'ece' => 0.05,
                'brier_score' => 0.16,
            ],
            'started_at' => now()->subHours(1),
            'completed_at' => now(),
        ]);

        $evaluated = $this->policy->evaluateCandidate($candidate->id, $passingRun->id);

        $this->assertSame('PAPER_ELIGIBLE', $evaluated->status);
        $this->assertTrue($evaluated->validation_gates['all_gates_passed']);
        $this->assertSame($passingRun->id, $evaluated->validation_gates['validation_run_id']);

        // Attempt promoting without operator approval when autonomous is false
        $pending = $this->policy->promoteCandidate($candidate->id, 'SYSTEM_AUTONOMOUS');
        $this->assertSame('PAPER_PROMOTION_PENDING', $pending->status);

        // Operator promotes candidate
        $promoted = $this->policy->promoteCandidate($candidate->id, 'USER_OPERATOR');
        $this->assertSame('PAPER_PROMOTED', $promoted->status);
        $this->assertNotNull($promoted->paper_account_id);
    }

    public function test_adversarial_attempt_to_promote_rejected_candidate_throws(): void
    {
        $candidate = $this->evolution->generateCandidate('trend_pullback', 105);
        $evaluated = $this->policy->evaluateCandidate($candidate->id); // Fails closed

        $this->expectException(ValidationException::class);
        $this->policy->promoteCandidate($evaluated->id, 'USER_OPERATOR');
    }
}
