<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Console;

use App\QuoteIntegrity\Application\QimFeatureFlag;
use Illuminate\Console\Command;

class QimSampleBarsCommand extends Command
{
    protected $signature = 'qim:sample-bars {--symbol=BTCUSDT} {--interval=1m}';

    protected $description = 'Sample closed bars for QIM quote consistency checking';

    public function handle(QimFeatureFlag $featureFlag): int
    {
        if (! $featureFlag->isEnabled()) {
            $this->info('QIM feature is disabled (QUOTE_INTEGRITY_ENABLED=false). No bars sampled.');

            return self::SUCCESS;
        }

        $symbol = (string) $this->option('symbol');
        $interval = (string) $this->option('interval');
        $this->info("Sampling {$symbol} {$interval} closed bars for QIM...");

        return self::SUCCESS;
    }
}
