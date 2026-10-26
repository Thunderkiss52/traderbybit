<?php

declare(strict_types=1);

namespace App\Strategy\Jobs;

use App\Research\Application\LearningFeatureStoreService;
use App\Strategy\Application\MarketScannerService;
use App\Strategy\Application\SignalGenerator;
use App\Strategy\Application\StrategyObservationService;
use App\Trading\Application\TradingPipelineRecoveryService;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

    public function handle(
        ?SignalGenerator $generator = null,
        ?StrategyObservationService $observations = null,
        ?LearningFeatureStoreService $featureStore = null,
        ?MarketScannerService $scanner = null
    ): void {
        $scanner ??= app(MarketScannerService::class);
        $at = $this->at === null ? null : new DateTimeImmutable($this->at);

        if (config('trading.pipeline_mode') === 'EVENT_DRIVEN') {
            $recovery = app(TradingPipelineRecoveryService::class);
            if ($recovery->isDecisionAlreadyServiced('bybit', $this->symbol, $at)) {
                Log::info("GenerateSignalJob: bybit:{$this->symbol} already processed by event-driven pipeline; scheduled run skipped.");

                return;
            }
        }

        $scanner->scanSymbol($this->symbol, $at);
    }
}
