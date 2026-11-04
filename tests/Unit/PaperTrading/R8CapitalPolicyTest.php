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
        config(['paper.new_primary_initial_balances.bybit.invalid' => '0']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('New PRIMARY virtual opening balance must be positive.');
        $this->policy->openingBalance('bybit', 'invalid');
    }

    public function test_rejects_malformed_starting_balance(): void
    {
        config(['paper.new_primary_initial_balances.bybit.invalid' => 'not-a-number']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid new PRIMARY virtual opening balance.');
        $this->policy->openingBalance('bybit', 'invalid');
    }
}
