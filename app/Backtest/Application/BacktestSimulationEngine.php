<?php

declare(strict_types=1);

namespace App\Backtest\Application;

use App\Backtest\Contracts\HistoricalSignalEvaluator;
use App\Backtest\Domain\Segment;
use App\Backtest\Domain\SimulationResult;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\CorrelationService;
use App\PaperTrading\Application\PaperExecutionEngine;
use App\PaperTrading\Application\PositionSizingService;
use App\PaperTrading\Application\RiskEngine;
use App\PaperTrading\Domain\InstrumentConstraints;
use App\PaperTrading\Domain\MarketBar;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

final readonly class BacktestSimulationEngine
{
    public function __construct(
        private HistoricalSignalEvaluator $signals,
        private RiskEngine $risk,
        private CorrelationService $correlation,
        private array $paperConfig,
    ) {}

    public function simulate(array $symbols, Segment $segment, array $strategyConfig, array $executionConfig, string $startingBalance, string $exchange = 'bybit'): SimulationResult
    {
        if (! in_array($exchange, ['bybit', 'binance'], true)) {
            throw new \InvalidArgumentException('Unsupported backtest exchange.');
        }
        $strategyConfig['_exchange'] = $exchange;
        $execution = new PaperExecutionEngine($executionConfig);
        $sizing = new PositionSizingService($executionConfig);
        $rows = CandleRecord::query()->where('exchange', $exchange)->where('category', 'linear')->whereIn('symbol', $symbols)
            ->where('interval', '5')->where('is_closed', true)->where('open_time', '>=', $segment->from)->where('open_time', '<', $segment->to)
            ->orderBy('open_time')->orderBy('symbol')->get();
        $byClose = [];
        foreach ($rows as $row) {
            $closeAt = $row->open_time->toDateTimeImmutable()->modify('+5 minutes');
            $byClose[$closeAt->format('U')][(string) $row->symbol] = $row;
        }
        ksort($byClose, SORT_NUMERIC);

        $equity = $this->d($startingBalance);
        $peak = $equity;
        $positions = [];
        $trades = [];
        $snapshots = [];
        $decisions = [];
        $warnings = [];
        $lastBars = [];
        $cooldownUntil = null;
        $lossStreak = 0;

        foreach ($byClose as $timestamp => $bars) {
            $at = (new DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('UTC'));
            foreach ($bars as $symbol => $row) {
                $bar = $this->bar($row, $at, $exchange);
                $lastBars[$symbol] = $bar;
                foreach ($positions as $key => &$position) {
                    if ($position['symbol'] !== $symbol || ! in_array($position['status'], ['PENDING', 'OPEN', 'PARTIALLY_CLOSED'], true)) {
                        continue;
                    }
                    if ($position['status'] === 'PENDING') {
                        if ($at > $position['expires_at']) {
                            $position['status'] = 'EXPIRED';

                            continue;
                        }
                        $quote = $execution->entry($position['side'], $position['entry_low'], $position['entry_high'], $position['stop'], $position['tp1'], $position['tp2'], $position['signal_at'], $position['expires_at'], $bar);
                        if ($quote === null) {
                            continue;
                        }
                        $position['status'] = 'OPEN';
                        $position['opened_at'] = $at;
                        $position['holding_until'] = $at->modify('+'.$position['holding_minutes'].' minutes');
                        $position['entry_price'] = $quote->price;
                        $position['ambiguous'] = $quote->ambiguous;
                        $entryFee = $execution->commission($position['quantity'], $quote->price);
                        $position['fees'] = $entryFee;
                        $position['executions'][] = $this->execution('ENTRY', $position['quantity'], $quote, $at, '0', $entryFee);
                        $equity = $equity->minus($entryFee);

                        continue;
                    }
                    $this->applyFunding($position, $at, $execution, $equity, $exchange);
                    $quote = $execution->exit($position['side'], $position['status'], $position['stop'], $position['tp1'], $position['tp2'], $position['holding_until'], $bar);
                    if ($quote === null) {
                        continue;
                    }
                    $quantity = $quote->type->value === 'TAKE_PROFIT_1'
                        ? $this->d($position['quantity'])->multipliedBy((string) $executionConfig['tp1_fraction'])->toScale(12, RoundingMode::Floor)
                        : $this->d($position['remaining']);
                    $gross = $execution->grossPnl($position['side'], $position['entry_price'], $quote->price, (string) $quantity);
                    $fee = $execution->commission((string) $quantity, $quote->price);
                    $position['gross'] = (string) $this->d($position['gross'])->plus($gross);
                    $position['fees'] = (string) $this->d($position['fees'])->plus($fee);
                    $position['remaining'] = (string) $this->d($position['remaining'])->minus($quantity);
                    $position['ambiguous'] = $position['ambiguous'] || $quote->ambiguous;
                    $position['executions'][] = $this->execution($quote->type->value, (string) $quantity, $quote, $at, $gross, $fee);
                    $equity = $equity->plus($gross)->minus($fee);
                    if ($quote->type->value === 'TAKE_PROFIT_1') {
                        $position['status'] = 'PARTIALLY_CLOSED';

                        continue;
                    }
                    $this->closePosition($position, $quote->type->value, $quote->price, $at, $trades);
                    if ($this->d($position['net'])->isNegative()) {
                        $lossStreak++;
                        if ($lossStreak >= (int) $this->paperConfig['losing_streak']['count']) {
                            $cooldownUntil = $at->modify('+'.(int) $this->paperConfig['losing_streak']['cooldown_minutes'].' minutes');
                        }
                    } else {
                        $lossStreak = 0;
                    }
                }
                unset($position);
            }

            if ((int) $at->format('i') % 15 === 0) {
                foreach ($symbols as $symbol) {
                    $decision = $this->signals->evaluate($symbol, $at, $strategyConfig);
                    $decisions[] = ['symbol' => $symbol, 'at' => $at->format(DATE_ATOM), 'side' => $decision->side->value, 'reasons' => $decision->reasons];
                    if (in_array($strategyConfig['_research_strategy_id'] ?? null, (array) config('research.forward_paper.signal_only_strategies', []), true)) {
                        $warnings[] = 'signal_only_strategy_execution_disabled';

                        continue;
                    }
                    if ($decision->side === SignalSide::Skip || $decision->pricePlan === null || $decision->qualityClass === null || $decision->modelRiskPercent === null) {
                        continue;
                    }
                    $context = MarketContextRecord::query()->where(['exchange' => $exchange, 'category' => 'linear', 'symbol' => $symbol])->where('observed_at', '<=', $at)->latest('observed_at')->first();
                    if ($context === null || in_array(null, [$context->qty_step, $context->min_order_qty, $context->max_order_qty, $context->min_notional_value], true)) {
                        $warnings[] = "missing_execution_metadata:{$symbol}:{$at->format(DATE_ATOM)}";

                        continue;
                    }
                    $entry = $decision->side === SignalSide::Buy ? $decision->pricePlan->entryHigh : $decision->pricePlan->entryLow;
                    $size = $sizing->calculate((string) $equity, $decision->modelRiskPercent, $entry, $decision->pricePlan->stopLoss, new InstrumentConstraints($context->qty_step, $context->min_order_qty, $context->max_order_qty, $context->min_notional_value));
                    if (! $size->allowed || $size->quantity === null) {
                        $warnings[] = 'position_size_rejected:'.($size->reason ?? 'unknown');

                        continue;
                    }
                    $open = array_values(array_filter($positions, fn (array $p): bool => in_array($p['status'], ['OPEN', 'PARTIALLY_CLOSED'], true)));
                    $sameDirection = array_values(array_filter($open, fn (array $p): bool => $p['side'] === $decision->side->value && $p['symbol'] !== $symbol));
                    $correlation = $sameDirection === [] ? null : $this->correlation->between($sameDirection[0]['symbol'], $symbol, $at, $exchange);
                    $totalRisk = BigDecimal::zero();
                    $notional = BigDecimal::zero();
                    foreach ($open as $item) {
                        $totalRisk = $totalRisk->plus($item['risk_percent']);
                        $notional = $notional->plus($item['notional']);
                    }
                    $drawdown = $peak->isZero() ? BigDecimal::zero() : $peak->minus($equity)->dividedBy($peak, 24, RoundingMode::HalfEven)->multipliedBy(100);
                    $assessment = $this->risk->assess([
                        'account_status' => 'ACTIVE', 'cooldown_until' => $cooldownUntil, 'at' => $at, 'open_positions' => count($open),
                        'equity' => (string) $equity, 'free_capital' => (string) $equity->minus($notional), 'risk_percent' => $decision->modelRiskPercent,
                        'quality_class' => $decision->qualityClass->value, 'total_risk_percent' => (string) $totalRisk,
                        'notional_after' => (string) $notional->plus((string) $size->notional), 'drawdown_percent' => (string) $drawdown,
                        'same_direction_correlated_exposure' => $sameDirection !== [], 'correlation' => $correlation, 'market_failures' => [],
                    ]);
                    if (! $assessment->allowed) {
                        $warnings[] = 'risk_rejected:'.implode(',', $assessment->reasons);

                        continue;
                    }
                    $identity = hash('sha256', implode('|', [$segment->windowIndex, $segment->kind, $decision->id]));
                    if (isset($positions[$identity])) {
                        continue;
                    }
                    $positions[$identity] = [
                        'identity_key' => $identity, 'symbol' => $symbol, 'side' => $decision->side->value, 'quality_class' => $decision->qualityClass->value,
                        'strategy_version' => $decision->strategyVersion, 'signal_at' => $at, 'expires_at' => $decision->expiresAt,
                        'holding_minutes' => $decision->maximumHoldingMinutes, 'entry_low' => $decision->pricePlan->entryLow, 'entry_high' => $decision->pricePlan->entryHigh,
                        'stop' => $decision->pricePlan->stopLoss, 'tp1' => $decision->pricePlan->takeProfit1, 'tp2' => $decision->pricePlan->takeProfit2,
                        'quantity' => $size->quantity, 'remaining' => $size->quantity, 'risk_capital' => $size->riskCapital, 'risk_percent' => $decision->modelRiskPercent,
                        'notional' => $size->notional, 'status' => 'PENDING', 'gross' => '0', 'fees' => '0', 'funding' => '0', 'funding_ids' => [],
                        'funding_complete' => true, 'ambiguous' => false, 'executions' => [],
                    ];
                }
            }
            if ($equity->isGreaterThan($peak)) {
                $peak = $equity;
            }
            $unrealized = $this->unrealized($positions, $lastBars, $execution);
            $marked = $equity->plus($unrealized);
            $drawdown = $peak->isZero() ? BigDecimal::zero() : $peak->minus($marked)->dividedBy($peak, 24, RoundingMode::HalfEven)->multipliedBy(100);
            $snapshots[] = ['recorded_at' => $at, 'equity' => (string) $marked, 'realized_pnl' => (string) $equity->minus($startingBalance), 'unrealized_pnl' => (string) $unrealized, 'drawdown_percent' => (string) $drawdown, 'exposed' => count(array_filter($positions, fn (array $p): bool => in_array($p['status'], ['OPEN', 'PARTIALLY_CLOSED'], true))) > 0];
        }

        foreach ($positions as &$position) {
            if (! in_array($position['status'], ['OPEN', 'PARTIALLY_CLOSED'], true)) {
                continue;
            }
            $bar = $lastBars[$position['symbol']] ?? null;
            if ($bar === null) {
                $warnings[] = 'segment_boundary_position_without_price';

                continue;
            }
            $quote = $execution->riskClosure($position['side'], $bar->close, 'segment_boundary_last_closed_bar');
            $quantity = $position['remaining'];
            $gross = $execution->grossPnl($position['side'], $position['entry_price'], $quote->price, $quantity);
            $fee = $execution->commission($quantity, $quote->price);
            $position['gross'] = (string) $this->d($position['gross'])->plus($gross);
            $position['fees'] = (string) $this->d($position['fees'])->plus($fee);
            $position['executions'][] = $this->execution('SEGMENT_BOUNDARY', $quantity, $quote, $bar->closeTime, $gross, $fee);
            $equity = $equity->plus($gross)->minus($fee);
            $this->closePosition($position, 'SEGMENT_BOUNDARY', $quote->price, $bar->closeTime, $trades);
        }
        unset($position);

        if ($snapshots !== []) {
            $lastIndex = array_key_last($snapshots);
            $finalPeak = $equity->isGreaterThan($peak) ? $equity : $peak;
            $finalDrawdown = $finalPeak->isZero() ? BigDecimal::zero() : $finalPeak->minus($equity)->dividedBy($finalPeak, 24, RoundingMode::HalfEven)->multipliedBy(100);
            $snapshots[$lastIndex]['equity'] = (string) $equity;
            $snapshots[$lastIndex]['realized_pnl'] = (string) $equity->minus($startingBalance);
            $snapshots[$lastIndex]['unrealized_pnl'] = '0';
            $snapshots[$lastIndex]['drawdown_percent'] = (string) $finalDrawdown;
            $snapshots[$lastIndex]['exposed'] = false;
        }

        return new SimulationResult($trades, $snapshots, $decisions, array_values(array_unique($warnings)));
    }

    private function bar($row, DateTimeImmutable $closeAt, string $exchange): MarketBar
    {
        $context = MarketContextRecord::query()->where(['exchange' => $exchange, 'category' => 'linear', 'symbol' => $row->symbol])->where('observed_at', '<=', $closeAt)->latest('observed_at')->first();
        $fresh = $context !== null && $closeAt->getTimestamp() - $context->observed_at->getTimestamp() <= (int) $this->paperConfig['market_safety']['maximum_context_age_seconds'];

        return new MarketBar($row->open_time->toDateTimeImmutable(), $closeAt, $row->open, $row->high, $row->low, $row->close, true, $fresh ? $context->bid : null, $fresh ? $context->ask : null);
    }

    private function applyFunding(array &$position, DateTimeImmutable $at, PaperExecutionEngine $execution, BigDecimal &$equity, string $exchange = 'bybit'): void
    {
        // A position only accrues funding after it was actually filled. Never build a
        // comparison query with a null operand: Laravel rejects `where(col, '>', null)`
        // with "Illegal operator and value combination".
        if (! isset($position['opened_at']) || ! $position['opened_at'] instanceof DateTimeImmutable) {
            return;
        }
        $events = MarketFundingEventRecord::query()->where(['exchange' => $exchange, 'category' => 'linear', 'symbol' => $position['symbol']])
            ->where('funding_at', '>', $position['opened_at'])->where('funding_at', '<=', $at)->orderBy('funding_at')->get();
        foreach ($events as $event) {
            if (isset($position['funding_ids'][$event->id])) {
                continue;
            }
            $amount = $execution->funding($position['side'], $position['remaining'], $event->mark_price, $event->rate);
            $position['funding'] = (string) $this->d($position['funding'])->plus($amount);
            $position['funding_ids'][$event->id] = true;
            $equity = $equity->plus($amount);
        }
    }

    private function closePosition(array &$position, string $reason, string $exitPrice, DateTimeImmutable $at, array &$trades): void
    {
        $position['status'] = 'CLOSED';
        $fundingInterval = (int) config('backtest.funding_expected_interval_hours', 8) * 3600;
        $firstExpected = (intdiv($position['opened_at']->getTimestamp(), $fundingInterval) + 1) * $fundingInterval;
        $expectedFundingEvents = $firstExpected > $at->getTimestamp() ? 0 : intdiv($at->getTimestamp() - $firstExpected, $fundingInterval) + 1;
        $position['funding_complete'] = count($position['funding_ids']) >= $expectedFundingEvents;
        $position['net'] = (string) $this->d($position['gross'])->minus($position['fees'])->plus($position['funding']);
        $risk = $this->d($position['risk_capital']);
        $r = $risk->isZero() ? null : (string) $this->d($position['net'])->dividedBy($risk, 10, RoundingMode::HalfEven);
        $trades[] = [
            'identity_key' => $position['identity_key'], 'symbol' => $position['symbol'], 'side' => $position['side'], 'quality_class' => $position['quality_class'],
            'strategy_version' => $position['strategy_version'], 'signal_at' => $position['signal_at'], 'opened_at' => $position['opened_at'], 'closed_at' => $at,
            'entry_price' => $position['entry_price'], 'exit_price' => $exitPrice, 'quantity' => $position['quantity'], 'gross_pnl' => $position['gross'],
            'fees' => $position['fees'], 'funding' => $position['funding'], 'net_pnl' => $position['net'], 'r_multiple' => $r,
            'holding_seconds' => $at->getTimestamp() - $position['opened_at']->getTimestamp(), 'close_reason' => $reason,
            'ambiguous_execution' => $position['ambiguous'], 'funding_complete' => $position['funding_complete'], 'executions' => $position['executions'],
        ];
    }

    private function execution(string $type, string $quantity, $quote, DateTimeImmutable $at, string $gross, string $fee): array
    {
        return ['type' => $type, 'quantity' => $quantity, 'price' => $quote->price, 'reference_price' => $quote->referencePrice, 'gross_pnl' => $gross, 'commission' => $fee, 'slippage' => $quote->slippage, 'at' => $at->format('Y-m-d\TH:i:s.v\Z'), 'source' => $quote->priceSource, 'ambiguous' => $quote->ambiguous];
    }

    private function unrealized(array $positions, array $lastBars, PaperExecutionEngine $execution): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($positions as $position) {
            if (! in_array($position['status'], ['OPEN', 'PARTIALLY_CLOSED'], true) || ! isset($lastBars[$position['symbol']])) {
                continue;
            }
            $sum = $sum->plus($execution->grossPnl($position['side'], $position['entry_price'], $lastBars[$position['symbol']]->close, $position['remaining']));
        }

        return $sum;
    }

    private function d(string $value): BigDecimal
    {
        return BigDecimal::of($value);
    }
}
