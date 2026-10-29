<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'bybit_test') {
    throw new RuntimeException('Isolated testing database required.');
}
config(['market_data.conflict_journal_path' => getenv('CANDLE_TEST_JOURNAL')]);
Http::preventStrayRequests();
Http::fake(['https://fapi.binance.com/fapi/v1/klines*' => function ($request) {
    if (getenv('CANDLE_TEST_CASE') === 'failed' && $request['symbol'] === 'BTCUSDT') {
        return Http::response(['malformed' => true]);
    }
    if (getenv('CANDLE_TEST_CASE') === 'failed_scope' && $request['symbol'] === 'BTCUSDT' && $request['interval'] === '15m') {
        return Http::response(['malformed' => true]);
    }
    $duration = ['15m' => 900000, '30m' => 1800000, '1h' => 3600000, '4h' => 14400000][$request['interval']];
    $rows = [];
    for ($i = 0; $i < 3; $i++) {
        $open = 1767225600000 + $i * $duration;
        $close = $i === 1 && in_array(getenv('CANDLE_TEST_CASE'), ['partial', 'persistence'], true) ? '103' : '102';
        $rows[] = [$open, '100', '105', '99', $close, '10', $open + $duration - 1, '1010'];
    }

    return Http::response($rows);
}]);
$input = new ArgvInput;
$status = $kernel->handle($input, new ConsoleOutput);
$kernel->terminate($input, $status);
exit($status);
