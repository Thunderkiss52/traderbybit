<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use DomainException;

/**
 * Fail-closed, byte-independent comparison of authoritative decimal market
 * values. Prevents insertOrIgnore from disguising a conflicting source event.
 * Database columns are DECIMAL(..., 12): do not silently round finer inputs.
 */
final class BinanceSourceIdentity
{
    private const CANDLE_COLUMNS = ['open', 'high', 'low', 'close', 'volume', 'turnover'];

    public static function assertSameCandle(object $stored, array $incoming): void
    {
        foreach (self::CANDLE_COLUMNS as $column) {
            self::sameDecimal($stored->{$column}, $incoming[$column], $column);
        }
        if (! (bool) $stored->is_closed) {
            throw new DomainException('Conflicting existing Binance candle closed state.');
        }
    }

    public static function assertSameFunding(object $stored, array $incoming): void
    {
        self::sameDecimal($stored->rate, $incoming['rate'], 'funding rate');
        self::sameDecimal($stored->mark_price, $incoming['mark_price'], 'funding mark price');
    }

    public static function assertPersistable(array $values): void
    {
        foreach ($values as $name => $number) {
            self::canonical($number, (string) $name);
        }
    }

    private static function sameDecimal(mixed $stored, mixed $incoming, string $label): void
    {
        if (self::canonical($stored, $label) !== self::canonical($incoming, $label)) {
            throw new DomainException('Conflicting immutable Binance '.$label.' observation.');
        }
    }

    private static function canonical(mixed $value, string $label): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new DomainException('Malformed Binance '.$label.' decimal.');
        }
        $number = (string) $value;
        if (! preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $number)) {
            throw new DomainException('Malformed Binance '.$label.' decimal.');
        }
        [$integer, $fraction] = array_pad(explode('.', $number, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) > 12) {
            throw new DomainException('Binance '.$label.' exceeds exact database decimal scale.');
        }
        $negative = str_starts_with($integer, '-');
        $integer = ltrim(ltrim($integer, '-'), '0');
        $integer = $integer === '' ? '0' : $integer;
        if ($integer === '0' && $fraction === '') {
            $negative = false;
        }

        return ($negative ? '-' : '').$integer.($fraction === '' ? '' : '.'.$fraction);
    }
}
