<?php

declare(strict_types=1);

namespace App\Backtest\Jobs;

use App\Backtest\Application\BacktestRunService;
use App\Exchanges\ExchangeRegistry;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class RunBacktestJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 2;

    public int $timeout = 3600;

    public array $backoff = [60, 300];

    // Default also applies when an older queued payload has no exchange field.
    public string $exchange = 'bybit';

    public function __construct(public readonly array $symbols, public readonly string $from, public readonly string $to, public readonly bool $walkForward = false, string $exchange = 'bybit')
    {
        if (! app(ExchangeRegistry::class)->isSupportedForBacktest($exchange)) {
            throw new \InvalidArgumentException("Unsupported backtest exchange '{$exchange}'.");
        }
        $this->exchange = $exchange;
        $this->onQueue('backtests');
    }

    public function middleware(): array
    {
        $key = hash('sha256', $this->exchange.'|'.implode(',', $this->symbols).$this->from.$this->to.($this->walkForward ? 'walk' : 'split'));

        return [(new WithoutOverlapping('backtest:'.$key))->expireAfter(3700)];
    }

    public function handle(BacktestRunService $service): void
    {
        $service->run($this->symbols, new DateTimeImmutable($this->from), new DateTimeImmutable($this->to), $this->walkForward, exchange: $this->exchange);
    }
}
