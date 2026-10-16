<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\MarketData\Domain\Interval;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PatternDiscovery\Infrastructure\Persistence\PatternRecord;
use App\PatternDiscovery\Infrastructure\Persistence\PatternRunRecord;
use App\PatternDiscovery\Jobs\DiscoverPatternsJob;
use App\Strategy\Infrastructure\Persistence\ResearchExperimentRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Jobs\RunResearchExperimentJob;
use Ramsey\Uuid\Uuid;

final readonly class ResearchBootstrapService
{
    public function __construct(private StrategyRegistry $strategies) {}

    public function ensure(): array
    {
        if (! (bool) config('research.automatic.enabled', true)) {
            return ['experiments' => 0, 'pattern_runs' => 0, 'status' => 'disabled'];
        }

        $symbols = array_values((array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']));
        $experiments = $this->ensureExperiments($symbols);
        $patterns = $this->ensurePatterns($symbols);

        return ['experiments' => $experiments, 'pattern_runs' => $patterns, 'status' => 'ready'];
    }

    private function ensureExperiments(array $symbols): int
    {
        $window = $this->researchWindow($symbols);
        if ($window === null) {
            return 0;
        }
        [$from, $to] = $window;
        $queued = 0;
        foreach ($this->strategies->metadata() as $strategy) {
            $exists = ResearchExperimentRecord::query()
                ->where('strategy_id', $strategy['id'])
                ->where('strategy_version', $strategy['version'])
                ->where('configuration_hash', $strategy['configuration_hash'])
                ->exists();
            if ($exists) {
                continue;
            }
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', [
                'automatic-research', $strategy['id'], $strategy['version'], $strategy['configuration_hash'],
                implode(',', $symbols), $from->format(DATE_ATOM), $to->format(DATE_ATOM),
            ]))->toString();
            $experiment = ResearchExperimentRecord::query()->firstOrCreate(['id' => $id], [
                'strategy_id' => $strategy['id'], 'strategy_version' => $strategy['version'],
                'configuration_hash' => $strategy['configuration_hash'], 'parameters' => $strategy['parameters'],
                'symbols' => $symbols, 'from_at' => $from, 'to_at' => $to, 'evaluation_mode' => 'split',
                'status' => 'QUEUED', 'resource_budget' => config('research.queue'), 'tested_combinations' => 0,
            ]);
            if ($experiment->wasRecentlyCreated) {
                RunResearchExperimentJob::dispatch($experiment->id);
                $queued++;
            }
        }

        return $queued;
    }

    private function researchWindow(array $symbols): ?array
    {
        $intervals = array_map(static fn (string $value): Interval => Interval::parse($value),
            array_values((array) config('market_data.intervals', ['5', '15', '30', '60'])));
        $to = null;
        foreach ($symbols as $symbol) {
            foreach ($intervals as $interval) {
                $latest = CandleRecord::query()->where([
                    'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
                    'interval' => $interval->value, 'is_closed' => true,
                ])->latest('open_time')->first(['open_time']);
                if ($latest === null) {
                    return null;
                }
                $candidate = $latest->open_time->addMilliseconds($interval->milliseconds())->toDateTimeImmutable();
                $to = $to === null || $candidate < $to ? $candidate : $to;
            }
        }
        $days = max(1, (int) config('research.automatic.evaluation_days', 14));
        $from = $to->modify("-{$days} days");
        $warmupBars = max(1, (int) config('backtest.warmup_bars', 220));
        foreach ($symbols as $symbol) {
            foreach ($intervals as $interval) {
                $seconds = intdiv($interval->milliseconds(), 1000);
                $warmupFrom = $from->modify('-'.($warmupBars * $seconds).' seconds');
                $expected = intdiv($to->getTimestamp() - $warmupFrom->getTimestamp(), $seconds);
                $actual = CandleRecord::query()->where([
                    'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
                    'interval' => $interval->value, 'is_closed' => true,
                ])->where('open_time', '>=', $warmupFrom)->where('open_time', '<', $to)->count();
                if ($actual !== $expected) {
                    return null;
                }
            }
        }

        return [$from, $to];
    }

    private function ensurePatterns(array $symbols): int
    {
        $config = (array) config('patterns');
        $configurationHash = hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
        $maximum = max(1, (int) ($config['maximum_decisions'] ?? 800));
        $minimum = min($maximum, max(1, (int) config('research.automatic.minimum_pattern_decisions', 700)));
        $retryNewDecisions = max(1, (int) config('research.automatic.pattern_retry_new_decisions', 120));
        $holdingSeconds = max(1, (int) ($config['holding_bars_5m'] ?? 48)) * 300;
        $queued = 0;
        foreach ($symbols as $symbol) {
            $runs = PatternRunRecord::query()->where('symbol', $symbol)->where('configuration_hash', $configurationHash);
            if ((clone $runs)->whereIn('status', ['QUEUED', 'RUNNING'])->exists()) {
                continue;
            }
            $completed = (clone $runs)->where('status', 'COMPLETED')->latest('completed_at')->first();
            if ($completed !== null && PatternRecord::query()->where('pattern_run_id', $completed->id)->exists()) {
                continue;
            }
            if ($completed !== null && SignalRecord::query()->where('symbol', $symbol)
                ->where('exchange', 'bybit')->where('category', 'linear')
                ->where('decision_time', '>', $completed->to_at)->count() < $retryNewDecisions) {
                continue;
            }
            $latest = CandleRecord::query()->where([
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
                'interval' => '5', 'is_closed' => true,
            ])->latest('open_time')->first(['open_time']);
            if ($latest === null) {
                continue;
            }
            $to = $latest->open_time->addMinutes(5)->toDateTimeImmutable();
            $cutoff = $to->modify("-{$holdingSeconds} seconds");
            $rows = SignalRecord::query()->where('symbol', $symbol)
                ->where('exchange', 'bybit')->where('category', 'linear')
                ->where('decision_time', '<=', $to)->latest('decision_time')->latest('id')
                ->limit($maximum)->get(['decision_time']);
            if ($rows->where('decision_time', '<=', $cutoff)->count() < $minimum) {
                continue;
            }
            $from = $rows->last()->decision_time->toDateTimeImmutable();
            while (SignalRecord::query()->where('symbol', $symbol)
                ->where('exchange', 'bybit')->where('category', 'linear')
                ->whereBetween('decision_time', [$from, $to])->count() > $maximum) {
                $earliest = SignalRecord::query()->where('symbol', $symbol)
                    ->where('exchange', 'bybit')->where('category', 'linear')
                    ->whereBetween('decision_time', [$from, $to])->oldest('decision_time')->oldest('id')->firstOrFail(['decision_time']);
                $from = $earliest->decision_time->addMillisecond()->toDateTimeImmutable();
            }
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', [
                'automatic-patterns', $symbol, $configurationHash, $from->format(DATE_ATOM), $to->format(DATE_ATOM),
            ]))->toString();
            $run = PatternRunRecord::query()->firstOrCreate(['id' => $id], [
                'status' => 'QUEUED', 'symbol' => $symbol, 'from_at' => $from, 'to_at' => $to,
                'parameters' => $config, 'configuration_hash' => $configurationHash,
            ]);
            if ($run->wasRecentlyCreated) {
                DiscoverPatternsJob::dispatch($run->id);
                $queued++;
            }
        }

        return $queued;
    }
}
