<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\Binance\BinancePaperCapital;
use App\Exchanges\Binance\BinancePaperProvisioner;
use App\PaperTrading\Application\PaperAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Phase0DiagnosticPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_diagnostic_queries_execute_and_census_includes_legacy_baseline(): void
    {
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        app(PaperAccountService::class)->allPrimaryAccounts();
        app(BinancePaperProvisioner::class)->provision();
        app(BinancePaperCapital::class)->allocate('10000', 'PHASE0-DIAGNOSTIC-TEST');
        $sql = file_get_contents(base_path('scripts/diagnostics/phase0_production_acceptance.sql'));
        self::assertStringContainsString('BEGIN TRANSACTION READ ONLY;', $sql);
        self::assertStringContainsString('ROLLBACK;', $sql);
        $sql = preg_replace('/^--.*$|^\\\\.*$/m', '', $sql);
        $queries = array_filter(array_map('trim', explode(';', $sql)));
        $results = [];
        foreach ($queries as $query) {
            if (preg_match('/^(BEGIN|SET LOCAL|ROLLBACK)\b/', $query)) {
                continue; // Already inside the test's rolled-back fixture transaction.
            }
            self::assertMatchesRegularExpression('/^SELECT\b/', $query);
            self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|TRUNCATE)\b/i', $query);
            $results[] = DB::select($query);
        }
        self::assertGreaterThanOrEqual(16, count($results));
        self::assertSame(26, array_sum(array_map(fn ($row): int => (int) $row->total_accounts, $results[0])));
        self::assertContains('FORWARD_PAPER', array_column($results[0], 'mode'));
    }

    public function test_evidence_closure_queries_execute_read_only_with_bounded_pages(): void
    {
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        app(PaperAccountService::class)->allPrimaryAccounts();
        app(BinancePaperProvisioner::class)->provision();
        app(BinancePaperCapital::class)->allocate('10000', 'PHASE0-CLOSURE-TEST');
        $sql = file_get_contents(base_path('scripts/diagnostics/phase0_evidence_closure.sql'));
        self::assertStringContainsString('BEGIN TRANSACTION READ ONLY;', $sql);
        self::assertStringContainsString('ROLLBACK;', $sql);
        self::assertStringContainsString('LIMIT 100 OFFSET :funding_offset', $sql);
        self::assertStringContainsString('LIMIT 100 OFFSET :pipeline_offset', $sql);
        $sql = preg_replace('/^--.*$|^\\\\.*$/m', '', $sql);
        $sql = str_replace([':funding_offset', ':pipeline_offset'], ['0', '0'], $sql);
        $queries = array_filter(array_map('trim', explode(';', $sql)));
        $results = [];
        foreach ($queries as $query) {
            if (preg_match('/^(BEGIN|SET LOCAL|ROLLBACK)\b/', $query)) {
                continue; // Already inside the test's rolled-back fixture transaction.
            }
            self::assertMatchesRegularExpression('/^(SELECT|WITH)\b/', $query);
            self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|TRUNCATE)\b/i', $query);
            $results[] = DB::select($query);
        }
        self::assertGreaterThanOrEqual(13, count($results));
        self::assertCount(26, $results[0]);
        self::assertSame(['IDENTITY_PRESENT'], array_values(array_unique(array_column($results[0], 'census_state'))));
    }

    public function test_checklist_maps_exactly_45_frozen_v2_obligations(): void
    {
        $registry = json_decode(file_get_contents(base_path('docs/progress/vector-requirements.json')), true, flags: JSON_THROW_ON_ERROR);
        $expected = [];
        foreach ($registry['requirements'] as $requirement) {
            if ($requirement['phase'] === 0) {
                foreach ($requirement['obligations'] as $obligation) {
                    if ($obligation['environment'] === 'production') {
                        $expected[] = $obligation['id'];
                    }
                }
            }
        }
        $checklist = file_get_contents(base_path('docs/progress/evidence/phase0-production-acceptance/OPERATOR_CHECKLIST.md'));
        preg_match_all('/\| `(VEC-P0-\d+-V2)` \|/', $checklist, $matches);
        sort($expected);
        sort($matches[1]);
        self::assertCount(45, $expected);
        self::assertSame($expected, $matches[1]);
    }

    public function test_export_queries_execute_read_only_and_return_valid_json(): void
    {
        foreach (['phase0_export_funding_positions.sql', 'phase0_export_pipeline_anomalies.sql'] as $file) {
            $sql = file_get_contents(base_path("scripts/diagnostics/{$file}"));
            self::assertStringContainsString('BEGIN TRANSACTION READ ONLY;', $sql);
            self::assertStringContainsString('ROLLBACK;', $sql);
            self::assertStringContainsString('\set QUIET on', $sql);
            self::assertStringContainsString('\set ON_ERROR_STOP on', $sql);
            $clean = preg_replace('/^--.*$|^\\\\.*$/m', '', $sql);
            $queries = array_filter(array_map('trim', explode(';', $clean)));
            foreach ($queries as $query) {
                if (preg_match('/^(BEGIN|SET LOCAL|ROLLBACK)\b/', $query)) {
                    continue;
                }
                self::assertMatchesRegularExpression('/^SELECT\b/', $query);
                self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|TRUNCATE)\b/i', $query);
                $result = DB::selectOne($query);
                self::assertNotNull($result);
                $property = array_key_first((array) $result);
                $json = ((array) $result)[$property];
                $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
            }

            // Verify raw psql invocation silences transaction status lines and outputs direct parseable JSON
            if (trim((string) shell_exec('command -v psql 2>/dev/null')) === '') {
                continue;
            }

            $cmd = sprintf(
                'PGPASSWORD=%s psql -h %s -p %s -U %s -d %s -X -A -t -v ON_ERROR_STOP=1 -f %s',
                escapeshellarg(config('database.connections.pgsql.password')),
                escapeshellarg(config('database.connections.pgsql.host')),
                escapeshellarg((string) config('database.connections.pgsql.port')),
                escapeshellarg(config('database.connections.pgsql.username')),
                escapeshellarg(config('database.connections.pgsql.database')),
                escapeshellarg(base_path("scripts/diagnostics/{$file}"))
            );
            $rawOutput = shell_exec($cmd);
            self::assertNotNull($rawOutput);
            self::assertStringNotContainsString('BEGIN', $rawOutput);
            self::assertStringNotContainsString('ROLLBACK', $rawOutput);
            $decodedDirect = json_decode(trim($rawOutput), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decodedDirect);
        }
    }
}
