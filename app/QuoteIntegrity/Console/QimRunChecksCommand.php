<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Console;

use App\QuoteIntegrity\Application\QimComparisonEngine;
use App\QuoteIntegrity\Application\QimFeatureFlag;
use App\QuoteIntegrity\Infrastructure\Persistence\QimObservationRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimObservationRepository;
use Illuminate\Console\Command;

class QimRunChecksCommand extends Command
{
    protected $signature = 'qim:run-checks {--symbol=BTCUSDT}';

    protected $description = 'Execute QIM comparison checks across REST and WS observations';

    public function handle(
        QimFeatureFlag $featureFlag,
        QimObservationRepository $repository,
        QimComparisonEngine $engine,
    ): int {
        if (! $featureFlag->isEnabled()) {
            $this->info('QIM feature is disabled (QUOTE_INTEGRITY_ENABLED=false). No checks run.');

            return self::SUCCESS;
        }

        $symbol = (string) $this->option('symbol');
        $this->info("Running QIM comparison checks for {$symbol}...");

        $recentWs = QimObservationRecord::where('channel', 'WS')
            ->where('symbol', $symbol)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $comparedCount = 0;
        foreach ($recentWs as $ws) {
            $pair = $repository->findComparablePair(
                category: $ws->category,
                symbol: $ws->symbol,
                interval: $ws->interval,
                candleOpenTime: $ws->candle_open_time,
            );

            if ($pair['rest'] !== null) {
                $engine->compare($pair['rest'], $ws);
                $comparedCount++;
            }
        }

        $this->info("Completed {$comparedCount} comparisons.");

        return self::SUCCESS;
    }
}
