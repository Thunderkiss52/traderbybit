<?php

declare(strict_types=1);

namespace App\Trading\Application;

/** Normal/retry queue routes must never diverge or borrow Bybit's dedicated queue. */
final class TradingQueueResolver
{
    public static function forExchange(string $exchange): string
    {
        $exchange = strtolower(trim($exchange));
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/D', $exchange)) {
            throw new \InvalidArgumentException('Invalid trading queue exchange identity.');
        }
        $configured = config("trading.queues.{$exchange}");
        if (is_string($configured) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $configured)) {
            return $configured;
        }

        // Only Bybit/Binance have dedicated workers. New exchanges use the
        // shared worker queue until an explicitly configured worker exists.
        return 'default';
    }
}
