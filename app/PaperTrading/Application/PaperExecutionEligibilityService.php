<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\Exchanges\Binance\BinancePaperSafety;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DomainException;
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
        if ($account->status !== 'ACTIVE') {
            return 'account_not_active';
        }

        // SIGNAL_ONLY check (e.g. btc_eth_lead_lag)
        $signalOnly = (array) config('research.forward_paper.signal_only_strategies', []);
        if (in_array($account->strategy_id, $signalOnly, true)) {
            return 'signal_only_strategy';
        }

        // Exchange-specific safety gates
        if ($account->exchange_id === 'binance') {
            if ($account->strategy_id === 'funding_open_interest_squeeze') {
                return 'signal_only_strategy';
            }
            try {
                BinancePaperSafety::assertExecutable($account);
            } catch (DomainException) {
                return 'binance_account_not_executable';
            }
        } elseif ($account->exchange_id !== 'bybit') {
            return 'unknown_exchange';
        }

        // Mode and configuration lifecycle validation
        if ($account->mode === 'RESEARCH_FORWARD_PAPER') {
            $promoted = LearningCandidateStrategyRecord::query()
                ->where('paper_account_id', $account->id)
                ->where('status', 'PAPER_PROMOTED')
                ->where('candidate_version', $account->strategy_version)
                ->exists();

            return $promoted ? null : 'unpromoted_research_candidate';
        }

        // For STRATEGY_FORWARD_PAPER and FORWARD_PAPER:
        $primary = (array) config('paper.primary_strategies', []);
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
        SignalRecord $signal
    ): void {
        $accountReason = $this->accountEntryRejectionReason($account);
        if ($accountReason !== null) {
            throw new DomainException("Entry ineligible: {$accountReason}");
        }

        if ($state->paper_account_id !== $account->id || $state->signal_id !== $signal->id) {
            throw new DomainException('Entry ineligible: signal_state_identity_mismatch');
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
    }
}
