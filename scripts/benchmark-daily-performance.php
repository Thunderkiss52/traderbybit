<?php

declare(strict_types=1);

use App\PaperTrading\Application\DailyPerformanceService;
use App\PaperTrading\Application\PaperAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Strict environment guard: isolated loopback PostgreSQL bybit_test required.
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
    || $db->getConfig('host') !== '127.0.0.1' || $db->getDatabaseName() !== 'bybit_test') {
    throw new RuntimeException('Resolved benchmark database configuration is not isolated.');
}

$options = getopt('', ['runs:', 'warmup:']);
$runs = (int) ($options['runs'] ?? 20);
$warmup = (int) ($options['warmup'] ?? 3);
if ($runs < 5 || $runs > 100 || $warmup < 1 || $warmup > 20) {
    throw new InvalidArgumentException('Use 5..100 measured runs and 1..20 warmup runs.');
}

$percentile = static function (array $samples, float $fraction): float {
    sort($samples, SORT_NUMERIC);

    return round($samples[(int) ceil(count($samples) * $fraction) - 1], 3);
};

$account = $app->make(PaperAccountService::class)->default();
$service = $app->make(DailyPerformanceService::class);
$now = CarbonImmutable::now('UTC');

DB::beginTransaction();
try {
    DB::table('equity_snapshots')->where('paper_account_id', $account->id)->delete();
    $rows = [];
    for ($index = 0; $index < 1000; $index++) {
        $rows[] = [
            'paper_account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'recorded_at' => $index === 0 ? $now->subSeconds(30) : $now->subDays($index + 1)->setTime(23, 59, 30),
            'equity' => (string) (10000 + $index / 10),
            'realized_pnl' => (string) ($index / 10),
            'unrealized_pnl' => '0',
            'drawdown_percent' => '0',
            'source_event_key' => 'daily-performance-benchmark:'.$index,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('equity_snapshots')->insert($chunk);
    }

    $measure = static function () use ($service, $account): float {
        $start = hrtime(true);
        $result = $service->report($account->id, 'UTC', null, 1, 10);
        if (count($result['days']['data']) !== 10) {
            throw new RuntimeException('Unexpected daily-performance result shape.');
        }

        return (hrtime(true) - $start) / 1e6;
    };

    for ($index = 0; $index < $warmup; $index++) {
        $measure();
    }

    $samples = [];
    for ($index = 0; $index < $runs; $index++) {
        $samples[] = $measure();
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $measure();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $report = [
        'benchmark' => 'VECTOR V2.6 Daily Performance Aggregation',
        'scope' => 'SERVICE_REPORT_CALL; ONE ACCOUNT; 1000 EQUITY SNAPSHOTS; 10 DAYS RETURNED',
        'timestamp' => $now->toIso8601String(),
        'runs' => $runs,
        'warmup' => $warmup,
        'query_count' => $queryCount,
        'p50_ms' => $percentile($samples, 0.50),
        'p95_ms' => $percentile($samples, 0.95),
        'max_ms' => round(max($samples), 3),
        'environment' => [
            'php' => PHP_VERSION,
            'database' => 'postgresql',
        ],
    ];

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    DB::rollBack();
}
