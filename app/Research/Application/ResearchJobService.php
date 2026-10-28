<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class ResearchJobService
{
    public const VALID_KINDS = [
        'BACKTEST',
        'WALK_FORWARD',
        'PATTERN_MINE',
        'REPLAY',
        'SKIP_OUTCOMES',
        'MONTE_CARLO',
        'FEATURE_BUILD',
        'MODEL_TRAIN',
    ];

    public const TERMINAL_STATUSES = [
        'SUCCEEDED',
        'FAILED',
        'CANCELLED',
        'EXPIRED',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function submitJob(array $data): ResearchJobRecord
    {
        try {
            $kind = strtoupper((string) ($data['kind'] ?? ''));
            if (! in_array($kind, self::VALID_KINDS, true)) {
                throw new InvalidArgumentException('Unsupported research job kind.');
            }

            $idempotencyKey = (string) ($data['idempotency_key'] ?? '');
            if ($idempotencyKey === '') {
                $idempotencyKey = hash('sha256', (string) json_encode($data));
            }

            return DB::transaction(function () use ($data, $kind, $idempotencyKey) {
                // Check if active job with same kind and idempotency key already exists
                $existing = ResearchJobRecord::query()
                    ->where('kind', $kind)
                    ->where('idempotency_key', $idempotencyKey)
                    ->whereNotIn('status', self::TERMINAL_STATUSES)
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $jobId = (string) ($data['job_id'] ?? Str::uuid()->toString());
                $now = CarbonImmutable::now('UTC');

                return ResearchJobRecord::query()->create([
                    'job_id' => $jobId,
                    'schema_version' => (int) ($data['schema_version'] ?? 1),
                    'idempotency_key' => $idempotencyKey,
                    'kind' => $kind,
                    'status' => 'QUEUED',
                    'algorithm_version' => (string) ($data['algorithm_version'] ?? '1.0.0'),
                    'code_revision' => (string) ($data['code_revision'] ?? 'main'),
                    'strategy_id' => isset($data['strategy_id']) ? (string) $data['strategy_id'] : null,
                    'strategy_version' => isset($data['strategy_version']) ? (string) $data['strategy_version'] : null,
                    'configuration_hash' => isset($data['configuration_hash']) ? (string) $data['configuration_hash'] : null,
                    'model_version' => isset($data['model_version']) ? (string) $data['model_version'] : null,
                    'dataset_id' => isset($data['dataset_id']) ? (string) $data['dataset_id'] : null,
                    'dataset_sha256' => isset($data['dataset_sha256']) ? (string) $data['dataset_sha256'] : null,
                    'as_of_utc' => isset($data['as_of_utc']) ? CarbonImmutable::parse($data['as_of_utc']) : $now,
                    'training_cutoff_utc' => isset($data['training_cutoff_utc']) ? CarbonImmutable::parse($data['training_cutoff_utc']) : null,
                    'research_mode' => (string) ($data['research_mode'] ?? 'BACKTEST'),
                    'random_seed' => (int) ($data['random_seed'] ?? 12345),
                    'limits' => $data['limits'] ?? ['max_rows' => 1000000, 'max_duration_s' => 900, 'max_memory_mib' => 512],
                    'payload' => $data['payload'] ?? [],
                    'created_by' => (string) ($data['created_by'] ?? 'system'),
                    'requested_at_utc' => $now,
                    'progress' => 0,
                    'retry_count' => 0,
                    'max_retries' => (int) ($data['max_retries'] ?? 3),
                ]);
            });
        } catch (InvalidArgumentException $e) {
            Log::warning('Research job submission validation failed.', ['exception_type' => $e::class]);
            throw $e;
        } catch (Throwable $e) {
            Log::error('Research job submission failed.', ['exception_type' => $e::class]);
            throw new RuntimeException('Research job submission failed.', 0, $e);
        }
    }

    /**
     * @param  list<string>|null  $allowedKinds
     */
    public function claimNextJob(string $workerId, int $leaseSeconds = 60, ?array $allowedKinds = null): ?ResearchJobRecord
    {
        try {
            return DB::transaction(function () use ($workerId, $leaseSeconds, $allowedKinds) {
                $query = ResearchJobRecord::query()
                    ->where('status', 'QUEUED')
                    ->orderBy('requested_at_utc', 'asc')
                    ->lock('FOR UPDATE SKIP LOCKED');

                if (! empty($allowedKinds)) {
                    $query->whereIn('kind', $allowedKinds);
                }

                /** @var ResearchJobRecord|null $job */
                $job = $query->first();

                if ($job === null) {
                    return null;
                }

                $now = CarbonImmutable::now('UTC');
                $job->update([
                    'status' => 'CLAIMED',
                    'lease_owner' => $workerId,
                    'lease_expires_at' => $now->addSeconds($leaseSeconds),
                    'claimed_at' => $now,
                    'fencing_token' => $job->fencing_token + 1,
                ]);

                return $job->fresh();
            });
        } catch (Throwable $e) {
            Log::error('Research job claim failed.', ['exception_type' => $e::class]);
            throw new RuntimeException('Research job claim failed.', 0, $e);
        }
    }

    public function startJob(string $jobId, string $workerId, int $fencingToken): bool
    {
        try {
            $now = CarbonImmutable::now('UTC');

            $affected = ResearchJobRecord::query()
                ->where('job_id', $jobId)
                ->where('lease_owner', $workerId)
                ->where('fencing_token', $fencingToken)
                ->where('status', 'CLAIMED')
                ->update([
                    'status' => 'RUNNING',
                    'started_at' => $now,
                    'heartbeat_at' => $now,
                ]);

            return $affected > 0;
        } catch (Throwable $e) {
            Log::error('Research job operation failed.', ['worker' => $workerId, 'token' => $fencingToken]);

            return false;
        }
    }

    public function heartbeat(string $jobId, string $workerId, int $fencingToken, int $progress = 0, ?string $message = null, int $extensionSeconds = 60): bool
    {
        try {
            $now = CarbonImmutable::now('UTC');

            $affected = ResearchJobRecord::query()
                ->where('job_id', $jobId)
                ->where('lease_owner', $workerId)
                ->where('fencing_token', $fencingToken)
                ->whereIn('status', ['CLAIMED', 'RUNNING'])
                ->update([
                    'heartbeat_at' => $now,
                    'lease_expires_at' => $now->addSeconds($extensionSeconds),
                    'progress' => min(100, max(0, $progress)),
                    'progress_message' => $message,
                ]);

            return $affected > 0;
        } catch (Throwable $e) {
            Log::error('Research job operation failed.', ['worker' => $workerId]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $resultPayload
     * @param  array<string, mixed>  $runtimeStats
     */
    public function completeJob(string $jobId, string $workerId, int $fencingToken, array $resultPayload, array $runtimeStats = []): bool
    {
        try {
            $encoded = (string) json_encode($resultPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $manifestSha256 = hash('sha256', $encoded);
            $now = CarbonImmutable::now('UTC');

            $affected = ResearchJobRecord::query()
                ->where('job_id', $jobId)
                ->where('lease_owner', $workerId)
                ->where('fencing_token', $fencingToken)
                ->whereIn('status', ['CLAIMED', 'RUNNING'])
                ->update([
                    'status' => 'SUCCEEDED',
                    'progress' => 100,
                    'result_manifest_sha256' => $manifestSha256,
                    'result_payload' => $resultPayload,
                    'runtime_stats' => $runtimeStats,
                    'completed_at' => $now,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ]);

            return $affected > 0;
        } catch (Throwable $e) {
            Log::error('Research job operation failed.', ['worker' => $workerId]);

            return false;
        }
    }

    public function failJob(string $jobId, string $workerId, int $fencingToken, string $error): bool
    {
        try {
            /** @var ResearchJobRecord|null $job */
            $job = ResearchJobRecord::query()
                ->where('job_id', $jobId)
                ->where('lease_owner', $workerId)
                ->where('fencing_token', $fencingToken)
                ->first();

            if ($job === null) {
                return false;
            }

            $now = CarbonImmutable::now('UTC');
            // Worker failures may contain URLs, tokens or connection strings.
            // Public job state must never store a raw exception message.
            $sanitizedError = 'Research worker execution failed; internal details suppressed.';

            if ($job->retry_count + 1 < $job->max_retries) {
                $job->update([
                    'status' => 'QUEUED',
                    'retry_count' => $job->retry_count + 1,
                    'sanitized_error' => $sanitizedError,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ]);
            } else {
                $job->update([
                    'status' => 'FAILED',
                    'retry_count' => $job->retry_count + 1,
                    'sanitized_error' => $sanitizedError,
                    'completed_at' => $now,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ]);
            }

            return true;
        } catch (Throwable $e) {
            Log::error('Research job operation failed.', ['worker' => $workerId]);

            return false;
        }
    }

    public function cancelJob(string $jobId, string $reason = 'Cancelled by user'): bool
    {
        try {
            $now = CarbonImmutable::now('UTC');

            $affected = ResearchJobRecord::query()
                ->where('job_id', $jobId)
                ->whereNotIn('status', self::TERMINAL_STATUSES)
                ->update([
                    'status' => 'CANCELLED',
                    'sanitized_error' => substr($reason, 0, 500),
                    'completed_at' => $now,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ]);

            return $affected > 0;
        } catch (Throwable $e) {
            Log::error('Research job operation failed.');

            return false;
        }
    }

    public function reconcileStaleLeases(): int
    {
        try {
            $now = CarbonImmutable::now('UTC');

            $staleJobs = ResearchJobRecord::query()
                ->whereIn('status', ['CLAIMED', 'RUNNING'])
                ->where('lease_expires_at', '<', $now)
                ->get();

            $reconciled = 0;
            foreach ($staleJobs as $job) {
                if ($job->retry_count + 1 < $job->max_retries) {
                    $job->update([
                        'status' => 'QUEUED',
                        'retry_count' => $job->retry_count + 1,
                        'sanitized_error' => 'Worker lease expired; re-queued for retry',
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                    ]);
                } else {
                    $job->update([
                        'status' => 'EXPIRED',
                        'retry_count' => $job->retry_count + 1,
                        'sanitized_error' => 'Worker lease expired; maximum retries exceeded',
                        'completed_at' => $now,
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                    ]);
                }
                $reconciled++;
            }

            return $reconciled;
        } catch (Throwable $e) {
            Log::error('Research job lease reconciliation failed.', ['exception_type' => $e::class]);

            return 0;
        }
    }

    /**
     * Register or update a worker node's capability and heartbeat.
     *
     * @param  list<string>  $algorithms
     * @return array<string, mixed>
     */
    public function registerWorker(
        string $workerId,
        string $engine,
        string $version,
        array $algorithms = [],
        ?CarbonImmutable $heartbeatAt = null
    ): array {
        $now = CarbonImmutable::now('UTC');
        $heartbeat = $heartbeatAt ?? $now;
        $worker = [
            'worker_id' => $workerId,
            'engine' => strtolower($engine),
            'version' => $version,
            'algorithms' => $algorithms,
            'heartbeat_at' => $heartbeat->toIso8601String(),
        ];

        $ids = (array) cache()->get('research:worker_ids', []);
        if (! in_array($workerId, $ids, true)) {
            $ids[] = $workerId;
            cache()->put('research:worker_ids', $ids, 3600);
        }
        cache()->put("research:worker:{$workerId}", $worker, 3600);

        return $worker;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCapabilityRouting(): array
    {
        try {
            $now = CarbonImmutable::now('UTC');
            $counts = ResearchJobRecord::query()
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $workerIds = (array) cache()->get('research:worker_ids', []);
            $registeredWorkers = [];
            $engineWorkers = [];

            foreach ($workerIds as $wid) {
                $w = cache()->get("research:worker:{$wid}");
                if (! is_array($w)) {
                    continue;
                }
                $hb = CarbonImmutable::parse($w['heartbeat_at']);
                $isHeartbeatExpired = $hb->isBefore($now->subSeconds(120));
                $isUnsupportedVersion = version_compare((string) $w['version'], '1.0.0', '<');

                if ($isHeartbeatExpired) {
                    $status = 'offline';
                    $available = false;
                    $reason = 'HEARTBEAT_EXPIRED';
                } elseif ($isUnsupportedVersion) {
                    $status = 'unsupported_version';
                    $available = false;
                    $reason = 'UNSUPPORTED_VERSION';
                } else {
                    $status = 'ready';
                    $available = true;
                    $reason = null;
                }

                $evaluated = array_merge($w, [
                    'status' => $status,
                    'available' => $available,
                    'unavailable_reason' => $reason,
                ]);

                $registeredWorkers[$wid] = $evaluated;
                $engineWorkers[$w['engine']][] = $evaluated;
            }

            $capabilities = [
                'php' => [
                    'engine' => 'PHP/Laravel',
                    'status' => 'active',
                    'available' => true,
                    'role' => 'orchestration_and_fallback',
                    'algorithms' => ['backtest', 'counterfactual', 'replay', 'calibration', 'learning'],
                    'concurrency_cap' => 2,
                    'deterministic' => true,
                ],
                'go' => [
                    'engine' => 'Go Research Engine',
                    'status' => 'ready',
                    'available' => true,
                    'role' => 'high_throughput_worker',
                    'algorithms' => ['backtest', 'walk_forward', 'pattern_mine', 'replay', 'monte_carlo'],
                    'concurrency_cap' => 1,
                    'deterministic' => true,
                    'memory_cap_mb' => 512,
                    'cpu_cap' => 1.0,
                ],
                'python' => [
                    'engine' => 'Python ML Engine',
                    'status' => 'conditional_offline',
                    'available' => false,
                    'role' => 'model_training_only',
                    'algorithms' => ['model_train'],
                    'concurrency_cap' => 1,
                    'deterministic' => true,
                ],
            ];

            foreach (['go', 'php', 'python'] as $eng) {
                if (! empty($engineWorkers[$eng])) {
                    $workers = $engineWorkers[$eng];
                    $hasReady = collect($workers)->contains(fn ($w) => $w['available'] === true);
                    $capabilities[$eng]['workers'] = $workers;
                    if ($hasReady) {
                        $capabilities[$eng]['status'] = 'ready';
                        $capabilities[$eng]['available'] = true;
                        $capabilities[$eng]['unavailable_reason'] = null;
                    } else {
                        $unsupported = collect($workers)->firstWhere('status', 'unsupported_version');
                        if ($unsupported !== null) {
                            $capabilities[$eng]['status'] = 'unsupported_version';
                            $capabilities[$eng]['available'] = false;
                            $capabilities[$eng]['unavailable_reason'] = 'UNSUPPORTED_VERSION';
                        } else {
                            $capabilities[$eng]['status'] = 'offline';
                            $capabilities[$eng]['available'] = false;
                            $capabilities[$eng]['unavailable_reason'] = 'HEARTBEAT_EXPIRED';
                        }
                    }
                }
            }

            return [
                'capabilities' => $capabilities,
                'workers' => $registeredWorkers,
                'jobs' => [
                    'queued' => (int) ($counts['QUEUED'] ?? 0),
                    'claimed' => (int) ($counts['CLAIMED'] ?? 0),
                    'running' => (int) ($counts['RUNNING'] ?? 0),
                    'succeeded' => (int) ($counts['SUCCEEDED'] ?? 0),
                    'failed' => (int) ($counts['FAILED'] ?? 0),
                    'cancelled' => (int) ($counts['CANCELLED'] ?? 0),
                    'expired' => (int) ($counts['EXPIRED'] ?? 0),
                ],
            ];
        } catch (Throwable $e) {
            Log::error('Research capability routing failed.', ['exception_type' => $e::class]);

            return [
                'capabilities' => [],
                'jobs' => [],
            ];
        }
    }
}
