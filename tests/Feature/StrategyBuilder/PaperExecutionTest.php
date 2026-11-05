<?php

declare(strict_types=1);

namespace Tests\Feature\StrategyBuilder;

use App\PaperTrading\Application\GlobalPaperCapitalGuard;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Contracts\SignalRepository;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\StrategyBuilder\AccountAuthorization;
use App\StrategyBuilder\DefinitionStore;
use App\StrategyBuilder\HistoricalResearch;
use App\StrategyBuilder\PaperGovernance;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsDslHistory;
use Tests\TestCase;

final class PaperExecutionTest extends TestCase
{
    use RefreshDatabase, SeedsDslHistory;

    public function test_owner_can_fund_and_authorize_only_the_new_strategy_then_forward_paper_executes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-15T00:00:00Z'));
        Http::preventStrayRequests();
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        app(PaperTopologySynchronizer::class)->sync(true);
        DB::table('paper_accounts')->update(['status' => 'BLOCKED']);
        $original = DB::table('paper_accounts')->orderBy('id')->get();
        $this->seedDslHistory('2026-01-14T00:00:00Z', '2026-01-15T00:00:00Z', lowVolatility: true);
        $at = new DateTimeImmutable('2026-01-15T00:00:00Z');
        $close = DB::table('market_candles')->where(['exchange' => 'bybit', 'symbol' => 'BTCUSDT', 'interval' => '15'])->orderByDesc('open_time')->value('close');
        $this->context($at, $close);
        $d = DefinitionTest::example();
        $d['schema_version'] = 2;
        $d['funding'] = ['enabled' => true, 'interval_hours' => 8];
        $d['entry'] = ['BUY' => ['op' => 'gt', 'left' => 'fast', 'right' => 0]];
        $definition = app(DefinitionStore::class)->save($d, 'owner');
        $run = app(HistoricalResearch::class)->run($definition->id, ['exchange' => 'bybit', 'symbol' => 'BTCUSDT', 'from' => '2026-01-14T00:00:00Z', 'to' => '2026-01-15T00:00:00Z', 'capital' => '10000']);
        $governance = app(PaperGovernance::class);
        $governance->certify($definition->id, $run->id);
        $governance->approve($definition->id, $definition->checksum, 'OWNER-TEST-INITIAL');
        self::assertSame(27, DB::table('paper_accounts')->count());
        $account = DB::table('paper_accounts')->where('primary_strategy_id', $d['id'])->first();
        $capital = app(GlobalPaperCapitalGuard::class);
        self::assertSame('global_risk_budget_exceeded', $capital->entryRejectionReason('6501'));
        $allocation = app(AccountAuthorization::class);
        $allocation->fund($definition->id, $account->id, '10000', 'OWNER-TEST-CAPITAL');
        $allocation->fund($definition->id, $account->id, '10000', 'OWNER-TEST-CAPITAL');
        self::assertSame(1, DB::table('paper_capital_events')->where('paper_account_id', $account->id)->count());
        self::assertSame('global_risk_budget_exceeded', $capital->entryRejectionReason('6501'));
        $governance->activate($definition->id, $definition->checksum, 'OWNER-TEST-STRATEGY-ACTIVE', false);
        $allocation->authorize($definition->id, $account->id, 'OWNER-TEST-ACCOUNT-ACTIVE');
        $decision = app(StrategyRegistry::class)->get($d['id'])->evaluate(app(StrategyInputFactory::class)->make('bybit', 'linear', 'BTCUSDT', $at));
        self::assertSame('BUY', $decision->side->value);
        self::assertNotNull($decision->indicatorSnapshot['15m']['atr_14']);
        app(SignalRepository::class)->createIfAbsent($decision);
        app(PaperProcessingService::class)->process($account->id, 'BTCUSDT');
        $this->travelTo(CarbonImmutable::parse('2026-01-15T00:05:00Z'));
        DB::table('market_candles')->insert(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '5', 'open_time' => '2026-01-15T00:00:00Z', 'open' => $close, 'high' => '125', 'low' => '95', 'close' => $close, 'volume' => '10', 'turnover' => '1000', 'is_closed' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->context(new DateTimeImmutable('2026-01-15T00:05:00Z'), $close);
        app(PaperProcessingService::class)->process($account->id, 'BTCUSDT');
        self::assertSame(1, DB::table('virtual_positions')->where('paper_account_id', $account->id)->count(), json_encode(['risk' => DB::table('risk_decisions')->get(), 'states' => DB::table('paper_signal_states')->get()]));
        self::assertSame($original->toJson(), DB::table('paper_accounts')->whereIn('id', $original->pluck('id'))->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
    }

    private function context(DateTimeImmutable $at, string $close): void
    {
        MarketContextRecord::query()->create(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'observed_at' => $at, 'bid' => (string) BigDecimal::of($close)->minus('0.01'), 'ask' => (string) BigDecimal::of($close)->plus('0.01'), 'turnover_24h' => '100000000', 'tick_size' => '0.01', 'qty_step' => '0.001', 'min_order_qty' => '0.001', 'max_order_qty' => '1000', 'min_notional_value' => '1', 'funding_rate' => '0.0001', 'open_interest' => '100000', 'open_interest_change' => '0']);
    }
}
