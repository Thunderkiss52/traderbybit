<?php

declare(strict_types=1);

namespace Tests\Feature\StrategyBuilder;

use App\PaperTrading\Application\GlobalPaperCapitalGuard;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\Strategy\Application\StrategyRegistry;
use App\StrategyBuilder\DefinitionStore;
use App\StrategyBuilder\HistoricalResearch;
use App\StrategyBuilder\PaperGovernance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsDslHistory;
use Tests\TestCase;

final class PaperGovernanceTest extends TestCase
{
    use RefreshDatabase, SeedsDslHistory;

    public function test_approved_fourteenth_strategy_is_unfunded_blocked_and_never_expands_budget(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        config(['exchanges.binance_paper_provisioning_approved' => true, 'exchanges.binance.paper_taker_rate' => '0.0005', 'exchanges.binance.paper_slippage_rate' => '0.0002']);
        app(PaperTopologySynchronizer::class)->sync(true);
        DB::table('paper_accounts')->update(['status' => 'BLOCKED']);
        $original = DB::table('paper_accounts')->orderBy('id')->get();
        $budget = config('paper.global_capital');
        $guard = app(GlobalPaperCapitalGuard::class)->entryRejectionReason('100');
        $d = DefinitionTest::example();
        $d['schema_version'] = 2;
        $d['exchanges'] = ['bybit', 'binance'];
        $d['funding'] = ['enabled' => true, 'interval_hours' => 8];
        $d['timeout_bars'] = 2;
        $d['entry'] = ['BUY' => ['op' => 'gt', 'left' => 'fast', 'right' => 0]];
        $definition = app(DefinitionStore::class)->save($d, 'owner');
        $governance = app(PaperGovernance::class);
        self::assertFalse($governance->eligibility($definition->id)['paper_eligible']);
        foreach (['bybit', 'binance'] as $exchange) {
            $this->seedDslHistory(exchange: $exchange);
            $run = app(HistoricalResearch::class)->run($definition->id, ['exchange' => $exchange, 'symbol' => 'BTCUSDT', 'from' => '2026-01-15T00:00:00Z', 'to' => '2026-01-16T00:00:00Z', 'capital' => '10000']);
            $governance->certify($definition->id, $run->id);
        }
        self::assertTrue($governance->eligibility($definition->id)['paper_eligible']);
        $governance->approve($definition->id, $definition->checksum, 'TEST-OWNER-APPROVAL');
        self::assertSame(28, DB::table('paper_accounts')->count());
        $new = DB::table('paper_accounts')->where('primary_strategy_id', $d['id'])->get();
        self::assertCount(2, $new);
        foreach ($new as $account) {
            self::assertSame('BLOCKED', $account->status);
            self::assertDecimalSame('0', $account->starting_balance);
            self::assertDecimalSame('0', $account->current_equity);
        }
        self::assertSame($original->toJson(), DB::table('paper_accounts')->whereIn('id', $original->pluck('id'))->orderBy('id')->get()->toJson());
        self::assertSame($guard, app(GlobalPaperCapitalGuard::class)->entryRejectionReason('100'));
        self::assertSame($budget, config('paper.global_capital'));
        $governance->activate($definition->id, $definition->checksum, 'TEST-OWNER-ACTIVATION', false);
        $governance->approve($definition->id, $definition->checksum, 'TEST-OWNER-REPEAT');
        self::assertSame('ACTIVE_PAPER', DB::table('strategy_definitions')->find($definition->id)->status);
        self::assertSame(28, DB::table('paper_accounts')->count());
        self::assertCount(14, app(StrategyRegistry::class)->all());
        self::assertSame(['BLOCKED', 'BLOCKED'], DB::table('paper_accounts')->where('primary_strategy_id', $d['id'])->orderBy('exchange_id')->pluck('status')->all());
        Http::assertNothingSent();
    }
}
