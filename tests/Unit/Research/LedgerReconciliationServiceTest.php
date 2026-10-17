<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\LedgerReconciliationService;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LedgerReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_passes_on_clean_account(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $account = $accountService->default();

        $service = $this->app->make(LedgerReconciliationService::class);
        $result = $service->reconcileAccount($account->id);

        self::assertSame('PASSED', $result['status']);
        self::assertSame(0, $result['discrepancy_count']);
        self::assertTrue(BigDecimal::of($result['starting_balance'])->isEqualTo('10000'));
        self::assertTrue(BigDecimal::of($result['recalculated_balance'])->isEqualTo('10000'));
        self::assertTrue(BigDecimal::of($result['recalculated_equity'])->isEqualTo('10000'));
    }

    public function test_reconciliation_detects_injected_discrepancies(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $account = $accountService->default();

        $signalId = (string) Str::uuid();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 80,
            'decision_time' => now('UTC'),
            'candle_close_time' => now('UTC'),
            'expires_at' => now('UTC')->addHour(),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // Inject overclosed position
        VirtualPositionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'paper_account_id' => $account->id,
            'signal_id' => $signalId,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'OPEN',
            'entry_price' => '65000.00',
            'stop_loss' => '64000.00',
            'take_profit_1' => '66000.00',
            'take_profit_2' => '67000.00',
            'initial_quantity' => '0.1',
            'remaining_quantity' => '-0.05', // Negative quantity = overclosed!
            'qty_step' => '0.001',
            'risk_capital' => '100.00',
            'unrealized_pnl' => '10.00',
            'gross_pnl' => '0.00',
            'net_pnl' => '0.00',
            'fees' => '0.00',
            'funding' => '0.00',
            'opened_at' => now('UTC'),
            'maximum_holding_until' => now('UTC')->addHours(4),
            'execution_model' => 'FORWARD_PAPER',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
        ]);

        $service = $this->app->make(LedgerReconciliationService::class);
        $result = $service->reconcileAccount($account->id);

        self::assertSame('DISCREPANCY_DETECTED', $result['status']);
        self::assertGreaterThanOrEqual(1, $result['discrepancy_count']);
        $codes = collect($result['discrepancies'])->pluck('code')->all();
        self::assertContains('OVERCLOSED_POSITION', $codes);
    }
}
