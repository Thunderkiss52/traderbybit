<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Strategy\Domain\StrategyInput;
use App\Strategy\Infrastructure\Persistence\LevelInteractionForecastRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Ramsey\Uuid\Uuid;

final readonly class LevelProbabilityService
{
    public function __construct(private array $config) {}

    public function record(StrategyInput $input, array $interaction): ?array
    {
        $this->resolveMatured($input);
        $event = $interaction['selected_event'] ?? null;
        $side = $interaction['selected_side'] ?? null;
        $entry = $input->entry->latest();
        if (! is_string($event) || ! is_string($side) || $entry === null || $entry->atr === null) {
            return null;
        }
        $reference = str_starts_with($event, 'resistance') ? $interaction['resistance'] ?? null : $interaction['support'] ?? null;
        if (! is_string($reference)) {
            return null;
        }
        $version = (string) $this->config['model_version'];
        $hash = hash('sha256', json_encode($this->config, JSON_THROW_ON_ERROR));
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', ['traderbybit:level-forecast', $input->exchange, $input->category, $input->symbol, $event, $version, $input->decisionTime->format('U.u')]))->toString();
        $features = [
            'reference_price' => $reference, 'atr' => $entry->atr, 'close' => $entry->close,
            'zone_strength' => data_get($interaction, str_starts_with($event, 'resistance') ? 'resistance_zone.strength' : 'support_zone.strength', 0),
            'momentum_side' => $interaction['momentum_side'] ?? 'NEUTRAL',
            'horizon_bars' => (int) $this->config['horizon_bars_15m'],
            'success_atr' => (string) $this->config['success_atr'], 'failure_atr' => (string) $this->config['failure_atr'],
            'regime' => $this->regime($input),
            'volatility_bucket' => $this->volatilityBucket($entry->atr, $entry->close),
            'zone_strength_bucket' => min(3, (int) floor((float) data_get($interaction, str_starts_with($event, 'resistance') ? 'resistance_zone.strength' : 'support_zone.strength', 0))),
        ];
        $statistics = $this->statistics($input->symbol, $event, $input->decisionTime, $features);
        $features['cohort_sample_size'] = $statistics['cohort_sample_size'];
        $features['probability_population'] = $statistics['cohort_fallback'] ? 'ALL_PRIOR_EVENT_OBSERVATIONS' : 'COMPARABLE_COHORT';
        LevelInteractionForecastRecord::query()->insertOrIgnore([[
            'id' => $id, 'exchange' => $input->exchange, 'category' => $input->category, 'symbol' => $input->symbol,
            'timeframe' => '15', 'event' => $event, 'side' => $side, 'triggered_at' => $input->decisionTime,
            'horizon_ends_at' => $input->decisionTime->modify('+'.((int) $this->config['horizon_bars_15m'] * 15).' minutes'),
            'model_version' => $version, 'configuration_hash' => $hash, 'sample_size' => $statistics['sample_size'],
            'success_probability' => $statistics['success_probability'], 'failure_probability' => $statistics['failure_probability'],
            'unresolved_probability' => $statistics['unresolved_probability'], 'confidence_low' => $statistics['confidence_low'],
            'confidence_high' => $statistics['confidence_high'], 'validation_status' => $statistics['validation_status'],
            'features' => json_encode($features, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]]);

        return LevelInteractionForecastRecord::query()->findOrFail($id)->only([
            'id', 'event', 'sample_size', 'success_probability', 'failure_probability', 'unresolved_probability',
            'confidence_low', 'confidence_high', 'validation_status', 'model_version', 'horizon_ends_at',
        ]);
    }

    public function estimate(string $symbol, string $event, \DateTimeImmutable $before): array
    {
        return $this->statistics($symbol, $event, $before);
    }

    private function statistics(string $symbol, string $event, \DateTimeImmutable $before, ?array $cohort = null): array
    {
        $rows = LevelInteractionForecastRecord::query()->where('symbol', $symbol)->where('event', $event)
            ->where('triggered_at', '<', $before)->whereNotNull('outcome')->latest('triggered_at')
            ->limit(max(1, (int) ($this->config['maximum_history'] ?? 2000)))->get(['outcome', 'features']);
        $cohortRows = $cohort === null ? collect() : $rows->filter(function (LevelInteractionForecastRecord $row) use ($cohort): bool {
            foreach (['regime', 'volatility_bucket', 'zone_strength_bucket', 'momentum_side'] as $field) {
                if (! array_key_exists($field, $row->features ?? []) || ($row->features[$field] ?? null) !== ($cohort[$field] ?? null)) {
                    return false;
                }
            }

            return true;
        })->values();
        $cohortFallback = $cohort !== null && $cohortRows->count() < (int) $this->config['minimum_sample_size'];
        if ($cohort !== null && ! $cohortFallback) {
            $rows = $cohortRows;
        }
        $sample = $rows->count();
        if ($sample < (int) $this->config['minimum_sample_size']) {
            return ['sample_size' => $sample, 'success_probability' => null, 'failure_probability' => null,
                'unresolved_probability' => null, 'confidence_low' => null, 'confidence_high' => null,
                'validation_status' => 'INSUFFICIENT_DATA', 'cohort_sample_size' => $cohortRows->count(), 'cohort_fallback' => $cohortFallback];
        }
        $success = $rows->where('outcome', 'SUCCESS')->count();
        $failure = $rows->where('outcome', 'FAILURE')->count();
        $unresolved = $sample - $success - $failure;
        $alpha = (float) $this->config['smoothing_alpha'];
        $denominator = $sample + 3 * $alpha;
        $probability = ($success + $alpha) / $denominator;
        [$low, $high] = $this->wilson($success, $sample, (float) $this->config['confidence_z']);

        return ['sample_size' => $sample, 'success_probability' => $this->decimal($probability),
            'failure_probability' => $this->decimal(($failure + $alpha) / $denominator),
            'unresolved_probability' => $this->decimal(($unresolved + $alpha) / $denominator),
            'confidence_low' => $this->decimal($low), 'confidence_high' => $this->decimal($high),
            'validation_status' => 'RESEARCH_BASELINE', 'cohort_sample_size' => $cohortRows->count(), 'cohort_fallback' => $cohortFallback];
    }

    private function resolveMatured(StrategyInput $input): void
    {
        LevelInteractionForecastRecord::query()->where('exchange', $input->exchange)->where('category', $input->category)
            ->where('symbol', $input->symbol)->whereNull('outcome')->where('horizon_ends_at', '<=', $input->decisionTime)
            ->orderBy('triggered_at')->limit(100)->each(function (LevelInteractionForecastRecord $forecast): void {
                $bars = CandleRecord::query()->where('exchange', $forecast->exchange)->where('category', $forecast->category)
                    ->where('symbol', $forecast->symbol)->where('interval', '15')->where('is_closed', true)
                    ->where('open_time', '>=', $forecast->triggered_at)->where('open_time', '<', $forecast->horizon_ends_at)
                    ->oldest('open_time')->get(['open_time', 'high', 'low']);
                $reference = BigDecimal::of($forecast->features['reference_price']);
                $atr = BigDecimal::of($forecast->features['atr']);
                $bullish = $forecast->side === 'BUY';
                $target = $bullish ? $reference->plus($atr->multipliedBy((string) $this->config['success_atr'])) : $reference->minus($atr->multipliedBy((string) $this->config['success_atr']));
                $failure = $bullish ? $reference->minus($atr->multipliedBy((string) $this->config['failure_atr'])) : $reference->plus($atr->multipliedBy((string) $this->config['failure_atr']));
                $outcome = 'TIMEOUT';
                $outcomeAt = $forecast->horizon_ends_at;
                foreach ($bars as $bar) {
                    $successHit = $bullish ? BigDecimal::of($bar->high)->isGreaterThanOrEqualTo($target) : BigDecimal::of($bar->low)->isLessThanOrEqualTo($target);
                    $failureHit = $bullish ? BigDecimal::of($bar->low)->isLessThanOrEqualTo($failure) : BigDecimal::of($bar->high)->isGreaterThanOrEqualTo($failure);
                    if ($successHit || $failureHit) {
                        $outcome = $successHit && $failureHit ? 'AMBIGUOUS' : ($successHit ? 'SUCCESS' : 'FAILURE');
                        $outcomeAt = $bar->open_time->addMinutes(15);
                        break;
                    }
                }
                $brier = null;
                if ($forecast->success_probability !== null && in_array($outcome, ['SUCCESS', 'FAILURE'], true)) {
                    $actual = $outcome === 'SUCCESS' ? BigDecimal::one() : BigDecimal::zero();
                    $difference = BigDecimal::of($forecast->success_probability)->minus($actual);
                    $brier = (string) $difference->multipliedBy($difference)->toScale(8, RoundingMode::HalfEven);
                }
                $forecast->update(['outcome' => $outcome, 'outcome_at' => $outcomeAt, 'brier_score' => $brier]);
            });
    }

    private function wilson(int $success, int $sample, float $z): array
    {
        $p = $success / max(1, $sample);
        $denominator = 1 + ($z * $z / $sample);
        $center = ($p + ($z * $z / (2 * $sample))) / $denominator;
        $margin = $z * sqrt(($p * (1 - $p) / $sample) + ($z * $z / (4 * $sample * $sample))) / $denominator;

        return [max(0.0, $center - $margin), min(1.0, $center + $margin)];
    }

    private function decimal(float $value): string
    {
        return number_format($value, 8, '.', '');
    }

    private function regime(StrategyInput $input): string
    {
        $trend = $input->trend->latest();
        if ($trend === null || $trend->adx === null || (float) $trend->adx < 18) {
            return 'RANGE';
        }
        if ($trend->emaFast === null || $trend->emaSlow === null) {
            return 'UNCERTAIN';
        }

        return (float) $trend->emaFast >= (float) $trend->emaSlow ? 'TREND_UP' : 'TREND_DOWN';
    }

    private function volatilityBucket(string $atr, string $close): string
    {
        $ratio = (float) $atr / max((float) $close, PHP_FLOAT_EPSILON);

        return $ratio < 0.005 ? 'LOW' : ($ratio < 0.015 ? 'MEDIUM' : 'HIGH');
    }
}
