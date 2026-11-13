<?php

declare(strict_types=1);

namespace App\Research\Jobs;

use App\Research\Application\SignalIntelligenceService;
use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use App\Research\Infrastructure\Persistence\SignalOutcomeEvaluationRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessSignalIntelligenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue('research');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('research:signal-intelligence'))->expireAfter(120)];
    }

    public function handle(SignalIntelligenceService $service): void
    {
        if (! config('research.signal_intelligence.enabled', true)) {
            return;
        }
        $limit = min(16, max(1, (int) config('research.signal_intelligence.max_signals_per_cycle', 8)));
        $completed = ResearchJobRecord::query()->where('kind', 'SIGNAL_MARKOUT')->where('status', 'SUCCEEDED')
            ->whereNull('runtime_stats->signal_intelligence_import')
            ->where('id', '>', (int) Cache::get('signal-intelligence:completed-cursor', 0))->orderBy('id')->limit($limit)->get();
        if ($completed->isEmpty()) {
            Cache::forget('signal-intelligence:completed-cursor');
        }
        foreach ($completed as $job) {
            try {
                $records = $service->importResult($job);
                foreach ($records as $record) {
                    if ($record instanceof SignalOutcomeEvaluationRecord && $record->status === 'MATURED') {
                        $service->scheduleCohort($record);
                    }
                }
            } catch (Throwable $error) {
                Log::warning('Signal intelligence import blocked.', ['job_id' => $job->job_id, 'exception_type' => $error::class]);
            }
            Cache::put('signal-intelligence:completed-cursor', $job->id, 3600);
        }
        // Cache is a non-authoritative scanning cursor. Its loss only causes safe,
        // idempotent rescanning, never a financial event or skipped safety gate.
        $asOf = CarbonImmutable::now('UTC')->startOfSecond();
        $signals = SignalRecord::query()->whereIn('side', ['BUY', 'SELL'])->where('decision_time', '<=', $asOf->subMinute())
            ->where('created_at', '>=', $asOf->subDays(7))->where('id', '>', (string) Cache::get('signal-intelligence:signal-cursor', '00000000-0000-0000-0000-000000000000'))
            ->orderBy('id')->limit($limit)->get();
        if ($signals->isEmpty()) {
            Cache::forget('signal-intelligence:signal-cursor');
        }
        foreach ($signals as $signal) {
            try {
                $service->scheduleSignal($signal, asOf: $asOf);
            } catch (Throwable $error) {
                Log::warning('Signal intelligence scheduling blocked.', ['signal_id' => $signal->id, 'exception_type' => $error::class]);
            }
            Cache::put('signal-intelligence:signal-cursor', $signal->id, 3600);
        }
        // Recover classification dispatch after an interruption following a
        // committed outcome import. Submission itself is deterministic/idempotent.
        $members = SignalOutcomeEvaluationRecord::query()->where('status', 'MATURED')
            ->where('created_at', '>=', $asOf->subDays(7))->where('created_at', '<=', $asOf)
            ->where('id', '>', (string) Cache::get('signal-intelligence:cohort-cursor', '00000000-0000-0000-0000-000000000000'))
            ->orderBy('id')->limit($limit)->get();
        if ($members->isEmpty()) {
            Cache::forget('signal-intelligence:cohort-cursor');
        }
        foreach ($members as $member) {
            try {
                $service->scheduleCohort($member, $asOf);
            } catch (Throwable $error) {
                Log::warning('Signal intelligence classification blocked.', ['evaluation_id' => $member->id, 'exception_type' => $error::class]);
            }
            Cache::put('signal-intelligence:cohort-cursor', $member->id, 3600);
        }
    }
}
