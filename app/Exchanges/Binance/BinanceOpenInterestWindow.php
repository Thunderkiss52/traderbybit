<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DomainException;

/** Only published, contiguous USD-M OI samples may inform a decision. */
final class BinanceOpenInterestWindow
{
    public static function derive(array $rows, string $symbol, DateTimeImmutable $knownAt, int $maxLagSeconds = 900): array
    {
        if ($maxLagSeconds < 300 || $maxLagSeconds > 3600) {
            throw new DomainException('Invalid Binance OI freshness policy.');
        }
        $eligible = [];
        $previousTimestamp = null;
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['exchange_id'] ?? null) !== 'binance'
                || ($row['symbol'] ?? null) !== $symbol
                || ! is_string($row['timestamp_utc'] ?? null)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/D', $row['timestamp_utc'])
                || ! is_string($row['open_interest'] ?? null)
                || ! is_string($row['raw_source_hash'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', $row['raw_source_hash'])) {
                throw new DomainException('Binance OI source provenance is invalid.');
            }
            try {
                $timestamp = (new DateTimeImmutable($row['timestamp_utc']))->getTimestamp();
                $quantity = BigDecimal::of($row['open_interest']);
            } catch (\Throwable) {
                throw new DomainException('Binance OI timestamp or quantity is malformed.');
            }
            if ($quantity->isNegative()) {
                throw new DomainException('Binance OI quantity must be nonnegative.');
            }
            if ($previousTimestamp !== null && $timestamp <= $previousTimestamp) {
                throw new DomainException('Binance OI samples are not strictly increasing.');
            }
            $previousTimestamp = $timestamp;
            // Future samples must never contribute to a decision-time feature.
            if ($timestamp <= $knownAt->getTimestamp()) {
                $eligible[] = ['timestamp' => $timestamp, 'quantity' => $quantity,
                    'hash' => $row['raw_source_hash']];
            }
        }
        if ($eligible === []) {
            return self::unavailable('NO_KNOWN_AT_SAMPLE');
        }
        $latest = $eligible[count($eligible) - 1];
        if ($knownAt->getTimestamp() - $latest['timestamp'] > $maxLagSeconds) {
            return self::unavailable('STALE_OPEN_INTEREST');
        }
        if (count($eligible) < 2) {
            return self::unavailable('INSUFFICIENT_OPEN_INTEREST_HISTORY');
        }
        $prior = $eligible[count($eligible) - 2];
        if ($latest['timestamp'] - $prior['timestamp'] !== 300) {
            return self::unavailable('GAPPED_OPEN_INTEREST_HISTORY');
        }
        if ($prior['quantity']->isZero()) {
            return self::unavailable('ZERO_OPEN_INTEREST_DENOMINATOR');
        }

        return [
            'status' => 'VERIFIED_SOURCE_WINDOW',
            'open_interest' => (string) $latest['quantity'],
            'open_interest_change' => (string) $latest['quantity']->minus($prior['quantity'])
                ->dividedBy($prior['quantity'], 12, RoundingMode::HalfEven),
            'source_timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z', $latest['timestamp']),
            'raw_source_hash' => $latest['hash'],
        ];
    }

    private static function unavailable(string $reason): array
    {
        return ['status' => $reason, 'open_interest' => null, 'open_interest_change' => null,
            'source_timestamp_utc' => null, 'raw_source_hash' => null];
    }
}
