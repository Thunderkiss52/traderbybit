<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$key = $argv[1] ?? throw new InvalidArgumentException('Missing lock key.');
$seconds = (int) ($argv[2] ?? 5);
$lock = $app->make('cache')->lock($key, $seconds);
if (! $lock->get()) {
    fwrite(STDERR, "LOCK_BUSY\n");
    exit(2);
}
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
sleep(30);
$lock->release();
