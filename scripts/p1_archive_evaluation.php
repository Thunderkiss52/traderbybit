<?php

declare(strict_types=1);

/**
 * PROFIT-1 / P-1 — read-only archive evaluator.
 *
 * Recomputes the slippage attribution and the realized-scope zero-slippage
 * counterfactual on a local research export (audit_data/) without touching the
 * database, the paper ledger or any live endpoint. The fill drag and the
 * allocation use exactly the same domain rules as the Laravel service
 * (App\Research\Application\SlippageAttributionService and
 * App\Research\Domain\SlippageAttribution\SlippageAllocation), so the offline
 * result cannot diverge from the stored one.
 *
 * Accounting rules implemented here:
 *  - the adverse fill drag is |slippage| * quantity, signed by the fill side
 *    (BUY filled above the reference, SELL filled below the reference);
 *  - the realized counterfactual is realized_scope_net_pnl +
 *    realized_allocated_slippage_drag, where the entry drag of a partially
 *    closed position is allocated proportionally to the realized closed volume;
 *  - the entry drag of a still open position is unrealized and is never added
 *    to the realized counterfactual;
 *  - a partially closed position leaves the realized-scope Net PnL unresolved
 *    (see SlippageAllocation), so its counterfactual stays NULL;
 *  - the full-fill figure is reported separately with an explicit
 *    NOT_VERIFIED_SCOPE_MISMATCH status and is never presented as the
 *    headline counterfactual.
 *
 * The evaluator fails closed: missing/mismatched manifest checksums, malformed
 * CSVs, unknown execution types, positions without executions and invalid
 * decimal values abort the run. An unverified allocation is still written to
 * the output but the process exits non-zero.
 *
 * Usage:
 *   php scripts/p1_archive_evaluation.php --data-dir=audit_data --out=reports/profit-growth/P1_evidence.json
 */

use App\Research\Domain\SlippageAttribution\SlippageAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

require __DIR__.'/../vendor/autoload.php';

const EXIT_UNVERIFIED = 3;
const EXIT_INPUT_ERROR = 2;
const EXIT_INTEGRITY_ERROR = 4;

/**
 * @return never
 */
function abort(int $code, string $message): void
{
    fwrite(STDERR, $message."\n");
    exit($code);
}

$options = getopt('', ['data-dir::', 'out::', 'as-of::']);
if ($options === false) {
    abort(EXIT_INPUT_ERROR, 'Invalid command line options.');
}
if (array_key_exists('as-of', $options)) {
    abort(EXIT_INPUT_ERROR, '--as-of is not supported: the export has no validated as-of filter, so reporting a different timestamp would misrepresent records outside the selected scope. The manifest window.to_exclusive_utc is authoritative.');
}

$dataDir = rtrim((string) ($options['data-dir'] ?? 'audit_data'), '/');
$outPath = (string) ($options['out'] ?? 'reports/profit-growth/P1_evidence.json');

$manifestPath = $dataDir.'/manifest.json';
if (! is_file($manifestPath)) {
    abort(EXIT_INPUT_ERROR, "Archive manifest not found: {$manifestPath}");
}

try {
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    abort(EXIT_INPUT_ERROR, 'Archive manifest is not valid JSON: '.$e->getMessage());
}
if (! is_array($manifest)) {
    abort(EXIT_INPUT_ERROR, 'Archive manifest must be a JSON object.');
}

$windowTo = $manifest['window']['to_exclusive_utc'] ?? null;
if (! is_string($windowTo) || trim($windowTo) === '') {
    abort(EXIT_INPUT_ERROR, 'Archive manifest is missing window.to_exclusive_utc; the reported as-of timestamp cannot be established.');
}
try {
    $asOf = CarbonImmutable::parse($windowTo, 'UTC');
} catch (Throwable $e) {
    abort(EXIT_INPUT_ERROR, 'Archive manifest window.to_exclusive_utc is not a valid timestamp: '.$e->getMessage());
}

$requiredFiles = ['virtual_positions.csv', 'virtual_executions.csv', 'paper_accounts.csv'];

/**
 * @param  list<string>  $requiredHeaders
 * @return array{header: list<string>, rows: list<array<string, string>>}
 */
function readCsv(string $path, array $requiredHeaders): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        abort(EXIT_INPUT_ERROR, "Cannot open {$path}");
    }
    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        abort(EXIT_INPUT_ERROR, "CSV has no header row: {$path}");
    }
    $header = array_map(static fn ($value): string => trim((string) $value), $header);

    foreach ($requiredHeaders as $required) {
        if (! in_array($required, $header, true)) {
            fclose($handle);
            abort(EXIT_INPUT_ERROR, "CSV {$path} is missing required header: {$required}");
        }
    }

    $rows = [];
    $line = 1;
    while (($values = fgetcsv($handle)) !== false) {
        $line++;
        if ($values === [null] || $values === []) {
            continue;
        }
        if (count($values) !== count($header)) {
            fclose($handle);
            abort(EXIT_INPUT_ERROR, "CSV {$path} row {$line} has ".count($values).' fields, expected '.count($header).'.');
        }
        $row = array_combine($header, $values);
        if ($row === false) {
            fclose($handle);
            abort(EXIT_INPUT_ERROR, "CSV {$path} row {$line} could not be mapped to the header.");
        }
        $rows[] = array_map(static fn ($value): string => trim((string) $value), $row);
    }
    fclose($handle);

    return ['header' => $header, 'rows' => $rows];
}

/**
 * Return the trimmed decimal string or abort when the value is not numeric.
 */
function requireDecimal(string $file, int $rowNumber, array $row, string $field, bool $allowEmpty = false): string
{
    $raw = $row[$field] ?? '';
    if ($raw === '' && $allowEmpty) {
        return '0';
    }
    if ($raw === '') {
        abort(EXIT_INPUT_ERROR, "CSV {$file} row {$rowNumber} has an empty {$field}.");
    }
    try {
        BigDecimal::of($raw);
    } catch (MathException) {
        abort(EXIT_INPUT_ERROR, "CSV {$file} row {$rowNumber} has a non-numeric {$field}: {$raw}");
    }

    return $raw;
}

// --- Integrity: every required input must be covered by a manifest checksum. ---
$integrity = [];
foreach ($requiredFiles as $file) {
    $path = $dataDir.'/'.$file;
    if (! is_file($path)) {
        abort(EXIT_INPUT_ERROR, "Required archive input is missing: {$path}");
    }
    $expected = $manifest['files'][$file]['sha256'] ?? null;
    if (! is_string($expected) || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
        abort(EXIT_INTEGRITY_ERROR, "Manifest checksum is missing or malformed for required input: {$file}");
    }
    $actual = hash_file('sha256', $path);
    if (! is_string($actual) || ! hash_equals($expected, $actual)) {
        abort(EXIT_INTEGRITY_ERROR, "Manifest checksum mismatch for required input: {$file}");
    }
    $integrity[$file] = [
        'sha256' => $actual,
        'manifest_sha256' => $expected,
        'matches_manifest' => true,
    ];
}
$integrity['manifest.json'] = [
    'sha256' => hash_file('sha256', $manifestPath),
    'manifest_sha256' => null,
    'matches_manifest' => true,
];

// --- CSV parsing and field validation. ---
$positionCsv = readCsv(
    $dataDir.'/virtual_positions.csv',
    ['id', 'paper_account_id', 'symbol', 'status', 'initial_quantity', 'remaining_quantity', 'net_pnl'],
);
$executionCsv = readCsv(
    $dataDir.'/virtual_executions.csv',
    ['id', 'position_id', 'type', 'side', 'quantity', 'price', 'slippage', 'market_event_at', 'ambiguous_execution'],
);
$accountCsv = readCsv($dataDir.'/paper_accounts.csv', ['id']);

foreach ($requiredFiles as $file) {
    $declared = $manifest['files'][$file]['rows'] ?? null;
    $actual = match ($file) {
        'virtual_positions.csv' => count($positionCsv['rows']),
        'virtual_executions.csv' => count($executionCsv['rows']),
        'paper_accounts.csv' => count($accountCsv['rows']),
    };
    if ($declared !== null && (int) $declared !== $actual) {
        abort(EXIT_INTEGRITY_ERROR, "Manifest row count mismatch for {$file}: expected {$declared}, found {$actual}");
    }
}

$positions = [];
foreach ($positionCsv['rows'] as $index => $row) {
    $rowNumber = $index + 2;
    $id = $row['id'];
    if ($id === '') {
        abort(EXIT_INPUT_ERROR, "CSV virtual_positions.csv row {$rowNumber} has an empty id.");
    }
    if (isset($positions[$id])) {
        abort(EXIT_INPUT_ERROR, "CSV virtual_positions.csv has a duplicate position id: {$id}");
    }
    requireDecimal('virtual_positions.csv', $rowNumber, $row, 'initial_quantity');
    requireDecimal('virtual_positions.csv', $rowNumber, $row, 'remaining_quantity');
    requireDecimal('virtual_positions.csv', $rowNumber, $row, 'net_pnl');
    $positions[$id] = $row;
}

$allowedExecutionRoles = [
    'ENTRY' => 'ENTRY',
    'TAKE_PROFIT_1' => 'PARTIAL_EXIT',
    'TAKE_PROFIT_2' => 'FINAL_EXIT',
    'STOP_LOSS' => 'FINAL_EXIT',
    'TIMEOUT' => 'FINAL_EXIT',
    'RISK_CLOSURE' => 'FINAL_EXIT',
];

$aggregates = [];
$positionsWithExecutions = [];
$totalDrag = BigDecimal::zero();
$totalNotional = BigDecimal::zero();
$fillCount = 0;
$ambiguousFillCount = 0;
$fillRoleCounts = ['ENTRY' => 0, 'PARTIAL_EXIT' => 0, 'FINAL_EXIT' => 0];
$executionIds = [];

foreach ($executionCsv['rows'] as $index => $exec) {
    $rowNumber = $index + 2;
    $executionId = $exec['id'];
    if ($executionId === '') {
        abort(EXIT_INPUT_ERROR, "CSV virtual_executions.csv row {$rowNumber} has an empty id.");
    }
    if (isset($executionIds[$executionId])) {
        abort(EXIT_INPUT_ERROR, "CSV virtual_executions.csv has a duplicate execution id: {$executionId}");
    }
    $executionIds[$executionId] = true;

    $positionId = $exec['position_id'];
    if (! isset($positions[$positionId])) {
        abort(EXIT_INPUT_ERROR, "CSV virtual_executions.csv row {$rowNumber} references unknown position: {$positionId}");
    }

    $execType = strtoupper($exec['type']);
    if (! array_key_exists($execType, $allowedExecutionRoles)) {
        abort(EXIT_INPUT_ERROR, "CSV virtual_executions.csv row {$rowNumber} has an unsupported execution type: {$exec['type']}");
    }
    $fillRole = $allowedExecutionRoles[$execType];

    $side = strtoupper($exec['side']);
    if ($side !== 'BUY' && $side !== 'SELL') {
        abort(EXIT_INPUT_ERROR, "CSV virtual_executions.csv row {$rowNumber} has an unsupported side: {$exec['side']}");
    }

    $ambiguousRaw = strtolower($exec['ambiguous_execution']);
    if (! in_array($ambiguousRaw, ['', '0', '1', 'false', 'true'], true)) {
        abort(EXIT_INPUT_ERROR, "CSV virtual_executions.csv row {$rowNumber} has an unsupported ambiguous_execution value: {$exec['ambiguous_execution']}");
    }
    $isAmbiguous = $ambiguousRaw === '1' || $ambiguousRaw === 'true';

    $fillPrice = BigDecimal::of(requireDecimal('virtual_executions.csv', $rowNumber, $exec, 'price'));
    $slippage = BigDecimal::of(requireDecimal('virtual_executions.csv', $rowNumber, $exec, 'slippage', true));
    $quantity = BigDecimal::of(requireDecimal('virtual_executions.csv', $rowNumber, $exec, 'quantity'));

    // Bybit linear perp simulator: for a BUY the adverse slippage raised the
    // fill price, for a SELL it lowered it. The reference price is the price
    // the fill would have had without the slippage.
    $referencePrice = $side === 'BUY' ? $fillPrice->minus($slippage) : $fillPrice->plus($slippage);
    $drag = $side === 'BUY'
        ? $fillPrice->minus($referencePrice)->multipliedBy($quantity)
        : $referencePrice->minus($fillPrice)->multipliedBy($quantity);
    $notional = $referencePrice->multipliedBy($quantity);

    $fillRoleCounts[$fillRole]++;

    if (! isset($aggregates[$positionId])) {
        $aggregates[$positionId] = [
            'position_id' => $positionId,
            'entry_slippage_usdt' => BigDecimal::zero(),
            'exit_slippage_usdt' => BigDecimal::zero(),
            'total_reference_notional' => BigDecimal::zero(),
            'fill_count' => 0,
            'ambiguous_fill_count' => 0,
        ];
    }

    if ($fillRole === 'ENTRY') {
        $aggregates[$positionId]['entry_slippage_usdt'] = $aggregates[$positionId]['entry_slippage_usdt']->plus($drag);
    } else {
        $aggregates[$positionId]['exit_slippage_usdt'] = $aggregates[$positionId]['exit_slippage_usdt']->plus($drag);
    }
    $aggregates[$positionId]['total_reference_notional'] = $aggregates[$positionId]['total_reference_notional']->plus($notional);
    $aggregates[$positionId]['fill_count']++;
    if ($isAmbiguous) {
        $aggregates[$positionId]['ambiguous_fill_count']++;
        $ambiguousFillCount++;
    }
    $positionsWithExecutions[$positionId] = true;

    $totalDrag = $totalDrag->plus($drag);
    $totalNotional = $totalNotional->plus($notional);
    $fillCount++;
}

$positionsWithoutExecutions = array_values(array_diff(array_keys($positions), array_keys($positionsWithExecutions)));
if ($positionsWithoutExecutions !== []) {
    abort(
        EXIT_UNVERIFIED,
        'Archive is unverified: '.count($positionsWithoutExecutions).' position(s) have no executions and would be silently excluded from the reconciled population: '.implode(', ', $positionsWithoutExecutions)
    );
}

// Every position is part of the population, not only the ones with fills.
$allocationRows = [];
foreach ($positions as $positionId => $position) {
    $aggregate = $aggregates[$positionId];
    $allocationRows[] = [
        'position_id' => $positionId,
        'initial_quantity' => $position['initial_quantity'],
        'remaining_quantity' => $position['remaining_quantity'],
        'net_pnl' => $position['net_pnl'],
        'entry_slippage_usdt' => (string) $aggregate['entry_slippage_usdt'],
        'exit_slippage_usdt' => (string) $aggregate['exit_slippage_usdt'],
    ];
}

$allocation = (new SlippageAllocation)->allocate($allocationRows, count($positions));

$baselineNetPnl = BigDecimal::zero();
foreach ($allocationRows as $row) {
    $baselineNetPnl = $baselineNetPnl->plus(BigDecimal::of($row['net_pnl']));
}

$weightedBps = $totalNotional->isZero()
    ? null
    : $totalDrag->dividedBy($totalNotional, 12, RoundingMode::HalfEven)->multipliedBy(10000)->toScale(4, RoundingMode::HalfEven);

// The partially closed fixture documented in the contract (entry 0.021, realized
// 0.010, still open 0.011). It is executed through the same allocator to prove
// the proportional rule; it is a synthetic fixture, not archive data.
$contractFixture = (new SlippageAllocation)->allocate([
    [
        'position_id' => 'contract-fixture-partially-closed',
        'initial_quantity' => '0.021',
        'remaining_quantity' => '0.011',
        'net_pnl' => '0',
        'entry_slippage_usdt' => '0.021',
        'exit_slippage_usdt' => '0',
    ],
], 1);

$statusCounts = [];
foreach ($positions as $row) {
    $statusCounts[$row['status']] = ($statusCounts[$row['status']] ?? 0) + 1;
}

$counterfactualVerified = $allocation['realized_pnl_scope_status'] === SlippageAllocation::SCOPE_ESTABLISHED;

$evidence = [
    'deliverable' => 'PROFIT-1_P_1_SLIPPAGE_ATTRIBUTION',
    'evaluator' => 'scripts/p1_archive_evaluation.php',
    'as_of_utc' => $asOf->toIso8601String(),
    'generated_at_utc' => CarbonImmutable::now('UTC')->toIso8601String(),
    'archive_integrity' => [
        'verified' => true,
        'required_inputs' => $requiredFiles,
        'checksums' => $integrity,
    ],
    'population' => [
        'paper_accounts' => count($accountCsv['rows']),
        'positions_total' => count($positions),
        'positions_with_executions' => count($positionsWithExecutions),
        'positions_without_executions' => count($positionsWithoutExecutions),
        'position_status_counts' => $statusCounts,
        'executions_total' => count($executionCsv['rows']),
        'fills_attributed' => $fillCount,
        'fill_role_counts' => $fillRoleCounts,
        'ambiguous_fills' => $ambiguousFillCount,
        'partially_closed_positions_in_archive' => $allocation['partial_position_count'],
        'open_positions_in_archive' => $allocation['open_position_count'],
    ],
    'slippage_attribution' => [
        'total_slippage_drag_usdt' => (string) $totalDrag,
        'total_reference_notional_usdt' => (string) $totalNotional,
        'weighted_slippage_bps' => $weightedBps !== null ? (string) $weightedBps : null,
        'double_deduction_prohibited' => true,
    ],
    'allocation' => $allocation,
    'counterfactual' => [
        'scope' => 'REALIZED_CLOSED_SCOPE',
        'status' => $allocation['status'],
        'provenance' => $allocation['provenance'],
        'verified' => $counterfactualVerified,
        'baseline_actual_net_pnl_all_positions' => (string) $baselineNetPnl,
        'realized_scope_net_pnl' => $allocation['realized_scope_net_pnl'],
        'realized_pnl_scope_status' => $allocation['realized_pnl_scope_status'],
        'realized_pnl_scope_reason' => $allocation['realized_pnl_scope_reason'],
        'realized_allocated_slippage_drag' => $allocation['realized_allocated_slippage_drag'],
        'open_unallocated_slippage_drag' => $allocation['open_unallocated_slippage_drag'],
        'full_fill_slippage_drag' => $allocation['full_fill_slippage_drag'],
        'hypothetical_net_pnl_zero_slippage' => $allocation['hypothetical_net_pnl_zero_slippage'],
        'raw_full_fill_counterfactual_net_pnl' => $counterfactualVerified
            ? (string) $baselineNetPnl->plus($totalDrag)
            : null,
        'raw_counterfactual_status' => $allocation['raw_counterfactual_status'],
        'reconciliation_note' => $allocation['reconciliation_note'],
        'disclaimer' => 'Hypothetical counterfactual for research calibration only. Commissions and funding held constant. Real-market liquidity friction cannot be eliminated.',
    ],
    'contract_fixture_partial_close' => [
        'provenance' => 'SYNTHETIC_CONTRACT_FIXTURE',
        'description' => 'Documented partially closed position: entry 0.021, realized 0.010, still open 0.011, entry slippage drag 0.021.',
        'allocation' => $contractFixture,
        'conclusion' => 'The entry drag is split 0.010 realized / 0.011 open. The partial-close realized Net PnL scope is unresolved from persisted data, so the counterfactual stays null; the full-fill figure is classified NOT_VERIFIED_SCOPE_MISMATCH.',
    ],
    'provenance' => [
        'database_written' => false,
        'paper_ledger_mutated' => false,
        'order_path_used' => false,
        'future_data_used' => false,
        'live_trading' => 'STRICTLY_PROHIBITED',
        'limitations' => [
            'The archive export contains no partially closed position, so the proportional drag allocation is proven by the unit test and by the synthetic contract fixture, not by archive data.',
            'Partially closed positions cannot produce a verified realized-scope Net PnL because the persisted position snapshot does not separate the closed-quantity share of the full-quantity entry fee and open-remainder funding.',
            'Open positions carry an unrealized entry drag that is excluded from the realized counterfactual; their entry fees are reported separately.',
            'Single bounded 24h forward-paper export; not a statistical sample and never a profitability claim.',
        ],
    ],
];

$directory = dirname($outPath);
if (! is_dir($directory)) {
    mkdir($directory, 0o755, true);
}
file_put_contents($outPath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

printf(
    "P-1 archive evaluation written to %s (positions=%d, fills=%d, status=%s, verified=%s, sha256=%s)\n",
    $outPath,
    count($positions),
    $fillCount,
    $allocation['status'],
    $counterfactualVerified ? 'true' : 'false',
    hash_file('sha256', $outPath)
);

if (! $counterfactualVerified) {
    exit(EXIT_UNVERIFIED);
}
