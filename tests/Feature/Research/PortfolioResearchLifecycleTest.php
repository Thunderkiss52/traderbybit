<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Exchanges\Application\ExchangeStrategyCompatibilityService;
use App\Exchanges\Infrastructure\Persistence\ExchangeStrategyCompatibilityRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\Research\Application\PortfolioResearchLifecycleService;
use App\Research\Jobs\EvaluateStrategyCandidateJob;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PortfolioResearchLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluation_is_bounded_and_never_funds_or_unblocks_accounts_without_evidence(): void
    {
        Queue::fake();
        app(ExchangeStrategyCompatibilityService::class)->reconcileAll();
        $before = DB::table('paper_accounts')->orderBy('id')->get()->toJson();
        $result = app(PortfolioResearchLifecycleService::class)->process(3);
        self::assertSame(['evaluated' => 3, 'research_scheduled' => 0, 'paper_activated' => 0], $result);
        self::assertSame($before, DB::table('paper_accounts')->orderBy('id')->get()->toJson());
        $records = ExchangeStrategyCompatibilityRecord::query()->get()->filter(fn ($record) => isset($record->diagnostics['autonomous_lifecycle']));
        self::assertCount(3, $records);
        foreach ($records as $record) {
            self::assertFalse($record->diagnostics['autonomous_lifecycle']['automatic_activation_applied']);
            self::assertNotEmpty($record->diagnostics['autonomous_lifecycle']['blockers']);
        }
        Queue::assertNotPushed(EvaluateStrategyCandidateJob::class);
    }

    public function test_policy_api_rejects_negative_capital_and_preserves_existing_portfolios(): void
    {
        $account = app(PaperAccountService::class)->default();
        $account->update(['status' => 'BLOCKED']);
        self::assertTrue(BigDecimal::of((string) $account->starting_balance)->isPositive());
        $before = DB::table('paper_accounts')->orderBy('id')->get()->toJson();
        $this->postJson('/api/v1/research/learning/autonomy-settings', [
            'limits' => ['paper_initial_capital' => '-1'],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/research/learning/autonomy-settings', [
            'capabilities' => ['auto_promote_paper' => true],
            'limits' => ['paper_initial_capital' => '2500'],
        ])->assertOk()->assertJsonPath('data.paper_initial_capital', '2500.000000000000');
        self::assertSame($before, DB::table('paper_accounts')->orderBy('id')->get()->toJson());
    }

    public function test_eligible_research_is_scheduled_once_without_financial_activation(): void
    {
        Queue::fake();
        $service = app(ExchangeStrategyCompatibilityService::class);
        foreach (['5', '15', '30', '60'] as $interval) {
            $rows = [];
            for ($i = 1; $i <= 220; $i++) {
                $time = now('UTC')->subMinutes($i * (int) $interval);
                $rows[] = ['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => $interval,
                    'open_time' => $time, 'open' => '100', 'high' => '105', 'low' => '95', 'close' => '102',
                    'volume' => '10', 'turnover' => '1000', 'is_closed' => true, 'created_at' => $time, 'updated_at' => $time];
            }
            CandleRecord::query()->insert($rows);
        }
        // Populate the other identities; the newly registered/missing Bybit baseline pair is discovered automatically.
        $service->reconcileAll();
        ExchangeStrategyCompatibilityRecord::query()->where('exchange_id', 'bybit')->where('strategy_id', 'baseline')->delete();
        $lifecycle = app(PortfolioResearchLifecycleService::class);
        self::assertSame(1, $lifecycle->process(1)['research_scheduled']);
        self::assertSame(0, $lifecycle->process(1)['research_scheduled']);
        Queue::assertPushed(EvaluateStrategyCandidateJob::class, 1);
        self::assertSame(0, DB::table('paper_accounts')->where('status', 'ACTIVE')->where('exchange_id', '!=', 'bybit')->count());
    }
}
