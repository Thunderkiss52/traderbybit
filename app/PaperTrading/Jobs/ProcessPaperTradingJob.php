<?php

declare(strict_types=1);

namespace App\PaperTrading\Jobs;

use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

final class ProcessPaperTradingJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [5, 20, 60];

    public function __construct(public readonly ?string $accountId = null, public readonly ?string $symbol = null, public readonly ?string $at = null) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('paper:'.($this->accountId ?? 'all')))->expireAfter(200)];
    }

    public function handle(PaperProcessingService $service): void
    {
        $at = $this->at === null ? null : new DateTimeImmutable($this->at);

        if ($this->accountId !== null) {
            $service->process($this->accountId, $this->symbol, $at);

            return;
        }

        $defaultProcessed = $service->process(null, $this->symbol, $at);
        $defaultId = $defaultProcessed['account_id'] ?? null;

        foreach (PaperAccountRecord::query()->whereIn('status', ['ACTIVE', 'BLOCKED', 'HALTED'])->cursor() as $account) {
            if ($account->id === $defaultId) {
                continue;
            }
            try {
                $service->process($account->id, $this->symbol, $at);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
