<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\AutonomyPermissionService;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        self::assertFalse($service->isAllowed('auto_promote_paper')); // Default approval required

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
}
