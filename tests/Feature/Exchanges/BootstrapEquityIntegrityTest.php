<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Binance\BinancePaperCapital;
use App\Exchanges\Binance\BinancePaperProvisioner;
use App\PaperTrading\Application\DailyPerformanceService;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PerformanceStatisticsService;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Jobs\CapturePaperEquityJob;
use App\Research\Application\LedgerReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BootstrapEquityIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_capitalized_accounts_have_one_real_performance_baseline(): void
    {
        $this->freezeTime();
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        app(PaperAccountService::class)->allPrimaryAccounts();
        app(BinancePaperProvisioner::class)->provision();
        (new CapturePaperEquityJob)->handle();
        app(BinancePaperCapital::class)->allocate('10000', 'PHASE0-REGRESSION');
        self::assertSame(13, DB::table('equity_snapshots')->where('exchange_id', 'binance')->count());
        foreach (PaperAccountRecord::query()->where('exchange_id', 'binance')->get() as $account) {
            self::assertDecimalSame('10000', $account->starting_balance);
            self::assertDecimalSame('0', app(PerformanceStatisticsService::class)->accountSummary($account)['maximum_drawdown_percent']);
        }
    }

    public function test_historical_unfunded_checkpoint_is_not_a_loss_after_capital_allocation(): void
    {
        $this->freezeTime();
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        app(PaperAccountService::class)->allPrimaryAccounts();
        app(BinancePaperProvisioner::class)->provision();
        $account = PaperAccountRecord::query()->where('exchange_id', 'binance')->firstOrFail();
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id, 'source_event_key' => 'checkpoint:2026-10-29T00:00:00Z',
            'exchange_id' => 'binance', 'recorded_at' => now()->subMinute(), 'equity' => '0',
            'realized_pnl' => '0', 'unrealized_pnl' => '0', 'drawdown_percent' => '0',
        ]);
        app(BinancePaperCapital::class)->allocate('10000', 'PHASE0-REGRESSION');
        self::assertDecimalSame('0', app(PerformanceStatisticsService::class)->accountSummary($account->refresh())['maximum_drawdown_percent']);
        self::assertSame(1, EquitySnapshotRecord::query()->performance()->where('paper_account_id', $account->id)->count());
    }

    public function test_legacy_bootstrap_is_readable_but_not_a_loss_and_real_zero_remains_a_loss(): void
    {
        $this->freezeTime();
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        app(PaperAccountService::class)->allPrimaryAccounts();
        app(BinancePaperProvisioner::class)->provision();
        $account = PaperAccountRecord::query()->where('exchange_id', 'binance')->firstOrFail();
        EquitySnapshotRecord::query()->firstOrCreate([
            'paper_account_id' => $account->id, 'source_event_key' => 'account:initial:binance',
        ], ['exchange_id' => 'binance', 'recorded_at' => now(), 'equity' => '0',
            'realized_pnl' => '0', 'unrealized_pnl' => '0', 'drawdown_percent' => '0']);
        app(BinancePaperCapital::class)->allocate('10000', 'PHASE0-REGRESSION');
        self::assertDecimalSame('0', app(PerformanceStatisticsService::class)->accountSummary($account->refresh())['maximum_drawdown_percent']);
        self::assertSame(2, EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->count());
        $series = EquitySnapshotRecord::query()->performance()->where('paper_account_id', $account->id)
            ->orderBy('recorded_at')->orderBy('id')->get();
        self::assertCount(1, $series);
        self::assertDecimalSame('10000', $series->first()->equity);
        self::assertTrue(EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->openingCapital()->exists());
        $daily = app(DailyPerformanceService::class)->report($account->id, 'UTC', null, 1, 10);
        self::assertDecimalSame('0', $daily['summary']['all_time']['net_pnl']);
        $window = app(LedgerReconciliationService::class)->reconcileWindow(
            $account->id, CarbonImmutable::now()->startOfDay(), CarbonImmutable::now()->endOfDay());
        self::assertDecimalSame('0', $window['equity_change']);

        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id, 'source_event_key' => 'execution:real-exhaustion',
            'recorded_at' => now(), 'equity' => '0', 'realized_pnl' => '-10000',
            'unrealized_pnl' => '0', 'drawdown_percent' => '100',
        ]);
        self::assertDecimalSame('100', app(PerformanceStatisticsService::class)->accountSummary($account->refresh())['maximum_drawdown_percent']);
    }
}
