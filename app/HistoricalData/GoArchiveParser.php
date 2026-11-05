<?php

declare(strict_types=1);

namespace App\HistoricalData;

use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;

final class GoArchiveParser
{
    public function parse(object $entry, string $zip, string $directory, callable $tick): array
    {
        $binary = (string) config('historical_data.go_binary');
        if (! is_executable($binary)) {
            throw new AcquisitionFailure('GO_IMPORT_EXECUTABLE_UNAVAILABLE');
        }
        $output = $directory.'/validated.ndjson';
        $request = ['path' => $zip, 'output' => $output, 'filename' => $entry->filename, 'family' => $entry->family, 'interval' => $entry->interval ?? '', 'source_from' => CarbonImmutable::parse($entry->source_from)->getTimestampMs(), 'source_to' => CarbonImmutable::parse($entry->source_to)->getTimestampMs(), 'from' => CarbonImmutable::parse($entry->requested_from)->getTimestampMs(), 'to' => CarbonImmutable::parse($entry->requested_to)->getTimestampMs(), 'max_bytes' => (int) config('historical_data.max_expanded_bytes'), 'max_file_bytes' => (int) config('historical_data.max_file_bytes'), 'max_entries' => (int) config('historical_data.max_entries')];
        $process = new Process([$binary]);
        $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
        $process->setTimeout((int) config('historical_data.decompression_seconds'));
        $process->start();
        try {
            while ($process->isRunning()) {
                $tick();
                $process->checkTimeout();
                usleep(100000);
            }$data = trim($process->getOutput());
            $lines = explode("\n", $data);
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            if (! $process->isSuccessful() || ! isset($result['result'])) {
                throw new AcquisitionFailure('ARCHIVE_VALIDATION_FAILED');
            }

            return $result['result'] + ['output' => $output, 'engine_sha256' => hash_file('sha256', $binary)];
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }
}
