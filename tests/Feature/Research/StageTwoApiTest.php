<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Infrastructure\Persistence\CounterfactualEvaluationRecord;
use App\Strategy\Infrastructure\Persistence\LevelInteractionForecastRecord;
use App\Strategy\Infrastructure\Persistence\ResearchExperimentRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StageTwoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_opportunities_and_detail_endpoints(): void
    {
        $now = CarbonImmutable::now();

        $eval = CounterfactualEvaluationRecord::query()->create([
            'id' => (string) Str::uuid(),
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'decision_time' => $now->subHours(2),
            'skip_reason' => 'adx_below_threshold',
            'filter_family' => 'ADX',
            'candidate_direction' => 'BUY',
            'hypothetical_entry' => '50000.0',
            'hypothetical_sl' => '49000.0',
            'hypothetical_tp' => '52000.0',
            'status' => 'RESOLVED_TP',
            'mfe' => '52500.0',
            'mae' => '49500.0',
            'r_multiple' => '2.0',
            'net_return_percent' => '4.0',
            'resolved_at' => $now->subHour(),
            'decision_snapshot' => ['adx' => 18.0],
        ]);

        $response = $this->getJson('/api/v1/research/opportunities');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'summary',
                    'evaluations',
                ],
            ]);

        $detailResponse = $this->getJson('/api/v1/research/opportunities/'.$eval->id);
        $detailResponse->assertOk()
            ->assertJsonPath('data.id', $eval->id)
            ->assertJsonPath('data.strategy_id', 'trend_pullback')
            ->assertJsonPath('data.status', 'RESOLVED_TP');
    }

    public function test_replay_endpoint(): void
    {
        $t0 = CarbonImmutable::now()->subHours(2);

        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0,
            'open' => '65000',
            'high' => '65500',
            'low' => '64800',
            'close' => '65200',
            'volume' => '200',
            'turnover' => '13000000',
            'is_closed' => true,
        ]);

        $obs = StrategyObservationRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_test',
            'side' => 'BUY',
            'score' => 85,
            'decision_time' => $t0,
            'reasons' => ['trend_aligned'],
        ]);

        $writes = 0;
        DB::listen(static function ($query) use (&$writes): void {
            $sql = strtoupper(ltrim($query->sql));
            if (str_starts_with($sql, 'INSERT') || str_starts_with($sql, 'UPDATE') || str_starts_with($sql, 'DELETE')) {
                $writes++;
            }
        });

        $countBefore = StrategyObservationRecord::query()->count();

        $response = $this->getJson('/api/v1/research/replay/'.$obs->id.'?mode=AS_OF_DECISION');
        $response->assertOk()
            ->assertJsonPath('data.mode', 'AS_OF_DECISION')
            ->assertJsonPath('data.is_reproducible', false)
            ->assertJsonPath('data.status', 'NOT_RECOMPUTED_STORED_ONLY')
            ->assertJsonPath('data.recomputed_decision.side', 'BUY');

        $response2 = $this->getJson('/api/v1/research/replay/'.$obs->id.'?mode=AS_OF_DECISION');
        $response2->assertOk()
            ->assertJsonPath('data.mode', 'AS_OF_DECISION')
            ->assertJsonPath('data.is_reproducible', false)
            ->assertJsonPath('data.status', 'NOT_RECOMPUTED_STORED_ONLY');

        self::assertSame(0, $writes, 'GET replay must perform zero INSERT/UPDATE/DELETE mutations.');
        self::assertSame($countBefore, StrategyObservationRecord::query()->count());
    }

    public function test_calibration_endpoint(): void
    {
        for ($i = 0; $i < 5; $i++) {
            LevelInteractionForecastRecord::query()->create([
                'id' => (string) Str::uuid(),
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'event' => 'BOUNCE',
                'side' => 'BUY',
                'triggered_at' => now('UTC')->subHours(10 - $i),
                'horizon_ends_at' => now('UTC')->subHours(6 - $i),
                'model_version' => '1.0.0',
                'configuration_hash' => 'hash_test',
                'sample_size' => 50,
                'validation_status' => 'IN_SAMPLE',
                'outcome' => $i % 2 === 0 ? 'BOUNCE' : 'REJECTION',
                'outcome_at' => now('UTC')->subHours(6 - $i),
                'success_probability' => '0.70',
                'failure_probability' => '0.30',
                'unresolved_probability' => '0.00',
                'features' => ['level_price' => '60000'],
            ]);
        }

        $response = $this->getJson('/api/v1/research/calibration?symbol=BTCUSDT');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'total_samples',
                    'brier_score',
                    'log_loss',
                    'ece',
                    'reliability_bins',
                ],
            ]);
    }

    public function test_shadow_experiment_start_and_stop_endpoints(): void
    {
        $record = ResearchExperimentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_test',
            'parameters' => ['adx_min' => 20.0],
            'symbols' => ['BTCUSDT'],
            'from_at' => now('UTC')->subDays(7),
            'to_at' => now('UTC'),
            'evaluation_mode' => 'split',
            'status' => 'DRAFT',
            'is_shadow' => true,
            'shadow_status' => 'STOPPED',
            'hypothesis' => 'Lowering ADX filter to 20 increases trade frequency without sacrificing win rate',
            'resource_budget' => ['timeout_seconds' => 3600],
        ]);

        $startRes = $this->postJson('/api/v1/research/experiments/'.$record->id.'/start-shadow');
        $startRes->assertOk()
            ->assertJsonPath('data.is_shadow', true)
            ->assertJsonPath('data.shadow_status', 'RUNNING');

        $stopRes = $this->postJson('/api/v1/research/experiments/'.$record->id.'/stop-shadow');
        $stopRes->assertOk()
            ->assertJsonPath('data.shadow_status', 'PAUSED');
    }
}
