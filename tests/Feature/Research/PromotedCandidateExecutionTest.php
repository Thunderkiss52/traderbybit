<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\SeedsValidatedPaperEvidence;
use Tests\TestCase;

final class PromotedCandidateExecutionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsValidatedPaperEvidence;

    #[DataProvider('evidenceAvailability')]
    public function test_promoted_strategy_candidate_receives_signals_and_virtual_executions(bool $hasEvidence): void
    {
        $parentStrategyId = 'trend_pullback';
        $candidateVersion = 'trend_pullback_v2_promoted';
        $timeStr = '2026-10-14T10:15:00Z';
        $decisionTime = new DateTimeImmutable($timeStr);
        $this->travelTo(CarbonImmutable::instance($decisionTime)->subMinute());

        // 1. Provision dedicated forward paper trading account for the candidate
        $accountService = $this->app->make(PaperAccountService::class);
        $candidateAccount = $accountService->strategyForward(
            $parentStrategyId,
            $candidateVersion,
            ['ema_fast' => 20, 'ema_slow' => 50, 'version' => $candidateVersion]
        );
        $candidateAccount->update(['sizing_settings' => ['mode' => 'risk_percent']]);

        // 2. Persist promoted candidate record
        $candidateRecord = LearningCandidateStrategyRecord::query()->create([
            'id' => '857e8ebb-ebc6-5009-8d5a-d093c51dadfd',
            'name' => 'Trend Pullback Optimized Candidate',
            'parent_strategy_id' => $parentStrategyId,
            'base_version' => '1.0.0',
            'candidate_version' => $candidateVersion,
            'configuration_hash' => hash('sha256', 'test-config'),
            'hypothesis' => 'Optimized EMA parameters for strong trend regime',
            'parameters' => ['ema_fast' => 20, 'ema_slow' => 50, 'version' => $candidateVersion],
            'parameter_diff' => ['ema_fast' => ['from' => 10, 'to' => 20]],
            'search_metadata' => ['method' => 'grid_search'],
            'status' => 'PAPER_PROMOTED',
            'paper_account_id' => $candidateAccount->id,
            'promoted_at' => $decisionTime,
            'metrics' => [
                'validation_run_id' => 'run-val-1',
                'sample_size' => 45,
                'expectancy_r' => 0.42,
                'max_drawdown_percent' => 2.0,
                'ece' => 0.04,
            ],
            'validation_gates' => [
                'all_gates_passed' => true,
            ],
        ]);

        $this->assertSame('PAPER_PROMOTED', $candidateRecord->status);
        $this->assertSame($candidateAccount->id, $candidateRecord->paper_account_id);

        $this->travelTo(CarbonImmutable::instance($decisionTime));

        // 3. Create a candidate signal tagged with candidate's version
        $signal = SignalRecord::query()->create([
            'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "cand-signal:{$candidateVersion}:{$timeStr}")->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 92,
            'decision_time' => $decisionTime,
            'candle_close_time' => $decisionTime,
            'entry_low' => '60000',
            'entry_high' => '60100',
            'stop_loss' => '59500',
            'take_profit_1' => '61000',
            'take_profit_2' => '61500',
            'risk_reward_1' => '1.8',
            'risk_reward_2' => '2.8',
            'model_risk_percent' => '0.50',
            'expires_at' => $decisionTime->modify('+30 minutes'),
            'maximum_holding_minutes' => 240,
            'reasons' => ['trend_aligned', 'pullback_to_ema20'],
            'risks' => [],
            'indicator_snapshot' => ['strategy_id' => $parentStrategyId, '15m' => ['close' => '60050', 'atr_14' => '100']],
            'contract' => ['research_mode' => true],
            'strategy_version' => $candidateVersion,
            'schema_version' => '1.0.0',
            'strategy_id' => $parentStrategyId,
        ]);

        // 4. Create market context
        MarketContextRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'observed_at' => $decisionTime,
            'bid' => '60040',
            'ask' => '60050',
            'turnover_24h' => '100000000',
            'tick_size' => '0.5',
            'funding_rate' => '0.0001',
            'open_interest' => '50000',
            'qty_step' => '0.001',
            'min_order_qty' => '0.001',
            'max_order_qty' => '100',
            'min_notional_value' => '5',
        ]);

        if ($hasEvidence) {
            $this->seedValidatedPaperEvidence($signal);
        }

        // Establish PENDING_ENTRY before the execution candle has closed.
        $processingService = $this->app->make(PaperProcessingService::class);
        $processingService->process(accountId: $candidateAccount->id, symbol: 'BTCUSDT', at: $decisionTime, historical: false);
        $this->travelTo(CarbonImmutable::instance($decisionTime)->addMinutes(5));

        // 5. Create 5m execution candle within entry zone
        DB::table('market_candles')->insert([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '5',
            'open_time' => $decisionTime,
            'open' => '60020',
            'high' => '60080',
            'low' => '60010',
            'close' => '60060',
            'volume' => '50',
            'turnover' => '3000000',
            'is_closed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 6. Process paper trading specifically for this candidate account
        $result = $processingService->process(
            accountId: $candidateAccount->id,
            symbol: 'BTCUSDT',
            at: $decisionTime->modify('+5 minutes'),
            historical: false,
        );

        $this->assertSame($candidateAccount->id, $result['account_id']);

        // 7. Verify the candidate's account ingested the signal and executed virtual position
        $state = PaperSignalStateRecord::query()
            ->where('paper_account_id', $candidateAccount->id)
            ->where('signal_id', $signal->id)
            ->first();

        $this->assertNotNull($state, 'Candidate account must ingest its version-matched signal.');
        if (! $hasEvidence) {
            self::assertSame('REJECTED', $state->status);
            self::assertSame('missing_or_stale_validated_edge_or_cost_evidence', $state->reason);
            self::assertFalse(VirtualPositionRecord::query()->where('paper_account_id', $candidateAccount->id)->where('signal_id', $signal->id)->exists());
            self::assertFalse(VirtualExecutionRecord::query()->whereIn('position_id',
                VirtualPositionRecord::query()->select('id')->where('paper_account_id', $candidateAccount->id)
            )->exists());

            return;
        }
        $this->assertSame('ACTIVE', $state->status, 'Stored rejection reason: '.($state->reason ?? 'none'));

        // Verify open virtual position created for candidate account
        $position = VirtualPositionRecord::query()
            ->where('paper_account_id', $candidateAccount->id)
            ->where('signal_id', $signal->id)
            ->first();

        $this->assertNotNull($position, 'Virtual position must be opened for candidate account.');
        $this->assertSame('OPEN', $position->status);
        $this->assertSame('BUY', $position->side);
        $this->assertSame($candidateVersion, $position->strategy_version);

        // Verify virtual execution linked to the position
        $execution = VirtualExecutionRecord::query()
            ->where('position_id', $position->id)
            ->first();

        $this->assertNotNull($execution, 'Virtual execution must be created for the position.');
        $this->assertSame('ENTRY', $execution->type);
        $this->assertSame('BUY', $execution->side);
    }

    public static function evidenceAvailability(): array
    {
        return ['matured_scoped_history' => [true], 'missing_history_blocks_entry' => [false]];
    }
}
