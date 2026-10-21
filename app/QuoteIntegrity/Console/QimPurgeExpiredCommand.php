<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Console;

use App\QuoteIntegrity\Infrastructure\Persistence\QimObservationRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimPurgeAuditRecord;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class QimPurgeExpiredCommand extends Command
{
    protected $signature = 'qim:purge-expired';

    protected $description = 'Perform authorized deletion of expired observations older than retention period';

    public function handle(): int
    {
        $purgeAuthorized = (bool) config('quote_integrity.purge_authorized', false);
        if (! $purgeAuthorized) {
            $this->error('Purge refused: QIM_PURGE_AUTHORIZED is not authorized (must be true).');

            return self::FAILURE;
        }

        $retentionDays = config('quote_integrity.retention_days');
        if ($retentionDays === null || (int) $retentionDays <= 0) {
            $this->error('Purge refused: QUOTE_INTEGRITY_RETENTION_DAYS is unset or invalid.');

            return self::FAILURE;
        }

        $cutoff = CarbonImmutable::now()->subDays((int) $retentionDays);

        $expiredQuery = QimObservationRecord::where('created_at', '<', $cutoff);
        $recordsCount = $expiredQuery->count();

        if ($recordsCount > 0) {
            $expiredQuery->delete();
        }

        QimPurgeAuditRecord::create([
            'purged_by' => 'qim:purge-expired',
            'retention_rule' => "TTL-{$retentionDays}-DAYS",
            'rule_version' => (string) config('quote_integrity.rules_version', '1.0.0'),
            'reason' => 'Scheduled TTL expiration of observations',
            'purged_at' => CarbonImmutable::now(),
            'records_purged' => $recordsCount,
            'details' => [
                'retention_days' => $retentionDays,
                'cutoff' => $cutoff->toIso8601String(),
            ],
            'created_at' => CarbonImmutable::now(),
        ]);

        $this->info("Purge completed: {$recordsCount} records deleted, logged in qim_purge_audit.");

        return self::SUCCESS;
    }
}
