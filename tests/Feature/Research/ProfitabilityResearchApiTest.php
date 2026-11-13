<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\Research\Application\LedgerReconciliationService;
use App\Research\Domain\ResearchGenerationRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProfitabilityResearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure all configured primary accounts are initialized.
        $this->app->make(PaperAccountService::class)->allPrimaryAccounts();
    }

    public function test_profitability_summary_returns_separated_accounting_scopes_and_gated_placeholders(): void
    {
        $response = $this->getJson('/api/v1/research/profitability/summary');

        $response->assertOk()
            ->assertJsonStructure(['status', 'data' => [
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
            ]]);

        $data = $response->json('data');

        // Scope verification
        $this->assertSame('POINT_IN_TIME', $data['accounting_scopes']['point_in_time']['scope']);
        $this->assertSame('FULL_LIFECYCLE', $data['accounting_scopes']['full_lifecycle_economics']['scope']);
        $this->assertSame('EVENT_EXECUTION', $data['accounting_scopes']['execution_slippage_diagnostic']['scope']);

        // Gated modules status verification (P-1 implemented, P-2 implemented, P-3 blocked, P-4/P-5/P-6 not implemented)
        $this->assertSame('NOT_GENERATED', $data['gated_modules']['P-1']['status']);
        $this->assertSame('IMPLEMENTED', $data['gated_modules']['P-2']['status']);
        $this->assertSame('BLOCKED_PROHIBITED', $data['gated_modules']['P-3']['status']);
        $this->assertSame('NOT_IMPLEMENTED', $data['gated_modules']['P-6']['status']);

        // Double deduction prohibited verification
        $this->assertTrue($data['accounting_scopes']['execution_slippage_diagnostic']['double_deduction_prohibited']);
    }

    public function test_p1_status_requires_a_completed_generation_in_the_selected_scope(): void
    {
        $generation = ResearchGenerationRecord::query()->create([
            'id' => (string) Str::uuid(), 'package_code' => 'P-1', 'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => now('UTC'), 'status' => 'SUCCEEDED', 'idempotency_key' => 'scoped-p1-fixture',
            'request_payload_hash' => hash('sha256', 'bybit-BTCUSDT'),
            'versioned_config' => ['algorithm_version' => '1.0.0'],
            'metadata' => ['exchange' => 'bybit', 'symbol' => 'BTCUSDT'],
        ]);
        $path = '/api/v1/research/profitability/summary?exchange=bybit&symbol=BTCUSDT';
        $this->getJson($path)->assertOk()->assertJsonPath('data.gated_modules.P-1.status', 'IMPLEMENTED_VERIFIED');
        $this->getJson('/api/v1/research/profitability/summary?exchange=binance&symbol=BTCUSDT')
            ->assertOk()->assertJsonPath('data.gated_modules.P-1.status', 'NOT_GENERATED');
        $this->getJson('/api/v1/research/profitability/summary?exchange=bybit&symbol=ETHUSDT')
            ->assertOk()->assertJsonPath('data.gated_modules.P-1.status', 'NOT_GENERATED');
        $generation->update(['status' => 'RUNNING']);
        $this->getJson($path)->assertOk()->assertJsonPath('data.gated_modules.P-1.status', 'RUNNING');
        $generation->update(['status' => 'FAILED']);
        $this->getJson($path)->assertOk()->assertJsonPath('data.gated_modules.P-1.status', 'FAILED');
    }

    public function test_profitability_strategies_lists_all_primary_accounts(): void
    {
        $response = $this->getJson('/api/v1/research/profitability/strategies');

        $response->assertOk()
            ->assertJsonStructure(['status', 'data' => [
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
            ]]);

        $strategies = $response->json('data.strategies');
        $this->assertCount(13, $strategies, 'Must return exactly 13 primary strategy accounts.');
    }

    public function test_profitability_strategy_detail_returns_specific_strategy(): void
    {
        $response = $this->getJson('/api/v1/research/profitability/strategies/baseline');

        $response->assertOk()
            ->assertJsonStructure(['status', 'data' => [
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
            ]]);

        $this->assertSame('baseline', $response->json('data.strategy_id'));
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
            ->assertJsonStructure(['status', 'data' => [
                'catalog' => [
                    'version',
                    'accounting_scope_rules',
                    'prohibitions',
                    'metrics',
                ],
            ]]);
    }

    public function test_profitability_reads_do_not_reprovision_missing_primary_accounts(): void
    {
        DB::table('equity_snapshots')->delete();
        DB::table('paper_accounts')->delete();

        $this->getJson('/api/v1/research/profitability/summary')->assertOk();
        $this->getJson('/api/v1/research/profitability/strategies')
            ->assertOk()
            ->assertJsonCount(0, 'data.strategies');

        $this->assertDatabaseCount('paper_accounts', 0);
        $this->assertDatabaseCount('equity_snapshots', 0);
    }

    public function test_persisted_discrepancies_and_missing_reconciliation_are_not_reported_as_clean(): void
    {
        $account = app(PaperAccountService::class)->default();
        $account->update(['realized_pnl' => '-20', 'current_equity' => '9980']);
        $result = app(LedgerReconciliationService::class)->reconcileAccount($account->id);
        self::assertGreaterThan(0, $result['discrepancy_count']);
        $rows = $this->getJson('/api/v1/research/profitability/summary')->assertOk()->json('data.accounting_scopes.point_in_time.accounts');
        $row = collect($rows)->firstWhere('account_id', $account->id);
        self::assertSame($result['discrepancy_count'], $row['reconciliation']['discrepancy_count']);
        $missing = collect($rows)->firstWhere('reconciliation.status', 'NO_RECORDED_RUN');
        self::assertNull($missing['reconciliation']['discrepancy_count']);
        $detail = $this->getJson('/api/v1/research/profitability/strategies/baseline')->assertOk();
        self::assertSame($result['discrepancies'], $detail->json('data.reconciliation.discrepancies'));
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

    public function test_f02_profitability_response_envelope_chain_for_success_and_errors(): void
    {
        // 1. Success envelopes contain status='SUCCESS' and data object
        $summaryRes = $this->getJson('/api/v1/research/profitability/summary');
        $summaryRes->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonStructure(['status', 'data']);

        $strategiesRes = $this->getJson('/api/v1/research/profitability/strategies');
        $strategiesRes->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonStructure(['status', 'data' => ['strategies']]);

        $detailRes = $this->getJson('/api/v1/research/profitability/strategies/baseline');
        $detailRes->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonStructure(['status', 'data' => ['strategy_id', 'account']]);

        // 2. Negative 404 envelope contains error with code and message
        $notFoundRes = $this->getJson('/api/v1/research/profitability/strategies/non_existent_strategy_xyz');
        $notFoundRes->assertStatus(404)
            ->assertJsonStructure(['error' => ['code', 'message']])
            ->assertJsonPath('error.code', 'STRATEGY_NOT_FOUND');

        // Negative position slippage 404
        $posNotFoundRes = $this->getJson('/api/v1/research/profitability/slippage-attribution/positions/00000000-0000-0000-0000-000000000000');
        $posNotFoundRes->assertStatus(404)
            ->assertJsonStructure(['error' => ['code', 'message']])
            ->assertJsonPath('error.code', 'POSITION_NOT_FOUND');
    }

    public function test_f03_null_vs_real_zero_distinction_preserved_without_fabrication(): void
    {
        $account = app(PaperAccountService::class)->default();

        // When no trading or reconciliation runs exist, reconciliation discrepancy_count is null, NOT fabricated 0
        $summary = $this->getJson('/api/v1/research/profitability/summary')->assertOk()->json('data');
        $accountRow = collect($summary['accounting_scopes']['point_in_time']['accounts'])->firstWhere('account_id', $account->id);

        if ($accountRow['reconciliation']['status'] === 'NO_RECORDED_RUN') {
            self::assertNull($accountRow['reconciliation']['discrepancy_count'], 'Unreconciled account must return null discrepancy_count, never fabricated 0');
        }

        // When starting balance and current equity are exact numbers, they are preserved as strings
        self::assertNotNull($accountRow['starting_balance']);
        self::assertNotNull($accountRow['current_equity']);

        // Check strategy detail reconciliation
        $detail = $this->getJson('/api/v1/research/profitability/strategies/baseline')->assertOk()->json('data');
        if (($detail['reconciliation']['status'] ?? '') === 'NO_RECORDED_RUN') {
            self::assertNull($detail['reconciliation']['discrepancy_count']);
        }
    }

    public function test_f05_gated_module_statuses_consistent_with_evidence_and_prohibitions(): void
    {
        $summary = $this->getJson('/api/v1/research/profitability/summary')->assertOk()->json('data.gated_modules');

        // No persisted scoped generation exists in this isolated fixture.
        self::assertSame('NOT_GENERATED', $summary['P-1']['status']);

        // P-2 is implemented
        self::assertSame('IMPLEMENTED', $summary['P-2']['status']);

        // P-3 is strictly BLOCKED_PROHIBITED by AGENTS.md invariant (live real-money trading forbidden)
        self::assertSame('BLOCKED_PROHIBITED', $summary['P-3']['status']);

        // P-4, P-5, P-6 are NOT_IMPLEMENTED
        self::assertSame('NOT_IMPLEMENTED', $summary['P-4']['status']);
        self::assertSame('NOT_IMPLEMENTED', $summary['P-5']['status']);
        self::assertSame('NOT_IMPLEMENTED', $summary['P-6']['status']);
    }
}
