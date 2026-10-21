<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Console;

use App\QuoteIntegrity\Application\QimFeatureFlag;
use App\QuoteIntegrity\Infrastructure\Persistence\QimComparisonRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimGapEventRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimIncidentRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimObservationRecord;
use Illuminate\Console\Command;

class QimMeasurementReportCommand extends Command
{
    protected $signature = 'qim:measurement-report';

    protected $description = 'Generate a 7-day QIM measurement period operational report';

    public function handle(QimFeatureFlag $featureFlag): int
    {
        if (! $featureFlag->isEnabled() || ! config('quote_integrity.measurement_mode', false)) {
            $this->line('NOT STARTED');

            return self::SUCCESS;
        }

        $totalObservations = QimObservationRecord::count();
        $totalComparisons = QimComparisonRecord::count();
        $totalGaps = QimGapEventRecord::count();
        $totalIncidents = QimIncidentRecord::count();

        $firstObs = QimObservationRecord::orderBy('id')->first();
        $lastObs = QimObservationRecord::orderByDesc('id')->first();

        $this->info('=== Quote Integrity Monitor — 7-Day Measurement Report ===');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Measurement Mode', 'ACTIVE'],
                ['Total Observations', (string) $totalObservations],
                ['Total Comparisons', (string) $totalComparisons],
                ['Gap Events Recorded', (string) $totalGaps],
                ['Incidents Detected', (string) $totalIncidents],
                ['First Observation', $firstObs?->created_at?->toIso8601String() ?? 'N/A'],
                ['Last Observation', $lastObs?->created_at?->toIso8601String() ?? 'N/A'],
                ['Disk Cap (GB)', (string) config('quote_integrity.measurement_disk_cap_gb', 10)],
                ['Measurement TTL (Days)', (string) config('quote_integrity.measurement_ttl_days', 7)],
            ]
        );

        return self::SUCCESS;
    }
}
