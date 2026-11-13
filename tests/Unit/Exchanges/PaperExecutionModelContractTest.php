<?php

declare(strict_types=1);

namespace Tests\Unit\Exchanges;

use App\Exchanges\Application\PaperExecutionModelContract;
use PHPUnit\Framework\TestCase;

final class PaperExecutionModelContractTest extends TestCase
{
    private function validCapabilities(): array
    {
        return ['has_candles' => true, 'price_precision' => 2, 'qty_precision' => 3,
            'min_qty' => '0.001', 'min_notional' => '5.0'];
    }

    private function validCosts(): array
    {
        return ['maker_rate' => '0.0002', 'taker_rate' => '0.00055', 'slippage_rate' => '0.0002'];
    }

    public function test_technical_model_does_not_require_prior_operator_approval(): void
    {
        self::assertTrue(PaperExecutionModelContract::supported(
            'linear_perpetual', $this->validCapabilities(), ['execution_model' => 'NEXT_CANDLE_OPEN'],
            $this->validCosts(), true
        ));
    }

    public function test_unimplemented_spot_inverse_and_missing_model_fail_closed(): void
    {
        foreach (['spot', 'inverse_perpetual', 'futures'] as $market) {
            self::assertFalse(PaperExecutionModelContract::supported(
                $market, $this->validCapabilities(), ['execution_model' => 'NEXT_CANDLE_OPEN'],
                $this->validCosts(), true
            ));
        }
        self::assertFalse(PaperExecutionModelContract::supported(
            'linear_perpetual', $this->validCapabilities(), [], $this->validCosts(), true
        ));
        self::assertFalse(PaperExecutionModelContract::supported(
            'linear_perpetual', $this->validCapabilities(), ['execution_model' => 'NEXT_CANDLE_OPEN'],
            $this->validCosts(), false
        ));
    }

    public function test_missing_or_invalid_instrument_constraints_and_costs_fail_closed(): void
    {
        foreach (['min_qty', 'min_notional', 'price_precision', 'qty_precision'] as $key) {
            $caps = $this->validCapabilities();
            unset($caps[$key]);
            self::assertFalse(PaperExecutionModelContract::supported(
                'linear_perpetual', $caps, ['execution_model' => 'NEXT_CANDLE_OPEN'], $this->validCosts(), true
            ), $key);
        }
        foreach (['maker_rate', 'taker_rate', 'slippage_rate'] as $key) {
            foreach (['-0.1', '0.1', 'NaN', 'INF'] as $rate) {
                $costs = $this->validCosts();
                $costs[$key] = $rate;
                self::assertFalse(PaperExecutionModelContract::supported(
                    'linear_perpetual', $this->validCapabilities(), ['execution_model' => 'NEXT_CANDLE_OPEN'], $costs, true
                ), $key.'='.$rate);
            }
        }
        $zeroFee = $this->validCosts();
        $zeroFee['taker_rate'] = '0';
        self::assertTrue(PaperExecutionModelContract::supported(
            'linear_perpetual', $this->validCapabilities(), ['execution_model' => 'NEXT_CANDLE_OPEN'], $zeroFee, true
        ));
    }
}
