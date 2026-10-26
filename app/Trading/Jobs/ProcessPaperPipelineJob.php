<?php

declare(strict_types=1);

namespace App\Trading\Jobs;

use App\Exchanges\Binance\BinancePaperSafety;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Trading\Application\TradingPipelineEventService;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
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
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("pipeline:paper:{$this->exchange}:{$this->symbol}:{$this->atIso}"))
                ->expireAfter(180),
        ];
    }

    public function handle(TradingPipelineEventService $pipelineService, PaperProcessingService $paperService): void
    {
        $at = new DateTimeImmutable($this->atIso);

        // Enforce temporal causality and freshness window.
        if (! $pipelineService->isFresh($at)) {
            Log::info("ProcessPaperPipelineJob: {$this->exchange}:{$this->symbol} at {$this->atIso} rejected as STALE (exceeded 300s freshness window).");

            return;
        }

        $allowNewEntries = match ($this->exchange) {
            'binance' => (bool) config('exchanges.binance.paper_execution_approved', false),
            'bybit' => true,
            default => false,
        };

        $accounts = PaperAccountRecord::query()
            ->where('exchange_id', $this->exchange)
            ->where('is_primary', true)
            ->whereIn('status', ['ACTIVE', 'BLOCKED', 'HALTED'])
            ->get();

        $signalOnly = (array) config('research.forward_paper.signal_only_strategies', []);
        $totalProcessed = 0;
        $failures = [];

        foreach ($accounts as $account) {
            if (in_array($account->strategy_id, $signalOnly, true)) {
                continue;
            }

            if ($this->exchange === 'binance') {
                BinancePaperSafety::assertProvenance($account);
                if ($account->status === 'ACTIVE' && $allowNewEntries) {
                    BinancePaperSafety::assertExecutable($account);
                }
            }

            try {
                $result = $paperService->process($account->id, $this->symbol, $at, $allowNewEntries);
                $totalProcessed += (int) ($result['events_processed'] ?? 0);
            } catch (Throwable $e) {
                Log::warning("ProcessPaperPipelineJob account processing error: {$account->id} ({$account->strategy_id}): ".$e->getMessage(), [
                    'account_id' => $account->id,
                    'strategy_id' => $account->strategy_id,
                    'exception' => $e::class,
                ]);
                $failures[] = [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (! empty($failures)) {
            Log::warning('ProcessPaperPipelineJob completed with individual account errors.', [
                'failed_count' => count($failures),
                'total_accounts' => $accounts->count(),
                'failures' => $failures,
            ]);
        }

        $pipelineService->recordPaperProcessed($this->exchange, $this->symbol, $at, $totalProcessed, [
            'failures_count' => count($failures),
        ]);
    }
}
