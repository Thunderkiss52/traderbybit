<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Idempotent ingestion of actual Binance funding settlement records. */
final readonly class BinanceFundingIngestion
{
    public function __construct(private BinancePublicAdapter $adapter) {}

    public function ingest(string $symbol): array
    {
        if (! in_array($symbol, (array) config('market_data.symbols', []), true)) {
            throw new DomainException('Unconfigured Binance funding symbol.');
        }
        $received = $this->adapter->fetchFundingHistory($symbol);
        [$inserted, $missing] = DB::transaction(function () use ($received, $symbol): array {
            $inserted = 0;
            $missing = 0;
            foreach ($received as $item) {
                if (($item['exchange_id'] ?? null) !== 'binance' || ($item['symbol'] ?? null) !== $symbol) {
                    throw new DomainException('Funding exchange/symbol mismatch.');
                }
                // A funding RATE without a settlement mark price is not a funding
                // cashflow. Never manufacture the mark price from a candle close.
                if ($item['mark_price'] === null) {
                    $missing++;

                    continue;
                }
                $at = CarbonImmutable::parse($item['funding_time_utc']);
                if ($at->isFuture()) {
                    continue;
                }
                BinanceSourceIdentity::assertPersistable([
                    'rate' => $item['funding_rate'], 'mark_price' => $item['mark_price'],
                ]);
                $identity = ['exchange' => 'binance', 'category' => 'linear', 'symbol' => $symbol, 'funding_at' => $at];
                $insertedNow = MarketFundingEventRecord::query()->insertOrIgnore([[
                    ...$identity, 'rate' => $item['funding_rate'], 'mark_price' => $item['mark_price'],
                    'mark_price_source' => 'BINANCE_FUNDING_HISTORY',
                    'created_at' => now(), 'updated_at' => now(),
                ]]);
                if ($insertedNow === 0) {
                    $stored = MarketFundingEventRecord::query()->where($identity)->first();
                    if ($stored === null) {
                        throw new DomainException('Binance funding insertion was ignored without matching identity.');
                    }
                    BinanceSourceIdentity::assertSameFunding($stored, [
                        'rate' => $item['funding_rate'], 'mark_price' => $item['mark_price'],
                    ]);
                }
                $inserted += $insertedNow;
            }

            return [$inserted, $missing];
        });

        if (count($received) > $missing) {
            try {
                app(PositionLifecycleService::class)->reconcilePendingFunding('binance', $symbol);
            } catch (\Throwable $e) {
                Log::warning("reconcilePendingFunding failed for binance:{$symbol}: ".$e->getMessage());
            }
        }

        return ['exchange_id' => 'binance', 'symbol' => $symbol, 'fetched' => count($received),
            'inserted' => $inserted, 'missing_mark_price' => $missing];
    }
}
