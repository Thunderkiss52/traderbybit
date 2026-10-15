<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Persistence;

use App\MarketData\Contracts\CandleStore;
use App\MarketData\Domain\CandleQuery;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class EloquentCandleStore implements CandleStore
{
    private const UPSERT_BATCH_SIZE = 500;

    public function upsert(array $candles): int
    {
        if ($candles === []) {
            return 0;
        }

        $now = now('UTC');
        $rows = array_map(static function ($candle) use ($now): array {
            $row = $candle->toPersistenceArray();
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            return $row;
        }, $candles);

        foreach (array_chunk($rows, self::UPSERT_BATCH_SIZE) as $batch) {
            DB::table('market_candles')->upsert(
                $batch,
                ['exchange', 'category', 'symbol', 'interval', 'open_time'],
                ['open', 'high', 'low', 'close', 'volume', 'turnover', 'is_closed', 'updated_at'],
            );
        }

        return count($rows);
    }

    public function closedOpenTimes(CandleQuery $query): array
    {
        return DB::table('market_candles')
            ->where('exchange', 'bybit')
            ->where('category', $query->category)
            ->where('symbol', $query->symbol)
            ->where('interval', $query->interval->value)
            ->where('is_closed', true)
            ->where('open_time', '>=', $this->date($query->fromMs))
            ->where('open_time', '<', $this->date($query->toMs))
            ->orderBy('open_time')
            ->pluck('open_time')
            ->map(static fn (string $value): int => (int) (new DateTimeImmutable($value))->format('U') * 1000)
            ->values()
            ->all();
    }

    public function latestClosedOpenTimeMs(string $exchange, string $category, string $symbol, string $interval): ?int
    {
        $value = DB::table('market_candles')
            ->where(compact('exchange', 'category', 'symbol', 'interval'))
            ->where('is_closed', true)
            ->max('open_time');

        return is_string($value) ? ((int) (new DateTimeImmutable($value))->format('U')) * 1000 : null;
    }

    private function date(int $timestampMs): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.intdiv($timestampMs, 1000)))->setTimezone(new DateTimeZone('UTC'));
    }
}
