<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\Exchanges\ExchangeRegistry;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
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

    /** @return array{estimated_costs: string, cost_drag_percent: float|null} */
    public function estimateRoundTripTp1Costs(string $entryPrice, string $targetPrice, string $quantity, string $exchangeId = 'bybit'): array
    {
        $entry = $this->d($entryPrice);
        $target = $this->d($targetPrice);
        $qty = $this->d($quantity);
        if (! $entry->isPositive() || ! $target->isPositive() || ! $qty->isPositive()) {
            throw new \DomainException('Positive prices and quantity required for PAPER cost evidence.');
        }
        $rates = $this->costs($exchangeId);
        $rate = $this->d($rates['taker_rate'])->plus($rates['slippage_rate']);
        $cost = $entry->plus($target)->multipliedBy($qty)->multipliedBy($rate);
        $gross = $target->minus($entry)->abs()->multipliedBy($qty);

        return [
            'estimated_costs' => (string) $cost,
            'cost_drag_percent' => $gross->isPositive()
                ? $cost->dividedBy($gross, 8, RoundingMode::HalfEven)->multipliedBy(100)->toFloat()
                : null,
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
        $registered = app(ExchangeRegistry::class)->registeredIds();
        if (! in_array($exchangeId, $registered, true)) {
            throw new \DomainException("Unknown paper exchange '{$exchangeId}'.");
        }
        // 1. Direct config override (e.g. exchanges.binance.paper_taker_rate)
        $value = config('exchanges.'.$exchangeId.'.'.$key);
        if (is_string($value) && preg_match('/^(?:0|[0-9]+)(?:\.[0-9]+)?$/D', $value)
            && BigDecimal::of($value)->isGreaterThanOrEqualTo('0')
            && BigDecimal::of($value)->isLessThan('0.1')) {
            return $value;
        }

        // 2. Exchange cost model from config or persistent database record
        $shortKey = match ($key) {
            'paper_taker_rate' => 'taker_rate',
            'paper_slippage_rate' => 'slippage_rate',
            default => $key,
        };
        $model = config('exchanges.'.$exchangeId.'.cost_model')
            ?? ExchangeRecord::query()->find($exchangeId)?->cost_model;
        if (is_array($model) && isset($model[$shortKey]) && is_string($model[$shortKey])
            && preg_match('/^(?:0|[0-9]+)(?:\.[0-9]+)?$/D', $model[$shortKey])
            && BigDecimal::of($model[$shortKey])->isGreaterThanOrEqualTo('0')
            && BigDecimal::of($model[$shortKey])->isLessThan('0.1')) {
            return $model[$shortKey];
        }

        // Strictly prohibit Bybit rates fallback for other exchanges
        throw new \DomainException("Exchange-specific cost rate missing for '{$exchangeId}': {$key}. Fallback to Bybit rates is strictly prohibited.");
    }

    private function d(string $value): BigDecimal
    {
        return BigDecimal::of($value);
    }
}
