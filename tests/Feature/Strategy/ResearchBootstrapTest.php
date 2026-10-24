<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PatternDiscovery\Infrastructure\Persistence\PatternRunRecord;
use App\PatternDiscovery\Jobs\DiscoverPatternsJob;
use App\Strategy\Application\ResearchBootstrapService;
use App\Strategy\Application\ResearchExperimentRunner;
use App\Strategy\Infrastructure\Persistence\ResearchExperimentRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Jobs\RunResearchExperimentJob;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ResearchBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_closed_history_queues_each_strategy_once(): void
    {
        Bus::fake();
        config()->set('market_data.symbols', ['BTCUSDT']);
        config()->set('research.automatic.evaluation_days', 1);
        config()->set('research.automatic.minimum_pattern_decisions', 999);
        config()->set('backtest.warmup_bars', 1);
        $to = new DateTimeImmutable('2026-10-10T00:00:00Z');
        foreach (['5' => 300, '15' => 900, '30' => 1800, '60' => 3600] as $interval => $seconds) {
            $this->candles('BTCUSDT', (string) $interval, $to->modify('-1 day')->modify("-{$seconds} seconds"), $to, $seconds);
        }

        $first = app(ResearchBootstrapService::class)->ensure();
        $second = app(ResearchBootstrapService::class)->ensure();

        self::assertSame(13, $first['experiments']);
        self::assertSame(0, $second['experiments']);
        self::assertSame(13, ResearchExperimentRecord::query()->count());
        Bus::assertDispatchedTimes(RunResearchExperimentJob::class, 13);
    }

    public function test_sufficient_forward_decisions_queue_one_pattern_run_per_symbol_once(): void
    {
        Bus::fake();
        config()->set('market_data.symbols', ['BTCUSDT']);
        config()->set('research.automatic.minimum_pattern_decisions', 2);
        $to = new DateTimeImmutable('2026-10-10T00:00:00Z');
        $this->candles('BTCUSDT', '5', $to->modify('-5 minutes'), $to, 300);
        foreach (['2026-10-09T18:00:00Z', '2026-10-09T19:00:00Z', '2026-10-09T23:00:00Z'] as $at) {
            $this->signal('BTCUSDT', $at);
        }

        $first = app(ResearchBootstrapService::class)->ensure();
        $second = app(ResearchBootstrapService::class)->ensure();

        self::assertSame(1, $first['pattern_runs']);
        self::assertSame(0, $second['pattern_runs']);
        self::assertSame(1, PatternRunRecord::query()->count());
        Bus::assertDispatchedTimes(DiscoverPatternsJob::class, 1);
    }

    public function test_queued_experiment_is_superseded_when_its_immutable_strategy_identity_is_stale(): void
    {
        $experiment = ResearchExperimentRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0', 'configuration_hash' => str_repeat('0', 64),
            'parameters' => [], 'symbols' => ['BTCUSDT'], 'from_at' => '2026-01-01',
            'to_at' => '2026-02-01', 'evaluation_mode' => 'split', 'status' => 'QUEUED',
            'resource_budget' => config('research.queue'), 'tested_combinations' => 0,
        ]);

        app(ResearchExperimentRunner::class)->run($experiment->id);

        $experiment->refresh();
        self::assertSame('SUPERSEDED', $experiment->status);
        self::assertNull($experiment->backtest_run_id);
        self::assertSame('strategy_identity_changed', $experiment->warnings[0]['class']);
    }

    private function candles(string $symbol, string $interval, DateTimeImmutable $from, DateTimeImmutable $to, int $stepSeconds): void
    {
        $rows = [];
        for ($at = $from; $at < $to; $at = $at->modify("+{$stepSeconds} seconds")) {
            $rows[] = ['exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => $interval,
                'open_time' => $at, 'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100,
                'volume' => 10, 'turnover' => 1000, 'is_closed' => true];
        }
        CandleRecord::query()->insert($rows);
    }

    private function signal(string $symbol, string $at): void
    {
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
            'timeframe' => '15', 'side' => 'SKIP', 'score' => 0, 'decision_time' => $at,
            'candle_close_time' => $at, 'expires_at' => (new DateTimeImmutable($at))->modify('+30 minutes'),
            'maximum_holding_minutes' => 240, 'reasons' => ['test'], 'risks' => [],
            'indicator_snapshot' => [], 'contract' => [], 'strategy_version' => 'bootstrap-test', 'schema_version' => '1.0.0',
        ]);
    }
}
