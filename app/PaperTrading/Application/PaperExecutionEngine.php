<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Domain\ExecutionQuote;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\MarketBar;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

final readonly class PaperExecutionEngine
{
    public function __construct(private array $config) {}

    public function entry(string $side, string $entryLow, string $entryHigh, string $stop, string $tp1, string $tp2, DateTimeImmutable $signalAt, DateTimeImmutable $expiresAt, MarketBar $bar, string $exchangeId = 'bybit'): ?ExecutionQuote
    {
        if (! $bar->isClosed || $bar->closeTime <= $signalAt || $bar->closeTime > $expiresAt) {
            return null;
        }
        $quote = $side === 'BUY' ? $bar->ask : $bar->bid;
        if ($quote !== null) {
            if ($this->d($quote)->isLessThan($this->d($entryLow)) || $this->d($quote)->isGreaterThan($this->d($entryHigh))) {
                return null;
            }
            $reference = $quote;
            $source = $side === 'BUY' ? 'historical_ask' : 'historical_bid';
        } else {
            if ($this->d($bar->low)->isGreaterThan($this->d($entryHigh)) || $this->d($bar->high)->isLessThan($this->d($entryLow))) {
                return null;
            }
            $reference = $side === 'BUY' ? $entryHigh : $entryLow;
            $source = $bar->source;
        }
        $price = $this->withSlippage($reference, $side === 'BUY', $exchangeId);
        $stopHit = $side === 'BUY' ? $this->d($bar->low)->isLessThanOrEqualTo($this->d($stop)) : $this->d($bar->high)->isGreaterThanOrEqualTo($this->d($stop));
        $targetHit = $side === 'BUY' ? $this->d($bar->high)->isGreaterThanOrEqualTo($this->d($tp1)) : $this->d($bar->low)->isLessThanOrEqualTo($this->d($tp1));

        return new ExecutionQuote(ExecutionType::Entry, (string) $price, $reference, (string) $price->minus($reference)->abs(), $source, $stopHit || $targetHit);
    }

    public function exit(string $side, string $status, string $stop, string $tp1, string $tp2, DateTimeImmutable $holdingUntil, MarketBar $bar, string $exchangeId = 'bybit'): ?ExecutionQuote
    {
        if (! $bar->isClosed) {
            return null;
        }
        $sl = $side === 'BUY' ? $this->d($bar->low)->isLessThanOrEqualTo($this->d($stop)) : $this->d($bar->high)->isGreaterThanOrEqualTo($this->d($stop));
        $one = $side === 'BUY' ? $this->d($bar->high)->isGreaterThanOrEqualTo($this->d($tp1)) : $this->d($bar->low)->isLessThanOrEqualTo($this->d($tp1));
        $two = $side === 'BUY' ? $this->d($bar->high)->isGreaterThanOrEqualTo($this->d($tp2)) : $this->d($bar->low)->isLessThanOrEqualTo($this->d($tp2));
        $ambiguous = ($sl && ($one || $two)) || ($one && $two);
        if ($sl) {
            return $this->exitQuote(ExecutionType::StopLoss, $side, $stop, $bar->source, $ambiguous, $exchangeId);
        }
        if ($status === 'OPEN' && $one) {
            return $this->exitQuote(ExecutionType::TakeProfit1, $side, $tp1, $bar->source, $ambiguous, $exchangeId);
        }
        if ($status === 'PARTIALLY_CLOSED' && $two) {
            return $this->exitQuote(ExecutionType::TakeProfit2, $side, $tp2, $bar->source, $ambiguous, $exchangeId);
        }
        if ($bar->closeTime >= $holdingUntil) {
            $reference = $side === 'BUY' ? ($bar->bid ?? $bar->close) : ($bar->ask ?? $bar->close);

            return $this->exitQuote(ExecutionType::Timeout, $side, $reference, $bar->bid !== null && $bar->ask !== null ? 'historical_quote' : $bar->source, false, $exchangeId);
        }

        return null;
    }

    public function commission(string $quantity, string $price, string $exchangeId = 'bybit'): string
    {
        return (string) $this->d($quantity)->multipliedBy($price)->multipliedBy((string) $this->cost($exchangeId, 'paper_taker_rate', (string) $this->config['fees']['taker_rate']));
    }

    /** The same validated exchange rates must drive sizing and virtual fills. */
    public function costs(string $exchangeId = 'bybit'): array
    {
        return [
            'taker_rate' => $this->cost($exchangeId, 'paper_taker_rate', (string) $this->config['fees']['taker_rate']),
            'slippage_rate' => $this->cost($exchangeId, 'paper_slippage_rate', (string) $this->config['slippage_rate']),
        ];
    }

    public function grossPnl(string $side, string $entryPrice, string $exitPrice, string $quantity): string
    {
        $move = $side === 'BUY' ? $this->d($exitPrice)->minus($entryPrice) : $this->d($entryPrice)->minus($exitPrice);

        return (string) $move->multipliedBy($quantity);
    }

    public function funding(string $side, string $quantity, string $markPrice, string $rate): string
    {
        $amount = $this->d($quantity)->multipliedBy($markPrice)->multipliedBy($rate);

        return (string) ($side === 'BUY' ? $amount->negated() : $amount);
    }

    public function riskClosure(string $side, string $reference, string $source, string $exchangeId = 'bybit'): ExecutionQuote
    {
        return $this->exitQuote(ExecutionType::RiskClosure, $side, $reference, $source, false, $exchangeId);
    }

    private function exitQuote(ExecutionType $type, string $positionSide, string $reference, string $source, bool $ambiguous, string $exchangeId): ExecutionQuote
    {
        $price = $this->withSlippage($reference, $positionSide === 'SELL', $exchangeId);

        return new ExecutionQuote($type, (string) $price, $reference, (string) $price->minus($reference)->abs(), $source, $ambiguous);
    }

    private function withSlippage(string $price, bool $increase, string $exchangeId): BigDecimal
    {
        $amount = $this->d($price)->multipliedBy((string) $this->cost($exchangeId, 'paper_slippage_rate', (string) $this->config['slippage_rate']));

        return ($increase ? $this->d($price)->plus($amount) : $this->d($price)->minus($amount))->toScale(12, RoundingMode::HalfEven);
    }

    private function cost(string $exchangeId, string $key, string $bybitValue): string
    {
        if ($exchangeId === 'bybit') {
            return $bybitValue;
        }
        if ($exchangeId !== 'binance') {
            throw new \DomainException('Unknown paper exchange.');
        }
        $value = config('exchanges.binance.'.$key);
        if (! is_string($value) || ! preg_match('/^(?:0|[0-9]+)(?:\.[0-9]+)?$/D', $value)
            || BigDecimal::of($value)->isLessThanOrEqualTo('0')) {
            throw new \DomainException('Binance execution cost model not approved.');
        }

        return $value;
    }

    private function d(string $value): BigDecimal
    {
        return BigDecimal::of($value);
    }
}
