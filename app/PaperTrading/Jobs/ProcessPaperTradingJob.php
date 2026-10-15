<?php

declare(strict_types=1);

namespace App\PaperTrading\Jobs;

use App\PaperTrading\Application\PaperProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ProcessPaperTradingJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [5, 20, 60];

    public function __construct(public readonly ?string $accountId = null, public readonly ?string $symbol = null, public readonly ?string $at = null) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('paper:'.($this->accountId ?? 'default')))->expireAfter(200)];
    }

    public function handle(PaperProcessingService $service): void
    {
        $service->process($this->accountId, $this->symbol, $this->at === null ? null : new \DateTimeImmutable($this->at));
    }
}
