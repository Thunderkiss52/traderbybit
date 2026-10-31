<?php

declare(strict_types=1);
use App\PaperTrading\Application\PaperProcessingService;
use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Jobs\ProcessPaperPipelineJob;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $atIso, $accountId, $barrier] = $argv;
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$deadline = microtime(true) + 10;
while (! file_exists($barrier)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "BARRIER_TIMEOUT\n");
        exit(2);
    }
    usleep(10_000);
}

(new ProcessPaperPipelineJob('bybit', 'BTCUSDT', $atIso, [$accountId]))
    ->handle(app(TradingPipelineEventService::class), app(PaperProcessingService::class));
fwrite(STDOUT, "DONE\n");
