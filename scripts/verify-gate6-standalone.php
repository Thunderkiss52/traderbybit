<?php

declare(strict_types=1);

// Dependency-free regression harness for risk evidence semantics. This does
// not replace PHPUnit or a real isolated PostgreSQL integration test.
require_once dirname(__DIR__).'/app/Research/Application/PromotionPolicyService.php';

function config(string $key, mixed $default = null): mixed
{
    return match ($key) {
        'strategy.promotion_policy' => [
            'version' => '4.0.0', 'min_training_samples' => 300,
            'min_oos_samples' => 100, 'min_shadow_samples' => 100,
            'min_challenger_duration_days' => 14, 'min_challenger_samples' => 50,
            'max_challenger_drawdown_margin' => 2.0, 'max_hard_drawdown' => 12.0,
            'max_brier' => 0.28, 'max_ece' => 0.15,
            'alpha_fwer' => 0.05, 'max_concurrent_candidates' => 5,
        ],
        'strategy.promotion_policy.version' => '4.0.0',
        'research.autonomy.gates' => [],
        default => $default,
    };
}

function app(): object
{
    return new class
    {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
}

use App\Research\Application\PromotionPolicyService;

$policy = (new ReflectionClass(PromotionPolicyService::class))->newInstanceWithoutConstructor();
$valid = [
    'training_samples' => 400, 'oos_samples' => 150, 'shadow_samples' => 130,
    'challenger_duration_days' => 20, 'challenger_samples' => 75,
    'average_r' => 0.55, 'max_drawdown_percent' => 5.0,
    'brier_score' => 0.15, 'ece' => 0.04,
    'r_differences' => array_fill(0, 75, 0.30),
];
$champ = ['average_r' => 0.20, 'max_drawdown_percent' => 4.5];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException('Gate6 regression FAILED: '.$message);
    }
    $checks++;
};

$eval = static function (array $candidate, ?array $champion = null) use ($policy, $champ): array {
    return $policy->evaluateModelPromotion($candidate, $champion ?? $champ, ['is_test_fixture' => true]);
};

$good = $eval($valid);
$check($good['all_gates_passed'] === true, 'valid evidence should pass synthetic isolated regression');
$check($good['gates']['min_oos_samples']['actual'] === 150, 'actual OOS count');
foreach (['training_samples', 'oos_samples', 'shadow_samples', 'challenger_samples', 'challenger_duration_days'] as $key) {
    foreach ([null, true, 'banana', -1, 1.5, INF] as $bad) {
        $input = $valid;
        $input[$key] = $bad;
        $verdict = $eval($input);
        $check($verdict['all_gates_passed'] === false, $key.' accepted invalid sample');
    }
}
foreach (['max_drawdown_percent', 'brier_score', 'ece', 'average_r'] as $key) {
    foreach ([null, true, 'banana', NAN, INF, -INF] as $bad) {
        $input = $valid;
        $input[$key] = $bad;
        $check($eval($input)['all_gates_passed'] === false, $key.' accepted invalid observation');
    }
}
foreach ([null, ['average_r' => .20], ['average_r' => INF, 'max_drawdown_percent' => 4.5]] as $badChampion) {
    $check($policy->evaluateModelPromotion($valid, $badChampion, ['is_test_fixture' => true])['all_gates_passed'] === false, 'missing/invalid champion passed');
}
foreach ([null, [], [0.3], [INF, .3], [NAN, .3], ['hack', 0.4]] as $invalidDiffs) {
    $input = $valid;
    $input['r_differences'] = $invalidDiffs;
    $input['standard_error'] = 0.0000001;
    $check($eval($input)['all_gates_passed'] === false, 'invalid paired data fabricated statistical improvement');
}
$tiny = $valid;
$tiny['r_differences'] = array_fill(0, 2, 0.30);
$check($eval($tiny)['all_gates_passed'] === false, 'two positive paired results cannot support 75 challenger operations');
$check($policy->computeMultiplicityAdjustedLcb([INF, 0.2])['pass'] === false, 'infinite paired difference');
$check($policy->computeMultiplicityAdjustedLcb([0.2, 0.2], alpha: 1.0)['pass'] === false, 'invalid alpha');

fwrite(STDOUT, "PASS: {$checks} Gate6 strict-evidence regression checks\n");
