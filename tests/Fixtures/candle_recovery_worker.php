<?php

declare(strict_types=1);

use App\MarketData\Application\CandleConflictRecovery;
use App\MarketData\Domain\CandleIntegrityUnavailable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'bybit_test') {
    throw new RuntimeException('Isolated PostgreSQL required.');
}
[$script, $path, $mode, $marker] = $argv;
config(['market_data.conflict_journal_path' => dirname($path)]);
$pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
if ($mode === 'concurrent') {
    file_put_contents($marker.'.'.getmypid(), (string) $pid);
    $deadline = microtime(true) + 10;
    while (! is_file($marker)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout.');
        }
        usleep(1000);
    }
}
Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use ($mode, $marker): void {
    if ($event->connectionName !== 'candle_integrity_writer') {
        return;
    }
    $state = $event->connection->table('market_candle_conflict_journal')->value('state');
    $pid = $event->connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
    if (($mode === 'crash-after-claim' && $state === 'IN_PROGRESS') || ($mode === 'crash-after-commit' && $state === 'RECOVERED')) {
        file_put_contents($marker, json_encode(['state' => $state, 'writer_pid' => $pid], JSON_THROW_ON_ERROR));
        sleep(30); // Parent kills this separate process at the actual committed boundary.
    }
});
Event::listen(QueryExecuted::class, function (QueryExecuted $event) use ($mode, $marker): void {
    if ($mode === 'crash-during-apply' && $event->connectionName === 'candle_integrity_writer'
        && str_starts_with($event->sql, 'insert into "market_candle_conflicts"')) {
        file_put_contents($marker, json_encode(['state' => 'IN_PROGRESS', 'uncommitted_count' => $event->connection->table('market_candle_conflicts')->count()], JSON_THROW_ON_ERROR));
        sleep(30); // Parent kills the writer before the receipt and conflict can commit.
    }
});
try {
    $result = app(CandleConflictRecovery::class)->recover($path);
    fwrite(STDOUT, json_encode(['main_pid' => $pid, 'state' => $result['state'], 'attempts' => $result['attempts']], JSON_THROW_ON_ERROR)."\n");
} catch (CandleIntegrityUnavailable $e) {
    fwrite(STDOUT, json_encode(['main_pid' => $pid, 'reason_code' => $e->reasonCode], JSON_THROW_ON_ERROR)."\n");
    exit($e->reasonCode === 'CANDLE_RECOVERY_IN_PROGRESS' ? 75 : 1);
}
