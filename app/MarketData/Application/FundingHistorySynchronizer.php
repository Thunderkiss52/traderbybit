<?php

declare(strict_types=1);

namespace App\MarketData\Application;

use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Contracts\FundingMarkPriceProvider;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

final readonly class FundingHistorySynchronizer
{
    public function __construct(
        private BybitMarketDataClient $marketData,
        private array $config,
        private ?PositionLifecycleService $lifecycle = null,
    ) {}

    public function sync(string $symbol, ?DateTimeImmutable $through = null): array
    {
        $through ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $latest = MarketFundingEventRecord::query()->where([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
        ])->latest('funding_at')->first(['funding_at']);
        $from = $latest?->funding_at?->toDateTimeImmutable()
            ?? $through->modify('-'.max(1, (int) ($this->config['initial_lookback_days'] ?? 30)).' days');

        $lifecycle = $this->lifecycle ?? (app()->has(PositionLifecycleService::class) ? app(PositionLifecycleService::class) : null);

        try {
            $rows = $this->marketData->fundingRates('linear', $symbol, $this->milliseconds($from), $this->milliseconds($through));
        } catch (\Throwable $e) {
            Log::warning("funding_gap_detected for bybit:{$symbol}: failed to fetch funding rates from API: {$e->getMessage()}", [
                'diagnostic' => 'funding_gap_detected',
                'exchange' => 'bybit',
                'symbol' => $symbol,
                'from' => $from->format(DATE_ATOM),
                'through' => $through->format(DATE_ATOM),
                'error' => $e->getMessage(),
            ]);

            return [
                'received' => 0,
                'inserted' => 0,
                'missing_mark_price' => 0,
                'diagnostic' => 'funding_gap_detected',
                'funding_gap_detected' => true,
                'missing_intervals' => [],
                'from' => $from->format(DATE_ATOM),
                'through' => $through->format(DATE_ATOM),
            ];
        }

        $inserted = 0;
        $missingMarkPrice = 0;
        $verified = 0;

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
            $mark = $this->marketData instanceof FundingMarkPriceProvider
                ? $this->marketData->fundingMarkPrice($symbol, $this->milliseconds($fundingAt))
                : null;
            if ($mark === null) {
                $missingMarkPrice++;
                Log::warning("funding_gap_detected for bybit:{$symbol}: missing mark price at {$fundingAt->format(DATE_ATOM)}", [
                    'diagnostic' => 'funding_gap_detected',
                    'exchange' => 'bybit',
                    'symbol' => $symbol,
                    'funding_at' => $fundingAt->format(DATE_ATOM),
                ]);

                continue;
            }
            if (BigDecimal::of($mark)->isLessThanOrEqualTo(0)) {
                throw new \DomainException('Funding mark must be positive.');
            }
            $identity = ['exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'funding_at' => $fundingAt];
            $inserted += MarketFundingEventRecord::query()->insertOrIgnore([[
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
                'funding_at' => $fundingAt, 'rate' => $row['fundingRate'], 'mark_price' => $mark,
                'mark_price_source' => 'BYBIT_MARK_KLINE_OPEN',
                'created_at' => now(), 'updated_at' => now(),
            ]]);
            $stored = MarketFundingEventRecord::query()->where($identity)->first();
            if ($stored !== null && (! BigDecimal::of($stored->rate)->isEqualTo($row['fundingRate'])
                || ! BigDecimal::of($stored->mark_price)->isEqualTo($mark))) {
                throw new \DomainException('Immutable Bybit funding conflict; historical reconciliation requires operator review.');
            }
            $verified++;
        }

        // Verify funding interval completeness
        $gapDetected = false;
        $missingBoundaries = [];

        if ($missingMarkPrice > 0) {
            $gapDetected = true;
        }

        if ($lifecycle !== null) {
            if ($latest !== null) {
                $checkFrom = $from;
            } elseif (! empty($rows)) {
                $validTimestamps = array_filter(
                    array_column($rows, 'fundingRateTimestamp'),
                    fn ($ts) => is_string($ts) && ctype_digit($ts)
                );
                if (! empty($validTimestamps)) {
                    $minTs = min(array_map('intval', $validTimestamps));
                    $checkFrom = $this->fromMilliseconds($minTs)->modify('-1 second');
                } else {
                    $checkFrom = $from;
                }
            } else {
                $checkFrom = $from;
            }

            $expectedBoundaries = $lifecycle->expectedFundingBoundaries($checkFrom, $through, 'bybit', $symbol);
            if ($expectedBoundaries !== null && ! empty($expectedBoundaries)) {
                $existingEvents = MarketFundingEventRecord::query()
                    ->where('exchange', 'bybit')
                    ->where('category', 'linear')
                    ->where('symbol', $symbol)
                    ->where('funding_at', '>', $checkFrom)
                    ->where('funding_at', '<=', $through)
                    ->get();

                foreach ($expectedBoundaries as $expected) {
                    $matched = $existingEvents->contains(function (MarketFundingEventRecord $event) use ($expected): bool {
                        return abs($event->funding_at->getTimestamp() - $expected->getTimestamp()) <= 60;
                    });
                    if (! $matched) {
                        $missingBoundaries[] = $expected->format(DATE_ATOM);
                        $gapDetected = true;
                    }
                }
            }
        }

        $diagnostic = $gapDetected ? 'funding_gap_detected' : 'complete';

        if ($gapDetected && $missingBoundaries !== []) {
            Log::warning("funding_gap_detected for bybit:{$symbol}: missing expected funding intervals", [
                'diagnostic' => 'funding_gap_detected',
                'exchange' => 'bybit',
                'symbol' => $symbol,
                'missing_intervals' => $missingBoundaries,
                'from' => $from->format(DATE_ATOM),
                'through' => $through->format(DATE_ATOM),
            ]);
        }

        if ($verified > 0 && ! $gapDetected) {
            try {
                $lifecycle?->reconcilePendingFunding('bybit', $symbol, $from);
            } catch (\Throwable $e) {
                Log::warning("reconcilePendingFunding failed for bybit:{$symbol}: ".$e->getMessage());
            }
        }

        return [
            'received' => count($rows),
            'inserted' => $inserted,
            'missing_mark_price' => $missingMarkPrice,
            'diagnostic' => $diagnostic,
            'funding_gap_detected' => $gapDetected,
            'missing_intervals' => $missingBoundaries,
            'from' => $from->format(DATE_ATOM),
            'through' => $through->format(DATE_ATOM),
        ];
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
