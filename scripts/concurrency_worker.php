<?php

declare(strict_types=1);
use App\Research\Application\CounterfactualAnalyzerService;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

if (getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Concurrency fixtures require APP_ENV=testing.');
}

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$options = getopt('', [
    'worker_id:',
    'symbol:',
    'strategy_id:',
    'strategy_version:',
    'decision_time:',
    'config_hash:',
    'race_observation::',
]);

$workerId = $options['worker_id'] ?? 'unknown';
$symbol = $options['symbol'] ?? 'ETHUSDT_CONC';
$strategyId = $options['strategy_id'] ?? 'baseline';
$strategyVersion = $options['strategy_version'] ?? '1.2.0';
$decisionTimeStr = $options['decision_time'] ?? '2026-10-18 14:45:00';
$configHash = $options['config_hash'] ?? 'hash_default';
$raceObservation = isset($options['race_observation']);

$testPort = (int) env('TEST_DB_PORT', 25432);
$testHost = env('TEST_DB_HOST', '127.0.0.1');
$testDb = env('TEST_DB_DATABASE', 'bybit_test');
$testUser = env('TEST_DB_USERNAME', 'postgres');
$testPass = env('TEST_DB_PASSWORD', 'testpass');

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.host' => $testHost,
    'database.connections.pgsql.port' => $testPort,
    'database.connections.pgsql.database' => $testDb,
    'database.connections.pgsql.username' => $testUser,
    'database.connections.pgsql.password' => $testPass,
]);
DB::purge('pgsql');
DB::reconnect('pgsql');

if (! $app->environment('testing') || $testDb !== 'bybit_test'
    || ! in_array($testHost, ['127.0.0.1', 'localhost', '::1'], true)
    || (string) env('DB_URL', '') !== ''
    || DB::scalar('SELECT current_database()') !== 'bybit_test') {
    throw new RuntimeException('Concurrency fixtures require isolated testing PostgreSQL.');
}
// The quarantined legacy engine is exercised only inside this isolated test fixture.
config(['research.counterfactual.legacy_analysis_enabled' => true]);

$pid = getmypid();
$decisionTime = CarbonImmutable::parse($decisionTimeStr, 'UTC');

// 1. Wait at concurrency synchronization barrier
// The parent process holds advisory lock 777111. When parent unlocks, both workers unblock simultaneously.
DB::select('SELECT pg_advisory_lock(777111)');
DB::select('SELECT pg_advisory_unlock(777111)');
$unblockedAt = microsecondTime();

// 2. Deterministic observation ID (Safeguard 3: derived from logical strategy slot)
$exchange = 'bybit';
$category = 'linear';
$obsId = Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', [
    'traderbybit:strategy-observation', $exchange, $category, $symbol,
    $strategyId, $strategyVersion, $decisionTime->format('U.u'),
]))->toString();

$analysis = [
    'diagnostics' => ['decision' => 'SKIP'],
    'level_interaction' => ['selected_side' => 'BUY'],
];

// Check if canonical observation exists
$existingObs = StrategyObservationRecord::query()
    ->where('exchange', $exchange)
    ->where('category', $category)
    ->where('symbol', $symbol)
    ->where('strategy_id', $strategyId)
    ->where('strategy_version', $strategyVersion)
    ->where('decision_time', $decisionTime)
    ->whereNull('superseded_by')
    ->first();

if ($existingObs === null) {
    StrategyObservationRecord::query()->insertOrIgnore([[
        'id' => $obsId,
        'exchange' => $exchange,
        'category' => $category,
        'symbol' => $symbol,
        'strategy_id' => $strategyId,
        'strategy_version' => $strategyVersion,
        'configuration_hash' => $configHash,
        'side' => 'SKIP',
        'quality_class' => null,
        'score' => 0,
        'decision_time' => $decisionTime,
        'reasons' => json_encode(['five_minute_confirmation_conflicts'], JSON_THROW_ON_ERROR),
        'analysis' => json_encode($analysis, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]]);
    $obs = StrategyObservationRecord::query()->findOrFail($obsId);
} else {
    $obs = $existingObs;
}

$obsFinishedAt = microsecondTime();

// 3. Race to create/retrieve counterfactual evaluation
$evalId = null;
$error = null;
$action = 'SUCCESS';

try {
    $service = app(CounterfactualAnalyzerService::class);
    // If incoming config hash differs from canonical record, evaluate with incoming hash to test conflict detection
    $evalObs = $obs;
    if ($obs->configuration_hash !== $configHash) {
        $evalObs = clone $obs;
        $evalObs->configuration_hash = $configHash;
    }
    $eval = $service->createFromObservation($evalObs);
    $evalId = $eval ? $eval->id : null;
} catch (Throwable $e) {
    $action = 'ERROR';
    $error = $e->getMessage();
}

$evalFinishedAt = microsecondTime();

echo json_encode([
    'worker_id' => $workerId,
    'pid' => $pid,
    'observation_id' => $obsId,
    'unblocked_at' => $unblockedAt,
    'obs_finished_at' => $obsFinishedAt,
    'eval_finished_at' => $evalFinishedAt,
    'obs_duration_ms' => round(($obsFinishedAt - $unblockedAt) * 1000, 2),
    'eval_duration_ms' => round(($evalFinishedAt - $obsFinishedAt) * 1000, 2),
    'total_duration_ms' => round(($evalFinishedAt - $unblockedAt) * 1000, 2),
    'evaluation_id' => $evalId,
    'action' => $action,
    'error' => $error,
]);

function microsecondTime(): float
{
    return (float) sprintf('%f', microtime(true));
}
