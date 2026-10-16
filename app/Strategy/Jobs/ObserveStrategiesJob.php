<?php

declare(strict_types=1);

namespace App\Strategy\Jobs;

use App\Strategy\Application\StrategyObservationService;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class ObserveStrategiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [5, 20, 60];

    public function __construct(public readonly string $symbol, public readonly ?string $at = null) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("strategy-observations:{$this->symbol}:{$this->at}"))->expireAfter(620)];
    }

    public function handle(StrategyObservationService $service): void
    {
        if ($this->at !== null) {
            $service->observe($this->symbol, new DateTimeImmutable($this->at));

            return;
        }
        $service->observe($this->symbol);
        $service->observeRecent($this->symbol, maximumCandles: 8);
    }
}
