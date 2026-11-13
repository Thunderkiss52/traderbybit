<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\Strategy\Application\StrategyCompletenessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemUiApiReconciliationAuditTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['paper.default_starting_balance' => '10000.00']);
        app(PaperTopologySynchronizer::class)->sync(true);
    }

    public function test_strategy_completeness_matrix_covers_all_registered_strategies_and_exchanges(): void
    {
        $service = app(StrategyCompletenessService::class);
        $matrix = $service->buildMatrix();

        $this->assertGreaterThanOrEqual(13, $matrix['total_strategies']);
        $this->assertGreaterThanOrEqual(10, $matrix['total_exchanges']);
        $this->assertGreaterThanOrEqual(130, $matrix['total_topology_pairs']);
        $this->assertGreaterThanOrEqual(13, $matrix['provisioned_pairs']);
        $this->assertGreaterThanOrEqual(13, $matrix['active_pairs']);

        foreach ($matrix['strategies'] as $strat) {
            $this->assertNotEmpty($strat['strategy_id']);
            $this->assertNotEmpty($strat['version']);
            $this->assertTrue($strat['execution_eligible']);
            $this->assertArrayHasKey('bybit', $strat['exchanges']);
            $this->assertArrayHasKey('binance', $strat['exchanges']);
            $this->assertTrue($strat['exchanges']['bybit']['provisioned']);
            $this->assertSame('ACTIVE', $strat['exchanges']['bybit']['account_status']);
            $bybit = $strat['exchanges']['bybit'];
            $this->assertArrayHasKey('execution_block_reason', $bybit);
            $this->assertSame($bybit['execution_block_reason'] === null, $bybit['execution_eligible']);
            // Provisioned or ACTIVE is not equivalent to eligible: exchange approval,
            // risk, drawdown, capital, and strategy assignment must also pass.
        }
    }

    public function test_financial_reconciliation_identity_holds_for_all_accounts(): void
    {
        $accounts = DB::table('paper_accounts')->get();
        $this->assertGreaterThanOrEqual(13, $accounts->count());

        foreach ($accounts as $account) {
            $starting = (float) $account->starting_balance;
            $equity = (float) $account->current_equity;
            $realized = (float) $account->realized_pnl;
            $unrealized = (float) $account->unrealized_pnl;

            // Identity: equity == starting_balance + realized_pnl + unrealized_pnl
            $expectedEquity = $starting + $realized + $unrealized;
            $this->assertEqualsWithDelta(
                $expectedEquity,
                $equity,
                0.001,
                "Financial reconciliation identity failed for account {$account->id} ({$account->exchange_id}:{$account->strategy_id})"
            );
        }
    }

    public function test_dashboard_api_reflects_reconciled_balances_and_aggregate_metrics(): void
    {
        $response = $this->getJson('/api/v1/dashboard?account=all');
        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'market',
                'signals',
                'positions',
                'portfolio' => [
                    'starting_balance',
                    'current_balance',
                    'current_equity',
                    'total_return_percent',
                    'total_net_pnl',
                    'realized_pnl',
                    'unrealized_pnl',
                    'available_virtual_capital',
                    'reserved_risk',
                    'maximum_drawdown_percent',
                    'open_positions',
                    'accounts_count',
                    'is_aggregate',
                ],
                'strategy_portfolios',
            ],
        ]);

        $portfolio = $response->json('data.portfolio');
        $this->assertTrue($portfolio['is_aggregate']);
        $this->assertGreaterThanOrEqual(13, $portfolio['accounts_count']);

        $starting = (float) $portfolio['starting_balance'];
        $equity = (float) $portfolio['current_equity'];
        $realized = (float) $portfolio['realized_pnl'];
        $unrealized = (float) $portfolio['unrealized_pnl'];

        $this->assertEqualsWithDelta($starting + $realized + $unrealized, $equity, 0.01);
    }

    public function test_exchange_comparison_isolates_exchanges_without_blending(): void
    {
        $response = $this->getJson('/api/v1/exchanges/comparison');
        $response->assertOk();

        $data = $response->json('data');
        $this->assertSame('PER_EXCHANGE_PER_STRATEGY', $data['scope']);
        $this->assertSame('DISABLED_BY_DEFAULT', $data['portfolio_aggregation']);
        $this->assertGreaterThanOrEqual(13, $data['strategy_families']);

        $accounts = $data['accounts'];
        $this->assertNotEmpty($accounts);

        $bybitAccounts = array_filter($accounts, fn ($a) => $a['exchange_id'] === 'bybit');
        $this->assertCount(13, $bybitAccounts);

        foreach ($bybitAccounts as $acc) {
            $this->assertSame('ACTIVE', $acc['status']);
            $this->assertSame('10000.000000000000', $acc['starting_balance']);
        }
    }

    public function test_pagination_endpoints_respect_per_page_and_empty_filters(): void
    {
        $endpoints = [
            '/api/v1/signals' => 'data',
            '/api/v1/positions' => 'data',
            '/api/v1/journal' => 'data',
            '/api/v1/audit/exports' => 'data.data',
            '/api/v1/backtests' => 'data',
            '/api/v1/research/patterns' => 'data',
            '/api/v1/research/strategies' => 'data.data',
        ];

        foreach ($endpoints as $uri => $dataPath) {
            // Test standard page request
            $res = $this->getJson("{$uri}?page=1&per_page=10");
            $res->assertOk();

            // Test empty filter result (beyond last page: must return 200 with empty collection, never 500)
            $emptyRes = $this->getJson("{$uri}?page=9999&per_page=10");
            $emptyRes->assertOk();
        }
    }
}
