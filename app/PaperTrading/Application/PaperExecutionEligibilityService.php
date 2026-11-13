<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\Exchanges\Application\ExchangePaperApprovalPolicy;
use App\Exchanges\Application\PaperExecutionModelContract;
use App\Exchanges\Binance\BinancePaperSafety;
use App\Exchanges\Domain\ExchangeLifecycleStatus;
use App\Exchanges\ExchangeRegistry;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\PaperTrading\Domain\SignalLifecycle;
use App\PaperTrading\Domain\TradeRiskClass;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Strategy\Application\PreEntryStrategyEvidenceService;
use App\Strategy\Application\StrategyExchangeAssignmentService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalQuality;
use App\Strategy\Infrastructure\Persistence\ResearchExperimentRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class PaperExecutionEligibilityService
{
    public function __construct(
        private StrategyRegistry $registry,
        private array $config = [],
    ) {}

    /**
     * Determines whether an account is eligible to ingest or open new paper entries.
     * Note: This controls new entries only. Existing open positions can always exit and settle funding.
     */
    public function isAccountEntryEligible(PaperAccountRecord $account): bool
    {
        return $this->accountEntryRejectionReason($account) === null;
    }

    /**
     * Returns null if eligible for new entries, or a specific rejection reason code if ineligible.
     */
    public function accountEntryRejectionReason(PaperAccountRecord $account): ?string
    {
        // 1. Exchange-specific safety and lifecycle gates
        $registered = app(ExchangeRegistry::class)->registeredIds();
        if (! in_array($account->exchange_id, $registered, true)) {
            return 'unknown_exchange';
        }

        $exchangeRecord = ExchangeRecord::query()->find($account->exchange_id);
        if ($exchangeRecord !== null) {
            if ($exchangeRecord->lifecycle_status === ExchangeLifecycleStatus::Deactivated->value) {
                return 'exchange_deactivated';
            }
            if ($exchangeRecord->lifecycle_status === ExchangeLifecycleStatus::Paused->value) {
                return 'exchange_paused';
            }
            if (! ExchangePaperApprovalPolicy::allowsEntries($exchangeRecord)) {
                return 'exchange_not_paper_approved';
            }
            if (! PaperExecutionModelContract::supported(
                (string) $exchangeRecord->market_type,
                (array) ($exchangeRecord->capabilities ?? []),
                (array) ($exchangeRecord->paper_execution_assumptions ?? []),
                (array) ($exchangeRecord->cost_model ?? []),
                app(ExchangeRegistry::class)->hasAdapter($account->exchange_id),
            )) {
                return 'exchange_execution_model_unverified';
            }
        } else {
            return 'exchange_catalog_record_missing';
        }

        if ($account->exchange_id === 'binance') {
            try {
                BinancePaperSafety::assertExecutable($account);
            } catch (DomainException) {
                return 'binance_account_not_executable';
            }
        }

        // 2. Drawdown & account status gates
        $drawdown = $account->current_drawdown_percent;
        if ($drawdown === null) {
            return $account->status === 'ACTIVE' ? 'drawdown_unverified' : 'account_not_active';
        }
        try {
            $measured = BigDecimal::of((string) $drawdown);
            $threshold = BigDecimal::min(
                BigDecimal::of((string) ($this->config['drawdown']['block_new_entries_percent'] ?? '5')),
                BigDecimal::of('5'),
            );
        } catch (Throwable) {
            return $account->status === 'ACTIVE' ? 'drawdown_unverified' : 'account_not_active';
        }
        if ($measured->isNegative() || $threshold->isLessThanOrEqualTo(0)) {
            return $account->status === 'ACTIVE' ? 'drawdown_unverified' : 'account_not_active';
        }
        if ($measured->isGreaterThanOrEqualTo($threshold)) {
            return 'entry_drawdown_limit_exceeded';
        }
        if ($account->status !== 'ACTIVE') {
            return 'account_not_active';
        }

        $globalReason = $account->is_primary
            ? app(GlobalPaperCapitalGuard::class)->entryRejectionReason() : null;
        if ($globalReason !== null) {
            return $globalReason;
        }

        // 3. SIGNAL_ONLY check (e.g. btc_eth_lead_lag)
        $signalOnly = (array) config('research.forward_paper.signal_only_strategies', []);
        if (in_array($account->strategy_id, $signalOnly, true)) {
            return 'signal_only_strategy';
        }

        // Mode and configuration lifecycle validation
        if ($account->mode === 'RESEARCH_FORWARD_PAPER') {
            if ($account->research_experiment_id !== null) {
                $experiment = ResearchExperimentRecord::query()
                    ->where('id', $account->research_experiment_id)
                    ->where('status', 'COMPLETED')
                    ->where('strategy_id', $account->strategy_id)
                    ->where('strategy_version', $account->strategy_version)
                    ->exists();

                return $experiment ? null : 'unapproved_research_experiment';
            }

            $promoted = LearningCandidateStrategyRecord::query()
                ->where('paper_account_id', $account->id)
                ->where('status', 'PAPER_PROMOTED')
                ->where('candidate_version', $account->strategy_version)
                ->exists();

            return $promoted ? null : 'unpromoted_research_candidate';
        }

        // For STRATEGY_FORWARD_PAPER and FORWARD_PAPER:
        if (str_starts_with($account->strategy_id, 'user_') && ! DB::table('strategy_primary_registrations')->where('strategy_id', $account->strategy_id)->where('enabled', true)->exists()) {
            return 'primary_strategy_not_owner_active';
        }

        if (! app(StrategyExchangeAssignmentService::class)->hasAssignment($account->strategy_id, $account->exchange_id)) {
            return 'strategy_not_assigned_to_exchange';
        }

        $primary = PrimaryStrategies::ids($account->exchange_id);
        $enabled = (array) config('research.forward_paper.enabled_strategies', []);
        if (! in_array($account->strategy_id, $primary, true) && ! in_array($account->strategy_id, $enabled, true)) {
            return 'forward_strategy_disabled';
        }

        try {
            $strategy = $this->registry->get($account->strategy_id);
        } catch (Throwable) {
            return 'strategy_not_registered';
        }

        if ($account->mode === 'STRATEGY_FORWARD_PAPER') {
            if ($account->is_primary) {
                if ($account->strategy_version !== $strategy->version()) {
                    return 'forward_configuration_retired';
                }

                // Configuration hash must match current registered parameters
                $currentHash = StrategyRegistry::hash($strategy->parameters());
                if ($account->configuration_hash === null || $account->configuration_hash !== $currentHash) {
                    return 'forward_configuration_retired';
                }
            } else {
                if ($account->configuration_hash === null || $account->strategy_parameters === null
                    || $account->configuration_hash !== StrategyRegistry::hash($account->strategy_parameters)) {
                    return 'forward_configuration_retired';
                }
            }
        }

        return null;
    }

    /**
     * Validates whether a specific pending signal is eligible to open a position for the given locked account.
     * Throws DomainException with the exact failure reason if not eligible.
     */
    public function assertOpenEligible(
        PaperAccountRecord $account,
        PaperSignalStateRecord $state,
        SignalRecord $signal,
        ?DateTimeImmutable $at = null
    ): void {
        $accountReason = $this->accountEntryRejectionReason($account);
        if ($accountReason !== null) {
            throw new DomainException("Entry ineligible: {$accountReason}");
        }

        if ($state->paper_account_id !== $account->id || $state->signal_id !== $signal->id) {
            throw new DomainException('Entry ineligible: signal_state_identity_mismatch');
        }

        if ($state->status !== SignalLifecycle::PendingEntry->value) {
            throw new DomainException('Entry ineligible: signal_state_not_pending_entry');
        }

        if ($at !== null && $at > $signal->expires_at->toDateTimeImmutable()) {
            throw new DomainException('Entry ineligible: signal_expired');
        }

        if ($signal->exchange !== $account->exchange_id) {
            throw new DomainException('Cross-exchange paper execution prohibited.');
        }

        if ($signal->category !== 'linear') {
            throw new DomainException('Entry ineligible: unsupported_market_category');
        }

        if ($signal->strategy_id !== $account->strategy_id) {
            throw new DomainException('Entry ineligible: strategy_mismatch');
        }

        if ($account->mode === 'STRATEGY_FORWARD_PAPER' && $signal->strategy_version !== $account->strategy_version) {
            throw new DomainException('Entry ineligible: strategy_version_mismatch');
        }

        if ($account->exchange_id === 'binance') {
            BinancePaperSafety::assertExecutable($account);
        }

        // Canonical Quality and Risk Gate Enforcement:
        // No virtual position can ever be opened if the signal is LOW quality, HIGH risk, UNKNOWN, or rejected by the gate.
        $quality = $signal->signal_quality !== null
            ? SignalQuality::fromString($signal->signal_quality)
            : SignalQuality::fromQualityClass(
                $signal->quality_class !== null ? QualityClass::tryFrom($signal->quality_class) : null,
                $signal->score
            );

        if ($quality === SignalQuality::Low) {
            throw new DomainException('Entry ineligible: low_signal_quality_observation_only');
        }
        if ($quality === SignalQuality::Unknown) {
            throw new DomainException('Entry ineligible: unknown_signal_quality');
        }

        $rawRisk = $signal->getRawOriginal('trade_risk');
        $tradeRisk = $rawRisk !== null && $rawRisk !== '' ? TradeRiskClass::tryFrom((string) $rawRisk) : null;
        if ($tradeRisk === null) {
            $decision = RiskDecisionRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->latest('id')->first();
            if ($decision !== null) {
                if (! $decision->allowed) {
                    throw new DomainException('Entry ineligible: '.($decision->reasons[0] ?? 'entry_risk_rejected'));
                }
                $tradeRisk = TradeRiskClass::tryFrom((string) $decision->trade_risk);
            } else {
                $classifier = app(TradeRiskClassificationService::class);
                $riskResult = $classifier->classify([
                    'equity' => $account->current_equity,
                    'entry_price' => $signal->entry_high ?? $signal->entry_low,
                    'stop_loss' => $signal->stop_loss,
                    'take_profit_1' => $signal->take_profit_1,
                    'current_drawdown_percent' => $account->current_drawdown_percent,
                ]);
                $tradeRisk = $riskResult['risk_class'];
            }
        }

        if ($tradeRisk === TradeRiskClass::High) {
            throw new DomainException('Entry ineligible: high_risk_trade_rejected');
        }
        if ($tradeRisk === TradeRiskClass::Unknown) {
            throw new DomainException('Entry ineligible: unknown_trade_risk');
        }

        // Recompute from persisted, scoped evidence under the caller's account lock.
        // A pending decision cannot borrow outcomes that became known after its signal.
        $asOf = $signal->decision_time->toDateTimeImmutable();
        if ($at !== null && $at < $asOf) {
            $asOf = $at;
        }
        if (trim((string) $signal->strategy_version) === '' || trim((string) $signal->timeframe) === '') {
            throw new DomainException('Entry ineligible: missing_evidence_identity');
        }
        $evidence = app(PreEntryStrategyEvidenceService::class)->computePreEntryEvidence(
            $account->strategy_id, $account->exchange_id, $signal->symbol, $signal->side,
            $asOf, (string) $signal->strategy_version, (string) $signal->timeframe,
        );
        $cost = app(PaperExecutionEngine::class)->estimateRoundTripTp1Costs(
            (string) ($signal->side === 'BUY' ? $signal->entry_high : $signal->entry_low),
            (string) $signal->take_profit_1, '1', $account->exchange_id,
        );
        $gate = app(PreExecutionQualityRiskGate::class);
        $gateEval = $gate->evaluate([
            'signal_quality' => $quality,
            'trade_risk' => $tradeRisk,
            'drawdown_percent' => $account->current_drawdown_percent,
            'side' => $signal->side,
            'expected_net_edge' => $evidence['expected_net_edge'],
            'sample_size' => $evidence['sample_size'],
            'evidence_status' => $evidence['status'],
            'directional_win_rate' => $evidence['directional_win_rate'],
            'directional_profit_factor' => $evidence['directional_profit_factor'],
            'directional_sample_size' => $evidence['directional_sample_size'],
            'cost_drag_percent' => $cost['cost_drag_percent'],
        ]);

        if (! $gateEval['decision']->isPermitted()) {
            $reason = $gateEval['rejection_reasons'][0] ?? 'quality_risk_gate_rejected';
            throw new DomainException("Entry ineligible: {$reason}");
        }
    }
}
