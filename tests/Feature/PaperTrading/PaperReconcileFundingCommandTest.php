<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperExecutionEngine;
use App\PaperTrading\Application\PaperSizingSettings;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Application\PositionSizingService;
use App\PaperTrading\Domain\ExecutionQuote;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\InstrumentConstraints;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\SeedsValidatedPaperEvidence;
use Tests\TestCase;

final class PaperReconcileFundingCommandTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsValidatedPaperEvidence;

    public function test_paper_reconcile_funding_command_dry_run_previews_without_mutating(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-26T23:30:00Z'));
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
            'signal_quality' => 'HIGH',
            'trade_risk' => 'LOW',
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

        $this->seedValidatedPaperEvidence($signal);

        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'status' => 'PENDING_ENTRY',
        ]);

        $openTime = new DateTimeImmutable('2026-10-26T23:30:00Z');
        MarketContextRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'observed_at' => $openTime,
            'bid' => '60000',
            'ask' => '60001',
            'turnover_24h' => '100000000',
            'tick_size' => '0.1',
            'qty_step' => '0.001',
            'min_order_qty' => '0.001',
            'max_order_qty' => '1000',
            'min_notional_value' => '1',
            'funding_rate' => '0.0001',
            'open_interest' => '1000000',
            'created_at' => $openTime,
            'updated_at' => $openTime,
        ]);
        $account->update(['sizing_settings' => ['mode' => 'fixed_notional', 'amount' => '600']]);
        $entryQuote = new ExecutionQuote(ExecutionType::Entry, '60000', '60000', '0', 'test', false);
        $limits = new InstrumentConstraints('0.001', '0.001', '1000', '1');
        $size = app(PositionSizingService::class)->calculate(
            $account->current_equity,
            $signal->model_risk_percent,
            $entryQuote->price,
            $signal->stop_loss,
            $limits,
            app(PaperExecutionEngine::class)->costs($account->exchange_id),
            PaperSizingSettings::effectiveForAccount($account->sizing_settings),
        );

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

        // 4. Running targeted --position-id with --force is strictly idempotent and safe
        $this->artisan('paper:reconcile-funding', ['--position-id' => $closed->id, '--force' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain("Reconciliation complete for position {$closed->id}. Applied 0 funding events.");
    }
}
