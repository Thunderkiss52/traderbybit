<?php

declare(strict_types=1);

namespace Tests\Unit\Exchanges;

use App\Exchanges\Binance\BinancePaperSafety;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use DomainException;
use Tests\TestCase;

final class BinancePaperSafetyTest extends TestCase
{
    public function test_explicit_cost_and_activation_evidence_are_required(): void
    {
        $account = new PaperAccountRecord([
            'exchange_id' => 'binance', 'is_primary' => true,
            'primary_strategy_id' => 'trend_pullback', 'strategy_id' => 'trend_pullback',
            'research_experiment_id' => null, 'status' => 'ACTIVE', 'current_equity' => '10000',
        ]);
        config()->set('exchanges.binance.paper_execution_approved', false);
        $this->assertRejects(fn () => BinancePaperSafety::assertExecutable($account));
        config()->set('exchanges.binance.paper_execution_approved', true);
        config()->set('exchanges.binance.paper_taker_rate', null);
        config()->set('exchanges.binance.paper_slippage_rate', null);
        $this->assertRejects(fn () => BinancePaperSafety::assertExecutable($account));
        config()->set('exchanges.binance.paper_taker_rate', '0.0004');
        config()->set('exchanges.binance.paper_slippage_rate', '0.0002');
        BinancePaperSafety::assertExecutable($account);
        $account->status = 'BLOCKED';
        $this->assertRejects(fn () => BinancePaperSafety::assertExecutable($account));
        $account->status = 'ACTIVE';
        $account->current_equity = '0';
        $this->assertRejects(fn () => BinancePaperSafety::assertExecutable($account));
    }

    public function test_bybit_or_nonprimary_account_must_never_execute_on_binance(): void
    {
        $account = new PaperAccountRecord([
            'exchange_id' => 'bybit', 'is_primary' => true, 'primary_strategy_id' => 'baseline',
            'strategy_id' => 'baseline', 'status' => 'ACTIVE', 'current_equity' => '10000',
        ]);
        $this->assertRejects(fn () => BinancePaperSafety::assertProvenance($account));
        $account->exchange_id = 'binance';
        $account->is_primary = false;
        $this->assertRejects(fn () => BinancePaperSafety::assertProvenance($account));
    }

    private function assertRejects(callable $operation): void
    {
        try {
            $operation();
            self::fail('Unsafe Binance operation was accepted.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}
