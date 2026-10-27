<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Domain\ExecutionQuote;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\PositionSize;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PaperReconcileFundingCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_paper_reconcile_funding_command_dry_run_previews_without_mutating(): void
    {
        $lifecycle = app(PositionLifecycleService::class);
        $account = app(PaperAccountService::class)->default();
        $account->update(['exchange_id' => 'bybit', 'is_primary' => true, 'status' => 'ACTIVE']);

        $signal = SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'timeframe' => '15',
            'entry_low' => '59900',
            'entry_high' => '60100',
            'stop_loss' => '59000',
            'take_profit_1' => '61000',
            'take_profit_2' => '63000',
            'risk_reward_1' => '1.1',
            'risk_reward_2' => '3.3',
            'decision_time' => new DateTimeImmutable('2026-10-26T23:30:00Z'),
            'candle_close_time' => new DateTimeImmutable('2026-10-26T23:30:00Z'),
            'expires_at' => new DateTimeImmutable('2026-10-27T04:00:00Z'),
            'maximum_holding_minutes' => 720,
            'model_risk_percent' => '0.50',
            'reasons' => ['test_fixture'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['close' => '60000']],
            'contract' => ['research_mode' => true],
            'schema_version' => '1.0.0',
            'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0',
        ]);

        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'status' => 'PENDING_ENTRY',
        ]);

        $openTime = new DateTimeImmutable('2026-10-26T23:30:00Z');
        $size = new PositionSize(true, '0.010', '100', '10', '600', qtyStep: '0.001');
        $entryQuote = new ExecutionQuote(ExecutionType::Entry, '60000', '60000', '0', 'test', false);

        $position = $lifecycle->open($account, $state, $signal, $size, $entryQuote, $openTime);

        // Position closes at 00:10 UTC on Oct 27
        $slQuote = new ExecutionQuote(ExecutionType::StopLoss, '59000', '59000', '0', 'test', false);
        $closed = $lifecycle->executeExit($position, $slQuote, new DateTimeImmutable('2026-10-27T00:10:00Z'));
        self::assertSame(PositionStatus::ClosedSl->value, $closed->status);

        // Funding event for 00:00:00 UTC
        $mfe = MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'funding_at' => new DateTimeImmutable('2026-10-27T00:00:00Z'),
            'rate' => '0.000100000000',
            'mark_price' => '60000.000000000000',
        ]);

        // 1. Running without --dry-run and without --force must fail safely
        $this->artisan('paper:reconcile-funding', ['--symbol' => ['BTCUSDT']])
            ->assertExitCode(2)
            ->expectsOutputToContain('Safety protection');

        // 2. Running with --dry-run must preview the adjustment and leave zero DB modifications
        $this->artisan('paper:reconcile-funding', ['--symbol' => ['BTCUSDT'], '--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('DRY-RUN PREVIEW')
            ->expectsOutputToContain('BTCUSDT')
            ->expectsOutputToContain('-0.060000 USDT')
            ->expectsOutputToContain('Zero database records were modified');

        self::assertSame(0, PositionFundingEventRecord::query()->where('position_id', $closed->id)->count());
        self::assertFalse($closed->fresh()->funding_complete);

        // 3. Running with --force must execute live reconciliation
        $this->artisan('paper:reconcile-funding', ['--symbol' => ['BTCUSDT'], '--force' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Reconciliation complete');

        self::assertSame(1, PositionFundingEventRecord::query()->where('position_id', $closed->id)->count());
        $closedFresh = $closed->fresh();
        self::assertSame('-0.060000000000', (string) $closedFresh->funding);
        self::assertTrue($closedFresh->funding_complete);
    }
}
