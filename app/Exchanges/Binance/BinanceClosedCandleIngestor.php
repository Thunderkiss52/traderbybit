<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\MarketData\Contracts\CandleStore;
use App\Trading\Application\TradingPipelineEventService;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class BinanceClosedCandleIngestor
{
    public function __construct(
        private BinanceWebSocketMessageParser $parser,
        private CandleStore $store,
        private ?LoggerInterface $log = null,
        private ?TradingPipelineEventService $pipelineService = null,
    ) {}

    public function ingest(string $message): bool
    {
        $candle = $this->parser->parseClosedCandle($message);
        if ($candle === null) {
            return false;
        }

        $lockName = implode(':', [
            'binance-candle',
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
                    $pipelineService->recordCandleClosed('binance', $candle->symbol, $candle->interval->value, $candleCloseAt);
                } catch (Throwable $e) {
                    $this->log?->warning('Binance event dispatch caught exception', [
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $lock->release();
        }

        return true;
    }
}
