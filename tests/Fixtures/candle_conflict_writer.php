<?php

declare(strict_types=1);
use App\MarketData\Application\MarketCandleConflictService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'bybit_test') {
    throw new RuntimeException('Isolated testing database required.');
}
config(['market_data.conflict_journal_path' => $argv[2]]);
file_put_contents($argv[3].'.'.$argv[1], 'ready');
flush();
$deadline = microtime(true) + 5;
while (! file_exists($argv[3])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(1000);
}
$app->make(MarketCandleConflictService::class)->recordConflict(
    'binance', 'linear', 'BTCUSDT', '5', '2026-01-01T00:05:00Z',
    ['close' => '102'], ['close' => (string) (103 + (int) $argv[1])], 'close', 'Conflicting immutable Binance close observation.'
);
