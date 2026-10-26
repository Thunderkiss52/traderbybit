<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\MarketData\Domain\Candle;
use App\MarketData\Domain\Interval;
use JsonException;
use UnexpectedValueException;

final class BinanceWebSocketMessageParser
{
    /**
     * Parse closed kline from Binance USD-M WebSocket message (single stream or combined stream).
     */
    public function parseClosedCandle(string $message): ?Candle
    {
        try {
            $payload = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Binance WebSocket message is not valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new UnexpectedValueException('Binance WebSocket message must be an object.');
        }

        // Handle combined stream wrapper: {"stream":"...","data":{...}}
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        if (($payload['e'] ?? null) !== 'kline' || ! isset($payload['k']) || ! is_array($payload['k'])) {
            return null;
        }

        $kline = $payload['k'];
        // "x": true indicates the candle has closed.
        if (($kline['x'] ?? false) !== true) {
            return null;
        }

        $symbol = strtoupper((string) ($kline['s'] ?? ''));
        $intervalStr = (string) ($kline['i'] ?? '');
        $intervalMap = ['5m' => '5', '15m' => '15', '30m' => '30', '1h' => '60', '4h' => '240'];

        if (! isset($intervalMap[$intervalStr])) {
            return null;
        }

        $openTimeMs = (int) ($kline['t'] ?? 0);
        if ($openTimeMs <= 0) {
            throw new UnexpectedValueException('Binance WebSocket candle start must be positive Unix milliseconds.');
        }

        return new Candle(
            exchange: 'binance',
            category: 'linear',
            symbol: $symbol,
            interval: Interval::parse($intervalMap[$intervalStr]),
            openTimeMs: $openTimeMs,
            open: $this->decimal($kline['o'] ?? null, 'open'),
            high: $this->decimal($kline['h'] ?? null, 'high'),
            low: $this->decimal($kline['l'] ?? null, 'low'),
            close: $this->decimal($kline['c'] ?? null, 'close'),
            volume: $this->decimal($kline['v'] ?? null, 'volume'),
            turnover: $this->decimal($kline['q'] ?? null, 'turnover'),
            isClosed: true,
        );
    }

    private function decimal(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            throw new UnexpectedValueException("Binance WebSocket candle [{$field}] must be a decimal string.");
        }

        return $value;
    }
}
