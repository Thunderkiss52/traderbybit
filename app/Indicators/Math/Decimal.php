<?php

declare(strict_types=1);

namespace App\Indicators\Math;

use App\Indicators\Exceptions\InvalidSeriesException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class Decimal
{
    public function __construct(
        private int $internalScale = 24,
        private int $outputScale = 8,
    ) {
        if ($internalScale < $outputScale || $outputScale < 0) {
            throw new InvalidSeriesException('Internal decimal scale must be greater than or equal to output scale.');
        }
    }

    public function of(string|int $value): BigDecimal
    {
        try {
            return $this->quantize(BigDecimal::of($value));
        } catch (\Throwable $exception) {
            throw new InvalidSeriesException("Invalid decimal value [{$value}].", previous: $exception);
        }
    }

    public function zero(): BigDecimal
    {
        return $this->quantize(BigDecimal::zero());
    }

    public function add(BigDecimal $left, BigDecimal $right): BigDecimal
    {
        return $this->quantize($left->plus($right));
    }

    public function subtract(BigDecimal $left, BigDecimal $right): BigDecimal
    {
        return $this->quantize($left->minus($right));
    }

    public function multiply(BigDecimal $left, BigDecimal|string|int $right): BigDecimal
    {
        return $this->quantize($left->multipliedBy($right));
    }

    public function divide(BigDecimal $left, BigDecimal|string|int $right): BigDecimal
    {
        if (BigDecimal::of($right)->isZero()) {
            throw new InvalidSeriesException('Division by zero in indicator calculation.');
        }

        return $left->dividedBy($right, $this->internalScale, RoundingMode::HalfEven);
    }

    /** @param list<BigDecimal> $values */
    public function sum(array $values): BigDecimal
    {
        $sum = $this->zero();
        foreach ($values as $value) {
            $sum = $this->add($sum, $value);
        }

        return $sum;
    }

    /** @param list<BigDecimal> $values */
    public function average(array $values): BigDecimal
    {
        if ($values === []) {
            throw new InvalidSeriesException('Cannot average an empty series.');
        }

        return $this->divide($this->sum($values), count($values));
    }

    public function sqrt(BigDecimal $value): BigDecimal
    {
        if ($value->isNegative()) {
            throw new InvalidSeriesException('Cannot calculate square root of a negative value.');
        }

        return $value->sqrt($this->internalScale, RoundingMode::HalfEven);
    }

    public function output(BigDecimal $value): string
    {
        return (string) $value->toScale($this->outputScale, RoundingMode::HalfEven);
    }

    public function quantize(BigDecimal $value): BigDecimal
    {
        return $value->toScale($this->internalScale, RoundingMode::HalfEven);
    }
}
