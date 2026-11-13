<?php

declare(strict_types=1);

require_once __DIR__.'/../../app/Backtest/Application/HistoricalExecutionCostPolicy.php';

use App\Backtest\Application\HistoricalExecutionCostPolicy as Policy;

$initial = ['fees' => ['taker_rate' => '0.00055'], 'slippage_rate' => '0.0002'];
$pass = 0;
$valid = [
    ['maker_rate' => '0', 'taker_rate' => '0.0004', 'slippage_rate' => '0.0003'],
    ['maker_rate' => '0', 'taker_rate' => '0', 'slippage_rate' => '0'],
];
foreach ($valid as $cost) {
    $updated = Policy::apply('okx', $initial, 'linear_perpetual', $cost);
    assert($updated['fees']['taker_rate'] === $cost['taker_rate']);
    assert($updated['slippage_rate'] === $cost['slippage_rate']);
    assert($initial['fees']['taker_rate'] === '0.00055');
    $pass++;
}
$rejections = [
    ['spot', ['maker_rate' => '0', 'taker_rate' => '0', 'slippage_rate' => '0']],
    [null, null],
    ['linear_perpetual', null],
    ['linear_perpetual', []],
    ['linear_perpetual', ['maker_rate' => '0', 'taker_rate' => '0.0004']],
    ['linear_perpetual', ['maker_rate' => '0', 'taker_rate' => '-0.1', 'slippage_rate' => '0']],
    ['linear_perpetual', ['maker_rate' => '0', 'taker_rate' => '0.1', 'slippage_rate' => '0']],
    ['linear_perpetual', ['maker_rate' => '0', 'taker_rate' => 'NaN', 'slippage_rate' => '0']],
    ['linear_perpetual', ['maker_rate' => '0', 'taker_rate' => 0.02, 'slippage_rate' => '0']],
];
foreach ($rejections as [$type, $cost]) {
    try {
        Policy::apply('okx', $initial, $type, $cost);
        throw new LogicException('Unverified exchange costs were accepted.');
    } catch (RuntimeException $error) {
        if ($error instanceof LogicException) {
            throw $error;
        }
        $pass++;
    }
}
echo "Historical execution cost isolation: {$pass}/".(count($valid) + count($rejections))." PASS\n";
