<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Application;

use App\MarketData\Domain\Candle;
use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

class QimRestObserver
{
    public function __construct(
        private readonly QimObservationService $observationService,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * @param  list<Candle>  $candles
     */
    public function observeCandles(
        array $candles,
        CarbonImmutable $requestSentAt,
        CarbonImmutable $responseReceivedAt,
        ?string $rawBody = null,
    ): void {
        foreach ($candles as $candle) {
            try {
                $openTime = CarbonImmutable::createFromTimestampMs($candle->openTimeMs());
                $closeTime = $openTime->addMilliseconds($candle->interval->milliseconds());
                $payload = $rawBody ?? (string) json_encode([
                    'category' => $candle->category,
                    'symbol' => $candle->symbol,
                    'interval' => $candle->interval->value,
                    'start' => $candle->openTimeMs(),
                    'open' => $candle->open,
                    'high' => $candle->high,
                    'low' => $candle->low,
                    'close' => $candle->close,
                    'volume' => $candle->volume,
                    'turnover' => $candle->turnover,
                ], JSON_THROW_ON_ERROR);

                $this->observationService->recordObservation([
                    'provider' => 'bybit',
                    'channel' => 'REST',
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
                    'request_sent_at' => $requestSentAt,
                    'response_received_at' => $responseReceivedAt,
                    'local_received_at' => $responseReceivedAt,
                    'raw_payload' => $payload,
                ]);
            } catch (Throwable $e) {
                $this->log->warning('QIM REST observer caught error', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
