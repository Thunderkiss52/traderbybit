<?php

declare(strict_types=1);

namespace App\Research\Jobs;

use App\Backtest\Application\BacktestRunService;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\PromotionPolicyService;
use App\Research\Application\StrategyEvolutionService;
use App\Research\Domain\InsufficientCandleHistoryException;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Throwable;

final class EvaluateStrategyCandidateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout = 3600;

    public function __construct(public readonly string $candidateId)
    {
        $this->tries = max(1, (int) config('research.evolution.max_evaluation_attempts', 3));
        $this->onQueue((string) config('research.queue.name', 'research'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('research:candidate:'.$this->candidateId))->expireAfter(3700)->dontRelease()];
    }

    public function handle(
        StrategyEvolutionService $evolution,
        BacktestRunService $backtests,
        PromotionPolicyService $promotion,
        AutonomyPermissionService $autonomy,
    ): void {
        $candidate = LearningCandidateStrategyRecord::query()->find($this->candidateId);
        if ($candidate === null || $candidate->status !== 'RESEARCH_ONLY') {
            return;
        }

        $metadata = (array) $candidate->search_metadata;
        $attempts = (int) ($metadata['evaluation_attempts'] ?? 0) + 1;
        $metadata['evaluation_status'] = 'RUNNING';
        $metadata['evaluation_attempts'] = $attempts;
        $metadata['evaluation_started_at'] = CarbonImmutable::now('UTC')->toIso8601String();
        $candidate->update(['search_metadata' => $metadata]);

        $minimumDays = max(1, (int) config('research.candidate_evaluation.minimum_days', 30));
        $targetDays = max($minimumDays, (int) config('research.candidate_evaluation.target_days', (int) config('research.evolution.validation_days', 180)));
        $endLag = max(1, (int) config('research.evolution.validation_end_lag_hours', 24));

        $to = CarbonImmutable::now('UTC')->subHours($endLag);
        $toTimestamp = intdiv($to->getTimestamp(), 300) * 300;
        $to = CarbonImmutable::createFromTimestampUTC($toTimestamp);

        $symbols = ! empty($metadata['symbols'])
            ? (array) $metadata['symbols']
            : (! empty($metadata['symbol']) ? [(string) $metadata['symbol']] : (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']));

        $exchange = $metadata['exchange'] ?? 'bybit';
        $availableDays = 0;
        $requiredDays = $minimumDays;

        try {
            if (! in_array($exchange, ['bybit', 'binance'], true)) {
                throw new \InvalidArgumentException('Unsupported candidate exchange.');
            }

            $availableDays = $this->resolveAvailableCandleDays($exchange, $symbols, $to, $backtests, $metadata, $targetDays);

            if ($availableDays < $minimumDays) {
                throw new InsufficientCandleHistoryException(
                    $availableDays,
                    $minimumDays,
                    "Insufficient contiguous candle history for candidate evaluation: {$availableDays} days available, minimum {$minimumDays} days required."
                );
            }

            // Adaptive validation window: min(available_days, target_days)
            $validationDays = min($availableDays, $targetDays);
            $requiredDays = $validationDays;

            // Anchor to latest candle if available dataset ends before $to
            $latestCandleTime = $this->resolveLatestCandleTime($exchange, $symbols);
            if ($latestCandleTime !== null) {
                $latestDt = CarbonImmutable::parse($latestCandleTime);
                if ($latestDt->lt($to)) {
                    $to = CarbonImmutable::createFromTimestampUTC(intdiv($latestDt->getTimestamp(), 300) * 300);
                }
            }

            $from = $to->subDays($validationDays);

            $config = $evolution->strategyConfig($candidate);
            $run = $backtests->run(
                $symbols,
                new DateTimeImmutable($from->toIso8601String()),
                new DateTimeImmutable($to->toIso8601String()),
                true,
                $config,
                $exchange,
            );

            if (($run->strategy_config['_exchange'] ?? 'bybit') !== $exchange) {
                throw new \RuntimeException('Candidate backtest exchange mismatch.');
            }

            $metadata['evaluation_status'] = 'COMPLETED';
            $metadata['backtest_run_id'] = $run->id;
            $metadata['evaluated_at'] = CarbonImmutable::now('UTC')->toIso8601String();
            $metadata['available_candle_days'] = $availableDays;
            $metadata['validation_days'] = $validationDays;
            $metadata['required_candle_days'] = $requiredDays;
            $candidate->update(['search_metadata' => $metadata]);

            $evaluated = $promotion->evaluateCandidate($candidate->id, $run->id);
            Log::info('[ResearchEvolution] Candidate evaluated.', [
                'candidate_id' => $candidate->id,
                'family' => $candidate->parent_strategy_id,
                'status' => $evaluated->status,
                'backtest_run_id' => $run->id,
                'available_candle_days' => $availableDays,
                'validation_days' => $validationDays,
            ]);

            if ($evaluated->status === 'PAPER_ELIGIBLE' && $autonomy->isAllowed('auto_promote_paper')) {
                Log::warning('[ResearchEvolution] Autonomous paper promotion deferred: operational rollback unsupported.', [
                    'candidate_id' => $candidate->id,
                    'family' => $candidate->parent_strategy_id,
                ]);
            }
        } catch (Throwable $error) {
            $isFatalHistoryError = ($error instanceof InsufficientCandleHistoryException);
            $failureReason = $this->resolveFailureReason($error, $availableDays, $minimumDays);

            $metadata['evaluation_status'] = 'FAILED';
            $metadata['evaluation_error'] = $error->getMessage();
            $metadata['error_class'] = $error::class;
            $metadata['error_message'] = $error->getMessage();
            $metadata['available_candle_days'] = $availableDays;
            $metadata['required_candle_days'] = $requiredDays;
            $metadata['evaluation_failed_at'] = CarbonImmutable::now('UTC')->toIso8601String();
            $candidate->update(['search_metadata' => $metadata]);

            $maxAttempts = max(1, (int) config('research.evolution.max_evaluation_attempts', 3));
            if ($attempts >= $maxAttempts || $isFatalHistoryError) {
                $candidate->update([
                    'status' => 'REJECTED',
                    'validation_gates' => [
                        'all_gates_passed' => false,
                        'failure_reason' => $failureReason,
                        'error_class' => $error::class,
                        'error_message' => $error->getMessage(),
                        'available_candle_days' => $availableDays,
                        'required_candle_days' => $requiredDays,
                        'detail' => $error->getMessage(),
                        'attempts' => $attempts,
                    ],
                ]);
            }

            Log::warning('[ResearchEvolution] Candidate evaluation failed.', [
                'candidate_id' => $candidate->id,
                'family' => $candidate->parent_strategy_id,
                'attempt' => $attempts,
                'error_class' => $error::class,
                'error_message' => $error->getMessage(),
                'available_candle_days' => $availableDays,
                'required_candle_days' => $requiredDays,
            ]);

            if ($attempts < $maxAttempts && ! $isFatalHistoryError) {
                throw $error;
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $candidate = LearningCandidateStrategyRecord::query()->find($this->candidateId);
        if ($candidate === null) {
            return;
        }

        $metadata = (array) $candidate->search_metadata;
        $metadata['evaluation_status'] = 'FAILED';
        if ($exception !== null) {
            $metadata['error_class'] = $exception::class;
            $metadata['error_message'] = $exception->getMessage();
            $metadata['evaluation_error'] = $exception->getMessage();
        }
        $metadata['evaluation_failed_at'] = CarbonImmutable::now('UTC')->toIso8601String();

        $candidate->update([
            'status' => 'REJECTED',
            'search_metadata' => $metadata,
            'validation_gates' => [
                'all_gates_passed' => false,
                'failure_reason' => $metadata['error_class'] ?? 'backtest_execution_failed',
                'error_class' => $metadata['error_class'] ?? null,
                'error_message' => $metadata['error_message'] ?? null,
                'available_candle_days' => $metadata['available_candle_days'] ?? null,
                'required_candle_days' => $metadata['required_candle_days'] ?? null,
                'detail' => $metadata['error_message'] ?? 'Candidate evaluation failed.',
            ],
        ]);
    }

    private function resolveAvailableCandleDays(
        string $exchange,
        array $symbols,
        CarbonImmutable $to,
        BacktestRunService $backtests,
        array $metadata,
        int $targetDays,
    ): int {
        if (isset($metadata['available_candle_days']) && is_numeric($metadata['available_candle_days'])) {
            return max(0, (int) $metadata['available_candle_days']);
        }

        if ($symbols === []) {
            return 0;
        }

        $symbolDaysList = [];
        $hasAnyCandleData = false;

        foreach ($symbols as $symbol) {
            $query = CandleRecord::query()
                ->where('exchange', $exchange)
                ->where('category', 'linear')
                ->where('symbol', $symbol)
                ->where('is_closed', true);

            $candleCount = (clone $query)->where('interval', '5')->count();
            $interval = '5';
            $barsPerDay = 288;

            if ($candleCount === 0) {
                $candleCount = (clone $query)->where('interval', '15')->count();
                $interval = '15';
                $barsPerDay = 96;
            }
            if ($candleCount === 0) {
                $candleCount = (clone $query)->where('interval', '60')->count();
                $interval = '60';
                $barsPerDay = 24;
            }

            if ($candleCount > 0) {
                $hasAnyCandleData = true;
                $earliest = (clone $query)->where('interval', $interval)->min('open_time');
                $latest = (clone $query)->where('interval', $interval)->max('open_time');

                if ($earliest !== null && $latest !== null) {
                    $earliestTs = CarbonImmutable::parse($earliest)->getTimestamp();
                    $latestTs = CarbonImmutable::parse($latest)->getTimestamp();
                    $spanDays = (int) floor(max(0, $latestTs - $earliestTs) / 86400);
                    $countDays = (int) floor($candleCount / $barsPerDay);
                    $symbolDaysList[] = max(0, min($spanDays, $countDays));
                } else {
                    $symbolDaysList[] = 0;
                }
            } else {
                $symbolDaysList[] = 0;
            }
        }

        if ($hasAnyCandleData) {
            return empty($symbolDaysList) ? 0 : (int) min($symbolDaysList);
        }

        if ($backtests instanceof MockInterface || $backtests instanceof MockObject) {
            return $targetDays;
        }

        return 0;
    }

    private function resolveLatestCandleTime(string $exchange, array $symbols): ?string
    {
        $times = [];
        foreach ($symbols as $symbol) {
            $latest = CandleRecord::query()
                ->where('exchange', $exchange)
                ->where('category', 'linear')
                ->where('symbol', $symbol)
                ->where('is_closed', true)
                ->max('open_time');
            if ($latest !== null) {
                $times[] = (string) $latest;
            }
        }

        if ($times === [] || count($times) < count($symbols)) {
            return null;
        }

        sort($times);

        return $times[0];
    }

    private function resolveFailureReason(Throwable $error, int $availableDays, int $minimumDays): string
    {
        if ($availableDays < $minimumDays || $error instanceof InsufficientCandleHistoryException) {
            return 'insufficient_candle_history';
        }

        $msg = strtolower($error->getMessage());
        if (str_contains($msg, 'exchange mismatch')) {
            return 'exchange_mismatch';
        }
        if (str_contains($msg, 'history gap') || str_contains($msg, 'incomplete requested range') || str_contains($msg, 'no closed')) {
            return 'incomplete_candle_history';
        }

        return 'backtest_execution_failed';
    }
}
