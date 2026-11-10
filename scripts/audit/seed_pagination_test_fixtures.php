<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

echo "Seeding pagination test fixtures for isolated local verification...\n";

// 1. Seed 800 Model Artifacts (satisfying > 786 historical record verification requirement)
$currentModels = DB::table('learning_model_artifacts')->count();
if ($currentModels < 800) {
    echo "Current learning_model_artifacts: {$currentModels}. Seeding up to 800 records...\n";
    $now = CarbonImmutable::now('UTC');
    $toInsert = [];
    $needed = 800 - $currentModels;

    for ($i = $currentModels + 1; $i <= 800; $i++) {
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "vector:learning-model-artifact:fixture:{$i}")->toString();
        $hash = hash('sha256', "artifact-payload-fixture-{$i}");
        $createdAt = $now->subMinutes(800 - $i);

        $toInsert[] = [
            'id' => $id,
            'name' => "Model-Fixture-{$i}",
            'model_type' => ($i % 2 === 0) ? 'GRADIENT_BOOSTED_TREE' : 'LOGISTIC_REGRESSION',
            'target_event' => ($i % 3 === 0) ? 'BOUNCE' : (($i % 3 === 1) ? 'BREAKOUT' : 'TP_BEFORE_SL'),
            'version' => '1.0.'.($i % 10),
            'parameters' => json_encode([
                'exchange' => ($i % 2 === 0) ? 'bybit' : 'binance',
                'symbol' => ($i % 2 === 0) ? 'BTCUSDT' : 'ETHUSDT',
                'timeframe' => '15m',
                'depth' => 4,
                'learning_rate' => 0.05,
            ], JSON_THROW_ON_ERROR),
            'weights' => json_encode(['feature_adx' => 0.35, 'feature_rsi' => 0.25, 'feature_volume' => 0.40], JSON_THROW_ON_ERROR),
            'metrics' => json_encode([
                'accuracy' => round(0.65 + (($i % 15) * 0.01), 4),
                'precision' => round(0.68 + (($i % 12) * 0.01), 4),
                'recall' => round(0.62 + (($i % 14) * 0.01), 4),
                'f1_score' => round(0.65 + (($i % 10) * 0.01), 4),
                'sample_size' => 1250,
            ], JSON_THROW_ON_ERROR),
            'artifact_hash' => $hash,
            'status' => ($i <= 10) ? 'ACTIVE' : (($i % 5 === 0) ? 'RETIRED' : 'CANDIDATE'),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];

        if (count($toInsert) >= 200) {
            DB::table('learning_model_artifacts')->insertOrIgnore($toInsert);
            $toInsert = [];
        }
    }
    if (! empty($toInsert)) {
        DB::table('learning_model_artifacts')->insertOrIgnore($toInsert);
    }
    echo 'learning_model_artifacts seeded. Total: '.DB::table('learning_model_artifacts')->count()."\n";
} else {
    echo "learning_model_artifacts already has {$currentModels} records (>= 800).\n";
}

// 2. Seed 25 Backtest Runs for deep pagination verification
$currentBacktests = DB::table('backtest_runs')->count();
if ($currentBacktests < 25) {
    echo "Current backtest_runs: {$currentBacktests}. Seeding up to 25...\n";
    $now = CarbonImmutable::now('UTC');
    $toInsert = [];

    for ($i = $currentBacktests + 1; $i <= 25; $i++) {
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "vector:backtest-run:fixture:{$i}")->toString();
        $fingerprint = hash('sha256', "backtest-fingerprint-{$i}");
        $createdAt = $now->subHours(26 - $i);

        $toInsert[] = [
            'id' => $id,
            'fingerprint' => $fingerprint,
            'type' => 'STRATEGY_WALK_FORWARD',
            'status' => 'COMPLETED',
            'from_at' => $now->subDays(60),
            'to_at' => $now->subDays(1),
            'symbols' => json_encode(['BTCUSDT', 'ETHUSDT'], JSON_THROW_ON_ERROR),
            'strategy_config' => json_encode(['strategy' => 'baseline', 'timeframe' => '15m'], JSON_THROW_ON_ERROR),
            'risk_config' => json_encode(['max_risk_per_trade' => 0.01, 'max_drawdown_limit' => 0.05], JSON_THROW_ON_ERROR),
            'execution_config' => json_encode(['fee_tier' => 'VIP0', 'slippage_model' => 'fixed_spread'], JSON_THROW_ON_ERROR),
            'strategy_version' => '1.2.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'backtest_version' => '2026.1',
            'schema_version' => '1.0',
            'dataset_checksum' => hash('sha256', "dataset-{$i}"),
            'seed' => 42 + $i,
            'progress' => 100,
            'warnings' => json_encode([], JSON_THROW_ON_ERROR),
            'errors' => json_encode([], JSON_THROW_ON_ERROR),
            'metrics' => json_encode([
                'trades_count' => 142 + $i,
                'win_rate' => 0.62,
                'profit_factor' => 1.84,
                'total_net_pnl' => 1845.50 + ($i * 10),
                'max_drawdown' => 0.038,
            ], JSON_THROW_ON_ERROR),
            'started_at' => $createdAt->subMinutes(5),
            'completed_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    DB::table('backtest_runs')->insertOrIgnore($toInsert);
    echo 'backtest_runs seeded. Total: '.DB::table('backtest_runs')->count()."\n";
} else {
    echo "backtest_runs already has {$currentBacktests} records (>= 25).\n";
}

echo "Pagination fixture preparation complete.\n";
