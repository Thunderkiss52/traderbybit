<?php

declare(strict_types=1);

namespace Tests\Unit\PaperTrading;

use App\PaperTrading\Application\GlobalPaperCapitalGuard;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GlobalPaperCapitalGuardCanonicalRiskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePostgresTestDatabaseReady('paper_accounts');
        app(PaperTopologySynchronizer::class)->sync(true);
    }

    public function test_blocked_accounts_cannot_expand_global_risk_budget(): void
    {
        $guard = app(GlobalPaperCapitalGuard::class);
        $initial = $guard->computeCapitalSummary();
        self::assertTrue(BigDecimal::of($initial['provisioned_capital'])->isEqualTo('130000'));
        self::assertTrue(BigDecimal::of($initial['risk_blocked_capital'])->isZero());
        // A synthetic, transaction-scoped funded BLOCKED cohort retains the
        // original positive-capital negative control without changing discovery.
        DB::beginTransaction();
        try {
            DB::table('paper_accounts')->where('status', 'BLOCKED')->update([
                'starting_balance' => '10000', 'current_equity' => '10000', 'peak_equity' => '10000',
            ]);
            $guard = app(GlobalPaperCapitalGuard::class);
            $summary = $guard->computeCapitalSummary();

            self::assertSame('VALID', $summary['status']);

            // 130 provisioned accounts: 13 Bybit (ACTIVE) + 117 Other (BLOCKED)
            $activeCapital = BigDecimal::of($summary['active_risk_bearing_capital']);
            $provisionedCapital = BigDecimal::of($summary['provisioned_capital']);
            $blockedCapital = BigDecimal::of($summary['risk_blocked_capital']);

            // Bybit has 13 primary strategies * 10,000 = 130,000 USDT active risk capital
            self::assertTrue($activeCapital->isEqualTo(BigDecimal::of('130000')));

            // Total provisioned is 1,300,000 USDT (130 accounts * 10,000)
            self::assertTrue($provisionedCapital->isEqualTo(BigDecimal::of('1300000')));

            // Blocked capital is 1,170,000 USDT (117 accounts * 10,000)
            self::assertTrue($blockedCapital->isEqualTo(BigDecimal::of('1170000')));

            // Deployable risk budget is 5% of ACTIVE capital (6,500 USDT), NOT 5% of provisioned (65,000 USDT)
            $deployableBudget = BigDecimal::of($summary['deployable_risk_budget']);
            self::assertTrue($deployableBudget->isEqualTo(BigDecimal::of('6500')));

            // Proposing 7,000 USDT of risk must be REJECTED because 7,000 > 6,500 USDT
            // (Even though 7,000 would be < 65,000 USDT under the old flawed calculation)
            $rejection = $guard->entryRejectionReason('7000');
            self::assertSame('global_risk_budget_exceeded', $rejection);

            // Proposing 5,000 USDT of risk is within the 6,500 USDT active budget
            $allowed = $guard->entryRejectionReason('5000');
            self::assertNull($allowed);
        } finally {
            DB::rollBack();
        }
    }

    public function test_unapproved_exchanges_cannot_expand_global_risk_budget(): void
    {
        $guard = app(GlobalPaperCapitalGuard::class);

        // By default, Binance is not execution-approved
        config(['exchanges.binance.paper_execution_approved' => false]);
        $summary = $guard->computeCapitalSummary();

        $budgetBefore = BigDecimal::of($summary['deployable_risk_budget']);
        self::assertTrue($budgetBefore->isEqualTo(BigDecimal::of('6500')));

        // Even if an unapproved exchange had status set to ACTIVE in DB,
        // it must NOT expand active risk-bearing capital unless explicitly execution-approved
        DB::table('paper_accounts')->where('exchange_id', 'binance')->update(['status' => 'ACTIVE']);
        try {
            $summaryUnapproved = $guard->computeCapitalSummary();
            $budgetStill = BigDecimal::of($summaryUnapproved['deployable_risk_budget']);
            self::assertTrue($budgetStill->isEqualTo(BigDecimal::of('6500')));
        } finally {
            DB::table('paper_accounts')->where('exchange_id', 'binance')->update(['status' => 'BLOCKED']);
        }
    }

    public function test_global_loss_limit_triggers_on_active_portfolio_drawdown(): void
    {
        $guard = app(GlobalPaperCapitalGuard::class);

        // Active Bybit capital is 130,000 USDT. Max loss is 5% = 6,500 USDT.
        // If current active equity drops by 6,600 USDT, it must trigger global_capital_loss_limit_exceeded.
        $bybitAccount = DB::table('paper_accounts')->where('exchange_id', 'bybit')->where('primary_strategy_id', 'baseline')->first();
        $originalEquity = $bybitAccount->current_equity;

        DB::table('paper_accounts')->where('id', $bybitAccount->id)->update([
            'current_equity' => (string) BigDecimal::of((string) $originalEquity)->minus('6600'),
        ]);

        try {
            $rejection = $guard->entryRejectionReason('100');
            self::assertSame('global_capital_loss_limit_exceeded', $rejection);
        } finally {
            DB::table('paper_accounts')->where('id', $bybitAccount->id)->update([
                'current_equity' => $originalEquity,
            ]);
        }
    }

    public function test_all_blocked_accounts_returns_no_active_risk_capital(): void
    {
        $guard = app(GlobalPaperCapitalGuard::class);

        DB::table('paper_accounts')->where('exchange_id', 'bybit')->update(['status' => 'BLOCKED']);
        try {
            $rejection = $guard->entryRejectionReason('100');
            self::assertSame('global_capital_no_active_risk_capital', $rejection);
        } finally {
            DB::table('paper_accounts')->where('exchange_id', 'bybit')->update(['status' => 'ACTIVE']);
        }
    }
}
