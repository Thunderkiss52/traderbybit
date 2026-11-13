<?php

declare(strict_types=1);

namespace Tests\Unit\PaperTrading;

use App\PaperTrading\Application\NewPrimaryCapitalPolicy;
use Tests\TestCase;

final class NewPrimaryCapitalPolicyTest extends TestCase
{
    public function test_discovery_cannot_fund_additional_portfolios_from_default_or_template(): void
    {
        config(['paper.initially_approved_bybit_strategies' => ['baseline'],
            'paper.new_primary_default_starting_balance' => '10000',
            'paper.new_primary_initial_balances.binance' => '50000',
            'paper.new_primary_initial_balances.bybit.new_strategy' => '5000']);
        $policy = new NewPrimaryCapitalPolicy;
        self::assertSame('10000', $policy->openingBalance('bybit', 'baseline'));
        foreach ([['bybit', 'new_strategy'], ['binance', 'baseline'], ['okx', 'baseline'], ['unknown_venue', 'baseline']] as [$exchange, $strategy]) {
            self::assertSame('0', $policy->openingBalance($exchange, $strategy));
        }
        self::assertSame('5000', $policy->activationBalance('5000'));
    }
}
