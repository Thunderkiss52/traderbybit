<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Application;

use App\MarketData\Domain\Candle;
use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

class QimWsObserver
{
    public function __construct(
        private readonly QimObservationService $observationService,
        private readonly LoggerInterface $log,
    ) {}

    public function observe(string $rawMessage, Candle $candle, ?int $providerEventTimeMs = null): void
    {
        try {
            $openTime = CarbonImmutable::createFromTimestampMs($candle->openTimeMs());
            $closeTime = $openTime->addMilliseconds($candle->interval->milliseconds());
            $providerTime = $providerEventTimeMs !== null
                ? CarbonImmutable::createFromTimestampMs($providerEventTimeMs)
                : null;

            $this->observationService->recordObservation([
                'provider' => 'bybit',
                'channel' => 'WS',
                'category' => $candle->category,
                'symbol' => $candle->symbol,
                'interval' => $candle->interval->value,
                'candle_open_time' => $openTime,
                'candle_close_time' => $closeTime,
                'open' => $candle->open,
                'high' => $candle->high,
                'low' => $candle->low,
                'close' => $candle->close,
                'volume' => $candle->volume,
                'turnover' => $candle->turnover,
                'is_closed' => $candle->isClosed,
                'provider_event_time' => $providerTime,
                'local_received_at' => CarbonImmutable::now(),
                'raw_payload' => $rawMessage,
            ]);
        } catch (Throwable $e) {
            $this->log->warning('QIM WS observer caught error', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
