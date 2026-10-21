<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use PHPUnit\Framework\TestCase;

/**
 * PROFIT-1 / P-1 — offline archive evaluator fail-closed behaviour.
 *
 * Each test builds an isolated temporary export, runs the read-only evaluator
 * as a subprocess and asserts the exit code and the emitted evidence. No
 * database, Docker resource or paper ledger is touched.
 */
final class P1ArchiveEvaluationTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    public function test_valid_fixture_produces_verified_counterfactual(): void
    {
        $fixture = $this->makeFixture();

        [$code, $output] = $this->runEvaluator($fixture['dir']);

        $this->assertSame(0, $code, $output);
        $evidence = $this->readEvidence($fixture['dir']);
        $this->assertTrue($evidence['archive_integrity']['verified']);
        $this->assertSame('COMPUTED', $evidence['allocation']['status']);
        $this->assertTrue($evidence['counterfactual']['verified']);
        $this->assertSame('980', $evidence['allocation']['realized_scope_net_pnl']);
        $this->assertSame('1.5', $evidence['allocation']['realized_allocated_slippage_drag']);
        $this->assertSame('981.5', $evidence['allocation']['hypothetical_net_pnl_zero_slippage']);
        $this->assertSame('2026-10-17T10:00:00+00:00', $evidence['as_of_utc']);
        $this->assertSame(1, $evidence['population']['positions_total']);
        $this->assertSame(0, $evidence['population']['positions_without_executions']);
    }

    public function test_missing_manifest_checksum_is_rejected(): void
    {
        $fixture = $this->makeFixture();
        $manifest = json_decode((string) file_get_contents($fixture['dir'].'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($manifest['files']['virtual_executions.csv']['sha256']);
        file_put_contents($fixture['dir'].'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        [$code, $output] = $this->runEvaluator($fixture['dir']);

        $this->assertSame(4, $code, $output);
        $this->assertStringContainsString('checksum is missing', $output);
        $this->assertFileDoesNotExist($fixture['dir'].'/out.json');
    }

    public function test_checksum_mismatch_is_rejected(): void
    {
        $fixture = $this->makeFixture();
        $manifest = json_decode((string) file_get_contents($fixture['dir'].'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['files']['virtual_positions.csv']['sha256'] = str_repeat('a', 64);
        file_put_contents($fixture['dir'].'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        [$code, $output] = $this->runEvaluator($fixture['dir']);

        $this->assertSame(4, $code, $output);
        $this->assertStringContainsString('checksum mismatch', $output);
    }

    public function test_unknown_execution_type_is_rejected(): void
    {
        $fixture = $this->makeFixture(['executions' => $this->executionsCsv(executionType: 'MYSTERY_FILL')]);

        [$code, $output] = $this->runEvaluator($fixture['dir']);

        $this->assertSame(2, $code, $output);
        $this->assertStringContainsString('unsupported execution type', $output);
    }

    public function test_position_without_executions_is_not_silently_excluded(): void
    {
        $fixture = $this->makeFixture([
            'positions' => $this->positionsCsv()."pos-2,acc-1,ETHUSDT,OPEN,1,1,0\n",
        ]);

        [$code, $output] = $this->runEvaluator($fixture['dir']);

        $this->assertSame(3, $code, $output);
        $this->assertStringContainsString('pos-2', $output);
        $this->assertStringContainsString('no executions', $output);
    }

    public function test_malformed_row_shape_is_rejected(): void
    {
        $fixture = $this->makeFixture([
            'positions' => $this->positionsCsv()."pos-2,acc-1,ETHUSDT,OPEN,1,1,0,extra-field\n",
        ]);

        [$code, $output] = $this->runEvaluator($fixture['dir']);

        $this->assertSame(2, $code, $output);
        $this->assertStringContainsString('fields, expected', $output);
    }

    public function test_non_numeric_values_are_rejected(): void
    {
        $fixture = $this->makeFixture([
            'executions' => $this->executionsCsv(price: 'not-a-price'),
        ]);

        [$code, $output] = $this->runEvaluator($fixture['dir']);

        $this->assertSame(2, $code, $output);
        $this->assertStringContainsString('non-numeric price', $output);
    }

    public function test_as_of_override_is_rejected(): void
    {
        $fixture = $this->makeFixture();

        [$code, $output] = $this->runEvaluator($fixture['dir'], '--as-of=2026-10-18T00:00:00Z');

        $this->assertSame(2, $code, $output);
        $this->assertStringContainsString('--as-of is not supported', $output);
    }

    public function test_partial_close_archive_marks_counterfactual_unverified(): void
    {
        $fixture = $this->makeFixture([
            'positions' => "id,paper_account_id,symbol,status,initial_quantity,remaining_quantity,net_pnl\n"
                ."pos-1,acc-1,BTCUSDT,PARTIALLY_CLOSED,0.021,0.011,4.99\n",
            'executions' => $this->executionsCsv(
                executionType: 'TAKE_PROFIT_1',
                entryQuantity: '0.021',
                entrySlippage: '1',
                exitQuantity: '0.010',
                exitSlippage: '0.3',
            ),
        ]);

        [$code, $output] = $this->runEvaluator($fixture['dir']);

        $this->assertSame(3, $code, $output);
        $evidence = $this->readEvidence($fixture['dir']);
        $this->assertSame('PARTIAL_CLOSE_UNRESOLVED', $evidence['allocation']['status']);
        $this->assertFalse($evidence['counterfactual']['verified']);
        $this->assertNull($evidence['allocation']['realized_scope_net_pnl']);
        $this->assertNull($evidence['allocation']['hypothetical_net_pnl_zero_slippage']);
        $this->assertSame('0.013', $evidence['allocation']['realized_allocated_slippage_drag']);
        $this->assertNull($evidence['counterfactual']['raw_full_fill_counterfactual_net_pnl']);
    }

    /**
     * @return array{dir: string}
     */
    private function makeFixture(array $overrides = []): array
    {
        $dir = sys_get_temp_dir().'/p1-eval-'.bin2hex(random_bytes(8));
        if (! mkdir($dir, 0o700, true)) {
            $this->fail("Could not create fixture directory {$dir}");
        }
        $this->tempDirs[] = $dir;

        $files = [
            'paper_accounts.csv' => $this->accountsCsv(),
            'virtual_positions.csv' => $overrides['positions'] ?? $this->positionsCsv(),
            'virtual_executions.csv' => $overrides['executions'] ?? $this->executionsCsv(),
        ];

        foreach ($files as $name => $contents) {
            file_put_contents($dir.'/'.$name, $contents);
        }

        $manifestFiles = [];
        foreach ($files as $name => $contents) {
            $manifestFiles[$name] = [
                'rows' => max(0, substr_count(trim($contents), "\n")),
                'sha256' => hash('sha256', $contents),
            ];
        }

        $manifest = [
            'schema_version' => '1.0.0',
            'window' => [
                'from_inclusive_utc' => '2026-10-17T07:45:00+00:00',
                'to_exclusive_utc' => '2026-10-17T10:00:00+00:00',
            ],
            'files' => $manifestFiles,
        ];
        file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        return ['dir' => $dir];
    }

    private function accountsCsv(): string
    {
        return "id\nacc-1\n";
    }

    private function positionsCsv(): string
    {
        return "id,paper_account_id,symbol,status,initial_quantity,remaining_quantity,net_pnl\n"
            ."pos-1,acc-1,BTCUSDT,CLOSED_TP,1,0,980\n";
    }

    private function executionsCsv(
        string $executionType = 'TAKE_PROFIT_2',
        string $price = '100',
        string $entryQuantity = '1',
        string $entrySlippage = '1',
        string $exitQuantity = '1',
        string $exitSlippage = '0.5',
    ): string {
        return "id,position_id,type,side,quantity,price,slippage,market_event_at,ambiguous_execution\n"
            ."exec-1,pos-1,ENTRY,BUY,{$entryQuantity},{$price},{$entrySlippage},2026-10-17T08:00:00+00,\n"
            ."exec-2,pos-1,{$executionType},SELL,{$exitQuantity},101,{$exitSlippage},2026-10-17T09:00:00+00,\n";
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runEvaluator(string $dataDir, string $extra = ''): array
    {
        $script = dirname(__DIR__, 3).'/scripts/p1_archive_evaluation.php';
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script)
            .' --data-dir='.escapeshellarg($dataDir)
            .' --out='.escapeshellarg($dataDir.'/out.json')
            .($extra !== '' ? ' '.$extra : '')
            .' 2>&1';

        $lines = [];
        $code = 0;
        exec($command, $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    /**
     * @return array<string, mixed>
     */
    private function readEvidence(string $dir): array
    {
        $this->assertFileExists($dir.'/out.json');

        return json_decode((string) file_get_contents($dir.'/out.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
