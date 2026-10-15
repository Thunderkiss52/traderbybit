<?php

declare(strict_types=1);

namespace App\MarketData\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class Candle
{
    public DateTimeImmutable $openTime;

    public function __construct(
        public string $exchange,
        public string $category,
        public string $symbol,
        public Interval $interval,
        int $openTimeMs,
        public string $open,
        public string $high,
        public string $low,
        public string $close,
        public string $volume,
        public string $turnover,
        public bool $isClosed,
    ) {
        if ($openTimeMs < 0) {
            throw new InvalidArgumentException('Candle open time must be a non-negative Unix timestamp.');
        }

        foreach (['open', 'high', 'low', 'close', 'volume', 'turnover'] as $field) {
            $value = $this->{$field};
            if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
                throw new InvalidArgumentException("Candle field [{$field}] is not a non-negative decimal string.");
            }
        }

        if (! preg_match('/^[A-Z0-9]+$/', $symbol)) {
            throw new InvalidArgumentException('Symbol must be uppercase alphanumeric.');
        }

        $seconds = intdiv($openTimeMs, 1000);
        $microseconds = ($openTimeMs % 1000) * 1000;
        $this->openTime = DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $seconds, $microseconds),
            new DateTimeZone('UTC'),
        )->setTimezone(new DateTimeZone('UTC'));
    }

    public function openTimeMs(): int
    {
        return ((int) $this->openTime->format('U')) * 1000 + intdiv((int) $this->openTime->format('u'), 1000);
    }

    /** @return array<string, mixed> */
    public function toPersistenceArray(): array
    {
        return [
            'exchange' => $this->exchange,
            'category' => $this->category,
            'symbol' => $this->symbol,
            'interval' => $this->interval->value,
            'open_time' => $this->openTime->format('Y-m-d H:i:s.vP'),
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
            'volume' => $this->volume,
            'turnover' => $this->turnover,
            'is_closed' => $this->isClosed,
        ];
    }
}
