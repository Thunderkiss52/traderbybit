<?php

declare(strict_types=1);

namespace App\MarketData\Application;

use App\MarketData\Contracts\CandleStore;
use App\MarketData\Infrastructure\Bybit\BybitWebSocketMessageParser;
use App\QuoteIntegrity\Application\QimWsObserver;
use App\Trading\Application\TradingPipelineEventService;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ClosedCandleIngestor
{
    public function __construct(
        private BybitWebSocketMessageParser $parser,
        private CandleStore $store,
        private ?QimWsObserver $qimObserver = null,
        private ?LoggerInterface $log = null,
        private ?TradingPipelineEventService $pipelineService = null,
    ) {}

    public function ingest(string $message): bool
    {
        $candle = $this->parser->parseClosedCandle($message);
        if ($candle === null) {
            return false;
        }

        if ($this->qimObserver !== null) {
            try {
                $providerEventTimeMs = null;
                $decoded = json_decode($message, true);
                if (is_array($decoded) && isset($decoded['ts']) && is_numeric($decoded['ts'])) {
                    $providerEventTimeMs = (int) $decoded['ts'];
                }
                $this->qimObserver->observe($message, $candle, $providerEventTimeMs);
            } catch (Throwable $e) {
                $this->log?->warning('QIM WS observation hook caught exception', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $lockName = implode(':', [
            'market-candle',
            $candle->category,
            $candle->symbol,
            $candle->interval->value,
            $candle->openTimeMs(),
        ]);
        $lock = Cache::lock($lockName, 30);

        if (! $lock->get()) {
            return false;
        }

        try {
            $this->store->upsert([$candle]);
            if ($this->pipelineService !== null || (config('trading.pipeline_mode') === 'EVENT_DRIVEN' && in_array($candle->symbol, (array) config('trading.symbols', ['BTCUSDT', 'ETHUSDT']), true))) {
                try {
                    $pipelineService = $this->pipelineService ?? app(TradingPipelineEventService::class);
                    $candleCloseAt = $candle->openTime->modify('+'.intdiv($candle->interval->milliseconds(), 1000).' seconds');
                    $pipelineService->recordCandleClosed('bybit', $candle->symbol, $candle->interval->value, $candleCloseAt);
                } catch (Throwable) {
                    // Non-fatal event dispatch failure
                }
            }
        } finally {
            $lock->release();
        }

        return true;
    }
}
