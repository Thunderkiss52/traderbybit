<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Research\Application\QuantPortfolioMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class QuantPortfolioMetricsPostgresIntegrationTest extends TestCase
{
    private array $goldenPositions = [];

    private array $goldenExecutions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $testPort = (int) env('TEST_DB_PORT', 25432);
        $testHost = env('TEST_DB_HOST', '127.0.0.1');
        $testDb = env('TEST_DB_DATABASE', 'bybit_test');
        $testUser = env('TEST_DB_USERNAME', 'postgres');
        $testPass = env('TEST_DB_PASSWORD', 'testpass');

        $connectionOk = @fsockopen($testHost, $testPort, $errno, $errstr, 1);
        if (! $connectionOk) {
            $this->markTestSkipped("PostgreSQL test container is not reachable on {$testHost}:{$testPort}.");
        }
        fclose($connectionOk);

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => $testHost,
            'database.connections.pgsql.port' => $testPort,
            'database.connections.pgsql.database' => $testDb,
            'database.connections.pgsql.username' => $testUser,
            'database.connections.pgsql.password' => $testPass,
        ]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $currentDb = DB::connection()->getDatabaseName();
        if ($currentDb !== 'bybit_test') {
            $this->fail("SAFETY ABORT: PostgreSQL test must run strictly against isolated 'bybit_test' database (current: {$currentDb}).");
        }
        if (app()->environment('production')) {
            $this->fail('SAFETY ABORT: Cannot run integration tests in production environment.');
        }

        $goldenPath = base_path('tests/Fixtures/Golden/golden_positions_29.json');
        self::assertFileExists($goldenPath, 'Golden positions fixture must exist.');
        $this->goldenPositions = json_decode(file_get_contents($goldenPath), true, 512, JSON_THROW_ON_ERROR);

        $execPath = base_path('tests/Fixtures/Golden/golden_executions.json');
        self::assertFileExists($execPath, 'Golden executions fixture must exist.');
        $this->goldenExecutions = json_decode(file_get_contents($execPath), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Test 1: Full lifecycle population economics across all 29 positions
     * using the authentic archive generation timestamp (2026-10-19T14:57:47Z).
     */
    public function test_complete_29_positions_population_economics(): void
    {
        $service = new QuantPortfolioMetricsService;
        $genTime = CarbonImmutable::parse('2026-10-19T14:57:47Z');

        $result = $service->computeLifecyclePopulationEconomics($this->goldenPositions, $genTime);

        self::assertSame('FULL_LIFECYCLE_POPULATION', $result['scope']);
        self::assertSame('2026-10-19T14:57:47+00:00', $result['extraction_generation_time']);
        self::assertSame(29, $result['total_positions']);

        // Financial aggregates matching Section 1 authoritative targets
        self::assertEqualsWithDelta(142.5411, $result['gross_pnl'], 0.001, 'Gross PnL must match +142.5411');
        self::assertEqualsWithDelta(104.4998, $result['fees'], 0.001, 'Fees must match 104.4998');
        self::assertEqualsWithDelta(-0.5356, $result['signed_funding'], 0.001, 'Signed funding must match -0.5356');
        self::assertEqualsWithDelta(37.5057, $result['net_pnl'], 0.001, 'Net PnL must match +37.5057');

        self::assertTrue($result['accounting_identity_holds'], 'Accounting identity gross - fees + funding = net must hold.');

        // Reconcile 142.5411 vs 137.9440 (delta 4.5971 from partially closed position 5b446c88)
        self::assertEqualsWithDelta(232.1882, $result['closed_positions_gross_gains'], 0.001);
        self::assertEqualsWithDelta(94.2442, $result['closed_positions_gross_losses'], 0.001);
        self::assertEqualsWithDelta(137.9440, $result['closed_positions_gross_pnl'], 0.001);
        self::assertEqualsWithDelta(4.5970, $result['partially_closed_realized_gross_pnl'], 0.001);

        self::assertEqualsWithDelta(236.7852, $result['total_realized_gross_gains'], 0.001);
        self::assertEqualsWithDelta(94.2442, $result['total_realized_gross_losses'], 0.001);
        self::assertEqualsWithDelta(142.5411, $result['total_realized_gross_gains'] - $result['total_realized_gross_losses'], 0.001);

        // Profit factor: closed vs total realized
        self::assertEqualsWithDelta(2.4637, $result['closed_positions_profit_factor'], 0.001);
        self::assertEqualsWithDelta(2.5125, $result['total_realized_profit_factor'], 0.001);

        // Sub-scopes exist and are valid
        self::assertArrayHasKey('fully_closed_positions_scope', $result);
        self::assertArrayHasKey('partially_closed_positions_scope', $result);
        self::assertArrayHasKey('total_realized_population_scope', $result);
        self::assertSame(28, $result['fully_closed_positions_scope']['positions_count']);
        self::assertSame(1, $result['partially_closed_positions_scope']['positions_count']);
        self::assertSame(29, $result['total_realized_population_scope']['total_positions_count']);

        // Counts
        self::assertSame(28, $result['counts']['closed']);
        self::assertSame(1, $result['counts']['partially_closed']);
        self::assertSame(0, $result['counts']['open']);
        self::assertGreaterThan(0, $result['counts']['win']);
        self::assertGreaterThan(0, $result['counts']['loss']);

        // R-multiples statistics
        self::assertSame(28, $result['r_metrics']['count']);
        self::assertIsFloat($result['r_metrics']['mean_r']);
        self::assertIsFloat($result['r_metrics']['median_r']);

        // Breakdown by symbol covers both BTCUSDT and ETHUSDT
        self::assertArrayHasKey('BTCUSDT', $result['breakdown_by_symbol']);
        self::assertArrayHasKey('ETHUSDT', $result['breakdown_by_symbol']);
        self::assertSame(29, $result['breakdown_by_symbol']['BTCUSDT']['count'] + $result['breakdown_by_symbol']['ETHUSDT']['count']);
    }

    /**
     * Test 2: Point-in-time financial state reconstruction at cutoff (2026-10-18T16:42:14Z).
     * Reconstructs state strictly from events and fills available at that cutoff;
     * rigorously distinguishes retrospective market-event state from strict system knowledge as-of state.
     */
    public function test_reconstruct_point_in_time_state_at_cutoff(): void
    {
        $service = new QuantPortfolioMetricsService;
        $cutoff = CarbonImmutable::parse('2026-10-18T16:42:14Z');

        $pit = $service->reconstructPointInTimeState($this->goldenPositions, $this->goldenExecutions, $cutoff);

        self::assertSame('POINT_IN_TIME_RECONSTRUCTION', $pit['scope']);
        self::assertSame('2026-10-18T16:42:14+00:00', $pit['as_of_cutoff']);

        // Exactly 9 positions were opened on or before 16:42:14 (20 were opened in the future on 2026-10-19)
        self::assertSame(9, $pit['positions_opened_count']);
        self::assertSame(9, $pit['positions_open_at_cutoff_count']);
        self::assertSame(0, $pit['positions_closed_before_cutoff_count']);

        // View A: Retrospective Market Event Clock (8 executions: 7 entries + 1 partial TP1)
        $retro = $pit['retrospective_market_event_state'];
        self::assertSame('MARKET_EVENT_TIME', $retro['clock']);
        self::assertSame(8, $retro['executions_count']);
        self::assertEqualsWithDelta(11.8735, $retro['realized_gross_pnl'], 0.001);
        self::assertEqualsWithDelta(15.3567, $retro['commissions_paid'], 0.001);
        self::assertEqualsWithDelta(-3.4832, $retro['realized_net_cash_flow'], 0.001);

        // View B: Strict System Knowledge As-Of Clock (6 executions: 2 lagged executions excluded)
        $strict = $pit['strict_system_knowledge_as_of_state'];
        self::assertSame('SYSTEM_REGISTRATION_TIME', $strict['clock']);
        self::assertSame(6, $strict['executions_count']);
        self::assertEqualsWithDelta(11.8735, $strict['realized_gross_pnl'], 0.001);
        self::assertEqualsWithDelta(13.3298, $strict['commissions_paid'], 0.001);
        self::assertEqualsWithDelta(-1.4563, $strict['realized_net_cash_flow'], 0.001);

        // Registration Latency Reconciliation (2 executions totaling 2.0269 USDT commissions)
        $latency = $pit['registration_latency_reconciliation'];
        self::assertSame(2, $latency['lagged_executions_count']);
        self::assertEqualsWithDelta(2.0269, $latency['lagged_commissions_sum'], 0.001);
        self::assertCount(2, $latency['lagged_executions']);

        self::assertTrue($pit['post_cutoff_exit_pnl_excluded'], 'Post-cutoff exit PnL must be strictly excluded.');
        self::assertTrue($pit['future_positions_excluded'], 'Positions opened after cutoff must be excluded.');
        self::assertTrue($pit['accounting_identity_holds'], 'Point-in-time cash flow identity must hold.');
    }

    /**
     * Test 3: Shared-market-event analysis computes transitive clusters,
     * overlap durations, instantaneous concurrency peaks, and separate directional exposure per symbol.
     */
    public function test_shared_market_events_and_overlap_clusters(): void
    {
        $service = new QuantPortfolioMetricsService;
        $genTime = CarbonImmutable::parse('2026-10-19T14:57:47Z');

        $clusterAnalysis = $service->computeSharedMarketEventsAndClusters($this->goldenPositions, $genTime);

        self::assertGreaterThan(0, $clusterAnalysis['total_clusters']);
        self::assertGreaterThanOrEqual(1, $clusterAnalysis['max_concurrent_cluster_size']);
        self::assertGreaterThanOrEqual(1, $clusterAnalysis['peak_instantaneous_active_positions']);
        self::assertNotNull($clusterAnalysis['peak_instantaneous_timestamp']);
        self::assertTrue($clusterAnalysis['cross_symbol_netting_prohibited']);
        self::assertNotEmpty($clusterAnalysis['clusters']);

        // Directional exposure evaluated separately per symbol
        self::assertArrayHasKey('directional_exposure_by_symbol', $clusterAnalysis);
        self::assertArrayHasKey('BTCUSDT', $clusterAnalysis['directional_exposure_by_symbol']);
        self::assertArrayHasKey('ETHUSDT', $clusterAnalysis['directional_exposure_by_symbol']);

        // Check first cluster structure
        $firstCluster = $clusterAnalysis['clusters'][0];
        self::assertArrayHasKey('cluster_id', $firstCluster);
        self::assertArrayHasKey('positions_count', $firstCluster);
        self::assertArrayHasKey('start_time', $firstCluster);
        self::assertArrayHasKey('end_time', $firstCluster);
        self::assertArrayHasKey('net_pnl_contribution', $firstCluster);
        self::assertArrayHasKey('symbols', $firstCluster);

        // Sum of all cluster position counts must equal total positions
        $totalClusteredPositions = array_sum(array_column($clusterAnalysis['clusters'], 'positions_count'));
        self::assertSame(29, $totalClusteredPositions, 'All 29 positions must belong to a disjoint transitive cluster.');

        // Sum of cluster net PnLs must equal total net PnL (+37.5057)
        $totalClusterNetPnl = array_sum(array_column($clusterAnalysis['clusters'], 'net_pnl_contribution'));
        self::assertEqualsWithDelta(37.5057, $totalClusterNetPnl, 0.01, 'Cluster net PnL sum must equal total net PnL.');
    }

    /**
     * Test 4: Deterministic two-run reproducibility proof (§15.4)
     * Two separate executions with identical frozen parameters produce identical payloads.
     */
    public function test_deterministic_reproducibility_proof(): void
    {
        $service = new QuantPortfolioMetricsService;
        $fixedGenTime = '2026-10-19T14:57:47+00:00';
        $fixedCutoff = '2026-10-18T16:42:14+00:00';

        // Run 1
        $payload1 = $service->exportQuantResearchPayload($this->goldenPositions, [
            'generation_time' => $fixedGenTime,
            'point_in_time_cutoff' => $fixedCutoff,
            'executions' => $this->goldenExecutions,
            'generation_id' => 'canonical-eval-001',
        ]);

        // Run 2
        $payload2 = $service->exportQuantResearchPayload($this->goldenPositions, [
            'generation_time' => $fixedGenTime,
            'point_in_time_cutoff' => $fixedCutoff,
            'executions' => $this->goldenExecutions,
            'generation_id' => 'canonical-eval-001',
        ]);

        $json1 = json_encode($payload1, JSON_THROW_ON_ERROR);
        $json2 = json_encode($payload2, JSON_THROW_ON_ERROR);

        self::assertSame($json1, $json2, 'Two independent analytic exports under frozen inputs must produce identical JSON.');
        self::assertSame(hash('sha256', $json1), hash('sha256', $json2), 'Hashes of identical runs must match.');
    }

    /**
     * Test 5 (Negative & Mutation Control §15.3 #6 - Source Variation):
     * Mutate an input fixture position fee from 2.0 to 12.0 and verify output economics
     * changes by exactly 10.0, proving formulas are dynamic and free from hardcoded constants.
     */
    public function test_negative_control_source_variation_reacts_dynamically(): void
    {
        $service = new QuantPortfolioMetricsService;
        $genTime = CarbonImmutable::parse('2026-10-19T14:57:47Z');

        $baseResult = $service->computeLifecyclePopulationEconomics($this->goldenPositions, $genTime);

        // Mutate first position's fees by +10.00 USDT
        $mutatedPositions = $this->goldenPositions;
        $originalFee = (float) $mutatedPositions[0]['fees'];
        $mutatedPositions[0]['fees'] = $originalFee + 10.00;
        $mutatedPositions[0]['net_pnl'] = ((float) $mutatedPositions[0]['gross_pnl']) - ($originalFee + 10.00) + ((float) $mutatedPositions[0]['funding']);

        $mutatedResult = $service->computeLifecyclePopulationEconomics($mutatedPositions, $genTime);

        self::assertEqualsWithDelta($baseResult['fees'] + 10.00, $mutatedResult['fees'], 0.001, 'Fees must increase by exactly 10.00');
        self::assertEqualsWithDelta($baseResult['net_pnl'] - 10.00, $mutatedResult['net_pnl'], 0.001, 'Net PnL must decrease by exactly 10.00');
        self::assertTrue($mutatedResult['accounting_identity_holds'], 'Accounting identity must hold on mutated data.');
    }
}
