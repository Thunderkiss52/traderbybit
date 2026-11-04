<?php

declare(strict_types=1);

use App\Research\Application\ResearchJobService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql' || getenv('DB_DATABASE') !== 'bybit_test') {
    throw new RuntimeException('Isolated PostgreSQL research-job fixture required.');
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$key = $argv[1] ?? throw new InvalidArgumentException('Missing idempotency key.');
$label = $argv[2] ?? throw new InvalidArgumentException('Missing test label.');
DB::select("SELECT set_config('application_name', CAST(? AS text), false)", [$label]);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$job = app(ResearchJobService::class)->submitJob([
    'kind' => 'BACKTEST',
    'idempotency_key' => $key,
    'payload' => ['exchange' => 'bybit'],
]);
fwrite(STDOUT, $job->job_id."\n");
