<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\PromotionPolicyService;
use App\Research\Application\StrategyEvolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        // 2. Evaluate candidate with passing gates
        $evaluated = $policy->evaluateCandidate($candidate->id, [
            'sample_size' => 45,
            'expectancy_r' => 0.35,
            'max_drawdown_percent' => 7.5,
            'ece' => 0.06,
        ]);

        self::assertSame('PAPER_ELIGIBLE', $evaluated->status);
        self::assertTrue($evaluated->validation_gates['all_gates_passed']);

        // 3. Autonomous attempt when auto_promote_paper is false -> sets PAPER_PROMOTION_PENDING
        $pending = $policy->promoteCandidate($candidate->id, 'SYSTEM_AUTONOMOUS');
        self::assertSame('PAPER_PROMOTION_PENDING', $pending->status);

        // 4. Operator promotion -> PAPER_PROMOTED and dedicated account provisioned
        $promoted = $policy->promoteCandidate($candidate->id, 'USER_OPERATOR');
        self::assertSame('PAPER_PROMOTED', $promoted->status);
        self::assertNotNull($promoted->paper_account_id);
        self::assertNotNull($promoted->promoted_at);

        // 5. Rollback candidate
        $rolledBack = $policy->rollbackCandidate($candidate->id, 'Excessive live drawdown observed', 'USER_OPERATOR');
        self::assertSame('ROLLED_BACK', $rolledBack->status);
        self::assertSame('Excessive live drawdown observed', $rolledBack->rollback_reason);
    }
}
