<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Backtest\Application\BacktestRunService;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\Research\Application\PromotionPolicyService;
use App\Research\Application\StrategyEvolutionService;
use App\Research\Jobs\EvaluateStrategyCandidateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CandidateExchangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_api_persists_exchange_and_rejects_unknown_identity(): void
    {
        $this->postJson('/api/v1/research/learning/candidates/generate', ['parent_strategy_id' => 'trend_pullback', 'seed' => 7777, 'exchange' => 'binance'])
            ->assertOk()->assertJsonPath('data.search_metadata.exchange', 'binance');
        $before = DB::table('learning_candidate_strategies')->count();
        $this->postJson('/api/v1/research/learning/candidates/generate', ['exchange' => 'unknown'])->assertUnprocessable();
        self::assertSame($before, DB::table('learning_candidate_strategies')->count());
    }

    public function test_binance_candidate_passes_exchange_and_rejects_foreign_run(): void
    {
        $candidate = app(StrategyEvolutionService::class)->generateCandidate('trend_pullback', 7777, 'binance');
        self::assertSame('binance', $candidate->search_metadata['exchange']);
        $service = \Mockery::mock(BacktestRunService::class);
        $service->shouldReceive('run')->once()->withArgs(fn ($symbols, $from, $to, $walk, $config, $exchange): bool => $exchange === 'binance' && $walk === true)->andReturn(new BacktestRunRecord(['strategy_config' => ['_exchange' => 'bybit']]));
        $this->app->instance(BacktestRunService::class, $service);
        try {
            $this->app->call([new EvaluateStrategyCandidateJob($candidate->id), 'handle']);
            self::fail('Foreign exchange evidence accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Candidate backtest exchange mismatch.', $exception->getMessage());
        }
        self::assertSame('FAILED', $candidate->fresh()->search_metadata['evaluation_status']);
        self::assertArrayNotHasKey('backtest_run_id', $candidate->fresh()->search_metadata);
    }

    public function test_binance_candidate_cannot_provision_a_default_exchange_account(): void
    {
        $candidate = app(StrategyEvolutionService::class)->generateCandidate('trend_pullback', 7777, 'binance');
        $before = DB::table('paper_accounts')->count();
        try {
            app(PromotionPolicyService::class)->promoteCandidate($candidate->id);
            self::fail('Foreign exchange provisioning accepted.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('unsupported by forward account provisioning', $exception->getMessage());
        }
        self::assertSame($before, DB::table('paper_accounts')->count());
        self::assertNull($candidate->fresh()->paper_account_id);
    }
}
