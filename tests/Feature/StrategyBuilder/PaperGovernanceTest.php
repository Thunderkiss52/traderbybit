<?php

declare(strict_types=1);

namespace Tests\Feature\StrategyBuilder;

use App\HistoricalData\SourcePolicy;
use App\PaperTrading\Application\GlobalPaperCapitalGuard;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\Strategy\Application\StrategyRegistry;
use App\StrategyBuilder\DefinitionStore;
use App\StrategyBuilder\HistoricalResearch;
use App\StrategyBuilder\PaperGovernance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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
        config(['services.research_api_token' => 'test-owner']);
        $this->withHeader('X-Research-Token', 'test-owner')->postJson('/api/v1/strategies/'.$definition->id.'/accounts/'.$original->first()->id.'/fund', ['amount' => '1000', 'reference' => 'OWNER-CROSS-ACCOUNT', 'confirm_virtual_allocation' => true])->assertForbidden();
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
        $d['version'] = 2;
        $d['indicators']['fast']['period'] = 21;
        $next = app(DefinitionStore::class)->save($d, 'owner');
        foreach (['bybit', 'binance'] as $exchange) {
            $run = app(HistoricalResearch::class)->run($next->id, ['exchange' => $exchange, 'symbol' => 'BTCUSDT', 'from' => '2026-01-15T00:00:00Z', 'to' => '2026-01-16T00:00:00Z', 'capital' => '10000']);
            $governance->certify($next->id, $run->id);
        }
        $governance->approve($next->id, $next->checksum, 'TEST-OWNER-VERSION-TWO');
        $activation = ['checksum' => $next->checksum, 'reference' => 'TEST-OWNER-HANDOVER', 'confirm_activation' => true];
        $this->postJson('/api/v1/strategies/'.$next->id.'/activate-paper', $activation)->assertConflict();
        self::assertSame(['1', '1'], DB::table('paper_accounts')->where('primary_strategy_id', $d['id'])->orderBy('exchange_id')->pluck('strategy_version')->all());
        $this->postJson('/api/v1/strategies/'.$next->id.'/activate-paper', $activation + ['confirm_version_handover' => true])->assertOk();
        self::assertSame(['2', '2'], DB::table('paper_accounts')->where('primary_strategy_id', $d['id'])->orderBy('exchange_id')->pluck('strategy_version')->all());
        self::assertSame($original->toJson(), DB::table('paper_accounts')->whereIn('id', $original->pluck('id'))->orderBy('id')->get()->toJson());
        $this->postJson('/api/v1/strategies/'.$next->id.'/lifecycle', ['status' => 'ARCHIVED', 'reference' => 'TEST-OWNER-ARCHIVE'])->assertOk();
        self::assertFalse(app(StrategyRegistry::class)->get($d['id'])->isEnabled());
        self::assertSame(28, DB::table('paper_accounts')->count());
        self::assertSame([], app(PaperTopologySynchronizer::class)->sync(false)['missing_pairs']);
        Http::assertNothingSent();
    }

    public function test_promotion_license_check(): void
    {
        $policy = app(SourcePolicy::class);
        $from = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $to = new \DateTimeImmutable('2026-01-02T00:00:00Z');

        // Test direct assertUse: PAPER_PROMOTION is accepted under CC-BY-NC-SA-4.0
        $license = [
            'license' => 'CC-BY-NC-SA-4.0',
            'permitted_uses' => ['NONCOMMERCIAL_RESEARCH', 'NONCOMMERCIAL_LEARNING'],
        ];
        $policy->assertUse('PAPER_PROMOTION', $license);

        // Commercial use must throw ValidationException
        $this->expectException(ValidationException::class);
        $policy->assertUse('COMMERCIAL_PRODUCTION_TRADING', $license);
    }
}
