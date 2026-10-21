<?php

declare(strict_types=1);

/**
 * PROFIT-1 / P-6 — read-only research decision gate evaluator.
 *
 * Builds the P-6 input packages from the published evidence of P-1, P-2, P-4
 * and P-5 and runs the *same* pure `DecisionGate` that the Laravel service
 * uses, so the offline result cannot diverge from the stored one.
 *
 * The evaluator never opens a database connection, never writes to the paper
 * ledger and never authorises anything.
 *
 * Usage:
 *   php scripts/p6_archive_evaluation.php --evidence-dir=reports/profit-growth --out=reports/profit-growth/P6_evidence.json
 */

use App\Research\Domain\Decision\DecisionGate;
use App\Research\Domain\Decision\DecisionInputPackage;
use App\Research\Domain\Decision\DecisionState;
use App\Research\Domain\Decision\HypothesisPriority;
use Carbon\CarbonImmutable;

require __DIR__.'/../vendor/autoload.php';

$options = getopt('', ['evidence-dir::', 'out::', 'now::']);
$evidenceDir = rtrim((string) ($options['evidence-dir'] ?? 'reports/profit-growth'), '/');
$outPath = (string) ($options['out'] ?? 'reports/profit-growth/P6_evidence.json');
$now = isset($options['now'])
    ? CarbonImmutable::parse((string) $options['now'], 'UTC')
    : CarbonImmutable::now('UTC');

/**
 * @return array<string, mixed>|null
 */
function loadEvidence(string $path): ?array
{
    if (! is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param  array<string, mixed>  $evidence
 */
function packageFromEvidence(string $code, ?array $evidence, string $path, CarbonImmutable $now): array
{
    $metadata = [];
    $status = 'MISSING';
    $asOf = null;
    $sampleSufficiency = null;
    $coverageMet = null;
    $reconciled = null;
    $notes = [];

    if ($evidence === null) {
        $notes[] = 'evidence file missing';
    } else {
        $metadata = $evidence;
        $status = 'SUCCEEDED';

        if (isset($evidence['as_of'])) {
            $asOf = (string) $evidence['as_of'];
        } elseif (isset($evidence['timestamp_utc'])) {
            $asOf = (string) $evidence['timestamp_utc'];
        }

        switch ($code) {
            case 'P-1':
                // The P-1 evidence publishes a reproduction and its invariants, but
                // no accounting reconciliation flag: the gate therefore treats the
                // reconciliation as "not declared" and fails closed.
                $reconciled = null;
                $notes[] = 'P-1 evidence does not publish an accounting reconciliation flag';
                break;
            case 'P-2':
                $status = ($evidence['status'] ?? null) === 'SUFFICIENT' ? 'SUCCEEDED' : 'PARTIAL';
                $sampleSufficiency = $evidence['status'] ?? null;
                $notes[] = 'P-2 evidence status='.(string) ($evidence['status'] ?? 'UNKNOWN');
                break;
            case 'P-4':
                $groups = $evidence['results']['cost_attribution']['strategy'] ?? [];
                $reconciled = $groups === [] ? null : (bool) array_reduce(
                    $groups,
                    static fn (bool $carry, array $group): bool => $carry && ($group['reconciles_gross_minus_fees_plus_funding'] ?? false),
                    true
                );
                $sampleSufficiency = null;
                $notes[] = 'P-4 evidence does not publish a sample sufficiency declaration';
                break;
            case 'P-5':
                $sampleSufficiency = null;
                $notes[] = 'P-5 evidence does not publish a sample sufficiency declaration';
                break;
        }
    }

    $ageSeconds = null;
    if ($asOf !== null) {
        $ageSeconds = $now->getTimestamp() - CarbonImmutable::parse($asOf, 'UTC')->getTimestamp();
    }

    return [
        'package_code' => $code,
        'evidence_file' => basename($path),
        'evidence_present' => $evidence !== null,
        'status' => $status,
        'as_of' => $asOf,
        'age_seconds' => $ageSeconds,
        'sample_sufficiency' => $sampleSufficiency,
        'coverage_met' => $coverageMet,
        'reconciled' => $reconciled,
        'notes' => $notes,
    ];
}

$definitions = [
    'P-1' => $evidenceDir.'/P1_evidence.json',
    'P-2' => $evidenceDir.'/P2_evidence.json',
    'P-4' => $evidenceDir.'/P4_evidence.json',
    'P-5' => $evidenceDir.'/P5_evidence.json',
];

$sources = [];
$derived = [];
$inputs = [];

foreach ($definitions as $code => $path) {
    $evidence = loadEvidence($path);
    $derived[] = packageFromEvidence($code, $evidence, $path, $now);
    $sources[$code] = [
        'file' => $path,
        'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
        'present' => is_file($path),
    ];

    $package = $derived[count($derived) - 1];
    $inputs[] = new DecisionInputPackage(
        packageCode: $code,
        generationId: $package['evidence_present'] ? 'evidence:'.$code : null,
        status: (string) $package['status'],
        accountingScope: 'FULL_LIFECYCLE',
        asOf: $package['as_of'],
        sampleSufficiency: $package['sample_sufficiency'],
        coverageMet: $package['coverage_met'],
        reconciled: $package['reconciled'],
        provenance: 'PUBLISHED_EVIDENCE',
    );
}

$gate = new DecisionGate;

$claims = [
    DecisionGate::CLAIM_PROFITABILITY,
    DecisionGate::CLAIM_IMPROVEMENT,
    DecisionGate::CLAIM_CONCENTRATION_RISK,
    DecisionGate::CLAIM_COST_STRUCTURE,
    DecisionGate::CLAIM_RISK_REJECTED_OPPORTUNITY,
];

$outcomes = [];
foreach ($claims as $claimType) {
    $outcome = $gate->evaluate($inputs, [
        'claim_type' => $claimType,
        'now' => $now,
        'preregistration_hash' => 'archive-evaluation-no-preregistration',
        'evidence_bundle_hash' => 'archive-evaluation-no-bundle',
    ]);

    $blockingGate = null;
    foreach ($outcome->gateResults as $gateResult) {
        if ($gateResult['passed'] === false) {
            $blockingGate = $gateResult['gate'];
            break;
        }
    }

    $outcomes[$claimType] = [
        'state' => $outcome->state,
        'reasons' => $outcome->reasons,
        'missing_inputs' => $outcome->missingInputs,
        'first_blocking_gate' => $blockingGate,
        'gate_results' => $outcome->gateResults,
    ];
}

// The published evidence contains no registered hypothesis with a matched
// baseline/counterfactual population, so no recovery estimate may be shown.
$priority = new HypothesisPriority;
$priorityRows = $priority->build([
    [
        'hypothesis_id' => 'NO_REGISTERED_HYPOTHESIS',
        'assumption_version' => 'NONE',
        'status' => 'REGISTERED',
        'source_generation_ids' => [],
        'delta_net_pnl' => [],
    ],
]);

$evidence = [
    'package_code' => 'P-6',
    'package_title' => 'Research decision gate',
    'spec_reference' => 'VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md §14',
    'mode' => 'RESEARCH_ONLY_EVIDENCE_EVALUATION',
    'generated_at_utc' => $now->toIso8601String(),
    'source' => [
        'evidence_dir' => $evidenceDir,
        'files' => $sources,
        'note' => 'The gate consumes published evidence only; no database and no ledger were touched.',
    ],
    'config' => [
        'algorithm_version' => '1.0.0',
        'metric_schema_version' => '1.0.0',
        'max_input_age_seconds' => DecisionGate::MAX_INPUT_AGE_SECONDS,
        'required_packages' => DecisionGate::REQUIRED_PACKAGES,
        'inferential_packages' => DecisionGate::INFERENTIAL_PACKAGES,
        'attribution_only_packages' => DecisionGate::ATTRIBUTION_ONLY_PACKAGES,
        'reconciliation_required_packages' => DecisionGate::RECONCILIATION_REQUIRED_PACKAGES,
        'decision_states' => DecisionState::all(),
    ],
    'results' => [
        'derived_input_packages' => $derived,
        'claim_outcomes' => $outcomes,
        'hypothesis_priority' => $priorityRows,
    ],
    'provenance' => [
        'verdict' => 'FAIL_CLOSED_NO_ELIGIBLE_CONCLUSION',
        'conclusions_that_cannot_be_made' => [
            'No profitability or improvement ranking: the 24-hour archive cannot support one.',
            'No cost or fee cut may be justified from the published evidence.',
            'No hypothesis may be promoted: no registered hypothesis with a matched population exists.',
        ],
        'strategy_paths_modified' => false,
        'orders_placed' => 0,
        'paper_ledger_mutations' => 0,
    ],
];

if (! is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0755, true);
}
file_put_contents($outPath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

$summary = [];
foreach ($outcomes as $claimType => $outcome) {
    $summary[] = $claimType.'='.$outcome['state'].' ('.$outcome['first_blocking_gate'].')';
}

echo 'P-6 research decision gate evaluation written to '.$outPath."\n";
echo '  '.implode("\n  ", $summary)."\n";
