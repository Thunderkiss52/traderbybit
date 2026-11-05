<?php

declare(strict_types=1);
use Predis\Client;

require __DIR__.'/../../vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'bybit_test'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('REDIS_HOST') !== '127.0.0.1') {
    throw new RuntimeException('Task-owned loopback test services required.');
}
$pdo = new PDO('pgsql:host=127.0.0.1;port='.getenv('DB_PORT').';dbname=bybit_test', getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
$identity = $pdo->query('SELECT current_database() AS database, system_identifier::text FROM pg_control_system()')->fetch(PDO::FETCH_ASSOC);
$redis = new Client(['host' => '127.0.0.1', 'port' => getenv('REDIS_PORT')]);
$info = $redis->info('server');
$runId = $info['Server']['run_id'] ?? null;
if ($identity['database'] !== 'bybit_test' || ! is_string($runId) || $runId === '') {
    throw new RuntimeException('Test service identity unavailable.');
}
$output = ['postgres_system_identifier' => $identity['system_identifier'], 'database' => $identity['database'],
    'redis_run_id' => $runId, 'redis_prefix' => getenv('REDIS_PREFIX')];
if (! is_dir('ci-results') && ! mkdir('ci-results', 0755, true)) {
    throw new RuntimeException('Cannot create isolation evidence directory.');
}
file_put_contents('ci-results/isolation.json', json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
