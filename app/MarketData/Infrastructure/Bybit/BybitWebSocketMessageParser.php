<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Bybit;

use App\MarketData\Domain\Candle;
use App\MarketData\Domain\Interval;
use JsonException;
use UnexpectedValueException;

final class BybitWebSocketMessageParser
{
    public function parseClosedCandle(string $message): ?Candle
    {
        try {
            $payload = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Bybit WebSocket message is not valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new UnexpectedValueException('Bybit WebSocket message must be an object.');
        }
        if (isset($payload['op'])) {
            return null;
        }

        $topic = $payload['topic'] ?? null;
        $data = $payload['data'] ?? null;
        if (! is_string($topic) || ! preg_match('/^kline\.(5|15|30|60)\.([A-Z0-9]+)$/', $topic, $matches)) {
            return null;
        }
        if (! is_array($data) || ! array_is_list($data) || ! isset($data[0]) || ! is_array($data[0])) {
            throw new UnexpectedValueException('Bybit kline WebSocket data must contain a candle object.');
        }

        $row = $data[0];
        if (($row['confirm'] ?? null) !== true) {
            return null;
        }

        foreach (['start', 'open', 'high', 'low', 'close', 'volume', 'turnover'] as $field) {
            if (! array_key_exists($field, $row)) {
                throw new UnexpectedValueException("Bybit WebSocket candle misses [{$field}].");
            }
        }
        if ((! is_int($row['start']) && ! is_string($row['start'])) || ! ctype_digit((string) $row['start'])) {
            throw new UnexpectedValueException('Bybit WebSocket candle start must be Unix milliseconds.');
        }

        return new Candle(
            exchange: 'bybit',
            category: 'linear',
            symbol: $matches[2],
            interval: Interval::parse($matches[1]),
            openTimeMs: (int) $row['start'],
            open: $this->decimal($row['open'], 'open'),
            high: $this->decimal($row['high'], 'high'),
            low: $this->decimal($row['low'], 'low'),
            close: $this->decimal($row['close'], 'close'),
            volume: $this->decimal($row['volume'], 'volume'),
            turnover: $this->decimal($row['turnover'], 'turnover'),
            isClosed: true,
        );
    }

    private function decimal(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            throw new UnexpectedValueException("Bybit WebSocket candle [{$field}] must be a decimal string.");
        }

        return $value;
    }
}
