<?php

declare(strict_types=1);

namespace App\Trading\Jobs;

use App\Exchanges\Binance\BinancePaperSafety;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessPaperPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [2, 10, 30];

    public function __construct(
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly string $atIso,
        public readonly ?array $onlyAccountIds = null,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("pipeline:paper:{$this->exchange}:{$this->symbol}:{$this->atIso}"))
                ->expireAfter(210),
        ];
    }

    public function handle(TradingPipelineEventService $pipelineService, PaperProcessingService $paperService): void
    {
        $startMs = (int) floor(microtime(true) * 1000);
        $at = new DateTimeImmutable($this->atIso);

        // Enforce temporal causality and freshness window.
        if (! $pipelineService->isFresh($at)) {
            $now = now('UTC')->toDateTimeImmutable();
            $ageSeconds = $now->getTimestamp() - $at->getTimestamp();
            $budgetSeconds = $pipelineService->freshnessSeconds();

            Log::warning("ProcessPaperPipelineJob: {$this->exchange}:{$this->symbol} rejected as STALE (exceeded freshness budget)", [
                'exchange' => $this->exchange,
                'symbol' => $this->symbol,
                'candle_close_at' => $this->atIso,
                'age_seconds' => $ageSeconds,
                'freshness_budget_seconds' => $budgetSeconds,
                'rejection_reason' => 'FRESHNESS_BUDGET_EXCEEDED',
            ]);

            $terminal = TradingPipelineEventRecord::query()->where('exchange', $this->exchange)
                ->where('symbol', $this->symbol)->where('candle_close_at', $at)
                ->where('stage', TradingPipelineEventRecord::STAGE_PAPER_PROCESSED)
                ->whereIn('status', ['COMPLETED', 'PARTIAL', 'FAILED'])->first();
            $pipelineService->acknowledgeUpstreamEvents($this->exchange, $this->symbol, $at, $terminal?->status ?? TradingPipelineEventRecord::STATUS_STALE);

            return;
        }

        $eventKey = "{$this->exchange}:{$this->symbol}:{$at->format(DATE_ATOM)}:".TradingPipelineEventRecord::STAGE_PAPER_PROCESSED;
        DB::table('trading_pipeline_events')->insertOrIgnore([
            'event_key' => $eventKey, 'exchange' => $this->exchange, 'symbol' => $this->symbol,
            'timeframe' => '5', 'stage' => TradingPipelineEventRecord::STAGE_PAPER_PROCESSED,
            'candle_close_at' => $at, 'status' => TradingPipelineEventRecord::STATUS_PENDING,
            'attempts' => 0, 'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $batch = TradingPipelineEventRecord::query()->where('event_key', $eventKey)->firstOrFail();
        if ($batch->status === TradingPipelineEventRecord::STATUS_COMPLETED) {
            $pipelineService->acknowledgeUpstreamEvents($this->exchange, $this->symbol, $at, $batch->status);

            return;
        }

        $allowNewEntries = match ($this->exchange) {
            'binance' => (bool) config('exchanges.binance.paper_execution_approved', false),
            'bybit' => true,
            default => false,
        };

        $accountsQuery = PaperAccountRecord::query()
            ->where('exchange_id', $this->exchange)
            ->where('is_primary', true)
            ->whereIn('status', ['ACTIVE', 'BLOCKED', 'HALTED'])
            ->orderBy('id');

        if (! empty($this->onlyAccountIds)) {
            $accountsQuery->whereIn('id', $this->onlyAccountIds);
        }

        $accounts = $accountsQuery->get();

        $signalOnly = (array) config('research.forward_paper.signal_only_strategies', []);
        $accounts = $accounts->reject(fn ($account): bool => in_array($account->strategy_id, $signalOnly, true));
        DB::transaction(function () use ($batch, $accounts): void {
            $locked = TradingPipelineEventRecord::query()->lockForUpdate()->findOrFail($batch->id);
            $data = (array) $locked->payload;
            $eligible = array_values(array_unique([...($data['eligible_account_ids'] ?? []), ...$accounts->pluck('id')->all()]));
            $locked->update(['payload' => array_merge($data, ['eligible_account_ids' => $eligible, 'eligible_accounts' => count($eligible)])]);
        }, attempts: 3);
        foreach ($accounts as $account) {
            // Commit the account's effects and its acknowledgement together. A crash
            // cannot leave a committed fill without a durable peer checkpoint.
            DB::transaction(function () use ($batch, $account, $paperService, $allowNewEntries, $at): void {
                $locked = TradingPipelineEventRecord::query()->lockForUpdate()->findOrFail($batch->id);
                $data = (array) $locked->payload;
                $done = (array) ($data['processed_account_ids'] ?? []);
                if (in_array($account->id, $done, true)) {
                    return;
                }
                $eligible = array_values(array_unique([...($data['eligible_account_ids'] ?? []), $account->id]));
                $failures = array_values(array_filter($data['failures'] ?? [], fn (array $f): bool => $f['account_id'] !== $account->id));
                try {
                    $result = DB::transaction(function () use ($account, $paperService, $allowNewEntries, $at): array {
                        if ($this->exchange === 'binance') {
                            BinancePaperSafety::assertProvenance($account);
                            if ($account->status === 'ACTIVE' && $allowNewEntries) {
                                BinancePaperSafety::assertExecutable($account);
                            }
                        }

                        return $paperService->process($account->id, $this->symbol, $at, $allowNewEntries, historical: false);
                    });
                    $done[] = $account->id;
                    $data['events_processed'] = ($data['events_processed'] ?? 0) + (int) ($result['events_processed'] ?? 0);
                } catch (Throwable $e) {
                    $failures[] = ['account_id' => $account->id, 'error' => $e->getMessage()];
                    Log::warning('Paper pipeline account failed.', ['account_id' => $account->id, 'exception' => $e::class]);
                }
                $locked->update(['payload' => array_merge($data, [
                    'eligible_account_ids' => $eligible, 'processed_account_ids' => $done,
                    'failures' => $failures, 'eligible_accounts' => count($eligible),
                    'processed_accounts' => count($done), 'failures_count' => count($failures),
                ])]);
            }, attempts: 3);
        }

        DB::transaction(function () use ($batch, $pipelineService, $at): void {
            $locked = TradingPipelineEventRecord::query()->lockForUpdate()->findOrFail($batch->id);
            $data = (array) $locked->payload;
            $pipelineService->recordPaperProcessed($this->exchange, $this->symbol, $at, (int) ($data['events_processed'] ?? 0), $data);
        }, attempts: 3);
    }
}
