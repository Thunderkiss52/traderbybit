<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$testPort = (int) env('TEST_DB_PORT', 25432);
$testHost = env('TEST_DB_HOST', '127.0.0.1');
$testDb = env('TEST_DB_DATABASE', 'bybit_test');
$testUser = env('TEST_DB_USERNAME', 'postgres');
$testPass = env('TEST_DB_PASSWORD', 'testpass');

$pdo = new PDO("pgsql:host={$testHost};port={$testPort};dbname={$testDb}", $testUser, $testPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$currentDb = $pdo->query('SELECT current_database()')->fetchColumn();
if ($currentDb !== 'bybit_test') {
    fwrite(STDERR, "FATAL: Must run against bybit_test, got: {$currentDb}\n");
    exit(1);
}

$symbol = 'ETHUSDT_CONC';
$decisionTimeStr = '2026-10-18 14:45:00';
$decisionTime = CarbonImmutable::parse($decisionTimeStr, 'UTC');

echo "=== P1 PostgreSQL Multi-Iteration Concurrency & Slot Idempotency Proof ===\n";
echo "Connected to {$testDb} on {$testHost}:{$testPort}\n";

// 1. Clean test records
$pdo->prepare('DELETE FROM counterfactual_evaluations WHERE symbol = :symbol')->execute([':symbol' => $symbol]);
$pdo->prepare('DELETE FROM strategy_observations WHERE symbol = :symbol')->execute([':symbol' => $symbol]);
$pdo->prepare('DELETE FROM market_candles WHERE symbol = :symbol')->execute([':symbol' => $symbol]);

// 2. Insert closed 15m candle prerequisite
$candleInsert = $pdo->prepare('
    INSERT INTO market_candles (exchange, category, symbol, interval, open_time, open, high, low, close, volume, turnover, is_closed, created_at, updated_at)
    VALUES (\'bybit\', \'linear\', :symbol, \'15\', :open_time, 2400.0, 2410.0, 2390.0, 2405.5, 500.0, 1202750.0, true, NOW(), NOW())
');
$candleInsert->execute([
    ':symbol' => $symbol,
    ':open_time' => $decisionTime->subMinutes(15)->format('Y-m-d H:i:s'),
]);

$totalIterations = 5;
$iterationResults = [];
$canonicalEvalId = null;

for ($i = 1; $i <= $totalIterations; $i++) {
    echo "\n--- Iteration {$i}/{$totalIterations}: Concurrent Race with Barrier Synchronization ---\n";

    // Acquire barrier lock 777111
    $pdo->query('SELECT pg_advisory_lock(777111)');

    $w1Id = "worker_{$i}_a";
    $w2Id = "worker_{$i}_b";

    $cmd1 = [PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__.'/concurrency_worker.php', "--worker_id={$w1Id}", "--symbol={$symbol}", '--strategy_id=baseline', '--strategy_version=1.2.0', "--decision_time={$decisionTimeStr}", '--config_hash=hash_initial'];
    $cmd2 = [PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__.'/concurrency_worker.php', "--worker_id={$w2Id}", "--symbol={$symbol}", '--strategy_id=baseline', '--strategy_version=1.2.0', "--decision_time={$decisionTimeStr}", '--config_hash=hash_initial'];

    $pipes1 = [];
    $pipes2 = [];
    $proc1 = proc_open($cmd1, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1);

    // Inject randomized microsecond jitter (5ms to 25ms) between worker process spawns
    $jitterMicros = random_int(5000, 25000);
    usleep($jitterMicros);

    $proc2 = proc_open($cmd2, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2);

    // Sleep 250ms to ensure both workers have connected to DB and blocked on pg_advisory_lock
    usleep(250000);

    echo "[Barrier] Releasing pg_advisory_lock(777111) to trigger simultaneous contention (jitter: {$jitterMicros}us)...\n";
    $releasedAt = microtime(true);
    $pdo->query('SELECT pg_advisory_unlock(777111)');

    $out1 = stream_get_contents($pipes1[1]);
    $err1 = stream_get_contents($pipes1[2]);
    fclose($pipes1[1]);
    fclose($pipes1[2]);
    $status1 = proc_close($proc1);

    $out2 = stream_get_contents($pipes2[1]);
    $err2 = stream_get_contents($pipes2[2]);
    fclose($pipes2[1]);
    fclose($pipes2[2]);
    $status2 = proc_close($proc2);

    $res1 = json_decode($out1, true);
    $res2 = json_decode($out2, true);

    if (! is_array($res1) || ! is_array($res2)) {
        echo "ERROR: Failed to parse worker outputs in iteration {$i}:\n";
        echo "Worker 1: {$out1}\nstderr: {$err1}\n";
        echo "Worker 2: {$out2}\nstderr: {$err2}\n";
        exit(1);
    }

    echo "  Worker A PID: {$res1['pid']}, unblocked: {$res1['unblocked_at']}, action: {$res1['action']}\n";
    echo "  Worker B PID: {$res2['pid']}, unblocked: {$res2['unblocked_at']}, action: {$res2['action']}\n";

    $eval1 = $res1['evaluation_id'];
    $eval2 = $res2['evaluation_id'];

    if ($eval1 === null || $eval1 !== $eval2) {
        echo "ERROR in iteration {$i}: Worker evaluation IDs do not match or are null: {$eval1} vs {$eval2}\n";
        exit(1);
    }

    if ($canonicalEvalId === null) {
        $canonicalEvalId = $eval1;
    } elseif ($eval1 !== $canonicalEvalId) {
        echo "ERROR in iteration {$i}: Evaluation ID drifted from canonical: {$eval1} vs {$canonicalEvalId}\n";
        exit(1);
    }

    $obsCount = (int) $pdo->query("SELECT COUNT(*) FROM strategy_observations WHERE symbol = '{$symbol}' AND superseded_by IS NULL")->fetchColumn();
    $evalCount = (int) $pdo->query("SELECT COUNT(*) FROM counterfactual_evaluations WHERE symbol = '{$symbol}' AND superseded_by IS NULL")->fetchColumn();

    echo "  Counts after iteration {$i}: strategy_observations={$obsCount}, counterfactual_evaluations={$evalCount}\n";

    if ($obsCount !== 1 || $evalCount !== 1) {
        echo "ERROR: Database count mismatch in iteration {$i}!\n";
        exit(1);
    }

    $unblockDeltaMs = round(abs($res1['unblocked_at'] - $res2['unblocked_at']) * 1000, 3);
    $overlap = ($res1['unblocked_at'] < $res2['eval_finished_at']) && ($res2['unblocked_at'] < $res1['eval_finished_at']);

    $iterationResults[] = [
        'iteration' => $i,
        'jitter_micros' => $jitterMicros,
        'barrier_release_epoch' => $releasedAt,
        'worker_a' => $res1,
        'worker_b' => $res2,
        'unblock_delta_ms' => $unblockDeltaMs,
        'execution_overlap' => $overlap,
        'canonical_evaluation_id' => $eval1,
        'strategy_observations_count' => $obsCount,
        'counterfactual_evaluations_count' => $evalCount,
    ];
}

// 5. Test Variation B: Incompatible configuration_hash change for the SAME strategy version
echo "\n--- Testing Variation B: Incompatible configuration_hash change ---\n";
$cmd3 = [PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__.'/concurrency_worker.php', '--worker_id=worker_var_b', "--symbol={$symbol}", '--strategy_id=baseline', '--strategy_version=1.2.0', "--decision_time={$decisionTimeStr}", '--config_hash=hash_conflicting_b'];
$proc3 = proc_open($cmd3, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes3);
$out3 = stream_get_contents($pipes3[1]);
$err3 = stream_get_contents($pipes3[2]);
fclose($pipes3[1]);
fclose($pipes3[2]);
proc_close($proc3);
$res3 = json_decode($out3, true);

echo 'Worker Variation B Result: '.json_encode($res3, JSON_PRETTY_PRINT)."\n";
$obsCountAfterB = (int) $pdo->query("SELECT COUNT(*) FROM strategy_observations WHERE symbol = '{$symbol}' AND superseded_by IS NULL")->fetchColumn();
$evalCountAfterB = (int) $pdo->query("SELECT COUNT(*) FROM counterfactual_evaluations WHERE symbol = '{$symbol}' AND superseded_by IS NULL")->fetchColumn();
$snapshotStmt = $pdo->prepare('SELECT decision_snapshot FROM counterfactual_evaluations WHERE id = :id');
$snapshotStmt->execute([':id' => $canonicalEvalId]);
$snapshot = json_decode($snapshotStmt->fetchColumn() ?: '{}', true);

echo "Snapshot after conflicting config hash:\n".json_encode($snapshot, JSON_PRETTY_PRINT)."\n";
echo " - strategy_observations canonical count after config variation: {$obsCountAfterB} (Expected: 1)\n";
echo " - counterfactual_evaluations canonical count after config variation: {$evalCountAfterB} (Expected: 1)\n";
echo ' - Incompatible payload detected: '.(($snapshot['incompatible_payload_detected'] ?? false) ? 'YES' : 'NO')." (Expected: YES)\n";
echo ' - Conflicting hashes recorded: '.json_encode($snapshot['conflicting_configuration_hashes'] ?? [])."\n";

if ($obsCountAfterB !== 1 || $evalCountAfterB !== 1 || ! ($snapshot['incompatible_payload_detected'] ?? false)) {
    echo "ERROR: Configuration variation resulted in duplicate observations or failed to flag incompatible payload!\n";
    exit(1);
}

// 6. Test Variation C: Legitimate strategy_version change (1.3.0)
echo "\n--- Testing Variation C: Legitimate strategy_version change (1.3.0) ---\n";
$cmd4 = [PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__.'/concurrency_worker.php', '--worker_id=worker_var_c', "--symbol={$symbol}", '--strategy_id=baseline', '--strategy_version=1.3.0', "--decision_time={$decisionTimeStr}", '--config_hash=hash_v1_3_0'];
$proc4 = proc_open($cmd4, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes4);
$out4 = stream_get_contents($pipes4[1]);
$err4 = stream_get_contents($pipes4[2]);
fclose($pipes4[1]);
fclose($pipes4[2]);
proc_close($proc4);
$res4 = json_decode($out4, true);

echo 'Worker Variation C Result: '.json_encode($res4, JSON_PRETTY_PRINT)."\n";
$eval4 = $res4['evaluation_id'];
$obsCountAfterC = (int) $pdo->query("SELECT COUNT(*) FROM strategy_observations WHERE symbol = '{$symbol}' AND superseded_by IS NULL")->fetchColumn();
$evalCountAfterC = (int) $pdo->query("SELECT COUNT(*) FROM counterfactual_evaluations WHERE symbol = '{$symbol}' AND superseded_by IS NULL")->fetchColumn();

echo " - Distinct evaluation ID for version 1.3.0: {$eval4} (Different from 1.2.0: ".($eval4 !== $canonicalEvalId ? 'YES' : 'NO').")\n";
echo " - strategy_observations canonical count after version bump: {$obsCountAfterC} (Expected: 2)\n";
echo " - counterfactual_evaluations canonical count after version bump: {$evalCountAfterC} (Expected: 2)\n";

if ($obsCountAfterC !== 2 || $evalCountAfterC !== 2 || $eval4 === $canonicalEvalId) {
    echo "ERROR: Legitimate version change failed to produce distinct observation or evaluation!\n";
    exit(1);
}

// 7. Write comprehensive evidence file
$evidence = [
    'test' => 'P1 PostgreSQL Multi-Iteration Concurrency & Slot Idempotency Proof',
    'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'database' => $testDb,
    'host' => $testHost,
    'port' => $testPort,
    'synchronization_mechanism' => 'PostgreSQL 17 advisory lock barrier (pg_advisory_lock(777111)) with randomized microsecond jitter',
    'total_concurrent_iterations' => $totalIterations,
    'iterations' => $iterationResults,
    'variation_b_conflicting_hash' => [
        'worker_result' => $res3,
        'strategy_observations_canonical_count' => $obsCountAfterB,
        'counterfactual_evaluations_canonical_count' => $evalCountAfterB,
        'incompatible_payload_detected' => (bool) ($snapshot['incompatible_payload_detected'] ?? false),
        'conflicting_configuration_hashes' => $snapshot['conflicting_configuration_hashes'] ?? [],
    ],
    'variation_c_version_bump' => [
        'worker_result' => $res4,
        'evaluation_id_v1_3_0' => $eval4,
        'strategy_observations_canonical_count' => $obsCountAfterC,
        'counterfactual_evaluations_canonical_count' => $evalCountAfterC,
        'distinct_from_v1_2_0' => ($eval4 !== $canonicalEvalId),
    ],
    'assertions' => [
        'all_iterations_passed' => true,
        'canonical_evaluation_id' => $canonicalEvalId,
        'strategy_observations_count_after_race' => 1,
        'counterfactual_evaluations_canonical_count_after_race' => 1,
        'incompatible_config_hash_handled_without_duplicate' => ($evalCountAfterB === 1),
        'incompatible_payload_flagged' => (bool) ($snapshot['incompatible_payload_detected'] ?? false),
        'conflicting_configuration_hashes' => $snapshot['conflicting_configuration_hashes'] ?? [],
        'legitimate_version_bump_creates_distinct_evaluation' => ($evalCountAfterC === 2 && $eval4 !== $canonicalEvalId),
        'evaluation_id_v1_3_0' => $eval4,
    ],
    'overall_verdict' => 'PASS',
];

$evidenceDir = __DIR__.'/../reports/evidence';
if (! is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0755, true);
}
file_put_contents($evidenceDir.'/p1_concurrency_proof.json', json_encode($evidence, JSON_PRETTY_PRINT));
echo "\nEvidence saved to reports/evidence/p1_concurrency_proof.json\n";
echo "OVERALL VERDICT: PASS\n";
