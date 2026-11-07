<?php

declare(strict_types=1);

namespace App\Research\Jobs;

use App\PaperTrading\Application\DrawdownInvestigationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class InvestigatePaperDrawdownJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [10, 60, 120];

    public function __construct()
    {
        $this->onQueue('research');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('paper:drawdown-investigation'))->expireAfter(150)];
    }

    public function handle(DrawdownInvestigationService $investigations): void
    {
        $investigations->investigatePending();
    }
}
