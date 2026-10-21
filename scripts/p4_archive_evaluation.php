<?php

declare(strict_types=1);

/**
 * PROFIT-1 / P-4 — read-only archive evaluator.
 *
 * Evaluates the P-4 cost structure, MFE/MAE diagnostics and the pre-entry
 * expected-MFE estimator on a local research export (audit_data/) without
 * touching the database, the paper ledger or any live endpoint. The same
 * domain classes as the Laravel service are used, so the offline result cannot
 * diverge from the stored one.
 *
 * Usage:
 *   php scripts/p4_archive_evaluation.php --data-dir=audit_data --out=reports/profit-growth/P4_evidence.json
 */

use App\Research\Domain\CostMfe\CostAttribution;
use App\Research\Domain\CostMfe\MfeMaeCalculator;
use App\Research\Domain\CostMfe\MfeMaeResult;
use App\Research\Domain\CostMfe\PreEntryMfeEstimator;
use App\Research\Domain\CostMfe\PriceCandle;
use Carbon\CarbonImmutable;

require __DIR__.'/../vendor/autoload.php';

$options = getopt('', ['data-dir::', 'out::', 'as-of::']);
$dataDir = rtrim((string) ($options['data-dir'] ?? 'audit_data'), '/');
$outPath = (string) ($options['out'] ?? 'reports/profit-growth/P4_evidence.json');

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

$filesUsed = [
    'paper_accounts.csv',
    'virtual_positions.csv',
    'virtual_executions.csv',
    'position_funding_events.csv',
    'market_candles.csv',
    'manifest.json',
];
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
$executions = readCsv($dataDir.'/virtual_executions.csv');
$fundingEvents = readCsv($dataDir.'/position_funding_events.csv');
$candles = readCsv($dataDir.'/market_candles.csv');

$strategyByAccount = [];
foreach ($accounts as $account) {
    $strategyByAccount[(string) $account['id']] = (string) ($account['strategy_id'] ?? 'unknown');
}

/** @var array<string, list<PriceCandle>> $candlesBySymbol */
$candlesBySymbol = [];
foreach ($candles as $candle) {
    if ((string) ($candle['is_closed'] ?? '0') !== '1') {
        continue;
    }

    if ((string) ($candle['interval'] ?? '') !== '5') {
        continue;
    }

    $openTime = CarbonImmutable::parse((string) $candle['open_time'], 'UTC');
    $candlesBySymbol[(string) $candle['symbol']][] = new PriceCandle(
        candleId: (string) $candle['id'],
        symbol: (string) $candle['symbol'],
        interval: '5',
        openTime: $openTime,
        closeTime: $openTime->addMinutes(5),
        open: (string) $candle['open'],
        high: (string) $candle['high'],
        low: (string) $candle['low'],
        close: (string) $candle['close'],
    );
}
foreach ($candlesBySymbol as $symbol => $list) {
    usort($list, static fn (PriceCandle $a, PriceCandle $b): int => $a->closeTime->getTimestamp() <=> $b->closeTime->getTimestamp()
        ?: strcmp($a->candleId, $b->candleId));
    $candlesBySymbol[$symbol] = $list;
}

$calculator = new MfeMaeCalculator;
$estimator = new PreEntryMfeEstimator;

$positionRows = [];
$results = [];
$excluded = ['EMPTY_HOLDING_INTERVAL' => 0, 'INVALID_QUANTITY' => 0];

foreach ($positions as $position) {
    $positionId = (string) $position['id'];
    $openedAt = CarbonImmutable::parse((string) $position['opened_at'], 'UTC');
    $closedAt = trim((string) ($position['closed_at'] ?? '')) === ''
        ? null
        : CarbonImmutable::parse((string) $position['closed_at'], 'UTC');
    $isOpen = $closedAt === null;
    $holdingEnd = $isOpen ? $asOf : $closedAt;

    if (! $holdingEnd->greaterThan($openedAt)) {
        $excluded['EMPTY_HOLDING_INTERVAL']++;

        continue;
    }

    $quantity = (string) ($position['initial_quantity'] ?? '0');
    if ($quantity === '' || (float) $quantity <= 0.0) {
        $excluded['INVALID_QUANTITY']++;

        continue;
    }

    $symbol = (string) $position['symbol'];
    $result = $calculator->calculate(
        positionId: $positionId,
        accountId: (string) $position['paper_account_id'],
        symbol: $symbol,
        side: (string) $position['side'],
        entryReference: (string) $position['entry_price'],
        quantity: $quantity,
        stopLoss: (string) ($position['stop_loss'] ?? ''),
        takeProfit: (string) ($position['take_profit_1'] ?? ''),
        holdingStart: $openedAt,
        holdingEnd: $holdingEnd,
        candles: $candlesBySymbol[$symbol] ?? [],
        asOf: $asOf,
    );

    $results[$positionId] = $result;
    $positionRows[$positionId] = [
        'position' => $position,
        'result' => $result,
        'opened_at' => $openedAt,
        'closed_at' => $closedAt,
        'is_open' => $isOpen,
    ];
}

$ordered = $positionRows;
uasort($ordered, static fn (array $a, array $b): int => $a['opened_at']->getTimestamp() <=> $b['opened_at']->getTimestamp()
    ?: strcmp((string) $a['position']['id'], (string) $b['position']['id']));

// Pre-entry expected MFE: for every candidate entry only observations that had
// already matured before that entry may be used.
$preEntry = [];
foreach ($ordered as $positionId => $row) {
    $candidateEntryAt = $row['opened_at'];
    $symbol = (string) $row['position']['symbol'];

    $observations = [];
    foreach ($ordered as $otherId => $other) {
        if ($otherId === $positionId) {
            continue;
        }

        if ((string) $other['position']['symbol'] !== $symbol) {
            continue;
        }

        if ($other['closed_at'] === null) {
            continue;
        }

        $observations[] = [
            'result' => $other['result'],
            'matured_at' => $other['closed_at'],
        ];
    }

    $estimate = $estimator->estimate($candidateEntryAt, $observations);
    $preEntry[] = [
        'position_id' => $positionId,
        'symbol' => $symbol,
        'candidate_entry_at' => $candidateEntryAt->toIso8601String(),
        'estimate' => $estimate,
    ];
}

$attribution = new CostAttribution;

$executionRows = [];
foreach ($executions as $execution) {
    $positionId = (string) $execution['position_id'];
    $position = $positionRows[$positionId]['position'] ?? null;
    $accountId = (string) ($position['paper_account_id'] ?? '');

    $executionRows[] = [
        'id' => (string) $execution['id'],
        'position_id' => $positionId,
        'account_id' => $accountId,
        'strategy_id' => $strategyByAccount[$accountId] ?? 'unknown',
        'symbol' => (string) ($position['symbol'] ?? ''),
        'type' => (string) $execution['type'],
        'side' => (string) $execution['side'],
        'quantity' => (string) $execution['quantity'],
        'price' => (string) $execution['price'],
        'slippage' => (string) $execution['slippage'],
        'commission' => (string) $execution['commission'],
        'gross_pnl' => (string) $execution['gross_pnl'],
        'ambiguous_execution' => trim((string) ($execution['ambiguous_execution'] ?? '')) === '1',
    ];
}

$fundingRows = [];
foreach ($fundingEvents as $funding) {
    $positionId = (string) $funding['position_id'];
    $position = $positionRows[$positionId]['position'] ?? null;
    $accountId = (string) ($position['paper_account_id'] ?? '');

    $fundingRows[] = [
        'position_id' => $positionId,
        'account_id' => $accountId,
        'strategy_id' => $strategyByAccount[$accountId] ?? 'unknown',
        'symbol' => (string) ($position['symbol'] ?? ''),
        'amount' => (string) $funding['amount'],
        'funding_at' => (string) $funding['funding_at'],
    ];
}

$positionRowsForAttribution = [];
foreach ($positionRows as $positionId => $row) {
    $position = $row['position'];
    $accountId = (string) $position['paper_account_id'];

    $positionRowsForAttribution[] = [
        'id' => $positionId,
        'account_id' => $accountId,
        'strategy_id' => $strategyByAccount[$accountId] ?? 'unknown',
        'symbol' => (string) $position['symbol'],
        'status' => (string) $position['status'],
        'gross_pnl' => (string) $position['gross_pnl'],
        'net_pnl' => (string) $position['net_pnl'],
        'fees' => (string) $position['fees'],
    ];
}

$attributionResult = $attribution->aggregate($executionRows, $fundingRows, $positionRowsForAttribution);

$mfeValues = [];
$maeValues = [];
$ambiguousCount = 0;
$noCandleCount = 0;
$openCount = 0;
foreach ($positionRows as $row) {
    $result = $row['result'];
    if ($row['is_open']) {
        $openCount++;
    }

    if ($result->status !== MfeMaeResult::STATUS_COMPUTED) {
        $noCandleCount++;

        continue;
    }

    if ($result->mfeR !== null) {
        $mfeValues[] = (float) $result->mfeR;
    }

    if ($result->maeR !== null) {
        $maeValues[] = (float) $result->maeR;
    }

    if ($result->ambiguousPath) {
        $ambiguousCount++;
    }
}
sort($mfeValues);
sort($maeValues);
$median = static function (array $values): ?float {
    if ($values === []) {
        return null;
    }
    $count = count($values);
    $middle = intdiv($count, 2);

    return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
};

$positionDetail = [];
foreach ($positionRows as $positionId => $row) {
    $result = $row['result'];
    $positionDetail[] = [
        'position_id' => $positionId,
        'paper_account_id' => (string) $row['position']['paper_account_id'],
        'strategy_id' => $strategyByAccount[(string) $row['position']['paper_account_id']] ?? 'unknown',
        'symbol' => (string) $row['position']['symbol'],
        'side' => (string) $row['position']['side'],
        'status' => (string) $row['position']['status'],
        'open_at_cutoff' => $row['is_open'],
        'holding_start' => $row['opened_at']->toIso8601String(),
        'holding_end' => ($row['closed_at'] ?? $asOf)->toIso8601String(),
        'mfe_mae_status' => $result->status,
        'mfe_price' => $result->mfePrice,
        'mae_price' => $result->maePrice,
        'mfe_usdt' => $result->mfeUsdt,
        'mae_usdt' => $result->maeUsdt,
        'mfe_r' => $result->mfeR,
        'mae_r' => $result->maeR,
        'mfe_candle_id' => $result->mfeCandleId,
        'mfe_candle_close_time' => $result->mfeCandleCloseTime,
        'mae_candle_id' => $result->maeCandleId,
        'mae_candle_close_time' => $result->maeCandleCloseTime,
        'candle_count' => $result->candleCount,
        'ambiguous_path' => $result->ambiguousPath,
        'ambiguity_reason' => $result->ambiguityReason,
        'risk_reason' => $result->riskReason,
        'source_candle_ids' => $result->sourceCandleIds,
        'source_candle_close_times' => $result->sourceCandleCloseTimes,
        'limitations' => $result->limitations,
    ];
}

$preEntrySummary = [
    'evaluated' => count($preEntry),
    'computed' => 0,
    'insufficient_data' => 0,
    'sample_count_histogram' => [],
];
foreach ($preEntry as $entry) {
    $status = (string) $entry['estimate']['status'];
    if ($status === PreEntryMfeEstimator::STATUS_COMPUTED) {
        $preEntrySummary['computed']++;
        $key = (string) $entry['estimate']['sample_count'];
    } else {
        $preEntrySummary['insufficient_data']++;
        $key = '0';
    }
    $preEntrySummary['sample_count_histogram'][$key] = ($preEntrySummary['sample_count_histogram'][$key] ?? 0) + 1;
}
ksort($preEntrySummary['sample_count_histogram']);

$evidence = [
    'package_code' => 'P-4',
    'package_title' => 'Cost structure, MFE/MAE and expected-excursion estimator',
    'spec_reference' => 'VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md §9',
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
            'virtual_executions' => count($executions),
            'position_funding_events' => count($fundingEvents),
            'market_candles' => count($candles),
        ],
    ],
    'config' => [
        'algorithm_version' => '1.0.0',
        'metric_schema_version' => '1.0.0',
        'candle_interval' => '5',
        'candle_close_time_derivation' => 'open_time + 5 minutes (the archive has no explicit candle close column)',
        'accounting_scope' => 'FULL_LIFECYCLE',
        'accounting_identity' => 'NET_PNL_EQUALS_GROSS_MINUS_FEES_PLUS_SIGNED_FUNDING',
        'slippage_treatment' => 'Attribution only; slippage is price distortion already inside gross PnL and is never deducted twice.',
    ],
    'results' => [
        'position_count' => count($positionRows),
        'excluded_positions' => $excluded,
        'mfe_mae_summary' => [
            'positions_with_computed_excursions' => count($positionRows) - $noCandleCount,
            'positions_without_eligible_candles' => $noCandleCount,
            'positions_open_at_cutoff' => $openCount,
            'positions_with_ambiguous_path' => $ambiguousCount,
            'mfe_r_min' => $mfeValues === [] ? null : $mfeValues[0],
            'mfe_r_median' => $median($mfeValues),
            'mfe_r_max' => $mfeValues === [] ? null : $mfeValues[count($mfeValues) - 1],
            'mae_r_min' => $maeValues === [] ? null : $maeValues[0],
            'mae_r_median' => $median($maeValues),
            'mae_r_max' => $maeValues === [] ? null : $maeValues[count($maeValues) - 1],
            'limitation' => 'OHLC derived excursion estimates over 5m candles; not tick-level path truth.',
        ],
        'pre_entry_mfe' => [
            'summary' => $preEntrySummary,
            'entries' => $preEntry,
        ],
        'cost_attribution' => $attributionResult,
        'positions' => $positionDetail,
    ],
    'provenance' => [
        'database_written' => false,
        'paper_ledger_mutated' => false,
        'order_path_used' => false,
        'future_data_used' => false,
        'no_lookahead_rule' => 'Every MFE/MAE uses only candles closing inside the observed holding interval and not after as_of; every pre-entry distribution uses only observations matured before the candidate entry.',
        'limitations' => [
            'Single bounded 24h forward-paper export; not a statistical sample.',
            'Manifest declares BOUNDED_BEST_EFFORT_NOT_REPEATABLE_READ and mutable position snapshots.',
            'Pre-entry expected MFE has an extremely small matured population; INSUFFICIENT_DATA is reported instead of a fabricated estimate.',
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
    "P-4 archive evaluation written to %s (positions=%d, pre_entry_computed=%d, sha256=%s)\n",
    $outPath,
    count($positionRows),
    $preEntrySummary['computed'],
    hash_file('sha256', $outPath)
);
