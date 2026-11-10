<?php

declare(strict_types=1);

namespace App\Exchanges\Domain;

use App\Exchanges\Contracts\ExchangeAdapter;
use App\HistoricalData\UniversalAcquisitionService;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Strict validator ensuring that an exchange manifest adheres to the VECTOR capability contract.
 */
final class ExchangeManifestValidator
{
    /**
     * Validate an exchange manifest. Throws InvalidArgumentException with specific reasons if invalid.
     *
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(ExchangeManifest $manifest): array
    {
        $errors = [];

        // 1. Exchange ID
        if (! preg_match('/^[a-z0-9_-]{2,32}$/D', $manifest->id)) {
            $errors[] = "Invalid exchange identifier '{$manifest->id}'. Must be 2-32 characters of lowercase alphanumeric, dashes, or underscores.";
        }

        // 2. Name
        if (trim($manifest->name) === '') {
            $errors[] = 'Exchange name cannot be empty.';
        }

        // 3. Market Type
        if (! in_array($manifest->marketType, ['linear_perpetual', 'spot', 'inverse_perpetual'], true)) {
            $errors[] = "Unsupported market type '{$manifest->marketType}'. Must be linear_perpetual, spot, or inverse_perpetual.";
        }

        // 4. Timeframes
        $normalizedTimeframes = array_map(
            UniversalAcquisitionService::normalizeInterval(...),
            $manifest->supportedTimeframes
        );
        $requiredTimeframes = ['5', '15', '30', '60'];
        $missingTf = array_diff($requiredTimeframes, $normalizedTimeframes);
        if (! empty($missingTf)) {
            $errors[] = 'Manifest must declare support for 5m, 15m, 30m, and 1h intervals (missing: '.implode(', ', $missingTf).').';
        }

        // 5. Cost Model
        $cost = $manifest->costModel;
        if (! isset($cost['taker_rate']) || ! $this->validRate($cost['taker_rate'])) {
            $errors[] = 'Manifest must specify a valid cost_model.taker_rate as a decimal fraction between 0.0000 and 0.1000.';
        }
        if (! isset($cost['slippage_rate']) || ! $this->validRate($cost['slippage_rate'])) {
            $errors[] = 'Manifest must specify a valid cost_model.slippage_rate as a decimal fraction between 0.0000 and 0.1000.';
        }

        // 6. Precisions & Limits
        if ($manifest->pricePrecision !== null && $manifest->pricePrecision < 0) {
            $errors[] = 'price_precision cannot be negative.';
        }
        if ($manifest->qtyPrecision !== null && $manifest->qtyPrecision < 0) {
            $errors[] = 'qty_precision cannot be negative.';
        }
        if ($manifest->minQty !== null && (! is_numeric($manifest->minQty) || BigDecimal::of($manifest->minQty)->isLessThanOrEqualTo(0))) {
            $errors[] = 'min_qty must be a positive numeric value.';
        }
        if ($manifest->minNotional !== null && (! is_numeric($manifest->minNotional) || BigDecimal::of($manifest->minNotional)->isLessThanOrEqualTo(0))) {
            $errors[] = 'min_notional must be a positive numeric value.';
        }

        // 7. Base URL & Adapter Class
        if ($manifest->baseUrl !== null && ! filter_var($manifest->baseUrl, FILTER_VALIDATE_URL)) {
            $errors[] = "Invalid base_url '{$manifest->baseUrl}'.";
        }
        if ($manifest->adapterClass !== null) {
            if (! class_exists($manifest->adapterClass)) {
                $errors[] = "Specified adapter class '{$manifest->adapterClass}' does not exist.";
            } elseif (! is_subclass_of($manifest->adapterClass, ExchangeAdapter::class)) {
                $errors[] = "Specified adapter class '{$manifest->adapterClass}' must implement ".ExchangeAdapter::class.'.';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate and throw if invalid.
     */
    public function assertValid(ExchangeManifest $manifest): void
    {
        $result = $this->validate($manifest);
        if (! $result['valid']) {
            throw new InvalidArgumentException('Exchange manifest validation failed: '.implode('; ', $result['errors']));
        }
    }

    private function validRate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^0(?:\.[0-9]+)?$/D', $value) === 1
            && BigDecimal::of($value)->isGreaterThanOrEqualTo('0')
            && BigDecimal::of($value)->isLessThan('0.1');
    }
}
