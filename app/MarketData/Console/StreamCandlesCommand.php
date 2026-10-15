<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Application\MarketStreamSupervisor;
use Illuminate\Console\Command;

final class StreamCandlesCommand extends Command
{
    protected $signature = 'market:stream-candles';

    protected $description = 'Consume confirmed closed candles from the public Bybit WebSocket';

    public function handle(MarketStreamSupervisor $supervisor): int
    {
        $this->info('Starting public Bybit candle stream. Send SIGTERM for graceful shutdown.');
        $supervisor->run();

        return self::SUCCESS;
    }
}
