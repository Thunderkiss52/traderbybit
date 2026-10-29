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
}
