<?php

declare(strict_types=1);

use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Infrastructure\Persistence\CandleConflictJournal;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Domain\StrategyInputIntegrityException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CandleConflictEvidenceFixture;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'bybit_test') {
    throw new RuntimeException('Isolated PostgreSQL required.');
}
config(['market_data.conflict_journal_path' => $argv[2]]);
if ($argv[1] === 'publish') {
    app(CandleConflictJournal::class)->begin(CandleConflictEvidenceFixture::make());
    exit(0);
}
if ($argv[1] === 'publish-conflict') {
    app(MarketCandleConflictService::class)->recordConflict('binance', 'linear', 'BTCUSDT', '30', '2026-01-01T00:30:00Z', ['close' => '102'], ['close' => '103'], 'close', 'Conflicting immutable Binance close observation.');
    exit(0);
}
try {
    $input = app(StrategyInputFactory::class)->make('binance', 'linear', 'BTCUSDT', new DateTimeImmutable('2026-01-01T01:00:00Z'));
    fwrite(STDOUT, json_encode(['status' => 'VALID', 'pid' => getmypid(), 'db_pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, 'validation_errors' => $input->validationErrors], JSON_THROW_ON_ERROR)."\n");
} catch (StrategyInputIntegrityException $e) {
    fwrite(STDOUT, json_encode(['status' => $e->integrityStatus, 'reason_code' => $e->reasonCode, 'pid' => getmypid(), 'db_pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR)."\n");
    exit(3);
}
