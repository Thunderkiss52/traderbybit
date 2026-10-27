<?php

declare(strict_types=1);

namespace App\MarketData\Application;

use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

final readonly class FundingHistorySynchronizer
{
    public function __construct(private BybitMarketDataClient $marketData, private array $config) {}

    public function sync(string $symbol, ?DateTimeImmutable $through = null): array
    {
        $through ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $latest = MarketFundingEventRecord::query()->where([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
        ])->latest('funding_at')->first(['funding_at']);
        $from = $latest?->funding_at?->toDateTimeImmutable()
            ?? $through->modify('-'.max(1, (int) $this->config['initial_lookback_days']).' days');
        $rows = $this->marketData->fundingRates('linear', $symbol, $this->milliseconds($from), $this->milliseconds($through));
        $inserted = 0;
        $missingMarkPrice = 0;
        $markPricesByDate = [];
        foreach ($rows as $row) {
            if (($row['symbol'] ?? null) !== $symbol || ! is_string($row['fundingRate'] ?? null)
                || ! is_string($row['fundingRateTimestamp'] ?? null) || ! ctype_digit($row['fundingRateTimestamp'])) {
                continue;
            }
            BigDecimal::of($row['fundingRate']);
            $fundingAt = $this->fromMilliseconds((int) $row['fundingRateTimestamp']);
            if ($fundingAt > $through || $fundingAt < $from) {
                continue;
            }
            $expectedOpen = $fundingAt->modify('-5 minutes');
            $date = $expectedOpen->format('Y-m-d');
            $markPricesByDate[$date] ??= CandleRecord::query()->where([
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
                'interval' => '5', 'is_closed' => true,
            ])->whereDate('open_time', $date)->limit(300)->get(['open_time', 'close'])
                ->keyBy(fn (CandleRecord $candle): int => $candle->open_time->getTimestamp());
            $mark = $markPricesByDate[$date]->get($expectedOpen->getTimestamp());
            if ($mark === null || $mark->open_time->addMinutes(5)->getTimestamp() !== $fundingAt->getTimestamp()) {
                $missingMarkPrice++;

                continue;
            }
            $inserted += MarketFundingEventRecord::query()->insertOrIgnore([[
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
                'funding_at' => $fundingAt, 'rate' => $row['fundingRate'], 'mark_price' => $mark->close,
                'created_at' => now(), 'updated_at' => now(),
            ]]);
        }

        if ($inserted > 0) {
            try {
                app(PositionLifecycleService::class)->reconcilePendingFunding('bybit', $symbol, $from);
            } catch (\Throwable $e) {
                Log::warning("reconcilePendingFunding failed for bybit:{$symbol}: ".$e->getMessage());
            }
        }

        return ['received' => count($rows), 'inserted' => $inserted, 'missing_mark_price' => $missingMarkPrice,
            'from' => $from->format(DATE_ATOM), 'through' => $through->format(DATE_ATOM)];
    }

    private function milliseconds(DateTimeImmutable $value): int
    {
        return $value->getTimestamp() * 1000 + (int) floor((int) $value->format('u') / 1000);
    }

    private function fromMilliseconds(int $milliseconds): DateTimeImmutable
    {
        $value = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($milliseconds, 1000), ($milliseconds % 1000) * 1000), new DateTimeZone('UTC'));
        if ($value === false) {
            throw new \RuntimeException('Invalid funding timestamp.');
        }

        return $value;
    }
}
