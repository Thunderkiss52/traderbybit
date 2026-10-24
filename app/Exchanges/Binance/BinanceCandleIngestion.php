<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Explicit, bounded Binance ingestion. Never generates trading signals or paper fills. */
final readonly class BinanceCandleIngestion
{
    public function __construct(private BinancePublicAdapter $adapter) {}

    public function ingest(string $symbol, string $interval, int $limit = 100): array
    {
        if (! in_array($symbol, (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']), true)) {
            throw new InvalidArgumentException('Market symbol not configured.');
        }
        if (! isset(['5m' => 1, '15m' => 1, '30m' => 1, '1h' => 1, '4h' => 1][$interval])) {
            throw new InvalidArgumentException('Interval not enabled for Binance research.');
        }
        $rows = $this->adapter->fetchCandles($symbol, $interval, $limit);
        $map = ['5m' => '5', '15m' => '15', '30m' => '30', '1h' => '60', '4h' => '240'];
        $inserted = DB::transaction(function () use ($rows, $symbol, $interval, $map): int {
            $inserted = 0;
            foreach ($rows as $candle) {
                if ($candle['exchange_id'] !== 'binance' || $candle['symbol'] !== $symbol || $candle['interval'] !== $interval) {
                    throw new \DomainException('Binance candle source mismatch.');
                }
                // Do not accept or store the open/unconfirmed candle.
                if (! $candle['is_closed']) {
                    continue;
                }
                BinanceSourceIdentity::assertPersistable([
                    'open' => $candle['open'], 'high' => $candle['high'], 'low' => $candle['low'],
                    'close' => $candle['close'], 'volume' => $candle['volume'], 'turnover' => $candle['turnover'],
                ]);
                $now = now('UTC');
                $identity = ['exchange' => 'binance', 'category' => 'linear', 'symbol' => $symbol,
                    'interval' => $map[$interval], 'open_time' => CarbonImmutable::parse($candle['open_time_utc'])];
                $insertedNow = DB::table('market_candles')->insertOrIgnore([
                    ...$identity,
                    'open' => $candle['open'], 'high' => $candle['high'], 'low' => $candle['low'],
                    'close' => $candle['close'], 'volume' => $candle['volume'], 'turnover' => $candle['turnover'],
                    'is_closed' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($insertedNow === 0) {
                    $stored = DB::table('market_candles')->where($identity)->first();
                    if ($stored === null) {
                        throw new \DomainException('Binance candle insertion was ignored without matching identity.');
                    }
                    BinanceSourceIdentity::assertSameCandle($stored, $candle);
                }
                $inserted += $insertedNow;
            }

            return $inserted;
        });

        // Existing rows are immutable; retries verify that bytes did not
        // change economically. Conflicting exchange replays fail closed.
        return [
            'exchange_id' => 'binance', 'symbol' => $symbol, 'interval' => $interval,
            'fetched' => count($rows), 'new_closed_candles' => $inserted,
            'execution_status' => 'PAPER_EXECUTION_BLOCKED_UNTIL_VERIFIED',
        ];
    }
}
