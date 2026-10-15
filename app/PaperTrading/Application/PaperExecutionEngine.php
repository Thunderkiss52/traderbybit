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

    public function entry(string $side, string $entryLow, string $entryHigh, string $stop, string $tp1, string $tp2, DateTimeImmutable $signalAt, DateTimeImmutable $expiresAt, MarketBar $bar): ?ExecutionQuote
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
        $price = $this->withSlippage($reference, $side === 'BUY');
        $stopHit = $side === 'BUY' ? $this->d($bar->low)->isLessThanOrEqualTo($this->d($stop)) : $this->d($bar->high)->isGreaterThanOrEqualTo($this->d($stop));
        $targetHit = $side === 'BUY' ? $this->d($bar->high)->isGreaterThanOrEqualTo($this->d($tp1)) : $this->d($bar->low)->isLessThanOrEqualTo($this->d($tp1));

        return new ExecutionQuote(ExecutionType::Entry, (string) $price, $reference, (string) $price->minus($reference)->abs(), $source, $stopHit || $targetHit);
    }

    public function exit(string $side, string $status, string $stop, string $tp1, string $tp2, DateTimeImmutable $holdingUntil, MarketBar $bar): ?ExecutionQuote
    {
        if (! $bar->isClosed) {
            return null;
        }
        $sl = $side === 'BUY' ? $this->d($bar->low)->isLessThanOrEqualTo($this->d($stop)) : $this->d($bar->high)->isGreaterThanOrEqualTo($this->d($stop));
        $one = $side === 'BUY' ? $this->d($bar->high)->isGreaterThanOrEqualTo($this->d($tp1)) : $this->d($bar->low)->isLessThanOrEqualTo($this->d($tp1));
        $two = $side === 'BUY' ? $this->d($bar->high)->isGreaterThanOrEqualTo($this->d($tp2)) : $this->d($bar->low)->isLessThanOrEqualTo($this->d($tp2));
        $ambiguous = ($sl && ($one || $two)) || ($one && $two);
        if ($sl) {
            return $this->exitQuote(ExecutionType::StopLoss, $side, $stop, $bar->source, $ambiguous);
        }
        if ($status === 'OPEN' && $one) {
            return $this->exitQuote(ExecutionType::TakeProfit1, $side, $tp1, $bar->source, $ambiguous);
        }
        if ($status === 'PARTIALLY_CLOSED' && $two) {
            return $this->exitQuote(ExecutionType::TakeProfit2, $side, $tp2, $bar->source, $ambiguous);
        }
        if ($bar->closeTime >= $holdingUntil) {
            $reference = $side === 'BUY' ? ($bar->bid ?? $bar->close) : ($bar->ask ?? $bar->close);

            return $this->exitQuote(ExecutionType::Timeout, $side, $reference, $bar->bid !== null && $bar->ask !== null ? 'historical_quote' : $bar->source, false);
        }

        return null;
    }

    public function commission(string $quantity, string $price): string
    {
        return (string) $this->d($quantity)->multipliedBy($price)->multipliedBy((string) $this->config['fees']['taker_rate']);
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

    public function riskClosure(string $side, string $reference, string $source): ExecutionQuote
    {
        return $this->exitQuote(ExecutionType::RiskClosure, $side, $reference, $source, false);
    }

    private function exitQuote(ExecutionType $type, string $positionSide, string $reference, string $source, bool $ambiguous): ExecutionQuote
    {
        $price = $this->withSlippage($reference, $positionSide === 'SELL');

        return new ExecutionQuote($type, (string) $price, $reference, (string) $price->minus($reference)->abs(), $source, $ambiguous);
    }

    private function withSlippage(string $price, bool $increase): BigDecimal
    {
        $amount = $this->d($price)->multipliedBy((string) $this->config['slippage_rate']);

        return ($increase ? $this->d($price)->plus($amount) : $this->d($price)->minus($amount))->toScale(12, RoundingMode::HalfEven);
    }

    private function d(string $value): BigDecimal
    {
        return BigDecimal::of($value);
    }
}
