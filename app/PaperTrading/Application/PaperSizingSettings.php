<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use Brick\Math\BigDecimal;
use DomainException;

/** Persisted, versioned per-account sizing selection. Never overrides risk gates. */
final class PaperSizingSettings
{
    public const DEFAULT_SETTINGS = [
        'mode' => 'fixed_notional',
        'amount' => '5',
    ];

    public static function defaultSettings(): array
    {
        return self::DEFAULT_SETTINGS;
    }

    public static function effectiveForAccount(?array $settings): array
    {
        if ($settings === null) {
            return self::DEFAULT_SETTINGS;
        }

        return self::normalize($settings) ?? self::DEFAULT_SETTINGS;
    }

    public static function normalize(?array $settings): ?array
    {
        if ($settings === null) {
            return null; // Legacy risk-percent behavior.
        }
        $mode = $settings['mode'] ?? null;
        if ($mode === 'risk_percent' && array_keys($settings) === ['mode']) {
            return ['mode' => 'risk_percent'];
        }
        if (! in_array($mode, ['fixed_notional', 'fixed_margin'], true)) {
            throw new DomainException('Unsupported PAPER sizing mode.');
        }
        $expected = $mode === 'fixed_margin' ? ['mode', 'amount', 'leverage'] : ['mode', 'amount'];
        $keys = array_keys($settings);
        sort($expected);
        sort($keys);
        if ($expected !== $keys) {
            throw new DomainException('Unexpected PAPER sizing settings.');
        }
        $amount = $settings['amount'];
        if (! is_string($amount) || ! preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,12})?$/D', $amount)
            || BigDecimal::of($amount)->isLessThanOrEqualTo('0')) {
            throw new DomainException('Invalid fixed PAPER position amount.');
        }
        $result = ['mode' => $mode, 'amount' => (string) BigDecimal::of($amount)];
        if ($mode === 'fixed_margin') {
            $leverage = $settings['leverage'];
            if (! is_string($leverage) || ! preg_match('/^[1-9][0-9]?(?:\.[0-9]{1,2})?$/D', $leverage)
                || BigDecimal::of($leverage)->isLessThan('1') || BigDecimal::of($leverage)->isGreaterThan('2')) {
                // Existing hard maximum_model_leverage is 2x. Do not silently relax it.
                throw new DomainException('PAPER leverage requires verified 1x–2x setting.');
            }
            $result['leverage'] = (string) BigDecimal::of($leverage);
        }

        return $result;
    }
}
