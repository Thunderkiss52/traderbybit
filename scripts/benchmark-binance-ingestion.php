<?php

declare(strict_types=1);

use App\Exchanges\Binance\BinanceCandleIngestion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Never bootstrap this write benchmark with an operational database configuration.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'bybit_test'
    || ! empty(getenv('DB_URL'))) {
    throw new RuntimeException('Explicit isolated loopback PostgreSQL bybit_test configuration required.');
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if (! $app->environment('testing') || $db->getDriverName() !== 'pgsql'
    || $db->getConfig('host') !== '127.0.0.1' || $db->getDatabaseName() !== 'bybit_test'
    || ! empty($db->getConfig('url'))) {
    throw new RuntimeException('Resolved benchmark database configuration is not isolated.');
}
$identity = $db->selectOne('select current_database() as database, version() as version');
if ($identity->database !== 'bybit_test') {
    throw new RuntimeException('Benchmark database identity mismatch.');
}

$options = getopt('', ['runs:', 'warmup:', 'revision:']);
$runs = (int) ($options['runs'] ?? 50);
$warmup = (int) ($options['warmup'] ?? 5);
if ($runs < 20 || $runs > 500 || $warmup < 1 || $warmup > 50) {
    throw new InvalidArgumentException('Use 20..500 measured runs and 1..50 warmup runs.');
}
$symbol = 'V26'.strtoupper(bin2hex(random_bytes(6))).'USDT';
config()->set('market_data.symbols', [$symbol]);
Http::preventStrayRequests();
$responseRows = [];
Http::fake(['https://fapi.binance.com/fapi/v1/klines*' => static function () use (&$responseRows) {
    return Http::response($responseRows);
}]);
$measuring = false;
$queries = 0;
$sqlMs = 0.0;
$db->listen(static function (QueryExecuted $query) use (&$measuring, &$queries, &$sqlMs): void {
    if ($measuring) {
        $queries++;
        $sqlMs += $query->time;
    }
});
$service = $app->make(BinanceCandleIngestion::class);
$percentile = static function (array $samples, float $fraction): float {
    sort($samples, SORT_NUMERIC);

    return round($samples[(int) ceil(count($samples) * $fraction) - 1], 3);
};
$report = [
    'revision' => $options['revision'] ?? 'working-tree',
    'generated_at_utc' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'postgresql' => $identity->version,
    'database' => $identity->database, 'isolation' => $db->selectOne('show transaction_isolation')->transaction_isolation,
    'method' => 'Sequential ingest calls with faked HTTP, exact decimals, real PostgreSQL commits; cleanup and seeding excluded. Query counts exclude PDO transaction control. Nearest-rank percentiles.',
    'prior_baseline_ms' => 51.11, 'runs_per_case' => $runs, 'warmup_per_case' => $warmup,
    'ingestion_sha256' => hash_file('sha256', __DIR__.'/../app/Exchanges/Binance/BinanceCandleIngestion.php'),
    'cases' => [],
];

try {
    foreach ([100, 500] as $size) {
        $allRows = [];
        for ($index = 0; $index < $size; $index++) {
            $start = 1767225600000 + $index * 300000; // Fixed closed synthetic candles, January 2026.
            $allRows[] = [$start, '100.000000000001', '105', '99', '102', '10', $start + 299999, '1010'];
        }
        foreach (['new', 'duplicate', 'mixed'] as $scenario) {
            $samples = [];
            for ($run = -$warmup; $run < $runs; $run++) {
                $db->table('market_candles')->where('exchange', 'binance')->where('symbol', $symbol)->delete();
                $existing = match ($scenario) {
                    'new' => 0,
                    'duplicate' => $size,
                    'mixed' => intdiv($size, 2),
                };
                if ($existing > 0) {
                    $responseRows = array_slice($allRows, 0, $existing);
                    $service->ingest($symbol, '5m', $existing);
                }
                $responseRows = $allRows;
                $queries = 0;
                $sqlMs = 0.0;
                $measuring = true;
                $start = hrtime(true);
                try {
                    $result = $service->ingest($symbol, '5m', $size);
                } finally {
                    $elapsed = (hrtime(true) - $start) / 1e6;
                    $measuring = false;
                }
                if ($result['new_closed_candles'] !== $size - $existing
                    || $db->table('market_candles')->where('exchange', 'binance')->where('symbol', $symbol)->count() !== $size) {
                    throw new RuntimeException('Benchmark result failed correctness check.');
                }
                if ($run >= 0) {
                    $samples[] = ['latency_ms' => round($elapsed, 3), 'queries' => $queries, 'sql_ms' => round($sqlMs, 3)];
                }
            }
            $latencies = array_column($samples, 'latency_ms');
            $report['cases'][] = [
                'scenario' => $scenario, 'batch_size' => $size, 'existing' => $existing,
                'queries_min' => min(array_column($samples, 'queries')), 'queries_max' => max(array_column($samples, 'queries')),
                'p50_ms' => $percentile($latencies, .50), 'p95_ms' => $percentile($latencies, .95),
                'samples' => $samples,
            ];
        }
    }
} finally {
    $db->table('market_candles')->where('exchange', 'binance')->where('symbol', $symbol)->delete();
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
