<?php

declare(strict_types=1);

namespace App\Strategy\Jobs;

use App\Strategy\Application\SignalGenerator;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class GenerateSignalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [5, 20, 60];

    public function __construct(public readonly string $symbol, public readonly ?string $at = null) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("signal:{$this->symbol}:{$this->at}"))->expireAfter(120)];
    }

    public function handle(SignalGenerator $generator): void
    {
        $generator->generate($this->symbol, $this->at === null ? null : new DateTimeImmutable($this->at));
    }
}
