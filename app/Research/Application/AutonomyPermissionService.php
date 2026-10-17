<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use App\Research\Infrastructure\Persistence\AutonomySettingRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class AutonomyPermissionService
{
    private const DEFAULT_CAPABILITIES = [
        'market_collection' => true,
        'discover_hypotheses' => true,
        'retrain_models' => true,
        'tune_parameters' => true,
        'launch_shadow' => true,
        'start_paper_trials' => true,
        'auto_promote_paper' => false, // Approval required by default
        'change_hard_risk_limits' => false, // Never automatic
        'live_exchange_trading' => false, // Strictly prohibited
    ];

    /**
     * Get or initialize global autonomy settings.
     */
    public function getSettings(): AutonomySettingRecord
    {
        return AutonomySettingRecord::query()->firstOrCreate(
            ['id' => 'default'],
            [
                'capabilities' => self::DEFAULT_CAPABILITIES,
                'kill_switch_active' => false,
                'max_active_shadow_experiments' => 4,
                'max_forward_paper_trials' => 2,
                'max_promotions_per_week' => 2,
                'min_shadow_observation_days' => 7,
                'max_allowed_drawdown_percent' => 12.0,
            ]
        );
    }

    /**
     * Check if an autonomous capability is allowed.
     */
    public function isAllowed(string $capability): bool
    {
        // Absolute invariants
        if (in_array($capability, ['change_hard_risk_limits', 'live_exchange_trading'], true)) {
            return false;
        }

        $settings = $this->getSettings();
        if ($settings->kill_switch_active) {
            return false;
        }

        $caps = (array) $settings->capabilities;

        return (bool) ($caps[$capability] ?? false);
    }

    /**
     * Update autonomy settings with an audit trail.
     */
    public function updateSettings(array $capabilities, array $limits = [], string $actor = 'USER_OPERATOR'): AutonomySettingRecord
    {
        $settings = $this->getSettings();

        // Ensure absolute invariants can never be enabled
        $capabilities['change_hard_risk_limits'] = false;
        $capabilities['live_exchange_trading'] = false;

        $previous = $settings->capabilities;
        $settings->update([
            'capabilities' => array_merge((array) $settings->capabilities, $capabilities),
            'max_active_shadow_experiments' => $limits['max_active_shadow_experiments'] ?? $settings->max_active_shadow_experiments,
            'max_forward_paper_trials' => $limits['max_forward_paper_trials'] ?? $settings->max_forward_paper_trials,
            'max_promotions_per_week' => $limits['max_promotions_per_week'] ?? $settings->max_promotions_per_week,
            'min_shadow_observation_days' => $limits['min_shadow_observation_days'] ?? $settings->min_shadow_observation_days,
            'max_allowed_drawdown_percent' => $limits['max_allowed_drawdown_percent'] ?? $settings->max_allowed_drawdown_percent,
        ]);

        $this->recordEvent(
            actor: $actor,
            action: 'PERMISSION_CHANGE',
            targetType: 'AUTONOMY_SETTINGS',
            targetId: 'default',
            previousState: json_encode($previous),
            newState: json_encode($settings->capabilities),
            reason: 'Autonomy permissions and resource limits updated.',
            evidence: ['limits' => $limits]
        );

        return $settings->fresh();
    }

    /**
     * Toggle emergency kill switch.
     */
    public function toggleKillSwitch(bool $active, string $actor = 'USER_OPERATOR', string $reason = 'Manual kill-switch intervention'): AutonomySettingRecord
    {
        $settings = $this->getSettings();
        $prev = $settings->kill_switch_active;
        $settings->update(['kill_switch_active' => $active]);

        $this->recordEvent(
            actor: $actor,
            action: 'KILL_SWITCH',
            targetType: 'AUTONOMY_SETTINGS',
            targetId: 'default',
            previousState: $prev ? 'ACTIVE' : 'INACTIVE',
            newState: $active ? 'ACTIVE' : 'INACTIVE',
            reason: $reason
        );

        return $settings->fresh();
    }

    /**
     * Record an immutable audit event.
     */
    public function recordEvent(
        string $actor,
        string $action,
        string $targetType,
        ?string $targetId,
        ?string $previousState,
        string $newState,
        string $reason,
        ?array $evidence = null
    ): AutonomyApprovalEventRecord {
        return AutonomyApprovalEventRecord::query()->create([
            'id' => (string) Str::uuid(),
            'actor' => $actor,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'previous_state' => $previousState,
            'new_state' => $newState,
            'reason' => $reason,
            'evidence' => $evidence,
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
