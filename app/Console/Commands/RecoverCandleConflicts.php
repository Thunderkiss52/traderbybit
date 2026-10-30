<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\MarketData\Application\CandleConflictRecovery;
use App\MarketData\Domain\CandleIntegrityUnavailable;
use App\MarketData\Infrastructure\Persistence\CandleConflictJournal;
use Illuminate\Console\Command;

final class RecoverCandleConflicts extends Command
{
    protected $signature = 'market-data:recover-candle-conflicts {--entry= : Original journal basename} {--limit=100 : Maximum pending files per run (1-1000)}';

    protected $description = 'Recover conflict evidence idempotently; never resolve conflicts or modify candles';

    public function handle(CandleConflictRecovery $recovery, CandleConflictJournal $journal): int
    {
        $limit = $this->option('limit');
        $entry = $this->option('entry');
        if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000 || ($entry !== null && (! is_string($entry) || ! preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})\.json$/D', $entry)))) {
            $this->error('CANDLE_RECOVERY_ARGUMENT_INVALID');

            return self::INVALID;
        }
        $failures = 0;
        $processed = 0;
        try {
            $paths = $entry === null ? $journal->pending((int) $limit) : [$journal->entryPath($entry)];
            foreach ($paths as $path) {
                try {
                    $result = $recovery->recover($path);
                    $this->line(json_encode(['event_id' => $result['event_id'], 'state' => $result['state'], 'attempts' => $result['attempts']], JSON_THROW_ON_ERROR));
                    $processed++;
                } catch (CandleIntegrityUnavailable $e) {
                    $this->error($e->reasonCode);
                    $failures++;
                }
            }
            $remaining = count($journal->pending());
            $unfinished = $recovery->unfinishedCount();
            $this->line(json_encode(['recovery_status' => $failures === 0 && $remaining === 0 && $unfinished === 0 ? 'COMPLETE' : 'INCOMPLETE', 'processed' => $processed, 'failed' => $failures, 'pending_files' => $remaining, 'unfinished_receipts' => $unfinished, 'candle_resolution_performed' => false], JSON_THROW_ON_ERROR));

            return $failures > 0 ? self::FAILURE : ($remaining + $unfinished > 0 ? 2 : self::SUCCESS);
        } catch (CandleIntegrityUnavailable $e) {
            $this->error($e->reasonCode);

            return self::FAILURE;
        }
    }
}
