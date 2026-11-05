<?php

declare(strict_types=1);

namespace App\HistoricalData;

use App\Strategy\Application\StrategyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final readonly class AcquisitionWorker
{
    public function __construct(private ArchiveDownloader $downloads, private GoArchiveParser $parser, private FundingFetcher $funding, private HistoricalWriter $writer, private BatchRepository $batches, private DownloadPlanner $planner, private StagingStorage $storage) {}

    public function once(string $batchId): bool
    {
        $entry = $this->claim($batchId);
        if ($entry === null) {
            return false;
        }$this->storage->clearExpiredFor($entry->id, $entry->lease_owner);
        $directory = $this->storage->directory($entry->id, $entry->lease_owner);
        $last = 0.0;
        $tick = function () use ($entry, &$last): void {
            if (microtime(true) - $last < 0.25) {
                return;
            }$this->guard($entry);
            DB::table('historical_download_entries')->where('id', $entry->id)->where('lease_owner', $entry->lease_owner)->where('fence', $entry->fence)->update(['lease_until' => now('UTC')->addSeconds((int) config('historical_data.lease_seconds')), 'updated_at' => now('UTC')]);
            $last = microtime(true);
        };
        try {
            $this->storage->admit();
            $tick();
            if ($entry->family === 'fundingApi') {
                $parsed = $this->funding->fetch($entry, $directory, $tick);
                $sha = $parsed['sha256'];
                $this->update($entry, ['download_status' => 'DOWNLOADED', 'downloaded_at' => now('UTC'), 'verification_status' => 'TLS_RESPONSE_HASHED']);
            } else {
                $download = $this->downloads->download($entry, $directory, $tick);
                $sha = $download['sha256'];
                $this->update($entry, ['status' => 'CHECKSUM_VERIFIED', 'download_status' => 'DOWNLOADED', 'download_bytes' => $download['bytes'], 'downloaded_at' => now('UTC'), 'verification_status' => 'CHECKSUM_VERIFIED', 'verified_at' => now('UTC')]);
                $parsed = $this->parser->parse($entry, $download['file'], $directory, $tick);
            }
            $revision = StrategyRegistry::hash([$entry->url, $sha]);
            $revisionChanged = DB::transaction(function () use ($entry, $revision, $sha, $parsed): bool {
                $this->guard($entry, true);
                $batch = DB::table('historical_download_batches')->find($entry->batch_id);
                DB::table('historical_source_revisions')->insertOrIgnore(['id' => $revision, 'entry_id' => $entry->id, 'uri' => $entry->url, 'sha256' => $sha, 'family' => $entry->family, 'provider' => $entry->family === 'fundingApi' ? 'BINANCE_PUBLIC_API' : 'BINANCE_VISION', 'license' => $batch->license, 'parser_version' => $parsed['parser'], 'validation_version' => 'vision-integrity-v1', 'metrics' => json_encode(array_diff_key($parsed, ['output' => true]), JSON_THROW_ON_ERROR), 'acquired_at' => now('UTC')]);
                if ($entry->sha256 !== null && ! hash_equals($entry->sha256, $sha)) {
                    return true;
                }
                $this->update($entry, ['sha256' => $sha, 'parsed_rows' => $parsed['rows'], 'status' => 'PARSED', 'import_status' => 'PARSED', 'quality' => json_encode(array_diff_key($parsed, ['output' => true]), JSON_THROW_ON_ERROR)]);

                return false;
            }, 3);
            if ($revisionChanged) {
                throw new AcquisitionFailure('SOURCE_REVISION_CHANGED');
            }
            $file = fopen($parsed['output'], 'rb');
            $number = 0;
            $chunk = [];
            try {
                while (($line = fgets($file, 65537)) !== false) {
                    $number++;
                    if ($number <= $entry->checkpoint) {
                        continue;
                    }$row = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                    $chunk[] = $row;
                    if (count($chunk) >= (int) config('historical_data.chunk_rows')) {
                        $this->commit($entry, $chunk, $number, $revision);
                        $chunk = [];
                        $tick();
                    }
                }if ($chunk !== []) {
                    $this->commit($entry, $chunk, $number, $revision);
                }
            } finally {
                fclose($file);
            }
            $fresh = DB::table('historical_download_entries')->find($entry->id);
            $state = $fresh->conflict_rows > 0 ? 'QUARANTINED' : (($parsed['missing'] ?? 0) > 0 ? 'ACCEPTED_WITH_LIMITATIONS' : 'IMPORTED');
            $this->update($entry, ['status' => $state, 'import_status' => $state, 'imported_at' => now('UTC'), 'lease_owner' => null, 'lease_until' => null]);
            $this->attempt($entry, $state, null);
        } catch (AcquisitionFailure $e) {
            if ($e->reason === 'BATCH_PAUSED_OR_CANCELLED') {
                $this->updateIfOwned($entry, ['status' => 'PLANNED', 'lease_owner' => null, 'lease_until' => null]);
                $this->attempt($entry, 'INTERRUPTED', $e->reason);
            } elseif ($e->reason === 'STALE_WORKER_FENCE') {
                return false;
            } elseif ($e->reason === 'SOURCE_UNAVAILABLE' && $entry->granularity === 'monthly' && $entry->family !== 'fundingApi') {
                $this->fallback($entry);
                $this->attempt($entry, 'UNAVAILABLE', $e->reason);
            } else {
                $retry = $e->retryable && $entry->attempts < (int) config('historical_data.max_attempts');
                $delay = max($e->retryAfter ?? 0, min(3600, 5 * (2 ** ($entry->attempts - 1))) + random_int(0, 5));
                $state = $retry ? 'PLANNED' : (in_array($e->reason, ['CHECKSUM_MISMATCH', 'CHECKSUM_FORMAT_INVALID', 'ARCHIVE_VALIDATION_FAILED', 'SOURCE_REVISION_CHANGED'], true) ? 'QUARANTINED' : ($e->reason === 'SOURCE_UNAVAILABLE' ? 'UNAVAILABLE' : 'FAILED'));
                $this->updateIfOwned($entry, ['status' => $state, 'error_code' => $e->reason, 'available_at' => now('UTC')->addSeconds($delay), 'lease_owner' => null, 'lease_until' => null]);
                $this->attempt($entry, $state, $e->reason);
            }
        } catch (\Throwable $e) {
            $this->updateIfOwned($entry, ['status' => 'FAILED', 'error_code' => 'IMPORT_EXECUTION_FAILED', 'lease_owner' => null, 'lease_until' => null]);
            $this->attempt($entry, 'FAILED', 'IMPORT_EXECUTION_FAILED');
            Log::warning('Historical acquisition failed.', ['entry_id' => $entry->id, 'exception_type' => $e::class]);
        } finally {
            $this->storage->clear($directory);
        }
        $this->finish($batchId);

        return true;
    }

    public function claim(string $batchId): ?object
    {
        return DB::transaction(function () use ($batchId): ?object {
            DB::select('SELECT pg_advisory_xact_lock(20261105,20001)');
            $batch = DB::table('historical_download_batches')->where('id', $batchId)->lockForUpdate()->first();
            if ($batch === null || $batch->status !== 'ACTIVE') {
                return null;
            }
            if (DB::table('historical_download_entries')->whereNotNull('lease_owner')->where('lease_until', '>', now('UTC'))->count() >= (int) config('historical_data.concurrency')) {
                return null;
            }
            DB::table('historical_download_entries')->where('batch_id', $batchId)->whereNotNull('lease_owner')->where('lease_until', '<=', now('UTC'))->where('attempts', '>=', (int) config('historical_data.max_attempts'))->update(['status' => 'FAILED', 'error_code' => 'WORKER_ATTEMPTS_EXHAUSTED', 'lease_owner' => null, 'lease_until' => null]);
            $entry = DB::table('historical_download_entries')->where('batch_id', $batchId)->where(function ($q) {
                $q->whereNull('lease_owner')->orWhere('lease_until', '<=', now('UTC'));
            })->where(function ($q) {
                $q->whereNull('available_at')->orWhere('available_at', '<=', now('UTC'));
            })->whereIn('status', ['PLANNED', 'RUNNING', 'DOWNLOADED', 'CHECKSUM_VERIFIED', 'PARSED'])->where('attempts', '<', (int) config('historical_data.max_attempts'))->orderBy('family')->orderBy('period')->orderBy('symbol')->orderBy('interval')->lockForUpdate()->first();
            if ($entry === null) {
                return null;
            }
            $entry->lease_owner = Uuid::uuid4()->toString();
            $entry->fence++;
            $entry->attempts++;
            DB::table('historical_download_entries')->where('id', $entry->id)->update(['status' => 'RUNNING', 'lease_owner' => $entry->lease_owner, 'fence' => $entry->fence, 'attempts' => $entry->attempts, 'lease_until' => now('UTC')->addSeconds((int) config('historical_data.lease_seconds')), 'updated_at' => now('UTC')]);
            DB::table('historical_download_attempts')->insert(['entry_id' => $entry->id, 'attempt' => $entry->attempts, 'fence' => $entry->fence, 'status' => 'RUNNING', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

            return $entry;
        }, 3);
    }

    private function commit(object $entry, array $chunk, int $checkpoint, string $revision): void
    {
        DB::transaction(function () use ($entry, $chunk, $checkpoint, $revision): void {
            $fresh = $this->guard($entry, true);
            if ($fresh->checkpoint >= $checkpoint) {
                return;
            }
            abort_unless($checkpoint - $fresh->checkpoint === count($chunk), 409, 'Historical checkpoint progression mismatch.');
            $result = $this->writer->write($entry, $chunk, $revision);
            $this->update($entry, ['checkpoint' => $checkpoint, 'imported_rows' => $fresh->imported_rows + $result['inserted'], 'duplicate_rows' => $fresh->duplicate_rows + $result['duplicates'], 'conflict_rows' => $fresh->conflict_rows + $result['conflicts']]);
        }, 3);
    }

    private function guard(object $entry, bool $lock = false): object
    {
        $batch = DB::table('historical_download_batches')->where('id', $entry->batch_id);
        if ($lock) {
            $batch->sharedLock();
        }$batch = $batch->first();
        if ($batch === null || $batch->status !== 'ACTIVE') {
            throw new AcquisitionFailure('BATCH_PAUSED_OR_CANCELLED');
        }
        $query = DB::table('historical_download_entries')->where('id', $entry->id);
        if ($lock) {
            $query->lockForUpdate();
        }$fresh = $query->first();
        if ($fresh === null || $fresh->lease_owner !== $entry->lease_owner || $fresh->fence !== $entry->fence || CarbonImmutable::parse($fresh->lease_until)->isPast()) {
            throw new AcquisitionFailure('STALE_WORKER_FENCE');
        }

        return $fresh;
    }

    private function update(object $entry, array $data): void
    {
        $this->guard($entry);
        $this->updateIfOwned($entry, $data);
    }

    private function updateIfOwned(object $entry, array $data): void
    {
        DB::table('historical_download_entries')->where('id', $entry->id)->where('lease_owner', $entry->lease_owner)->where('fence', $entry->fence)->update($data + ['updated_at' => now('UTC')]);
    }

    private function attempt(object $entry, string $state, ?string $error): void
    {
        DB::table('historical_download_attempts')->where('entry_id', $entry->id)->where('attempt', $entry->attempts)->update(['status' => $state, 'error_code' => $error, 'updated_at' => now('UTC')]);
    }

    private function fallback(object $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $this->guard($entry, true);
            if ($entry->family === 'fundingRate') {
                $state = 'SUPERSEDED_API';
            } else {
                $from = CarbonImmutable::parse($entry->requested_from);
                $to = CarbonImmutable::parse($entry->requested_to);
                for ($day = $from->startOfDay(); $day < $to; $day = $day->addDay()) {
                    $this->batches->addEntry($entry->batch_id, $this->planner->entry($entry->symbol, $entry->family, $entry->interval, $day, $from, $to, 'daily'), $entry->id);
                }$state = 'SUPERSEDED_DAILY';
            }
            $this->update($entry, ['status' => $state, 'download_status' => 'UNAVAILABLE', 'verification_status' => 'UNAVAILABLE', 'error_code' => 'MONTHLY_UNAVAILABLE_FALLBACK_PLANNED', 'lease_owner' => null, 'lease_until' => null]);
            $this->batches->audit($entry->batch_id, 'OFFICIAL_FALLBACK_PLANNED', ['entry_id' => $entry->id, 'fallback' => $state]);
        }, 3);
    }

    public function finish(string $batchId): void
    {
        $batch = DB::table('historical_download_batches')->find($batchId);
        if ($batch === null || $batch->status !== 'ACTIVE') {
            return;
        }
        $pending = DB::table('historical_download_entries')->where('batch_id', $batchId)->whereIn('status', ['PLANNED', 'RUNNING', 'DOWNLOADED', 'CHECKSUM_VERIFIED', 'PARSED'])->exists();
        if ($pending) {
            return;
        }
        $bad = DB::table('historical_download_entries')->where('batch_id', $batchId)->whereIn('status', ['QUARANTINED', 'FAILED', 'UNAVAILABLE'])->exists();
        DB::table('historical_download_batches')->where('id', $batchId)->where('status', 'ACTIVE')->update(['status' => $bad ? 'FAILED' : 'IMPORTED', 'updated_at' => now('UTC')]);
    }
}
