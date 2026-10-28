<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Backtest\Application\BacktestRunService;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Research\Jobs\EvaluateStrategyCandidateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CandidateLabValidationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_without_run_id_marks_missing_evidence_and_allows_validation_scheduling(): void
    {
        $genRes = $this->postJson('/api/v1/research/learning/candidates/generate', [
            'parent_strategy_id' => 'trend_pullback',
            'seed' => 1234,
        ]);
        $genRes->assertOk();
        $candidateId = (string) $genRes->json('data.id');

        $evalRes = $this->postJson("/api/v1/research/learning/candidates/{$candidateId}/evaluate");
        $evalRes->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.validation_gates.status', 'MISSING_EVIDENCE')
            ->assertJsonPath('data.validation_gates.failure_reason', 'missing_validation_evidence')
            ->assertJsonPath('data.validation_gates.all_gates_passed', false);

        $candidate = LearningCandidateStrategyRecord::query()->findOrFail($candidateId);
        $this->assertSame('REJECTED', $candidate->status);
        $this->assertSame('MISSING_EVIDENCE', $candidate->validation_gates['status'] ?? null);

        // Candidate can subsequently be scheduled for validation
        Queue::fake();
        $validateRes = $this->postJson("/api/v1/research/learning/candidates/{$candidateId}/validate");
        $validateRes->assertOk()
            ->assertJsonPath('data.status', 'RESEARCH_ONLY')
            ->assertJsonPath('data.validation_gates.status', 'VALIDATION_IN_PROGRESS');
    }

    public function test_candidate_validation_scheduling_is_bounded_and_idempotent(): void
    {
        Queue::fake();

        $genRes = $this->postJson('/api/v1/research/learning/candidates/generate', [
            'parent_strategy_id' => 'trend_pullback',
            'seed' => 5678,
        ]);
        $genRes->assertOk();
        $candidateId = (string) $genRes->json('data.id');

        // First schedule call
        $validateRes1 = $this->postJson("/api/v1/research/learning/candidates/{$candidateId}/validate");
        $validateRes1->assertOk()
            ->assertJsonPath('data.status', 'RESEARCH_ONLY')
            ->assertJsonPath('data.search_metadata.evaluation_status', 'QUEUED')
            ->assertJsonPath('data.validation_gates.status', 'VALIDATION_IN_PROGRESS');

        Queue::assertPushed(EvaluateStrategyCandidateJob::class, 1);

        // Second schedule call should be idempotent (no duplicate job dispatched)
        $validateRes2 = $this->postJson("/api/v1/research/learning/candidates/{$candidateId}/validate");
        $validateRes2->assertOk()
            ->assertJsonPath('data.search_metadata.evaluation_status', 'QUEUED');

        Queue::assertPushed(EvaluateStrategyCandidateJob::class, 1);

        // Exhaust attempts and verify bounded rejection
        $candidate = LearningCandidateStrategyRecord::query()->findOrFail($candidateId);
        $candidate->update([
            'search_metadata' => array_merge((array) $candidate->search_metadata, [
                'evaluation_status' => 'FAILED',
                'evaluation_attempts' => 3,
            ]),
        ]);

        $validateRes3 = $this->postJson("/api/v1/research/learning/candidates/{$candidateId}/validate");
        $validateRes3->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Candidate exceeded maximum validation attempts (3).');
    }

    public function test_candidate_validation_scheduling_fails_closed_when_kill_switch_active(): void
    {
        Queue::fake();

        $genRes = $this->postJson('/api/v1/research/learning/candidates/generate', [
            'parent_strategy_id' => 'trend_pullback',
            'seed' => 9999,
        ]);
        $genRes->assertOk();
        $candidateId = (string) $genRes->json('data.id');

        $this->postJson('/api/v1/research/learning/kill-switch', [
            'active' => true,
            'reason' => 'Emergency audit lock',
        ])->assertOk();

        $validateRes = $this->postJson("/api/v1/research/learning/candidates/{$candidateId}/validate");
        $validateRes->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Autonomy kill-switch is active. Validation scheduling is paused.');

        Queue::assertNothingPushed();
    }

    public function test_candidate_evaluates_with_completed_backtest_evidence_and_records_rejection_reasons(): void
    {
        $genRes = $this->postJson('/api/v1/research/learning/candidates/generate', [
            'parent_strategy_id' => 'trend_pullback',
            'seed' => 4444,
        ]);
        $candidateId = (string) $genRes->json('data.id');

        // Create a completed run with insufficient OOS sample count (< 100)
        $run = BacktestRunRecord::query()->create([
            'id' => (string) Str::uuid(),
            'fingerprint' => 'fp-'.Str::random(16),
            'symbols' => ['BTCUSDT'],
            'strategy_config' => ['id' => 'trend_pullback'],
            'risk_config' => [],
            'execution_config' => [],
            'strategy_version' => '1.3.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'backtest_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'dataset_checksum' => 'checksum-'.Str::random(16),
            'status' => 'COMPLETED',
            'type' => 'SPLIT',
            'from_at' => now()->subDays(30),
            'to_at' => now(),
            'metrics' => [
                'test' => [
                    'total_trades' => 40,
                    'average_r' => 0.35,
                    'profit_factor' => 1.70,
                    'win_rate' => 0.55,
                    'maximum_drawdown_percent' => 5.0,
                ],
                'test_is_out_of_sample' => true,
            ],
            'started_at' => now()->subHours(1),
            'completed_at' => now(),
        ]);

        $evalRes = $this->postJson("/api/v1/research/learning/candidates/{$candidateId}/evaluate", [
            'validation_run_id' => $run->id,
        ]);

        $evalRes->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.validation_gates.all_gates_passed', false)
            ->assertJsonPath('data.validation_gates.failure_reason', 'insufficient_sample_size');
    }

    public function test_f25_evaluate_strategy_candidate_job_durable_backtest_linkage_with_competing_unrelated_run(): void
    {
        // 1. Create a competing unrelated backtest run in the database
        $competingRun = BacktestRunRecord::query()->create([
            'id' => (string) Str::uuid(),
            'fingerprint' => 'competing-fp-'.Str::random(16),
            'symbols' => ['BTCUSDT'],
            'strategy_config' => ['id' => 'competing_strategy'],
            'risk_config' => [],
            'execution_config' => [],
            'strategy_version' => '2.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'backtest_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'dataset_checksum' => 'checksum-competing-'.Str::random(16),
            'status' => 'COMPLETED',
            'type' => 'SPLIT',
            'from_at' => now()->subDays(60),
            'to_at' => now()->subDays(10),
            'metrics' => [
                'test' => [
                    'total_trades' => 500,
                    'average_r' => 1.5,
                    'profit_factor' => 2.5,
                    'win_rate' => 0.70,
                    'maximum_drawdown_percent' => 3.0,
                ],
                'test_is_out_of_sample' => true,
            ],
            'started_at' => now()->subHours(2),
            'completed_at' => now()->subHour(),
        ]);

        // 2. Generate candidate strategy record
        $genRes = $this->postJson('/api/v1/research/learning/candidates/generate', [
            'parent_strategy_id' => 'trend_pullback',
            'seed' => 7777,
        ]);
        $candidateId = (string) $genRes->json('data.id');

        // 3. Create the candidate's actual owned backtest run
        $ownedRun = BacktestRunRecord::query()->create([
            'id' => (string) Str::uuid(),
            'fingerprint' => 'owned-fp-'.Str::random(16),
            'symbols' => ['BTCUSDT', 'ETHUSDT'],
            'strategy_config' => ['id' => 'trend_pullback', 'parameters' => ['ema_period' => 21]],
            'risk_config' => [],
            'execution_config' => [],
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'backtest_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'dataset_checksum' => 'checksum-owned-'.Str::random(16),
            'status' => 'COMPLETED',
            'type' => 'SPLIT',
            'from_at' => now()->subDays(180),
            'to_at' => now()->subHours(24),
            'metrics' => [
                'test' => [
                    'total_trades' => 150,
                    'average_r' => 0.45,
                    'profit_factor' => 1.85,
                    'win_rate' => 0.58,
                    'maximum_drawdown_percent' => 6.5,
                ],
                'test_is_out_of_sample' => true,
            ],
            'started_at' => now()->subHours(1),
            'completed_at' => now(),
        ]);

        // Mock BacktestRunService to return the candidate's owned run
        $mockBacktestService = $this->createMock(BacktestRunService::class);
        $mockBacktestService->expects($this->once())
            ->method('run')
            ->willReturn($ownedRun);
        $this->app->instance(BacktestRunService::class, $mockBacktestService);

        // 4. Execute EvaluateStrategyCandidateJob
        $job = new EvaluateStrategyCandidateJob($candidateId);
        $this->app->call([$job, 'handle']);

        // 5. Reload candidate from DB and assert durable linkage
        $reloaded = LearningCandidateStrategyRecord::query()->findOrFail($candidateId);

        // Assert candidate is linked strictly to its owned backtest run, NOT the competing run
        $this->assertSame($ownedRun->id, $reloaded->search_metadata['backtest_run_id']);
        $this->assertNotSame($competingRun->id, $reloaded->search_metadata['backtest_run_id']);
        $this->assertSame('COMPLETED', $reloaded->search_metadata['evaluation_status']);
        $this->assertSame(1, $reloaded->search_metadata['evaluation_attempts']);

        // Assert validation gates evaluated and durable outcome recorded
        $this->assertNotNull($reloaded->validation_gates);
        $this->assertArrayHasKey('all_gates_passed', $reloaded->validation_gates);

        // Assert no live exchange promotion occurred (strict safety invariant)
        $this->assertNotSame('ACTIVE', $reloaded->status);
        $this->assertNotSame('LIVE_PROMOTED', $reloaded->status);
    }
}
