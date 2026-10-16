<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Strategy\Domain\BarState;
use App\Strategy\Domain\PricePlan;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateInterval;
use Ramsey\Uuid\Uuid;

final readonly class StrategyEngine
{
    public function __construct(private array $config) {}

    public function evaluate(StrategyInput $input): SignalDecision
    {
        if ($input->validationErrors !== []) {
            return $this->skip($input, $input->validationErrors);
        }

        if (($temporalError = $this->temporalError($input)) !== null) {
            return $this->skip($input, [$temporalError]);
        }

        if (! $input->trend->warmupReady || ! $input->setup->warmupReady || ! $input->entry->warmupReady || ! $input->confirmation->warmupReady) {
            return $this->skip($input, ['insufficient_warmup']);
        }

        $hour = $input->trend->latest();
        $entry = $input->entry->latest();
        $confirmation = $input->confirmation->latest();
        if ($hour === null || $entry === null || $confirmation === null) {
            return $this->skip($input, ['missing_timeframe_data']);
        }

        $trend = $this->trend($hour);
        if ($trend === SignalSide::Skip) {
            return $this->skip($input, ['neutral_hourly_trend'], $this->snapshot($input));
        }

        if (! $this->setupMatches($trend, $input)) {
            return $this->skip($input, ['thirty_minute_setup_conflicts'], $this->snapshot($input));
        }

        if (! $this->confirmationMatches($trend, $input)) {
            return $this->skip($input, ['five_minute_confirmation_conflicts'], $this->snapshot($input));
        }

        if ($entry->atr === null || $this->d($entry->atr)->isZero()) {
            return $this->skip($input, ['atr_unavailable_or_zero'], $this->snapshot($input));
        }

        $contextFailure = $this->validateContext($input, $entry);
        if ($contextFailure !== null) {
            return $this->skip($input, [$contextFailure], $this->snapshot($input));
        }

        $triggers = $this->triggers($trend, $input);
        if ($triggers === []) {
            return $this->skip($input, ['no_entry_trigger'], $this->snapshot($input));
        }

        $plan = $this->pricePlan($trend, $entry, $input->marketContext?->tickSize);
        if ($plan === null) {
            return $this->skip($input, ['invalid_or_insufficient_risk_reward'], $this->snapshot($input));
        }

        [$score, $quality] = $this->score($trend, $input, $triggers, $plan);
        if ($quality === null) {
            return $this->skip($input, ['quality_score_below_minimum'], $this->snapshot($input), $score);
        }

        $risks = $this->contextRisks($input);
        $reasons = array_merge(
            [$trend === SignalSide::Buy ? 'bullish_hourly_trend' : 'bearish_hourly_trend', 'thirty_minute_setup', 'five_minute_confirmation'],
            $triggers,
        );

        return $this->decision(
            input: $input,
            side: $trend,
            quality: $quality,
            score: $score,
            plan: $plan,
            risk: (string) $this->config['model_risk_percent'][$quality->value],
            reasons: $reasons,
            risks: $risks,
            snapshot: $this->snapshot($input),
        );
    }

    private function trend(BarState $bar): SignalSide
    {
        if ($this->missing($bar->emaFast, $bar->emaSlow, $bar->adx, $bar->plusDi, $bar->minusDi)) {
            return SignalSide::Skip;
        }
        $strongEnough = $this->gte($bar->adx, (string) $this->config['trend']['adx_min']);
        if ($strongEnough && $this->gt($bar->emaFast, $bar->emaSlow) && $this->gt($bar->close, $bar->emaFast) && $this->gt($bar->plusDi, $bar->minusDi)) {
            return SignalSide::Buy;
        }
        if ($strongEnough && $this->lt($bar->emaFast, $bar->emaSlow) && $this->lt($bar->close, $bar->emaFast) && $this->gt($bar->minusDi, $bar->plusDi)) {
            return SignalSide::Sell;
        }

        return SignalSide::Skip;
    }

    private function temporalError(StrategyInput $input): ?string
    {
        foreach (['trend' => $input->trend, 'setup' => $input->setup, 'entry' => $input->entry, 'confirmation' => $input->confirmation] as $role => $state) {
            $previous = null;
            $step = ((int) $state->interval) * 60;
            foreach ($state->bars as $bar) {
                if ($bar->closeTime > $input->decisionTime) {
                    return 'future_candle_rejected';
                }
                if ($previous !== null && $bar->openTime->getTimestamp() !== $previous + $step) {
                    return "non_contiguous_{$role}_history";
                }
                $previous = $bar->openTime->getTimestamp();
            }
            $latest = $state->latest();
            if ($latest !== null) {
                $lag = $input->decisionTime->getTimestamp() - $latest->closeTime->getTimestamp();
                if ($lag > (int) $this->config['freshness']['max_lag_seconds'][$state->interval]) {
                    return "stale_{$role}_data";
                }
            }
        }

        return null;
    }

    private function confirmationMatches(SignalSide $side, StrategyInput $input): bool
    {
        $now = $input->confirmation->latest();
        $previous = $input->confirmation->previous();
        if ($now === null || $previous === null || $this->missing($now->emaEntry, $now->rsi, $now->macdHistogram, $previous->macdHistogram)) {
            return false;
        }
        $mid = (string) $this->config['confirmation']['rsi_midline'];

        return $side === SignalSide::Buy
            ? $this->gt($now->close, $now->emaEntry) && $this->gte($now->rsi, $mid) && $this->gte($now->macdHistogram, $previous->macdHistogram)
            : $this->lt($now->close, $now->emaEntry) && $this->lte($now->rsi, $mid) && $this->lte($now->macdHistogram, $previous->macdHistogram);
    }

    private function setupMatches(SignalSide $side, StrategyInput $input): bool
    {
        $setup = $input->setup->latest();
        if ($setup === null || $this->missing($setup->close, $setup->emaFast, $setup->emaSlow)) {
            return false;
        }

        return $side === SignalSide::Buy
            ? $this->gt($setup->close, $setup->emaFast) && $this->gt($setup->emaFast, $setup->emaSlow)
            : $this->lt($setup->close, $setup->emaFast) && $this->lt($setup->emaFast, $setup->emaSlow);
    }

    private function validateContext(StrategyInput $input, BarState $entry): ?string
    {
        $context = $input->marketContext;
        if ($context === null) {
            return (bool) $this->config['freshness']['require_market_context'] ? 'historical_market_context_unavailable' : null;
        }
        if ($context->tickSize === '' || $this->d($context->tickSize)->isLessThanOrEqualTo(BigDecimal::zero())) {
            return 'tick_size_unavailable';
        }
        if ($input->decisionTime->getTimestamp() - $context->observedAt->getTimestamp() > (int) $this->config['freshness']['market_context_max_age_seconds']) {
            return 'market_context_stale';
        }
        if ($this->lt($context->turnover24h, (string) $this->config['liquidity']['minimum_turnover_24h'])) {
            return 'insufficient_liquidity';
        }
        $mid = $this->d($context->bid)->plus($context->ask)->dividedBy(2, 24, RoundingMode::HalfEven);
        if ($mid->isZero() || $this->d($context->ask)->isLessThan($this->d($context->bid))) {
            return 'invalid_market_context';
        }
        $spread = $this->d($context->ask)->minus($context->bid)->dividedBy($mid, 24, RoundingMode::HalfEven);
        if ($spread->isGreaterThan($this->d((string) $this->config['liquidity']['maximum_spread_percent']))) {
            return 'spread_above_maximum';
        }
        $atrPercent = $this->d((string) $entry->atr)->dividedBy($entry->close, 24, RoundingMode::HalfEven);
        if ($atrPercent->isLessThan($this->d((string) $this->config['volatility']['minimum_atr_percent']))) {
            return 'volatility_below_minimum';
        }
        if ($atrPercent->isGreaterThan($this->d((string) $this->config['volatility']['maximum_atr_percent']))) {
            return 'extreme_volatility';
        }
        if ($entry->relativeVolume === null || $this->lt($entry->relativeVolume, (string) $this->config['volume']['minimum_relative_volume'])) {
            return 'abnormally_low_activity';
        }
        if ($entry->averageTurnover === null || $this->lt($entry->averageTurnover, (string) $this->config['volume']['minimum_average_turnover'])) {
            return 'average_turnover_below_minimum';
        }

        return null;
    }

    /** @return list<string> */
    private function triggers(SignalSide $side, StrategyInput $input): array
    {
        $result = [];
        $bars = $input->entry->bars;
        $now = $input->entry->latest();
        $previous = $input->entry->previous();
        if ($now === null || $previous === null) {
            return [];
        }

        $lookback = array_slice($bars, -(int) $this->config['triggers']['ema_pullback']['lookback_bars']);
        $touched = false;
        foreach ($lookback as $bar) {
            if ($bar->emaEntry === null || $bar->atr === null) {
                continue;
            }
            $zone = $this->d($bar->atr)->multipliedBy((string) $this->config['triggers']['ema_pullback']['atr_zone_multiplier']);
            $lower = $this->d($bar->emaEntry)->minus($zone);
            $upper = $this->d($bar->emaEntry)->plus($zone);
            $touched = $touched || ($this->d($bar->low)->isLessThanOrEqualTo($upper) && $this->d($bar->high)->isGreaterThanOrEqualTo($lower));
        }
        $mid = (string) $this->config['triggers']['ema_pullback']['rsi_midline'];
        if ($touched && ! $this->missing($now->emaEntry, $now->rsi, $now->macdHistogram, $previous->macdHistogram)) {
            $pullback = $side === SignalSide::Buy
                ? $this->gt($now->close, $now->emaEntry) && $this->gt($now->rsi, $mid) && $this->gt($now->macdHistogram, $previous->macdHistogram)
                : $this->lt($now->close, $now->emaEntry) && $this->lt($now->rsi, $mid) && $this->lt($now->macdHistogram, $previous->macdHistogram);
            if ($pullback) {
                $result[] = 'ema_pullback';
            }
        }

        if (! $this->missing($now->macd, $now->macdSignal, $previous->macd, $previous->macdSignal, $now->rsi, $now->relativeVolume)) {
            $cfg = $this->config['triggers']['macd_crossover'];
            $cross = $side === SignalSide::Buy
                ? $this->lte($previous->macd, $previous->macdSignal) && $this->gt($now->macd, $now->macdSignal) && $this->between($now->rsi, (string) $cfg['buy_rsi_min'], (string) $cfg['buy_rsi_max'])
                : $this->gte($previous->macd, $previous->macdSignal) && $this->lt($now->macd, $now->macdSignal) && $this->between($now->rsi, (string) $cfg['sell_rsi_min'], (string) $cfg['sell_rsi_max']);
            if ($cross && $this->gte($now->relativeVolume, (string) $cfg['relative_volume_min'])) {
                $result[] = 'macd_crossover';
            }
        }

        $level = $side === SignalSide::Buy ? $previous->resistance : $previous->support;
        if ($level !== null && $now->relativeVolume !== null && $now->atr !== null) {
            $cfg = $this->config['triggers']['breakout'];
            $buffer = $this->d($now->atr)->multipliedBy((string) $cfg['min_buffer_atr']);
            $threshold = $side === SignalSide::Buy ? $this->d($level)->plus($buffer) : $this->d($level)->minus($buffer);
            $rangeRatio = $this->d($now->high)->minus($now->low)->dividedBy($now->atr, 24, RoundingMode::HalfEven);
            $broken = $side === SignalSide::Buy ? $this->d($now->close)->isGreaterThan($threshold) : $this->d($now->close)->isLessThan($threshold);
            if ($broken && $this->gte($now->relativeVolume, (string) $cfg['relative_volume_min']) && $rangeRatio->isLessThanOrEqualTo($this->d((string) $cfg['max_candle_range_atr']))) {
                $result[] = 'confirmed_breakout';
            }
        }

        return $result;
    }

    private function pricePlan(SignalSide $side, BarState $bar, ?string $tickSize): ?PricePlan
    {
        if ($tickSize === null || $bar->atr === null) {
            return null;
        }
        $tick = $this->d($tickSize);
        $close = $this->d($bar->close);
        $atr = $this->d($bar->atr);
        $zone = $atr->multipliedBy((string) $this->config['price_model']['entry_zone_atr']);
        $entryLow = $this->roundToTick($close->minus($zone), $tick, RoundingMode::Floor);
        $entryHigh = $this->roundToTick($close->plus($zone), $tick, RoundingMode::Ceiling);
        $atrStop = $atr->multipliedBy((string) $this->config['price_model']['stop_atr']);

        if ($side === SignalSide::Buy) {
            $worstEntry = $entryHigh;
            $stop = $this->roundToTick($close->minus($atrStop), $tick, RoundingMode::Floor);
            if ($bar->support !== null) {
                $structural = $this->roundToTick($this->d($bar->support)->minus($tick), $tick, RoundingMode::Floor);
                if ($structural->isLessThan($stop)) {
                    $stop = $structural;
                }
            }
            if (! $stop->isLessThan($entryLow)) {
                return null;
            }
            $risk = $worstEntry->minus($stop);
            $tp1 = $this->roundToTick($worstEntry->plus($risk->multipliedBy((string) $this->config['price_model']['tp1_r'])), $tick, RoundingMode::Ceiling);
            $tp2 = $this->roundToTick($worstEntry->plus($risk->multipliedBy((string) $this->config['price_model']['tp2_r'])), $tick, RoundingMode::Ceiling);
            if ($bar->resistance !== null && $this->d($bar->resistance)->isGreaterThan($worstEntry) && $this->d($bar->resistance)->isLessThan($tp2)) {
                return null;
            }
        } else {
            $worstEntry = $entryLow;
            $stop = $this->roundToTick($close->plus($atrStop), $tick, RoundingMode::Ceiling);
            if ($bar->resistance !== null) {
                $structural = $this->roundToTick($this->d($bar->resistance)->plus($tick), $tick, RoundingMode::Ceiling);
                if ($structural->isGreaterThan($stop)) {
                    $stop = $structural;
                }
            }
            if (! $stop->isGreaterThan($entryHigh)) {
                return null;
            }
            $risk = $stop->minus($worstEntry);
            $tp1 = $this->roundToTick($worstEntry->minus($risk->multipliedBy((string) $this->config['price_model']['tp1_r'])), $tick, RoundingMode::Floor);
            $tp2 = $this->roundToTick($worstEntry->minus($risk->multipliedBy((string) $this->config['price_model']['tp2_r'])), $tick, RoundingMode::Floor);
            if ($tp2->isNegative()) {
                return null;
            }
            if ($bar->support !== null && $this->d($bar->support)->isLessThan($worstEntry) && $this->d($bar->support)->isGreaterThan($tp2)) {
                return null;
            }
        }

        $rr1 = $side === SignalSide::Buy ? $tp1->minus($worstEntry)->dividedBy($risk, 8, RoundingMode::HalfEven) : $worstEntry->minus($tp1)->dividedBy($risk, 8, RoundingMode::HalfEven);
        $rr2 = $side === SignalSide::Buy ? $tp2->minus($worstEntry)->dividedBy($risk, 8, RoundingMode::HalfEven) : $worstEntry->minus($tp2)->dividedBy($risk, 8, RoundingMode::HalfEven);

        return new PricePlan((string) $entryLow, (string) $entryHigh, (string) $stop, (string) $tp1, (string) $tp2, (string) $rr1, (string) $rr2);
    }

    private function score(SignalSide $side, StrategyInput $input, array $triggers, PricePlan $plan): array
    {
        $weights = $this->config['scoring']['weights'];
        $score = (int) $weights['trend_alignment'] + (int) $weights['setup_30m'] + (int) $weights['confirmation_5m'] + (int) $weights['first_trigger'];
        $score += min(max(0, count($triggers) - 1) * (int) $weights['additional_trigger'], (int) $weights['additional_trigger_max']);
        $hour = $input->trend->latest();
        $entry = $input->entry->latest();
        if ($hour?->adx !== null && $this->gte($hour->adx, (string) $this->config['trend']['adx_strong'])) {
            $score += (int) $weights['strong_adx'];
        }
        if ($entry?->relativeVolume !== null && $this->gte($entry->relativeVolume, (string) $this->config['triggers']['breakout']['relative_volume_min'])) {
            $score += (int) $weights['relative_volume'];
        }
        if ($this->d($plan->riskReward2)->isGreaterThanOrEqualTo($this->d((string) $this->config['structure']['minimum_level_space_r']))) {
            $score += (int) $weights['level_space'];
        }
        if ($entry !== null && $entry->rsi !== null && $entry->macdHistogram !== null && (($side === SignalSide::Buy && $this->gt($entry->rsi, '50') && $this->gt($entry->macdHistogram, '0')) || ($side === SignalSide::Sell && $this->lt($entry->rsi, '50') && $this->lt($entry->macdHistogram, '0')))) {
            $score += (int) $weights['momentum'];
        }
        $score = min(100, $score);
        $classes = $this->config['scoring']['classes'];
        $quality = $score >= (int) $classes['A'] ? QualityClass::A : ($score >= (int) $classes['B'] ? QualityClass::B : ($score >= (int) $classes['C'] ? QualityClass::C : null));

        return [$score, $quality];
    }

    private function contextRisks(StrategyInput $input): array
    {
        $context = $input->marketContext;
        if ($context === null) {
            return ['market_context_not_verified'];
        }
        $risks = [];
        if ($context->fundingRate !== null && $this->d($context->fundingRate)->abs()->isGreaterThanOrEqualTo($this->d((string) $this->config['context']['extreme_funding_absolute']))) {
            $risks[] = 'extreme_funding_rate';
        }
        if ($context->openInterestChange !== null && $this->d($context->openInterestChange)->abs()->isGreaterThanOrEqualTo($this->d((string) $this->config['context']['extreme_open_interest_change_absolute']))) {
            $risks[] = 'extreme_open_interest_change';
        }

        return $risks;
    }

    private function snapshot(StrategyInput $input): array
    {
        $bar = static fn (?BarState $b): ?array => $b === null ? null : [
            'close_time' => $b->closeTime->format('Y-m-d\TH:i:s.v\Z'), 'close' => $b->close,
            'ema_20' => $b->emaEntry, 'ema_50' => $b->emaFast, 'ema_200' => $b->emaSlow,
            'rsi_14' => $b->rsi, 'macd' => $b->macd, 'macd_signal' => $b->macdSignal,
            'macd_histogram' => $b->macdHistogram, 'atr_14' => $b->atr, 'adx_14' => $b->adx,
            'plus_di' => $b->plusDi, 'minus_di' => $b->minusDi, 'relative_volume' => $b->relativeVolume,
            'average_turnover' => $b->averageTurnover, 'support' => $b->support, 'resistance' => $b->resistance,
        ];

        return ['1h' => $bar($input->trend->latest()), '30m' => $bar($input->setup->latest()), '15m' => $bar($input->entry->latest()), '5m' => $bar($input->confirmation->latest())];
    }

    private function skip(StrategyInput $input, array $reasons, array $snapshot = [], int $score = 0): SignalDecision
    {
        return $this->decision($input, SignalSide::Skip, null, $score, null, null, $reasons, [], $snapshot);
    }

    private function decision(StrategyInput $input, SignalSide $side, ?QualityClass $quality, int $score, ?PricePlan $plan, ?string $risk, array $reasons, array $risks, array $snapshot): SignalDecision
    {
        $version = (string) $this->config['version'];
        $identity = implode('|', [$input->exchange, $input->category, $input->symbol, $this->config['decision_timeframe'], $input->decisionTime->format('U.u'), $version]);

        return new SignalDecision(
            id: Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:'.$identity)->toString(),
            exchange: $input->exchange, category: $input->category, symbol: $input->symbol,
            timeframe: (string) $this->config['decision_timeframe'], decisionTime: $input->decisionTime,
            candleCloseTime: $input->decisionTime, side: $side, qualityClass: $quality, score: $score,
            pricePlan: $plan, modelRiskPercent: $risk,
            expiresAt: $input->decisionTime->add(new DateInterval('PT'.(int) $this->config['signal_expiration_minutes'].'M')),
            maximumHoldingMinutes: (int) $this->config['maximum_holding_minutes'], reasons: $reasons, risks: $risks,
            indicatorSnapshot: $snapshot, strategyVersion: $version, schemaVersion: (string) $this->config['schema_version'],
        );
    }

    private function roundToTick(BigDecimal $value, BigDecimal $tick, RoundingMode $mode): BigDecimal
    {
        return $value->dividedBy($tick, 0, $mode)->multipliedBy($tick);
    }

    private function d(string $value): BigDecimal
    {
        return BigDecimal::of($value);
    }

    private function gt(string $a, string $b): bool
    {
        return $this->d($a)->isGreaterThan($this->d($b));
    }

    private function gte(string $a, string $b): bool
    {
        return $this->d($a)->isGreaterThanOrEqualTo($this->d($b));
    }

    private function lt(string $a, string $b): bool
    {
        return $this->d($a)->isLessThan($this->d($b));
    }

    private function lte(string $a, string $b): bool
    {
        return $this->d($a)->isLessThanOrEqualTo($this->d($b));
    }

    private function between(string $v, string $min, string $max): bool
    {
        return $this->gte($v, $min) && $this->lte($v, $max);
    }

    private function missing(?string ...$values): bool
    {
        return in_array(null, $values, true);
    }
}
