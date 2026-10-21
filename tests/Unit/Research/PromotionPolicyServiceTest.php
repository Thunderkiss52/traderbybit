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

final class PromotionPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluates_gates_and_promotes_with_operator_approval(): void
    {
        $this->app->make(PaperAccountService::class)->default();
        $evolution = new StrategyEvolutionService;
        $policy = $this->app->make(PromotionPolicyService::class);
        $autonomy = $this->app->make(AutonomyPermissionService::class);

        $candidate = $evolution->generateCandidate('trend_pullback', 42);

        // 1. Attempting to promote before gates pass throws validation exception
        $this->expectException(ValidationException::class);
        $policy->promoteCandidate($candidate->id, 'USER_OPERATOR');
    }

    public function test_gates_evaluation_and_promotion_flow(): void
    {
        $this->app->make(PaperAccountService::class)->default();
        $evolution = new StrategyEvolutionService;
        $policy = $this->app->make(PromotionPolicyService::class);
        $autonomy = $this->app->make(AutonomyPermissionService::class);

        $candidate = $evolution->generateCandidate('trend_pullback', 99);

        // 2a. Candidate without validation evidence fails closed
        $failedCandidate = $policy->evaluateCandidate($candidate->id);
        self::assertSame('REJECTED', $failedCandidate->status);
        self::assertFalse($failedCandidate->validation_gates['all_gates_passed']);
        self::assertSame('missing_validation_evidence', $failedCandidate->validation_gates['failure_reason']);

        // 2b. Create a real completed backtest run in the database
        $backtestRun = BacktestRunRecord::query()->create([
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
                    'average_r' => 0.35,
                    'profit_factor' => 1.45,
                    'win_rate' => 0.55,
                    'maximum_drawdown_percent' => 7.5,
                ],
                'all_segments' => ['total_trades' => 120],
                'test_is_out_of_sample' => true,
            ],
            'warnings' => [],
            'errors' => [],
            'started_at' => now()->subMinutes(10),
            'completed_at' => now(),
        ]);

        // Evaluate candidate with real completed backtest run
        $evaluated = $policy->evaluateCandidate($candidate->id, $backtestRun->id);

        self::assertSame('PAPER_ELIGIBLE', $evaluated->status);
        self::assertTrue($evaluated->validation_gates['all_gates_passed']);
        self::assertSame($backtestRun->id, $evaluated->validation_gates['validation_run_id']);
        self::assertSame('BACKTEST_RUN', $evaluated->validation_gates['validation_source']);

        // 3. Autonomous promotion is allowed for a gate-validated candidate.
        $promoted = $policy->promoteCandidate($candidate->id, 'SYSTEM_AUTONOMOUS');
        self::assertSame('PAPER_PROMOTED', $promoted->status);
        self::assertNotNull($promoted->paper_account_id);
        self::assertNotNull($promoted->promoted_at);

        // 4. Rollback candidate
        $rolledBack = $policy->rollbackCandidate($candidate->id, 'Excessive live drawdown observed', 'USER_OPERATOR');
        self::assertSame('ROLLED_BACK', $rolledBack->status);
        self::assertSame('Excessive live drawdown observed', $rolledBack->rollback_reason);
    }

    public function test_rejects_candidate_without_out_of_sample_validation(): void
    {
        $this->app->make(PaperAccountService::class)->default();
        $evolution = new StrategyEvolutionService;
        $policy = $this->app->make(PromotionPolicyService::class);
        $candidate = $evolution->generateCandidate('trend_pullback', 501);

        $run = BacktestRunRecord::query()->create([
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
                'total_trades' => 60,
                'average_r' => 0.5,
                'profit_factor' => 1.7,
                'maximum_drawdown_percent' => 5.0,
            ],
            'warnings' => [],
            'errors' => [],
            'started_at' => now()->subMinutes(10),
            'completed_at' => now(),
        ]);

        $evaluated = $policy->evaluateCandidate($candidate->id, $run->id);

        self::assertSame('REJECTED', $evaluated->status);
        self::assertFalse($evaluated->validation_gates['out_of_sample_verified']['pass']);
        self::assertFalse($evaluated->validation_gates['calibration_error']['applicable']);
    }

    public function test_calibration_gate_is_not_applicable_to_strategy_candidates(): void
    {
        $this->app->make(PaperAccountService::class)->default();
        $evolution = new StrategyEvolutionService;
        $policy = $this->app->make(PromotionPolicyService::class);
        $candidate = $evolution->generateCandidate('momentum', 502);

        $run = BacktestRunRecord::query()->create([
            'id' => (string) Str::uuid(),
            'fingerprint' => 'fp-'.Str::random(16),
            'symbols' => ['BTCUSDT'],
            'strategy_config' => ['id' => 'momentum'],
            'risk_config' => [],
            'execution_config' => [],
            'strategy_version' => '1.3.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'backtest_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'dataset_checksum' => 'checksum-'.Str::random(16),
            'status' => 'COMPLETED',
            'type' => 'WALK_FORWARD',
            'from_at' => now()->subDays(30),
            'to_at' => now(),
            'metrics' => [
                'test' => [
                    'total_trades' => 60,
                    'average_r' => 0.5,
                    'profit_factor' => 1.7,
                    'maximum_drawdown_percent' => 5.0,
                ],
                'all_segments' => ['total_trades' => 200],
                'test_is_out_of_sample' => true,
            ],
            'warnings' => [],
            'errors' => [],
            'started_at' => now()->subMinutes(10),
            'completed_at' => now(),
        ]);

        $evaluated = $policy->evaluateCandidate($candidate->id, $run->id);

        self::assertSame('PAPER_ELIGIBLE', $evaluated->status);
        self::assertFalse($evaluated->validation_gates['calibration_error']['applicable']);
        self::assertTrue($evaluated->validation_gates['calibration_error']['pass']);
    }
}
