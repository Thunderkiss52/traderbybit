<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Application\StrategyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ThirteenPrimaryAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_candidate_is_never_reclassified_as_primary(): void
    {
        $service = app(PaperAccountService::class);
        $candidate = $service->strategyForward('liquidity_sweep_reversal', 'candidate-1', ['test' => 'candidate']);
        $before = $candidate->fresh()->getRawOriginal();
        $primary = $service->primaryAccount('liquidity_sweep_reversal');
        self::assertNotSame($candidate->id, $primary->id);
        self::assertSame($before, $candidate->fresh()->getRawOriginal());
        self::assertFalse($candidate->fresh()->is_primary);
        self::assertSame($primary->id, $service->primaryAccount('liquidity_sweep_reversal')->id);
        self::assertSame(1, PaperAccountRecord::query()->where('primary_strategy_id', 'liquidity_sweep_reversal')->count());
    }

    public function test_all_thirteen_primary_accounts_are_independent_and_fully_funded(): void
    {
        $service = $this->app->make(PaperAccountService::class);
        $accounts = $service->allPrimaryAccounts();

        self::assertCount(13, $accounts);
        self::assertSame((array) config('paper.primary_strategies'), $accounts->pluck('primary_strategy_id')->all());
        self::assertCount(13, $accounts->pluck('id')->unique());

        foreach ($accounts as $account) {
            self::assertTrue((bool) $account->is_primary);
            self::assertSame('ACTIVE', $account->status);
            self::assertDecimalSame('10000', (string) $account->starting_balance);
            self::assertDecimalSame('10000', (string) $account->current_equity);
            self::assertDecimalSame('0', (string) $account->realized_pnl);
            self::assertSame(1, EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->count());
        }
    }

    public function test_strategy_forward_adopts_a_migration_primary_account_and_backfills_its_configuration(): void
    {
        $service = $this->app->make(PaperAccountService::class);
        $primary = $service->primaryAccount('liquidity_sweep_reversal');
        $strategy = $this->app->make(StrategyRegistry::class)->get('liquidity_sweep_reversal');

        $primary->update(['configuration_hash' => null, 'strategy_parameters' => null]);
        $primary->refresh();

        self::assertNull($primary->configuration_hash);

        $account = $service->strategyForward($strategy->id(), $strategy->version(), $strategy->parameters());

        self::assertSame($primary->id, $account->id);
        self::assertSame($strategy->version(), $account->strategy_version);
        self::assertNotNull($account->configuration_hash);
        self::assertSame($strategy->parameters(), $account->strategy_parameters);
        self::assertSame(1, PaperAccountRecord::query()->where('strategy_id', $strategy->id())->count());
    }
}
