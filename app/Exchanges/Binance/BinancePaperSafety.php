<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use Brick\Math\BigDecimal;
use DomainException;

/** Gate for synthetic PAPER fills, never a live-order authorization. */
final class BinancePaperSafety
{
    public static function assertProvenance(PaperAccountRecord $account): void
    {
        if ($account->exchange_id !== 'binance' || ! $account->is_primary || $account->research_experiment_id !== null
            || ! in_array($account->primary_strategy_id, (array) config('paper.primary_strategies', []), true)
            || $account->primary_strategy_id !== $account->strategy_id) {
            throw new DomainException('Binance PRIMARY account provenance is not verified.');
        }
    }

    public static function assertExecutable(PaperAccountRecord $account): void
    {
        self::assertProvenance($account);
        if (config('exchanges.binance.paper_execution_approved') !== true) {
            throw new DomainException('Binance paper execution is not approved.');
        }
        if ($account->status !== 'ACTIVE' || BigDecimal::of($account->current_equity)->isLessThanOrEqualTo('0')) {
            throw new DomainException('Binance paper account is not funded and active.');
        }
        self::assertCostEvidence();
        if (in_array($account->strategy_id, (array) config('research.forward_paper.signal_only_strategies', []), true)
            || $account->strategy_id === 'funding_open_interest_squeeze') {
            throw new DomainException('Strategy is research signal-only on Binance until execution semantics are validated.');
        }
    }

    public static function assertCostEvidence(): void
    {
        foreach (['paper_taker_rate', 'paper_slippage_rate'] as $key) {
            $value = config('exchanges.binance.'.$key);
            if (! is_string($value) || ! preg_match('/^(?:0|[0-9]+)(?:\.[0-9]+)?$/D', $value)
                || BigDecimal::of($value)->isLessThanOrEqualTo('0') || BigDecimal::of($value)->isGreaterThanOrEqualTo('0.1')) {
                throw new DomainException('Binance paper fee/slippage evidence is missing or invalid.');
            }
        }
    }
}
