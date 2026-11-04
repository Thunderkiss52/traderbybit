<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperExecutionEligibilityService;
use App\PaperTrading\Application\PerformanceStatisticsService;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

final class AccountSafetyRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_percent_valuation_drawdown_persists_block_and_rejects_new_entries(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();
        $recalculate = new ReflectionMethod(PositionLifecycleService::class, 'refreshAccountMetrics');
        $recalculate->invoke(
            $this->app->make(PositionLifecycleService::class),
            $account, BigDecimal::of('-500'), BigDecimal::zero(),
        );

        $account = $account->fresh();
        self::assertSame('BLOCKED', $account->status);
        self::assertSame('9500.000000000000', $account->current_equity);
        self::assertSame('5.00000000', $account->current_drawdown_percent);
        self::assertSame('entry_drawdown_limit_exceeded',
            $this->app->make(PaperExecutionEligibilityService::class)->accountEntryRejectionReason($account));
        self::assertSame('BLOCKED', $this->app->make(PaperAccountService::class)->resume($account->id)->status);
    }

    public function test_equity_recovery_does_not_reactivate_blocked_bybit_account_without_owner_approval(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();
        $recalculate = new ReflectionMethod(PositionLifecycleService::class, 'refreshAccountMetrics');
        $lifecycle = $this->app->make(PositionLifecycleService::class);
        $recalculate->invoke($lifecycle, $account, BigDecimal::of('-500'), BigDecimal::zero());
        self::assertSame('BLOCKED', $account->fresh()->status);
        $recalculate->invoke($lifecycle, $account->fresh(), BigDecimal::of('-400'), BigDecimal::zero());
        self::assertSame('BLOCKED', $account->fresh()->status);
        self::assertSame('4.00000000', $account->fresh()->current_drawdown_percent);

        $accounts = $this->app->make(PaperAccountService::class);
        try {
            $accounts->resume($account->id);
            self::fail('A blocked account was reactivated without owner approval.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('owner approval', $error->getMessage());
        }
        self::assertSame('BLOCKED', $account->fresh()->status);
        self::assertSame(0, JournalEventRecord::query()->where('paper_account_id', $account->id)
            ->where('event_type', 'OWNER_APPROVED_PAPER_RESUME')->count());

        self::assertSame('ACTIVE', $accounts->resume($account->id, ownerApproved: true)->status);
        self::assertSame(1, JournalEventRecord::query()->where('paper_account_id', $account->id)
            ->where('event_type', 'OWNER_APPROVED_PAPER_RESUME')->count());
    }

    public function test_unverified_or_negative_drawdown_blocks_new_entries(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();
        $eligibility = $this->app->make(PaperExecutionEligibilityService::class);
        $account->current_drawdown_percent = null;
        self::assertSame('drawdown_unverified', $eligibility->accountEntryRejectionReason($account));
        $account->current_drawdown_percent = '-1';
        self::assertSame('drawdown_unverified', $eligibility->accountEntryRejectionReason($account));
        $account->current_drawdown_percent = 'invalid';
        self::assertSame('drawdown_unverified', $eligibility->accountEntryRejectionReason($account));
    }

    public function test_owner_flag_cannot_reactivate_an_account_with_negative_drawdown(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();
        $account->update(['status' => 'BLOCKED', 'current_drawdown_percent' => '-1']);
        try {
            $this->app->make(PaperAccountService::class)->resume($account->id, ownerApproved: true);
            self::fail('Negative drawdown was accepted for an owner-approved resume.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('Invalid PAPER account drawdown', $error->getMessage());
        }
        self::assertSame('BLOCKED', $account->fresh()->status);
    }

    public function test_resume_keeps_hard_drawdown_limits_and_cannot_override_cooldown(): void
    {
        $service = $this->app->make(PaperAccountService::class);
        $account = $service->default();
        $account->update([
            'status' => 'HALTED', 'current_drawdown_percent' => '12',
            'drawdown_override' => true, 'cooldown_until' => now()->addHour(),
        ]);

        $resumed = $service->resume($account->id);
        self::assertSame('HALTED', $resumed->status);
        self::assertFalse($resumed->drawdown_override);
        self::assertNotNull($resumed->cooldown_until);
    }

    public function test_forward_accounts_are_version_and_configuration_isolated_without_resetting_history(): void
    {
        $service = $this->app->make(PaperAccountService::class);
        $a = $service->strategyForward('momentum', '1.0.0', ['minimum_score' => 70]);
        $same = $service->strategyForward('momentum', '1.0.0', ['minimum_score' => 70]);
        $different = $service->strategyForward('momentum', '1.1.0', ['minimum_score' => 75]);
        self::assertSame($a->id, $same->id);
        self::assertNotSame($a->id, $different->id);
        $this->assertDatabaseCount('paper_accounts', 2);
        try {
            $service->strategyForward('momentum', '1.0.0', ['minimum_score' => 95]);
            self::fail('Changing configuration under the same strategy version must be rejected.');
        } catch (\DomainException $expected) {
            self::assertStringContainsString('version bump', $expected->getMessage());
        }
        self::assertSame(1, EquitySnapshotRecord::query()->where('paper_account_id', $a->id)
            ->where('source_event_key', 'account:initial')->count());
    }

    public function test_drawdown_metrics_include_starting_equity(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();
        $account->update(['current_equity' => '9902.113231607524', 'realized_pnl' => '-97.886768392476']);
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id, 'recorded_at' => now()->addMinute(),
            'equity' => '9902.113231607524', 'realized_pnl' => '-97.886768392476',
            'unrealized_pnl' => '0', 'drawdown_percent' => '0', 'source_event_key' => 'fixture:loss',
        ]);
        $metrics = $this->app->make(PerformanceStatisticsService::class)->calculate($account->id)->metrics;
        self::assertGreaterThanOrEqual(0.97886768, (float) $metrics['maximum_drawdown_percent']);
        $summary = $this->app->make(PerformanceStatisticsService::class)->accountSummary($account);
        self::assertGreaterThanOrEqual(0.97886768, (float) $summary['maximum_drawdown_percent']);
    }
}
