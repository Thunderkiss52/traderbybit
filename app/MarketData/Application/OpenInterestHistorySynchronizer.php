<?php

declare(strict_types=1);

namespace App\MarketData\Application;

use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Infrastructure\Persistence\OpenInterestEventRecord;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;

final readonly class OpenInterestHistorySynchronizer
{
    public function __construct(private BybitMarketDataClient $marketData, private array $config) {}

    public function sync(string $symbol, ?DateTimeImmutable $through = null): array
    {
        $through ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cursor = null;
        $received = 0;
        $inserted = 0;
        $pages = 0;
        do {
            $result = $this->marketData->openInterest('linear', $symbol, (string) $this->config['interval'], $cursor);
            $rows = is_array($result['list'] ?? null) ? $result['list'] : [];
            $received += count($rows);
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_string($row['openInterest'] ?? null)
                    || ! is_string($row['timestamp'] ?? null) || ! ctype_digit($row['timestamp'])) {
                    continue;
                }
                BigDecimal::of($row['openInterest']);
                $observedAt = $this->fromMilliseconds((int) $row['timestamp']);
                if ($observedAt > $through) {
                    continue;
                }
                $inserted += OpenInterestEventRecord::query()->insertOrIgnore([[
                    'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
                    'interval' => (string) $this->config['interval'], 'observed_at' => $observedAt,
                    'open_interest' => $row['openInterest'], 'created_at' => now(), 'updated_at' => now(),
                ]]);
            }
            $pages++;
            $next = $result['nextPageCursor'] ?? null;
            $cursor = is_string($next) && $next !== '' && $next !== $cursor ? $next : null;
        } while ($cursor !== null && $pages < max(1, (int) $this->config['maximum_pages']));

        return compact('received', 'inserted', 'pages');
    }

    private function fromMilliseconds(int $milliseconds): DateTimeImmutable
    {
        $value = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($milliseconds, 1000), ($milliseconds % 1000) * 1000), new DateTimeZone('UTC'));
        if ($value === false) {
            throw new \RuntimeException('Invalid open-interest timestamp.');
        }

        return $value;
    }
}
