<?php

declare(strict_types=1);

namespace Tests\Unit\PaperTrading;

use App\PaperTrading\Application\NewPrimaryCapitalPolicy;
use DomainException;
use Tests\TestCase;

final class R8CapitalPolicyTest extends TestCase
{
    private NewPrimaryCapitalPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new NewPrimaryCapitalPolicy;
    }

    public function test_default_virtual_starting_balance_is_10000(): void
    {
        config(['paper.new_primary_default_starting_balance' => '10000']);
        $amount = $this->policy->openingBalance('bybit', 'baseline');
        self::assertSame('10000', $amount);
    }

    public function test_strategy_specific_configuration_override(): void
    {
        config([
            'paper.new_primary_initial_balances.bybit.trend_pullback' => '25000',
        ]);
        $amount = $this->policy->openingBalance('bybit', 'trend_pullback');
        self::assertSame('25000', $amount);

        // Fallback for unset strategies
        self::assertSame('10000', $this->policy->openingBalance('bybit', 'baseline'));
    }

    public function test_rejects_zero_or_negative_starting_balance(): void
    {
        config(['paper.new_primary_initial_balances.bybit.baseline' => '0']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('New PRIMARY virtual opening balance must be positive.');
        $this->policy->openingBalance('bybit', 'baseline');
    }

    public function test_rejects_malformed_starting_balance(): void
    {
        config(['paper.new_primary_initial_balances.bybit.baseline' => 'not-a-number']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid new PRIMARY virtual opening balance.');
        $this->policy->openingBalance('bybit', 'baseline');
    }

    public function test_new_standalone_strategies_receive_capital_across_all_exchanges(): void
    {
        config(['paper.new_primary_default_starting_balance' => '10000']);
        // Bybit receives configured starting balance
        self::assertSame('10000', $this->policy->openingBalance('bybit', 'new_registered_strategy'));

        // All registered venues receive configured starting balance regardless of execution approval status
        config(['exchanges.okx_paper_execution_approved' => false]);
        config(['exchanges.binance_paper_execution_approved' => false]);
        self::assertSame('10000', $this->policy->openingBalance('okx', 'baseline'));
        self::assertSame('10000', $this->policy->openingBalance('binance', 'baseline'));

        // Supports exchange-specific configuration override
        config(['paper.new_primary_initial_balances.okx' => '15000']);
        self::assertSame('15000', $this->policy->openingBalance('okx', 'baseline'));

        // Supports strategy-specific configuration override
        config(['paper.new_primary_initial_balances.bitget.trend_pullback' => '25000']);
        self::assertSame('25000', $this->policy->openingBalance('bitget', 'trend_pullback'));
        self::assertSame('10000', $this->policy->openingBalance('bitget', 'baseline'));
    }

    public function test_activation_capital_accepts_zero_without_implicitly_funding(): void
    {
        self::assertSame('0', $this->policy->activationBalance('0'));
        self::assertSame('1250.50', $this->policy->activationBalance('1250.50'));
    }
}
