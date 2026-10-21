<?php

declare(strict_types=1);

/**
 * PROFIT-1 / P-5 — read-only archive evaluator.
 *
 * Evaluates the P-5 concentration/dependence views on a local research export
 * (audit_data/) without touching the database, the paper ledger or any live
 * endpoint. The same domain classes as the Laravel service are used, so the
 * offline result cannot diverge from the stored one.
 *
 * Usage:
 *   php scripts/p5_archive_evaluation.php --data-dir=audit_data --out=reports/profit-growth/P5_evidence.json
 */

use App\Research\Domain\Concentration\ConcentrationAnalyzer;
use App\Research\Domain\Concentration\CrossSymbolExposure;
use App\Research\Domain\Concentration\EquityAlignment;
use App\Research\Domain\Concentration\OverlapClusterBuilder;
use App\Research\Domain\Concentration\PositionIntervalFactory;
use Carbon\CarbonImmutable;

require __DIR__.'/../vendor/autoload.php';

$options = getopt('', ['data-dir::', 'out::', 'as-of::']);
$dataDir = rtrim((string) ($options['data-dir'] ?? 'audit_data'), '/');
$outPath = (string) ($options['out'] ?? 'reports/profit-growth/P5_evidence.json');

$manifestPath = $dataDir.'/manifest.json';
if (! is_file($manifestPath)) {
    fwrite(STDERR, "Archive manifest not found: {$manifestPath}\n");
    exit(2);
}
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

$asOf = isset($options['as-of'])
    ? CarbonImmutable::parse((string) $options['as-of'], 'UTC')
    : CarbonImmutable::parse((string) $manifest['window']['to_exclusive_utc'], 'UTC');

/**
 * @return list<array<string, string>>
 */
function readCsv(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        fwrite(STDERR, "Cannot open {$path}\n");
        exit(2);
    }
    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        exit(2);
    }
    $rows = [];
    while (($line = fgetcsv($handle)) !== false) {
        if ($line === [null] || $line === []) {
            continue;
        }
        $row = [];
        foreach ($header as $index => $name) {
            $row[$name] = (string) ($line[$index] ?? '');
        }
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

$filesUsed = ['paper_accounts.csv', 'virtual_positions.csv', 'equity_snapshots.csv', 'manifest.json'];
$sourceIntegrity = [];
foreach ($filesUsed as $file) {
    $path = $dataDir.'/'.$file;
    $actual = hash_file('sha256', $path);
    $expected = $manifest['files'][$file]['sha256'] ?? null;
    $sourceIntegrity[$file] = [
        'sha256' => $actual,
        'manifest_sha256' => $expected,
        'matches_manifest' => $expected === null ? null : hash_equals((string) $expected, (string) $actual),
        'bytes' => filesize($path),
    ];
}

$accounts = readCsv($dataDir.'/paper_accounts.csv');
$positions = readCsv($dataDir.'/virtual_positions.csv');
$snapshots = readCsv($dataDir.'/equity_snapshots.csv');

$accountIds = [];
foreach ($accounts as $account) {
    $accountIds[(string) $account['id']] = (string) ($account['strategy_id'] ?? 'unknown');
}

$factory = new PositionIntervalFactory;
$builder = new OverlapClusterBuilder;
$analyzer = new ConcentrationAnalyzer;

$intervals = [];
$perAccount = [];
$excluded = ['EMPTY_INTERVAL' => 0];
foreach ($positions as $position) {
    $openedAt = CarbonImmutable::parse((string) $position['opened_at'], 'UTC');
    $closedAt = trim((string) ($position['closed_at'] ?? '')) === ''
        ? null
        : CarbonImmutable::parse((string) $position['closed_at'], 'UTC');

    $interval = $factory->make(
        positionId: (string) $position['id'],
        accountId: (string) $position['paper_account_id'],
        symbol: (string) $position['symbol'],
        side: (string) $position['side'],
        openedAt: $openedAt,
        closedAt: $closedAt,
        initialQuantity: (string) ($position['initial_quantity'] ?? '0'),
        remainingQuantity: (string) ($position['remaining_quantity'] ?? '0'),
        netPnl: (string) ($position['net_pnl'] ?? '0'),
        unrealizedPnl: (string) ($position['unrealized_pnl'] ?? '0'),
        entryPrice: (string) ($position['entry_price'] ?? '0'),
        asOf: $asOf,
    );

    if ($interval === null) {
        $excluded['EMPTY_INTERVAL']++;

        continue;
    }

    $intervals[] = $interval;
    $perAccount[(string) $position['paper_account_id']][] = $interval;
}
ksort($perAccount);

$views = [];
$clusterIndex = [];
foreach ($perAccount as $accountId => $accountIntervals) {
    $scope = 'ACCOUNT:'.$accountId;
    $sameSide = $builder->buildSameSide($accountIntervals, $scope);
    $opposite = $builder->buildOppositeSide($accountIntervals, $scope);
    $views[] = [
        'scope_code' => 'ACCOUNT',
        'cluster_scope_code' => $scope,
        'paper_account_id' => $accountId,
        'strategy_id' => $accountIds[$accountId] ?? null,
        'position_count' => count($accountIntervals),
        'same_side_summary' => $analyzer->summarize($sameSide, $accountIntervals),
        'opposite_side_summary' => $analyzer->summarize($opposite['clusters'], $accountIntervals),
        'opposite_side_edge_count' => count($opposite['edges']),
    ];
    $clusterIndex[$scope] = [
        'same_side' => array_map(static fn ($c): array => [
            'cluster_id' => $c->clusterId,
            'symbol' => $c->symbol,
            'side_family' => $c->sideFamily,
            'member_position_ids' => $c->memberIds(),
            'member_count' => $c->memberCount(),
            'net_pnl' => $c->netPnl,
            'start_at' => $c->startAt->toIso8601String(),
            'end_at' => $c->endAt->toIso8601String(),
        ], $sameSide),
        'opposite_side' => array_map(static fn ($c): array => [
            'cluster_id' => $c->clusterId,
            'symbol' => $c->symbol,
            'member_position_ids' => $c->memberIds(),
            'member_count' => $c->memberCount(),
            'net_pnl' => $c->netPnl,
            'start_at' => $c->startAt->toIso8601String(),
            'end_at' => $c->endAt->toIso8601String(),
        ], $opposite['clusters']),
        'opposite_side_edges' => $opposite['edges'],
    ];
}

$combinedScope = 'RESEARCH_COMBINED';
$combinedSameSide = $builder->buildSameSide($intervals, $combinedScope);
$combinedOpposite = $builder->buildOppositeSide($intervals, $combinedScope);
$views[] = [
    'scope_code' => 'RESEARCH_COMBINED',
    'cluster_scope_code' => $combinedScope,
    'paper_account_id' => null,
    'strategy_id' => null,
    'position_count' => count($intervals),
    'same_side_summary' => $analyzer->summarize($combinedSameSide, $intervals),
    'opposite_side_summary' => $analyzer->summarize($combinedOpposite['clusters'], $intervals),
    'opposite_side_edge_count' => count($combinedOpposite['edges']),
];
$clusterIndex[$combinedScope] = [
    'same_side' => array_map(static fn ($c): array => [
        'cluster_id' => $c->clusterId,
        'symbol' => $c->symbol,
        'side_family' => $c->sideFamily,
        'member_position_ids' => $c->memberIds(),
        'member_count' => $c->memberCount(),
        'net_pnl' => $c->netPnl,
        'start_at' => $c->startAt->toIso8601String(),
        'end_at' => $c->endAt->toIso8601String(),
    ], $combinedSameSide),
    'opposite_side' => array_map(static fn ($c): array => [
        'cluster_id' => $c->clusterId,
        'symbol' => $c->symbol,
        'member_position_ids' => $c->memberIds(),
        'member_count' => $c->memberCount(),
        'net_pnl' => $c->netPnl,
        'start_at' => $c->startAt->toIso8601String(),
        'end_at' => $c->endAt->toIso8601String(),
    ], $combinedOpposite['clusters']),
    'opposite_side_edges' => $combinedOpposite['edges'],
];

$exposure = (new CrossSymbolExposure)->compute($intervals, $asOf->getTimestamp());

$equitySeries = [];
foreach ($snapshots as $snapshot) {
    $accountId = (string) $snapshot['paper_account_id'];
    $equitySeries[$accountId][] = [
        'ts' => CarbonImmutable::parse((string) $snapshot['recorded_at'], 'UTC')->getTimestamp(),
        'equity' => (string) ($snapshot['equity'] ?? '0'),
    ];
}
$alignmentInterval = 900;
$maxCarryForwardAge = 3600;
$alignment = (new EquityAlignment)->align($equitySeries, $alignmentInterval, $maxCarryForwardAge, $asOf->getTimestamp());

// Observed equity snapshot cadence: the fixed carry-forward age must be
// justified by the real snapshot spacing (§10.3).
$cadence = [];
foreach ($equitySeries as $accountId => $points) {
    usort($points, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
    $gaps = [];
    for ($i = 1; $i < count($points); $i++) {
        $gaps[] = $points[$i]['ts'] - $points[$i - 1]['ts'];
    }
    sort($gaps);
    $cadence[$accountId] = [
        'snapshots' => count($points),
        'first_at' => $points ? CarbonImmutable::createFromTimestampUTC($points[0]['ts'])->toIso8601String() : null,
        'last_at' => $points ? CarbonImmutable::createFromTimestampUTC($points[count($points) - 1]['ts'])->toIso8601String() : null,
        'median_gap_seconds' => $gaps === [] ? null : $gaps[intdiv(count($gaps), 2)],
        'max_gap_seconds' => $gaps === [] ? null : max($gaps),
    ];
}
ksort($cadence);
$maxObservedGap = 0;
foreach ($cadence as $row) {
    $maxObservedGap = max($maxObservedGap, (int) ($row['max_gap_seconds'] ?? 0));
}

$evidence = [
    'package_code' => 'P-5',
    'package_title' => 'Concentration, overlapping positions and dependence',
    'spec_reference' => 'VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md §10',
    'mode' => 'RESEARCH_ONLY_ARCHIVE_EVALUATION',
    'generated_at_utc' => CarbonImmutable::now('UTC')->toIso8601String(),
    'as_of' => $asOf->toIso8601String(),
    'source' => [
        'data_dir' => $dataDir,
        'manifest_generated_at_utc' => $manifest['generated_at_utc'] ?? null,
        'manifest_source_consistency' => $manifest['source_consistency'] ?? null,
        'manifest_window' => $manifest['window'] ?? null,
        'mutable_position_snapshots_may_change_after_cutoff' => $manifest['mutable_position_snapshots_may_change_after_cutoff'] ?? null,
        'file_integrity' => $sourceIntegrity,
        'row_counts' => [
            'paper_accounts' => count($accounts),
            'virtual_positions' => count($positions),
            'equity_snapshots' => count($snapshots),
        ],
    ],
    'config' => [
        'algorithm_version' => '1.1.0',
        'metric_schema_version' => '1.1.0',
        'cluster_definition_version' => OverlapClusterBuilder::CLUSTER_DEFINITION_VERSION,
        'alignment_interval_seconds' => $alignmentInterval,
        'max_carry_forward_age_seconds' => $maxCarryForwardAge,
        'max_carry_forward_age_rationale' => 'Fixed maximum age of 3600s; the largest observed equity snapshot gap in the archive is '.$maxObservedGap.'s, so a compliant carry-forward cannot cover the full window and the cross-account comparison is reported as NOT_COMPARABLE instead of being fabricated.',
        'observed_snapshot_cadence_seconds' => $cadence,
        'accounting_scope' => 'FULL_LIFECYCLE',
    ],
    'results' => [
        'position_count' => count($intervals),
        'excluded_positions' => $excluded,
        'views' => $views,
        'clusters' => $clusterIndex,
        'cross_symbol_exposure' => $exposure,
        'equity_alignment' => [
            'status' => $alignment['status'],
            'reason' => $alignment['reason'] ?? null,
            'grid_points' => $alignment['grid_points'] ?? 0,
            'missing_points' => $alignment['missing_points'] ?? 0,
            'account_count' => $alignment['account_count'] ?? 0,
            'alignment_interval_seconds' => $alignmentInterval,
            'max_carry_forward_age_seconds' => $maxCarryForwardAge,
            'simultaneous_drawdown_periods' => $alignment['simultaneous_drawdown_periods'] ?? [],
            'interpolation_applied' => false,
            'zero_fill_applied' => false,
        ],
    ],
    'provenance' => [
        'database_written' => false,
        'paper_ledger_mutated' => false,
        'order_path_used' => false,
        'netting_applied' => false,
        'independence_caveat' => 'A cluster is a dependence group, not a set of independent observations.',
        'limitations' => [
            'Single bounded 24h forward-paper export; not a statistical sample.',
            'Manifest declares BOUNDED_BEST_EFFORT_NOT_REPEATABLE_READ and mutable position snapshots.',
            'No prospective shadow history: results are descriptive, never a profitability claim.',
        ],
    ],
];

$directory = dirname($outPath);
if (! is_dir($directory)) {
    mkdir($directory, 0o755, true);
}
file_put_contents($outPath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

printf(
    "P-5 archive evaluation written to %s (positions=%d, views=%d, sha256=%s)\n",
    $outPath,
    count($intervals),
    count($views),
    hash_file('sha256', $outPath)
);
