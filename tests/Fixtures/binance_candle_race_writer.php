<?php

declare(strict_types=1);

// Separate PostgreSQL backend: hold the winning rows until the ingestion INSERT blocks.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql'
    || getenv('DB_DATABASE') !== 'bybit_test' || getenv('DB_HOST') !== '127.0.0.1') {
    throw new RuntimeException('Isolated PostgreSQL race fixture required.');
}
$pdo = new PDO('pgsql:host=127.0.0.1;port='.getenv('DB_PORT').';dbname=bybit_test', getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rows = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$blockedPid = (int) $argv[2];
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare('INSERT INTO market_candles (exchange, category, symbol, interval, open_time, open, high, low, close, volume, turnover, is_closed, created_at, updated_at) VALUES (:exchange, :category, :symbol, :interval, :open_time, :open, :high, :low, :close, :volume, :turnover, true, :created_at, :updated_at)');
    foreach ($rows as $row) {
        $insert->execute($row);
    }
    echo "READY\n";
    flush();
    $blocked = $pdo->prepare('SELECT pg_backend_pid() = ANY(pg_blocking_pids(?))');
    $deadline = microtime(true) + 10;
    do {
        $blocked->execute([$blockedPid]);
        if ($blocked->fetchColumn()) {
            $pdo->commit();
            echo "COMMITTED_AFTER_INSERT_BLOCKED\n";
            exit(0);
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Ingestion never blocked on the competing transaction.');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
