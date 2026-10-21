<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProfitabilityResearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure the 8 primary accounts are initialized
        $this->app->make(PaperAccountService::class)->allPrimaryAccounts();
    }

    public function test_profitability_summary_returns_separated_accounting_scopes_and_gated_placeholders(): void
    {
        $response = $this->getJson('/api/v1/research/profitability/summary');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'as_of',
                'accounting_scopes' => [
                    'point_in_time' => [
                        'scope',
                        'total_starting_balance',
                        'total_current_equity',
                        'total_peak_equity',
                        'total_realized_pnl',
                        'active_accounts_count',
                        'accounts',
                    ],
                    'full_lifecycle_economics' => [
                        'scope',
                        'as_of',
                        'total_positions',
                        'gross_pnl',
                        'fees',
                        'signed_funding',
                        'net_pnl',
                        'accounting_identity_holds',
                        'profit_factor',
                        'win_rate',
                        'counts',
                        'r_metrics',
                    ],
                    'execution_slippage_diagnostic' => [
                        'scope',
                        'execution_count',
                        'stored_slippage_unit',
                        'total_attributable_dollar_drag',
                        'double_deduction_prohibited',
                    ],
                ],
                'gated_modules' => [
                    'P-1',
                    'P-2',
                    'P-3',
                    'P-4',
                    'P-5',
                    'P-6',
                ],
                'archives_status',
            ]);

        $data = $response->json();

        // Scope verification
        $this->assertSame('POINT_IN_TIME', $data['accounting_scopes']['point_in_time']['scope']);
        $this->assertSame('FULL_LIFECYCLE', $data['accounting_scopes']['full_lifecycle_economics']['scope']);
        $this->assertSame('EVENT_EXECUTION', $data['accounting_scopes']['execution_slippage_diagnostic']['scope']);

        // Gated modules status verification (P-1 implemented, P-2 implemented, P-3 blocked, P-4/P-5/P-6 not implemented)
        $this->assertSame('IMPLEMENTED_VERIFIED', $data['gated_modules']['P-1']['status']);
        $this->assertSame('IMPLEMENTED', $data['gated_modules']['P-2']['status']);
        $this->assertSame('BLOCKED_PROHIBITED', $data['gated_modules']['P-3']['status']);
        $this->assertSame('NOT_IMPLEMENTED', $data['gated_modules']['P-6']['status']);

        // Double deduction prohibited verification
        $this->assertTrue($data['accounting_scopes']['execution_slippage_diagnostic']['double_deduction_prohibited']);
    }

    public function test_profitability_strategies_lists_all_primary_accounts(): void
    {
        $response = $this->getJson('/api/v1/research/profitability/strategies');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'as_of',
                'strategies' => [
                    '*' => [
                        'strategy_id',
                        'account_id',
                        'name',
                        'point_in_time',
                        'full_lifecycle',
                    ],
                ],
            ]);

        $strategies = $response->json('strategies');
        $this->assertCount(8, $strategies, 'Must return exactly 8 primary strategy accounts.');
    }

    public function test_profitability_strategy_detail_returns_specific_strategy(): void
    {
        $response = $this->getJson('/api/v1/research/profitability/strategies/baseline');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'as_of',
                'strategy_id',
                'account' => [
                    'id',
                    'name',
                    'starting_balance',
                    'current_equity',
                ],
                'lifecycle_economics',
                'reconciliation',
                'recent_executions',
            ]);

        $this->assertSame('baseline', $response->json('strategy_id'));
    }

    public function test_profitability_strategy_detail_returns_404_on_unknown_strategy(): void
    {
        $response = $this->getJson('/api/v1/research/profitability/strategies/non_existent_strategy_xyz');

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'STRATEGY_NOT_FOUND');
    }

    public function test_metric_registry_endpoint_returns_catalog(): void
    {
        $response = $this->getJson('/api/v1/research/profitability/metric-registry');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'catalog' => [
                    'version',
                    'accounting_scope_rules',
                    'prohibitions',
                    'metrics',
                ],
            ]);
    }

    /**
     * Negative Control: Verify that GET requests perform ZERO database writes (no INSERT, UPDATE, DELETE).
     */
    public function test_profitability_get_endpoints_are_strictly_read_only(): void
    {
        $writeQueries = [];

        DB::listen(function ($query) use (&$writeQueries): void {
            $sql = trim(strtoupper($query->sql));
            if (str_starts_with($sql, 'INSERT') || str_starts_with($sql, 'UPDATE') || str_starts_with($sql, 'DELETE')) {
                $writeQueries[] = $query->sql;
            }
        });

        $this->getJson('/api/v1/research/profitability/summary')->assertOk();
        $this->getJson('/api/v1/research/profitability/strategies')->assertOk();
        $this->getJson('/api/v1/research/profitability/strategies/baseline')->assertOk();
        $this->getJson('/api/v1/research/profitability/metric-registry')->assertOk();

        $this->assertEmpty($writeQueries, 'GET endpoints must perform ZERO database write queries. Found writes: '.implode('; ', $writeQueries));
    }
}
