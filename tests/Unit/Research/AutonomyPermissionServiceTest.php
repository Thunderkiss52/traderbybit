<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\Research\Application\AutonomyPermissionService;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AutonomyPermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_autonomy_defaults_and_hard_invariants(): void
    {
        $service = new AutonomyPermissionService;
        $settings = $service->getSettings();

        self::assertFalse($settings->kill_switch_active);
        self::assertTrue($service->isAllowed('market_collection'));
        self::assertTrue($service->isAllowed('tune_parameters'));
        self::assertFalse($service->isAllowed('auto_promote_paper')); // A2 is off by default

        // Hard invariant: never allowed
        self::assertFalse($service->isAllowed('change_hard_risk_limits'));
        self::assertFalse($service->isAllowed('live_exchange_trading'));

        // Attempting to enable hard invariants must be rejected
        $updated = $service->updateSettings([
            'change_hard_risk_limits' => true,
            'live_exchange_trading' => true,
            'auto_promote_paper' => true,
        ]);

        self::assertFalse($service->isAllowed('change_hard_risk_limits'));
        self::assertFalse($service->isAllowed('live_exchange_trading'));
        self::assertTrue($service->isAllowed('auto_promote_paper'));

        $disabled = $service->updateSettings(['auto_promote_paper' => false]);
        self::assertFalse($disabled->capabilities['auto_promote_paper']);
        self::assertFalse($service->isAllowed('auto_promote_paper'));
    }

    public function test_kill_switch_immediately_blocks_all_autonomy(): void
    {
        $service = new AutonomyPermissionService;

        self::assertTrue($service->isAllowed('market_collection'));

        $service->toggleKillSwitch(true, 'USER_OPERATOR', 'Emergency risk halt');

        self::assertFalse($service->isAllowed('market_collection'));
        self::assertFalse($service->isAllowed('tune_parameters'));
        self::assertFalse($service->isAllowed('auto_promote_paper'));

        $events = AutonomyApprovalEventRecord::query()
            ->where('action', 'KILL_SWITCH')
            ->get();

        self::assertCount(1, $events);
        self::assertSame('ACTIVE', $events->first()->new_state);
    }

    public function test_paper_capital_policy_is_explicit_audited_and_does_not_fund_accounts(): void
    {
        $service = new AutonomyPermissionService;
        self::assertSame('0.000000000000', $service->getSettings()->paper_initial_capital);
        $account = app(PaperAccountService::class)->default();
        $account->update(['status' => 'BLOCKED']);
        self::assertTrue(BigDecimal::of((string) $account->starting_balance)->isPositive());
        $before = DB::table('paper_accounts')->orderBy('id')->get()->toJson();
        $settings = $service->updateSettings(['auto_promote_paper' => true], ['paper_initial_capital' => '1250.50']);
        self::assertSame('1250.500000000000', $settings->paper_initial_capital);
        self::assertSame($before, DB::table('paper_accounts')->orderBy('id')->get()->toJson());
        self::assertSame('1250.50', AutonomyApprovalEventRecord::query()->where('action', 'PERMISSION_CHANGE')->firstOrFail()->evidence['limits']['paper_initial_capital']);
    }
}
