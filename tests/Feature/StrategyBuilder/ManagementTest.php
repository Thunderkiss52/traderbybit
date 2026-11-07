<?php

declare(strict_types=1);

namespace Tests\Feature\StrategyBuilder;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\GlobalPaperCapitalGuard;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\Strategy\Application\StrategyRegistry;
use App\StrategyBuilder\DefinitionStore;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authoring_and_rejected_approval_preserve_all_registered_accounts_and_global_guard(): void
    {
        Http::preventStrayRequests();
        config(['exchanges.binance_paper_provisioning_approved' => true, 'services.research_api_token' => 'test-owner']);
        app(PaperTopologySynchronizer::class)->sync(true);
        DB::table('paper_accounts')->update(['status' => 'BLOCKED']);
        $before = DB::table('paper_accounts')->orderBy('id')->get()->toJson();
        self::assertSame(130, DB::table('paper_accounts')->count());
        $guard = app(GlobalPaperCapitalGuard::class)->entryRejectionReason('1');
        $budget = config('paper.global_capital');
        $this->withHeader('X-Research-Token', 'test-owner');
        $id = $this->postJson('/api/v1/strategies', ['definition' => DefinitionTest::example(), 'interpretation_confirmed' => true])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/strategies/{$id}/approve-paper")->assertUnprocessable();
        app(PaperTopologySynchronizer::class)->sync(true);
        self::assertSame($before, DB::table('paper_accounts')->orderBy('id')->get()->toJson());
        self::assertSame($guard, app(GlobalPaperCapitalGuard::class)->entryRejectionReason('1'));
        self::assertSame($budget, config('paper.global_capital'));
        Http::assertNothingSent();
    }

    public function test_management_requires_owner_token_and_confirmation(): void
    {
        config(['services.research_api_token' => 'test-owner']);
        $this->getJson('/api/v1/strategies')->assertUnauthorized();
        $this->postJson('/api/v1/strategies/1/activate-paper', ['confirm_activation' => true])->assertUnauthorized();
        $this->withHeader('X-Research-Token', 'invalid')->postJson('/api/v1/strategies', ['definition' => DefinitionTest::example(), 'interpretation_confirmed' => true])->assertForbidden();
        $this->withHeader('X-Research-Token', 'test-owner')->postJson('/api/v1/strategies', ['definition' => DefinitionTest::example()])->assertUnprocessable();
        $id = $this->postJson('/api/v1/strategies', ['definition' => DefinitionTest::example(), 'interpretation_confirmed' => true])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/strategies')->assertOk()->assertJsonCount(13, 'builtin');
        $this->getJson("/api/v1/strategies/{$id}/eligibility")->assertOk()->assertJsonPath('data.paper_eligible', false);
        $this->postJson("/api/v1/strategies/{$id}/approve-paper")->assertUnprocessable()->assertJsonPath('error.code', 'PAPER_INELIGIBLE');
        self::assertSame(0, DB::table('paper_accounts')->count());
        self::assertCount(13, app(StrategyRegistry::class)->all());
        self::assertSame('5', config('paper.drawdown.block_new_entries_percent'));
        self::assertSame('10', config('paper.drawdown.halt_percent'));
    }

    public function test_real_go_backtest_is_pinned_reproducible_and_rejects_gaps(): void
    {
        config(['services.research_api_token' => 'test-owner']);
        $this->withHeader('X-Research-Token', 'test-owner');
        $d = app(DefinitionStore::class)->save(DefinitionTest::example(), 'test-owner');
        $scope = ['exchange' => 'bybit', 'symbol' => 'BTCUSDT', 'from' => '2026-01-15T00:00:00Z', 'to' => '2026-01-16T00:00:00Z', 'capital' => '10000'];
        $this->postJson("/api/v1/strategies/{$d->id}/backtests", $scope)->assertUnprocessable();
        foreach ([5, 15, 30, 60] as $minutes) {
            $from = new DateTimeImmutable($scope['from']);
            $to = new DateTimeImmutable($scope['to']);
            for ($at = $from->modify('-'.(220 * $minutes).' minutes'); $at < $to; $at = $at->modify("+{$minutes} minutes")) {
                $price = (string) (100 + (intdiv($at->getTimestamp(), $minutes * 60) % 20));
                CandleRecord::query()->create(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => (string) $minutes, 'open_time' => $at, 'open' => $price, 'high' => '125', 'low' => '95', 'close' => $price, 'volume' => '10', 'turnover' => '1000', 'is_closed' => true]);
            }
        }
        $one = $this->postJson("/api/v1/strategies/{$d->id}/backtests", $scope)->assertOk()->json('data');
        $two = $this->postJson("/api/v1/strategies/{$d->id}/backtests", $scope)->assertOk()->json('data');
        self::assertSame($one['id'], $two['id']);
        self::assertSame($one['result'], $two['result']);
        self::assertFalse(json_decode($one['result'], true)['paper_eligible']);
        self::assertGreaterThan(0, json_decode($one['result'], true)['report']['total_trades']);
        self::assertSame(1, DB::table('strategy_definition_runs')->count());
        $pin = json_decode($one['metadata'], true)['dataset_pin']['id'];
        $this->getJson('/api/v1/strategies/datasets?exchange=bybit')->assertOk()->assertJsonPath('data.data.0.id', $pin);
        $this->postJson("/api/v1/strategies/{$d->id}/backtests", $scope + ['dataset_pin_id' => $pin])->assertOk();
        $this->postJson("/api/v1/strategies/{$d->id}/backtests", $scope + ['dataset_pin_id' => '00000000-0000-4000-8000-000000000000'])->assertUnprocessable();
        CandleRecord::query()->where('interval', '15')->where('open_time', '2026-01-15T12:00:00Z')->delete();
        $this->postJson("/api/v1/strategies/{$d->id}/backtests", $scope)->assertUnprocessable();
    }
}
