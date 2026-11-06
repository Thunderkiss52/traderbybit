<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Backtest\Application\BacktestRunService;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\StrategyEvolutionService;
use App\Research\Domain\InsufficientCandleHistoryException;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Research\Jobs\EvaluateStrategyCandidateJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Rem05AdaptiveCandidateEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private function createCandidate(string $exchange = 'bybit', ?int $availableCandleDays = null, array $symbols = ['BTCUSDT']): LearningCandidateStrategyRecord
    {
        $evolution = app(StrategyEvolutionService::class);
        $candidate = $evolution->generateCandidate('trend_pullback', 4242, $exchange);

        $metadata = (array) $candidate->search_metadata;
        $metadata['exchange'] = $exchange;
        $metadata['symbols'] = $symbols;
        if ($availableCandleDays !== null) {
            $metadata['available_candle_days'] = $availableCandleDays;
        }

        $candidate->update(['search_metadata' => $metadata]);

        return $candidate->fresh();
    }

    private function createOwnedBacktestRun(string $candidateId, string $exchange = 'bybit'): BacktestRunRecord
    {
        return BacktestRunRecord::query()->create([
            'id' => (string) Str::uuid(),
            'fingerprint' => 'rem05-fingerprint-'.Str::random(12),
            'type' => 'walk_forward',
            'status' => 'COMPLETED',
            'from_at' => now()->subDays(60),
            'to_at' => now(),
            'symbols' => ['BTCUSDT'],
            'strategy_config' => ['_exchange' => $exchange, 'version' => '1.0.0'],
            'risk_config' => ['risk_version' => '1.0.0'],
            'execution_config' => ['execution_version' => '1.0.0'],
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'backtest_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'dataset_checksum' => 'checksum-'.Str::random(8),
            'progress' => 100,
            'warnings' => [],
            'errors' => [],
            'metrics' => [
                'net_profit' => '500.00',
                'profit_factor' => '1.75',
                'max_drawdown_percent' => '4.2',
                'win_rate' => '0.58',
                'trades_count' => 120,
            ],
            'started_at' => now()->subHours(1),
            'completed_at' => now(),
        ]);
    }

    public function test_candidates_are_evaluated_adaptively_when_history_is_between_minimum_and_target(): void
    {
        config([
            'research.candidate_evaluation.minimum_days' => 30,
            'research.candidate_evaluation.target_days' => 180,
        ]);

        // Candidate has 45 days available (between 30d minimum and 180d target)
        $candidate = $this->createCandidate('bybit', 45);
        $ownedRun = $this->createOwnedBacktestRun($candidate->id, 'bybit');

        $mockBacktest = $this->createMock(BacktestRunService::class);
        $mockBacktest->expects($this->once())
            ->method('run')
            ->with(
                $this->equalTo(['BTCUSDT']),
                $this->callback(function (DateTimeImmutable $from) {
                    return true;
                }),
                $this->callback(function (DateTimeImmutable $to) {
                    return true;
                }),
                $this->isTrue(),
                $this->anything(),
                $this->equalTo('bybit'),
            )
            ->willReturnCallback(function ($symbols, $from, $to, $walk, $config, $exchange) use ($ownedRun) {
                // Verify the adaptive window between from and to is exactly 45 days
                $fromDt = CarbonImmutable::instance($from);
                $toDt = CarbonImmutable::instance($to);
                $diffDays = (int) $fromDt->diffInDays($toDt);
                $this->assertSame(45, $diffDays, 'Evaluation window must adapt to exactly 45 days.');

                return $ownedRun;
            });

        $this->app->instance(BacktestRunService::class, $mockBacktest);

        $job = new EvaluateStrategyCandidateJob($candidate->id);
        $this->app->call([$job, 'handle']);

        $reloaded = $candidate->fresh();
        $this->assertSame('COMPLETED', $reloaded->search_metadata['evaluation_status']);
        $this->assertSame($ownedRun->id, $reloaded->search_metadata['backtest_run_id']);
        $this->assertSame(45, $reloaded->search_metadata['available_candle_days']);
        $this->assertSame(45, $reloaded->search_metadata['validation_days']);
        $this->assertSame(45, $reloaded->search_metadata['required_candle_days']);
    }

    public function test_candidate_evaluation_window_caps_at_target_days_when_history_exceeds_target(): void
    {
        config([
            'research.candidate_evaluation.minimum_days' => 30,
            'research.candidate_evaluation.target_days' => 180,
        ]);

        // Candidate has 220 days available (exceeds 180d target)
        $candidate = $this->createCandidate('binance', 220);
        $ownedRun = $this->createOwnedBacktestRun($candidate->id, 'binance');

        $mockBacktest = $this->createMock(BacktestRunService::class);
        $mockBacktest->expects($this->once())
            ->method('run')
            ->willReturnCallback(function ($symbols, $from, $to, $walk, $config, $exchange) use ($ownedRun) {
                $fromDt = CarbonImmutable::instance($from);
                $toDt = CarbonImmutable::instance($to);
                $diffDays = (int) $fromDt->diffInDays($toDt);
                $this->assertSame(180, $diffDays, 'Evaluation window must cap at target 180 days.');

                return $ownedRun;
            });

        $this->app->instance(BacktestRunService::class, $mockBacktest);

        $job = new EvaluateStrategyCandidateJob($candidate->id);
        $this->app->call([$job, 'handle']);

        $reloaded = $candidate->fresh();
        $this->assertSame('COMPLETED', $reloaded->search_metadata['evaluation_status']);
        $this->assertSame(220, $reloaded->search_metadata['available_candle_days']);
        $this->assertSame(180, $reloaded->search_metadata['validation_days']);
        $this->assertSame(180, $reloaded->search_metadata['required_candle_days']);
    }

    public function test_structured_failure_diagnostics_are_recorded_when_history_is_below_minimum(): void
    {
        config([
            'research.candidate_evaluation.minimum_days' => 30,
            'research.candidate_evaluation.target_days' => 180,
        ]);

        // Candidate has only 14 days of history (< 30d minimum)
        $candidate = $this->createCandidate('bybit', 14);

        $mockBacktest = $this->createMock(BacktestRunService::class);
        $mockBacktest->expects($this->never())->method('run');
        $this->app->instance(BacktestRunService::class, $mockBacktest);

        $job = new EvaluateStrategyCandidateJob($candidate->id);
        // Must handle and persist results without crashing
        $this->app->call([$job, 'handle']);

        $reloaded = $candidate->fresh();
        $this->assertSame('REJECTED', $reloaded->status);

        // Assert search_metadata has structured diagnostics without suppression
        $metadata = $reloaded->search_metadata;
        $this->assertSame('FAILED', $metadata['evaluation_status']);
        $this->assertSame(InsufficientCandleHistoryException::class, $metadata['error_class']);
        $this->assertStringContainsString('14 days available', $metadata['error_message']);
        $this->assertStringContainsString('minimum 30 days required', $metadata['error_message']);
        $this->assertSame(14, $metadata['available_candle_days']);
        $this->assertSame(30, $metadata['required_candle_days']);
        $this->assertStringNotContainsString('details suppressed', $metadata['evaluation_error']);

        // Assert validation_gates has authentic structured rejection reasons
        $gates = $reloaded->validation_gates;
        $this->assertFalse($gates['all_gates_passed']);
        $this->assertSame('insufficient_candle_history', $gates['failure_reason']);
        $this->assertSame(InsufficientCandleHistoryException::class, $gates['error_class']);
        $this->assertStringContainsString('14 days available', $gates['error_message']);
        $this->assertSame(14, $gates['available_candle_days']);
        $this->assertSame(30, $gates['required_candle_days']);
        $this->assertStringNotContainsString('details suppressed', $gates['detail']);
    }

    public function test_structured_failure_diagnostics_are_recorded_when_backtest_execution_fails(): void
    {
        config([
            'research.candidate_evaluation.minimum_days' => 30,
            'research.candidate_evaluation.target_days' => 180,
            'research.evolution.max_evaluation_attempts' => 3,
        ]);

        $candidate = $this->createCandidate('bybit', 60);
        // Simulate candidate on attempt 2, so this evaluation is attempt 3 (final attempt)
        $metadata = $candidate->search_metadata;
        $metadata['evaluation_attempts'] = 2;
        $candidate->update(['search_metadata' => $metadata]);

        $mockBacktest = $this->createMock(BacktestRunService::class);
        $mockBacktest->expects($this->once())
            ->method('run')
            ->willThrowException(new \RuntimeException('Historical dataset corrupted at bar 412: checksum verification failed.'));
        $this->app->instance(BacktestRunService::class, $mockBacktest);

        $job = new EvaluateStrategyCandidateJob($candidate->id);
        // On final attempt, must handle and persist rejection without crashing
        $this->app->call([$job, 'handle']);

        $reloaded = $candidate->fresh();
        $this->assertSame('REJECTED', $reloaded->status);

        $metadata = $reloaded->search_metadata;
        $this->assertSame('FAILED', $metadata['evaluation_status']);
        $this->assertSame(\RuntimeException::class, $metadata['error_class']);
        $this->assertSame('Historical dataset corrupted at bar 412: checksum verification failed.', $metadata['error_message']);
        $this->assertSame(60, $metadata['available_candle_days']);
        $this->assertSame(60, $metadata['required_candle_days']);
        $this->assertSame(3, $metadata['evaluation_attempts']);
        $this->assertStringNotContainsString('details suppressed', $metadata['evaluation_error']);

        $gates = $reloaded->validation_gates;
        $this->assertFalse($gates['all_gates_passed']);
        $this->assertSame('backtest_execution_failed', $gates['failure_reason']);
        $this->assertSame(\RuntimeException::class, $gates['error_class']);
        $this->assertSame('Historical dataset corrupted at bar 412: checksum verification failed.', $gates['error_message']);
        $this->assertSame(60, $gates['available_candle_days']);
        $this->assertSame(60, $gates['required_candle_days']);
        $this->assertSame('Historical dataset corrupted at bar 412: checksum verification failed.', $gates['detail']);
        $this->assertSame(3, $gates['attempts']);
    }

    public function test_transient_failure_persists_diagnostics_to_database_without_losing_info(): void
    {
        config([
            'research.candidate_evaluation.minimum_days' => 30,
            'research.candidate_evaluation.target_days' => 180,
            'research.evolution.max_evaluation_attempts' => 3,
        ]);

        $candidate = $this->createCandidate('bybit', 60);
        $this->assertSame(0, $candidate->search_metadata['evaluation_attempts'] ?? 0);

        $mockBacktest = $this->createMock(BacktestRunService::class);
        $mockBacktest->expects($this->once())
            ->method('run')
            ->willThrowException(new \RuntimeException('Transient worker memory limit exceeded during Monte Carlo pass.'));
        $this->app->instance(BacktestRunService::class, $mockBacktest);

        $job = new EvaluateStrategyCandidateJob($candidate->id);

        try {
            $this->app->call([$job, 'handle']);
            $this->fail('Expected transient exception to be re-thrown for queue worker retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Transient worker memory limit exceeded during Monte Carlo pass.', $e->getMessage());
        }

        // Verify that diagnostics are ALREADY persisted in the database, not lost!
        $reloaded = $candidate->fresh();
        $this->assertSame('RESEARCH_ONLY', $reloaded->status, 'Transient failure should retain candidate for retry.');

        $metadata = $reloaded->search_metadata;
        $this->assertSame('FAILED', $metadata['evaluation_status']);
        $this->assertSame(\RuntimeException::class, $metadata['error_class']);
        $this->assertSame('Transient worker memory limit exceeded during Monte Carlo pass.', $metadata['error_message']);
        $this->assertSame(60, $metadata['available_candle_days']);
        $this->assertSame(60, $metadata['required_candle_days']);
        $this->assertSame(1, $metadata['evaluation_attempts']);
        $this->assertStringNotContainsString('details suppressed', $metadata['evaluation_error']);
    }

    public function test_candidate_evaluation_resolves_available_candle_days_from_real_database_candles(): void
    {
        config([
            'research.candidate_evaluation.minimum_days' => 30,
            'research.candidate_evaluation.target_days' => 180,
        ]);

        $symbol = 'BTCUSDT';
        $exchange = 'bybit';
        $category = 'linear';
        $interval = '60';

        // Seed 41 days of closed 60m candles (41 * 24 = 984 bars, spanning 40+ full days)
        $t0 = CarbonImmutable::parse('2026-09-01 00:00:00');
        $now = now('UTC');
        $rows = [];

        for ($day = 0; $day <= 40; $day++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $openTime = $t0->addDays($day)->addHours($hour);
                $rows[] = [
                    'exchange' => $exchange,
                    'category' => $category,
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'open_time' => $openTime->format('Y-m-d H:i:s.vP'),
                    'open' => '60000.000000000000',
                    'high' => '60500.000000000000',
                    'low' => '59800.000000000000',
                    'close' => '60200.000000000000',
                    'volume' => '100.000000000000',
                    'turnover' => '6020000.000000000000',
                    'is_closed' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        CandleRecord::query()->insert($rows);

        // Candidate without forced available_candle_days: should dynamically resolve 40 days from DB
        $candidate = $this->createCandidate($exchange, null, [$symbol]);
        $ownedRun = $this->createOwnedBacktestRun($candidate->id, $exchange);

        $mockBacktest = $this->createMock(BacktestRunService::class);
        $mockBacktest->expects($this->once())
            ->method('run')
            ->willReturnCallback(function ($symbols, $from, $to, $walk, $config, $exchange) use ($ownedRun) {
                $fromDt = CarbonImmutable::instance($from);
                $toDt = CarbonImmutable::instance($to);
                $diffDays = (int) $fromDt->diffInDays($toDt);
                $this->assertSame(40, $diffDays, 'Validation window should automatically match the 40 days of candles.');

                return $ownedRun;
            });

        $this->app->instance(BacktestRunService::class, $mockBacktest);

        $job = new EvaluateStrategyCandidateJob($candidate->id);
        $this->app->call([$job, 'handle']);

        $reloaded = $candidate->fresh();
        $this->assertSame('COMPLETED', $reloaded->search_metadata['evaluation_status']);
        $this->assertSame(40, $reloaded->search_metadata['available_candle_days']);
        $this->assertSame(40, $reloaded->search_metadata['validation_days']);
        $this->assertSame(40, $reloaded->search_metadata['required_candle_days']);
    }
}
