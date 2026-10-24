<?php

declare(strict_types=1);

// Pure-domain smoke tests without Composer, Laravel or a database.
require dirname(__DIR__).'/app/Research/Domain/Autonomy/DataSufficiencyPolicy.php';
require dirname(__DIR__).'/app/Research/Domain/Autonomy/PromotionControlPolicy.php';
require dirname(__DIR__).'/app/Research/Domain/Autonomy/PaperPromotionPolicy.php';

use App\Research\Domain\Autonomy\DataSufficiencyPolicy;
use App\Research\Domain\Autonomy\PaperPromotionPolicy;
use App\Research\Domain\Autonomy\PromotionControlPolicy;

$gates = [
    'min_valid_utc_days' => 20, 'min_ess' => 20,
    'min_train_observations' => 300, 'min_oos_observations' => 100,
    'min_shadow_observations' => 100, 'min_shadow_days' => 14,
    'min_shadow_trades' => 50, 'min_net_improvement_lcb' => '0',
    'max_drawdown_degradation_pct' => '0', 'max_concentration_degradation' => '0',
];
$evidence = [
    'valid_utc_days' => 30, 'ess' => 25, 'train_observations' => 400,
    'oos_observations' => 150, 'shadow_observations' => 120,
    'shadow_days' => 15, 'shadow_trades' => 60,
    'net_improvement_lcb' => '0.4', 'max_drawdown_degradation_pct' => '0',
    'max_concentration_degradation' => '0',
];
$policy = new PaperPromotionPolicy;
$passed = 0;
$check = static function (bool $result, string $label) use (&$passed): void {
    if (! $result) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    $passed++;
};
$check($policy->evaluate($evidence, $gates, true)['verdict'] === 'ELIGIBLE', 'valid evidence remains eligible');
foreach (['net_improvement_lcb', 'max_drawdown_degradation_pct', 'max_concentration_degradation'] as $key) {
    foreach ([true, INF, NAN, [], 'invalid'] as $bad) {
        $result = $policy->evaluate(array_replace($evidence, [$key => $bad]), $gates, true);
        $check($result['verdict'] === 'NOT_ELIGIBLE', "invalid {$key} is blocked");
    }
}
foreach ([true, INF, NAN, [], 'invalid'] as $bad) {
    $result = (new DataSufficiencyPolicy)->evaluate(array_replace($evidence, ['ess' => $bad]), $gates);
    $check($result['status'] === 'INSUFFICIENT_DATA', 'invalid sample-size metric blocked');
}
$incomplete = $gates;
unset($incomplete['min_shadow_trades']);
$check($policy->evaluate($evidence, $incomplete, true)['verdict'] === 'NOT_ELIGIBLE', 'missing gate blocks promotion');
$check(PromotionControlPolicy::evaluateTriggers(['source_generation_revoked' => true])['status'] === 'ROLLBACK_REQUIRED', 'revocation breach');
$check(PromotionControlPolicy::evaluateTriggers([])['status'] === 'INCOMPLETE_EVIDENCE', 'missing observations not safe');

echo "PASS: {$passed} standalone A0/A2/Gate6 assertions\n";
