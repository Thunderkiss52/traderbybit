<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class StrategyObservationService
{
    public function __construct(
        private StrategyRegistry $strategies,
        private StrategyInputFactory $inputs,
        private CacheFactory $cache,
    ) {}

    public function observe(string $symbol, ?DateTimeImmutable $at = null, string $exchange = 'bybit', string $category = 'linear'): array
    {
        $at ??= $this->inputs->latestDecisionTime($exchange, $category, $symbol)
            ?? throw new RuntimeException("No closed 15m candle is available for {$symbol}.");
        $lock = $this->cache->lock("strategy-observations:{$exchange}:{$category}:{$symbol}:{$at->format('U.u')}", 120);
        if (! $lock->get()) {
            throw new RuntimeException("Strategy observation is already running for {$symbol} at {$at->format(DATE_ATOM)}.");
        }

        try {
            $input = $this->inputs->make($exchange, $category, $symbol, $at);
            $observations = [];
            foreach ($this->strategies->all() as $strategy) {
                $decision = $strategy->evaluate($input);
                $configurationHash = $this->configurationHash($strategy->parameters());
                $id = Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', [
                    'traderbybit:strategy-observation', $exchange, $category, $symbol,
                    $strategy->id(), $configurationHash, $at->format('U.u'),
                ]))->toString();
                StrategyObservationRecord::query()->insertOrIgnore([[
                    'id' => $id,
                    'exchange' => $exchange,
                    'category' => $category,
                    'symbol' => $symbol,
                    'strategy_id' => $strategy->id(),
                    'strategy_version' => $strategy->version(),
                    'configuration_hash' => $configurationHash,
                    'side' => $decision->side->value,
                    'quality_class' => $decision->qualityClass?->value,
                    'score' => $decision->score,
                    'decision_time' => $decision->decisionTime,
                    'reasons' => json_encode($decision->reasons, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]]);
                $observations[] = StrategyObservationRecord::query()->findOrFail($id);
            }

            return $observations;
        } finally {
            $lock->release();
        }
    }

    public function observeRecent(string $symbol, int $hours = 24, int $maximumCandles = 96, string $exchange = 'bybit', string $category = 'linear'): int
    {
        $through = $this->inputs->latestDecisionTime($exchange, $category, $symbol);
        if ($through === null) {
            return 0;
        }
        $expected = collect($this->strategies->metadata())
            ->map(fn (array $strategy): string => $strategy['id'].'|'.$strategy['configuration_hash'])
            ->all();
        $existing = StrategyObservationRecord::query()
            ->where('exchange', $exchange)->where('category', $category)->where('symbol', $symbol)
            ->where('decision_time', '>', $through->modify("-{$hours} hours"))->where('decision_time', '<=', $through)
            ->get(['strategy_id', 'configuration_hash', 'decision_time'])
            ->groupBy(fn (StrategyObservationRecord $row): string => $row->decision_time->format('U.u'))
            ->map(fn ($rows): array => $rows->map(fn (StrategyObservationRecord $row): string => $row->strategy_id.'|'.$row->configuration_hash)->all());
        $decisionTimes = CandleRecord::query()
            ->where('exchange', $exchange)->where('category', $category)->where('symbol', $symbol)
            ->where('interval', '15')->where('is_closed', true)
            ->where('open_time', '>=', $through->modify("-{$hours} hours"))
            ->where('open_time', '<', $through)
            ->oldest('open_time')->limit($maximumCandles)->pluck('open_time');
        $processed = 0;
        foreach ($decisionTimes as $openTime) {
            $at = $openTime->toDateTimeImmutable()->modify('+15 minutes');
            $recorded = $existing->get($at->format('U.u'), []);
            if (array_diff($expected, $recorded) === []) {
                continue;
            }
            $this->observe($symbol, $at, $exchange, $category);
            $processed++;
        }

        return $processed;
    }

    private function configurationHash(array $parameters): string
    {
        $sort = function (mixed &$value) use (&$sort): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as &$item) {
                $sort($item);
            }
            unset($item);
            if (! array_is_list($value)) {
                ksort($value);
            }
        };
        $sort($parameters);

        return hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));
    }
}
