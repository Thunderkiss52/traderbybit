<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use Brick\Math\BigDecimal;
use DomainException;

/**
 * Opening balance is a virtual research allocation, not an exchange deposit.
 * It MUST NOT be used as the shared portfolio risk-budget denominator.
 */
final class NewPrimaryCapitalPolicy
{
    public function openingBalance(string $exchangeId, string $strategyId): string
    {
        $strategyAmount = config("paper.new_primary_initial_balances.{$exchangeId}.{$strategyId}");
        $exchangeAmount = config("paper.new_primary_initial_balances.{$exchangeId}");
        $initiallyApproved = (array) config('paper.initially_approved_bybit_strategies', []);

        // Discovery is not an owner funding instruction. Preserve the explicit
        // legacy Bybit cohort; every additional portfolio starts unfunded.
        if ($exchangeId !== 'bybit' || ! in_array($strategyId, $initiallyApproved, true)) {
            return '0';
        }

        $amount = is_string($strategyAmount)
            ? $strategyAmount
            : (is_string($exchangeAmount)
                ? $exchangeAmount
                : (string) config('paper.new_primary_default_starting_balance', '10000'));

        if (! is_string($amount) || ! preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,12})?$/D', $amount)) {
            throw new DomainException('Invalid new PRIMARY virtual opening balance.');
        }
        if (BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            throw new DomainException('New PRIMARY virtual opening balance must be positive.');
        }

        return (string) BigDecimal::of($amount);
    }

    /** Applied only by a verified prospective PAPER activation, never topology discovery. */
    public function activationBalance(string $amount): string
    {
        if (! preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,12})?$/D', $amount)) {
            throw new DomainException('Invalid autonomous PAPER initial capital.');
        }

        return (string) BigDecimal::of($amount);
    }
}
