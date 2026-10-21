<?php

declare(strict_types=1);

/**
 * A0-A3 — read-only autonomy evaluation over the published research evidence.
 *
 * Evaluates the real candidate results of the section 7 run against the
 * precommitted data-sufficiency and Gate-6 requirements and produces the
 * fail-closed promotion verdict. It never opens a database connection, never
 * writes to the paper ledger and never authorises a promotion.
 *
 * Usage:
 *   php scripts/a0_a3_archive_evaluation.php --evidence-dir=reports/profit-growth --out=reports/profit-growth/A0_A3_evidence.json
 */

use App\Research\Domain\Autonomy\AllocationProposal;
use App\Research\Domain\Autonomy\CandidateRegistry;
use App\Research\Domain\Autonomy\DataSufficiencyPolicy;
use App\Research\Domain\Autonomy\PaperPromotionPolicy;
use App\Research\Domain\Autonomy\PromotionControlPolicy;
use Carbon\CarbonImmutable;

require __DIR__.'/../vendor/autoload.php';

$options = getopt('', ['evidence-dir::', 'out::', 'now::']);
$evidenceDir = rtrim((string) ($options['evidence-dir'] ?? 'reports/profit-growth'), '/');
$outPath = (string) ($options['out'] ?? 'reports/profit-growth/A0_A3_evidence.json');
$now = isset($options['now'])
    ? CarbonImmutable::parse((string) $options['now'], 'UTC')
    : CarbonImmutable::now('UTC');

$s7Path = $evidenceDir.'/S7_evidence.json';
if (! is_file($s7Path)) {
    fwrite(STDERR, "Section 7 evidence not found: {$s7Path}\n");
    exit(2);
}

$s7 = json_decode((string) file_get_contents($s7Path), true, 512, JSON_THROW_ON_ERROR);

$p5Path = $evidenceDir.'/P5_evidence.json';
$p5 = is_file($p5Path)
    ? json_decode((string) file_get_contents($p5Path), true, 512, JSON_THROW_ON_ERROR)
    : null;

$gates = [
    'min_valid_utc_days' => 20,
    'min_ess' => 20,
    'min_train_observations' => 300,
    'min_oos_observations' => 100,
    'min_shadow_observations' => 100,
    'min_shadow_days' => 14,
    'min_shadow_trades' => 50,
    'min_net_improvement_lcb' => '0',
    'max_drawdown_degradation_pct' => '0',
    'max_concentration_degradation' => '0',
];

$sufficiency = new DataSufficiencyPolicy;
$promotion = new PaperPromotionPolicy;
$allocation = (new AllocationProposal)->propose(
    ['btc_usdt' => '100', 'eth_usdt' => '100'],
    ['btc_usdt' => 0.02, 'eth_usdt' => 0.03],
    ['btc_usdt|eth_usdt' => 0.7],
    ['btc_usdt' => 0.8, 'eth_usdt' => 0.8],
    '200',
    0.8,
    0.6,
);

$candidates = [];
$eligible = 0;

foreach ($s7['candidates'] ?? [] as $candidate) {
    $hypothesis = CandidateRegistry::lookup((string) $candidate['candidate_id']);

    $observations = [
        'train_observations' => (int) ($candidate['split_metrics']['TRAIN']['trades'] ?? 0),
        'oos_observations' => (int) ($candidate['split_metrics']['VALIDATION']['trades'] ?? 0),
        // The prospective shadow history of an isolated paper challenger does not
        // exist in this environment. It is reported as missing, never as zero.
        'shadow_observations' => null,
        'shadow_days' => null,
        'shadow_trades' => null,
        'valid_utc_days' => (int) ($s7['dataset']['days'] ?? 0),
        'ess' => null,
    ];

    $suff = $sufficiency->evaluate($observations, $gates);

    $verdict = $promotion->evaluate([
        'valid_utc_days' => $observations['valid_utc_days'],
        'ess' => $observations['ess'],
        'train_observations' => $observations['train_observations'],
        'oos_observations' => $observations['oos_observations'],
        'shadow_observations' => $observations['shadow_observations'],
        'shadow_days' => $observations['shadow_days'],
        'shadow_trades' => $observations['shadow_trades'],
        'net_improvement_lcb' => null,
        'max_drawdown_degradation_pct' => null,
        'max_concentration_degradation' => null,
    ], $gates, true);

    if ($verdict['verdict'] === PaperPromotionPolicy::VERDICT_ELIGIBLE) {
        $eligible++;
    }

    $candidates[] = [
        'candidate_id' => (string) $candidate['candidate_id'],
        'registered' => $hypothesis !== null,
        'registration_hash' => $hypothesis?->registrationHash(),
        'executable' => (bool) ($candidate['executable'] ?? false),
        'section7_status' => (string) ($candidate['status'] ?? 'UNKNOWN'),
        'observations' => $observations,
        'data_sufficiency' => $suff,
        'promotion_verdict' => $verdict['verdict'],
        'promotion_reason' => $verdict['reason'],
        'failed_gates' => $verdict['failed_gates'],
    ];
}

$evidence = [
    'package_code' => 'A0-A3',
    'package_title' => 'Autonomous paper-research roadmap (A0 registry, A1 challenger controller, A2 paper-only promotion, A3 allocation research)',
    'spec_reference' => 'VECTOR_OPENCODE_OVERNIGHT_FULL_EXECUTION_SPEC_EN.md §8; VECTOR_AUTONOMOUS_PROFIT_RESEARCH_MASTER_SPEC_V1_EN.md §4.8/§5.1',
    'mode' => 'RESEARCH_ONLY_EVIDENCE_EVALUATION',
    'generated_at_utc' => $now->toIso8601String(),
    'source' => [
        'section7_evidence' => $s7Path,
        'section7_sha256' => hash_file('sha256', $s7Path),
        'note' => 'The evaluation consumes the published section 7 candidate results; no database and no ledger were touched.',
    ],
    'config' => [
        'gates' => $gates,
        'registry_hash' => CandidateRegistry::hash(),
        'registry_candidate_count' => count(CandidateRegistry::all()),
        'familywise_experiment_count' => (int) CandidateRegistry::FAMILYWISE_COUNT,
        'paper_promotion_flag_in_offline_evaluation' => true,
        'note' => 'The offline evaluation assumes the flag is ON to show the underlying evidence verdict; the runtime default is OFF.',
    ],
    'results' => [
        'candidates' => $candidates,
        'eligible_candidate_count' => $eligible,
        'eligible_candidate_ids' => array_values(array_map(
            static fn (array $candidate): string => $candidate['candidate_id'],
            array_filter($candidates, static fn (array $candidate): bool => $candidate['promotion_verdict'] === PaperPromotionPolicy::VERDICT_ELIGIBLE)
        )),
    ],
    'provenance' => [
        'verdict' => $eligible === 0 ? 'NOT_ELIGIBLE_SHADOW_HISTORY_ABSENT' : 'REVIEW_REQUIRED',
        'reasons' => [
            'No prospective shadow history of an isolated paper challenger exists in this environment.',
            'The archived dataset is shorter than the minimum valid-UTC-day requirement.',
            'The effective sample size of the archive cannot be established for the candidate cohorts.',
        ],
        'shadow_history_present' => false,
        'feature_flag_enabled' => false,
        'strategy_paths_modified' => false,
        'risk_budget_written' => false,
        'ledgers_merged' => false,
        'orders_placed' => 0,
        'primary_paper_accounts_touched' => false,
    ],
    'controls' => [
        'kill_switch_active' => false,
        'kill_switch_release_enabled' => false,
        'rollback_triggers' => PromotionControlPolicy::rollbackTriggers(),
        'feature_flags' => [
            'paper_promotion_enabled' => false,
            'challenger_execution_enabled' => false,
        ],
        'note' => 'The kill switch and the promotion flags are OFF. The rollback triggers are precommitted and immutable.',
    ],
    'allocation' => $allocation,
];

if (! is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0755, true);
}
file_put_contents($outPath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

echo 'A0-A3 autonomy evaluation written to '.$outPath."\n";
echo '  eligible_candidates='.$eligible.' of '.count($candidates)."\n";
foreach ($candidates as $candidate) {
    echo '  '.$candidate['candidate_id'].' -> '.$candidate['promotion_verdict'].' ('.$candidate['promotion_reason'].")\n";
}
