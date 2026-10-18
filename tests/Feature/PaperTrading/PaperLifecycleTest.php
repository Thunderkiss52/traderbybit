<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\PaperTrading\Jobs\ProcessPaperTradingJob;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PaperLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_forward_paper_does_not_replay_signals_before_account_creation(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-15T12:00:00Z'));
        $this->signal('BUY'); // Historical signal from 2026-10-14.
        $account = $this->app->make(PaperAccountService::class)->default();
        $account->update(['created_at' => new DateTimeImmutable('2026-10-15T12:00:00Z')]);
        $this->app->make(PaperProcessingService::class)->process(symbol: 'BTCUSDT');

        // The account is created after the historical signal and must not invent
        // a forward trade that would have required the account to exist earlier.
        $this->assertDatabaseCount('paper_signal_states', 0);
        self::assertTrue(BigDecimal::of((string) $account->refresh()->realized_pnl)->isEqualTo(0));
    }

    public function test_blocked_account_still_closes_existing_paper_position(): void
    {
        $this->signal('BUY');
        $this->context();
        $this->candle('10:15', '100.5', '102', '100.5', '101');
        $this->app->make(PaperProcessingService::class)->process(
            symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:20:00Z')
        );
        $account = $this->app->make(PaperAccountService::class)->default();
        $account->update(['status' => 'BLOCKED']);
        $this->candle('10:20', '101', '102', '94', '96');
        $this->app->make(PaperProcessingService::class)->process(
            accountId: $account->id, symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:25:00Z')
        );
        self::assertSame(PositionStatus::ClosedSl->value, VirtualPositionRecord::query()->firstOrFail()->status);
    }

    public function test_late_backfilled_signal_cannot_create_forward_entry(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-14T10:10:00Z'));
        $account = $this->app->make(PaperAccountService::class)->default();
        $account->update(['created_at' => new DateTimeImmutable('2026-10-14T10:10:00Z')]);
        $this->travelTo(new DateTimeImmutable('2026-10-14T10:40:00Z'));
        $this->signal('BUY'); // Decision timestamp 10:15, inserted 25 minutes too late.
        $this->context();
        $this->candle('10:40', '100.5', '102', '100.5', '101');
        $this->app->make(PaperProcessingService::class)->process(accountId: $account->id, symbol: 'BTCUSDT');
        $this->assertDatabaseCount('paper_signal_states', 1);
        $this->assertDatabaseHas('paper_signal_states', [
            'status' => 'LATE_SIGNAL_NOT_EXECUTABLE',
            'reason' => 'late_signal_not_executable',
        ]);
        $this->assertDatabaseCount('virtual_positions', 0);
        $this->assertDatabaseHas('risk_decisions', [
            'allowed' => false,
        ]);
    }

    public function test_complete_buy_lifecycle_entry_tp1_tp2_is_idempotent_and_journaled(): void
    {
        $signal = $this->signal('BUY');
        $this->context();
        $this->candle('10:15', '100.5', '102', '100.5', '101.2');
        $this->candle('10:20', '101.2', '106', '99', '105');
        $this->candle('10:25', '105', '111', '104', '110');

        $service = $this->app->make(PaperProcessingService::class);
        $service->process(symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:30:00Z'));
        $service->process(symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:30:00Z'));

        $position = VirtualPositionRecord::query()->firstOrFail();
        self::assertSame(PositionStatus::ClosedTp->value, $position->status);
        self::assertTrue(BigDecimal::of((string) $position->remaining_quantity)->isEqualTo(0));
        self::assertTrue(BigDecimal::of((string) $position->net_pnl)->isEqualTo('51.43027179678'));
        self::assertTrue(BigDecimal::of((string) $position->r_multiple)->isEqualTo('1.0286054359'));
        $this->assertDatabaseCount('virtual_executions', 3);
        $this->assertDatabaseHas('virtual_executions', ['type' => 'TAKE_PROFIT_1']);
        $this->assertDatabaseHas('virtual_executions', ['type' => 'TAKE_PROFIT_2']);
        self::assertSame('COMPLETED', PaperSignalStateRecord::query()->where('signal_id', $signal->id)->value('status'));
        self::assertSame(3, JournalEventRecord::query()->whereNotNull('position_id')->count());
        $this->getJson('/api/v1/positions/'.$position->id)->assertOk()
            ->assertJsonPath('data.mode', 'FORWARD_PAPER')
            ->assertJsonPath('data.position.id', $position->id)
            ->assertJsonCount(3, 'data.executions.data');
    }

    public function test_non_baseline_actionable_signal_reaches_isolated_paper_execution(): void
    {
        $account = $this->app->make(PaperAccountService::class)->strategyForward(
            'trend_pullback',
            '1.2.0',
            ['version' => '1.2.0'],
        );
        $signal = $this->signal('BUY', strategyId: 'trend_pullback');
        $signal->update(['strategy_version' => '1.2.0']);
        $this->context();
        $this->candle('10:15', '100.5', '102', '100.5', '101.2');

        $this->app->make(PaperProcessingService::class)->process(
            accountId: $account->id,
            symbol: 'BTCUSDT',
            at: new DateTimeImmutable('2026-10-14T10:20:00Z'),
        );

        $position = VirtualPositionRecord::query()->firstOrFail();
        self::assertSame($account->id, $position->paper_account_id);
        self::assertSame($signal->id, $position->signal_id);
        self::assertSame(PositionStatus::Open->value, $position->status);
        $this->assertDatabaseHas('risk_decisions', ['paper_account_id' => $account->id, 'signal_id' => $signal->id, 'allowed' => true]);
        $this->assertDatabaseCount('virtual_executions', 1);
    }

    public function test_spot_sell_is_risk_rejected_and_never_opens_a_short(): void
    {
        $signal = $this->signal('SELL');
        $signal->update(['category' => 'spot']);
        $this->context();

        $this->app->make(PaperProcessingService::class)->process(
            symbol: 'BTCUSDT',
            at: new DateTimeImmutable('2026-10-14T10:15:00Z'),
        );

        self::assertFalse(VirtualPositionRecord::query()->exists());
        self::assertSame('REJECTED', PaperSignalStateRecord::query()->where('signal_id', $signal->id)->value('status'));
        self::assertContains('spot_short_unsupported', RiskDecisionRecord::query()->where('signal_id', $signal->id)->firstOrFail()->reasons);
    }

    public function test_extreme_funding_and_open_interest_are_hard_risk_rejections(): void
    {
        $signal = $this->signal('BUY');
        $this->context();
        MarketContextRecord::query()->firstOrFail()->update([
            'funding_rate' => '0.002', 'open_interest_change' => '0.20',
        ]);

        $this->app->make(PaperProcessingService::class)->process(
            symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:15:00Z'),
        );

        $decision = RiskDecisionRecord::query()->where('signal_id', $signal->id)->firstOrFail();
        self::assertFalse($decision->allowed);
        self::assertContains('extreme_funding_rate', $decision->reasons);
        self::assertContains('extreme_open_interest_change', $decision->reasons);
        self::assertFalse(VirtualPositionRecord::query()->exists());
    }

    #[DataProvider('stopSides')]
    public function test_buy_and_sell_close_at_stop(string $side): void
    {
        $this->signal($side);
        $this->context();
        if ($side === 'BUY') {
            $this->candle('10:15', '100.5', '102', '100.5', '101');
            $this->candle('10:20', '101', '102', '94', '96');
        } else {
            $this->candle('10:15', '100', '101', '99.5', '99.7');
            $this->candle('10:20', '100', '106', '98', '104');
        }
        $this->process('10:25');
        $position = VirtualPositionRecord::query()->firstOrFail();
        self::assertSame(PositionStatus::ClosedSl->value, $position->status);
        self::assertLessThan(0, (float) $position->net_pnl);
    }

    public static function stopSides(): array
    {
        return [['BUY'], ['SELL']];
    }

    public function test_sell_can_take_tp1_then_stop_remaining_quantity(): void
    {
        $this->signal('SELL');
        $this->context();
        $this->candle('10:15', '100', '101', '99.5', '99.7');
        $this->candle('10:20', '99', '101', '94', '95');
        $this->candle('10:25', '96', '106', '96', '104');
        $this->process('10:30');

        $position = VirtualPositionRecord::query()->firstOrFail();
        self::assertSame(PositionStatus::ClosedSl->value, $position->status);
        self::assertSame(3, VirtualExecutionRecord::query()->count());
        self::assertTrue(VirtualExecutionRecord::query()->where('type', 'TAKE_PROFIT_1')->exists());
        self::assertTrue(VirtualExecutionRecord::query()->where('type', 'STOP_LOSS')->exists());
    }

    public function test_pending_signal_expires_and_missing_metadata_is_risk_rejected(): void
    {
        $signal = $this->signal('BUY');
        $this->context();
        foreach (['10:15', '10:20', '10:25', '10:30', '10:35', '10:40', '10:45'] as $time) {
            $this->candle($time, '110', '112', '109', '111');
        }
        $this->process('10:50');
        self::assertSame('EXPIRED', PaperSignalStateRecord::query()->where('signal_id', $signal->id)->value('status'));
        self::assertFalse(VirtualPositionRecord::query()->exists());

        $other = $this->signal('BUY', 'ETHUSDT', '11:15');
        $this->process('11:15', 'ETHUSDT');
        self::assertSame('REJECTED', PaperSignalStateRecord::query()->where('signal_id', $other->id)->value('status'));
        self::assertContains('market_metadata_unavailable', RiskDecisionRecord::query()->where('signal_id', $other->id)->firstOrFail()->reasons);
    }

    public function test_skip_ingestion_is_bounded_and_idempotently_marked_complete(): void
    {
        $signal = $this->signal('SKIP');
        $service = $this->app->make(PaperProcessingService::class);

        $service->process(symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:15:00Z'));
        $service->process(symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:15:00Z'));

        $this->assertDatabaseHas('paper_signal_states', ['signal_id' => $signal->id, 'status' => 'COMPLETED']);
        self::assertSame(1, JournalEventRecord::query()->where('event_type', 'ANALYTICAL_SKIP')->count());
        self::assertFalse(RiskDecisionRecord::query()->where('signal_id', $signal->id)->exists());
    }

    public function test_timeout_waits_for_next_available_closed_bar_without_lookahead(): void
    {
        $this->signal('BUY');
        $this->context();
        for ($i = 0; $i < 49; $i++) {
            $time = (new DateTimeImmutable('2026-10-14T10:15:00Z'))->modify('+'.($i * 5).' minutes')->format('H:i');
            $this->candle($time, '100.5', '102', '99', '101');
        }
        $service = $this->app->make(PaperProcessingService::class);
        $service->process(symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:20:00Z'));
        self::assertSame('OPEN', VirtualPositionRecord::query()->firstOrFail()->status);
        self::assertSame(1, VirtualExecutionRecord::query()->count());

        $service->process(symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T14:20:00Z'));
        self::assertSame(PositionStatus::ClosedTimeout->value, VirtualPositionRecord::query()->firstOrFail()->status);
        self::assertSame(0, VirtualPositionRecord::query()->firstOrFail()->timeout_delay_seconds);
    }

    public function test_gap_stops_processing_and_marks_position_waiting_for_data(): void
    {
        $this->signal('BUY');
        $this->context();
        $this->candle('10:15', '100.5', '102', '100', '101');
        $this->candle('10:25', '101', '111', '100', '110');
        $this->process('10:30');
        $position = VirtualPositionRecord::query()->firstOrFail();
        self::assertSame('OPEN', $position->status);
        self::assertTrue($position->awaiting_market_data);
        self::assertSame(1, VirtualExecutionRecord::query()->count());
    }

    public function test_funding_event_is_applied_once(): void
    {
        $this->signal('BUY');
        $this->context();
        $this->candle('10:15', '100.5', '102', '100', '101');
        $this->candle('10:20', '101', '103', '99', '102');
        MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'funding_at' => new DateTimeImmutable('2026-10-14T10:22:00Z'), 'rate' => '0.001', 'mark_price' => '102',
        ]);
        $this->process('10:25');
        $this->process('10:25');
        self::assertSame(1, DB::table('position_funding_events')->count());
        self::assertLessThan(0, (float) VirtualPositionRecord::query()->firstOrFail()->funding);
        self::assertSame(1, JournalEventRecord::query()->where('event_type', 'FUNDING_APPLIED')->count());
    }

    public function test_three_completed_losses_start_a_sixty_minute_cooldown(): void
    {
        foreach ([['10:15', '10:15', '10:20'], ['10:30', '10:30', '10:35'], ['10:45', '10:45', '10:50']] as [$signalTime, $entryTime, $stopTime]) {
            $this->signal('BUY', 'BTCUSDT', $signalTime);
            $this->context('BTCUSDT', $signalTime);
            $this->candle($entryTime, '100.5', '102', '100', '101');
            $this->candle($stopTime, '101', '102', '94', '96');
        }
        $this->process('10:55');
        $account = $this->app->make(PaperAccountService::class)->default()->refresh();
        self::assertSame('2026-10-14T11:55:00+00:00', $account->cooldown_until?->format(DATE_ATOM));
        self::assertSame(3, VirtualPositionRecord::query()->where('status', 'CLOSED_SL')->count());
    }

    public function test_recorded_execution_and_risk_decision_are_immutable_across_configuration_changes(): void
    {
        $this->signal('BUY');
        $this->context();
        $this->candle('10:15', '100.5', '102', '100', '101');
        $this->process('10:20');
        $execution = VirtualExecutionRecord::query()->firstOrFail();
        self::assertSame('1.0.0', $execution->execution_version);
        config()->set('paper.execution_version', '9.9.9');
        $this->process('10:20');
        self::assertSame('1.0.0', $execution->refresh()->execution_version);
        $this->expectException(\LogicException::class);
        RiskDecisionRecord::query()->firstOrFail()->update(['risk_version' => 'changed']);
    }

    public function test_default_account_and_artisan_commands_work(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();
        self::assertTrue(BigDecimal::of((string) $account->starting_balance)->isEqualTo(10000));
        self::assertSame($account->id, $this->app->make(PaperAccountService::class)->default()->id);
        $this->artisan('paper:account')->assertSuccessful();
        $this->artisan('paper:positions')->assertSuccessful();
        $this->artisan('paper:stats')->assertSuccessful();
        $this->artisan('paper:process', ['--at' => '2026-10-14T10:15:00Z'])->assertSuccessful();
        (new ProcessPaperTradingJob(at: '2026-10-14T10:15:00Z'))->handle($this->app->make(PaperProcessingService::class));
    }

    private function signal(string $side, string $symbol = 'BTCUSDT', string $time = '10:15', string $strategyId = 'baseline'): SignalRecord
    {
        $at = new DateTimeImmutable("2026-10-14T{$time}:00Z");
        $buy = $side === 'BUY';

        return SignalRecord::query()->create([
            'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "test:{$symbol}:{$side}:{$time}")->toString(), 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
            'timeframe' => '15', 'side' => $side, 'quality_class' => 'A', 'score' => 90, 'decision_time' => $at, 'candle_close_time' => $at,
            'entry_low' => $buy ? '100' : '99', 'entry_high' => $buy ? '101' : '100', 'stop_loss' => $buy ? '95' : '105',
            'take_profit_1' => $buy ? '105' : '95', 'take_profit_2' => $buy ? '110' : '90', 'risk_reward_1' => '1.5', 'risk_reward_2' => '2.5',
            'model_risk_percent' => '0.50', 'expires_at' => $at->modify('+30 minutes'), 'maximum_holding_minutes' => 240,
            'reasons' => ['fixture'], 'risks' => [], 'indicator_snapshot' => ['15m' => ['atr_14' => '1', 'close' => '100']], 'contract' => ['research_mode' => true],
            'strategy_version' => '1.0.0', 'schema_version' => '1.0.0', 'strategy_id' => $strategyId,
        ]);
    }

    private function context(string $symbol = 'BTCUSDT', string $time = '10:15'): void
    {
        MarketContextRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'observed_at' => new DateTimeImmutable("2026-10-14T{$time}:00Z"),
            'bid' => '100', 'ask' => '100.05', 'turnover_24h' => '100000000', 'tick_size' => '0.1', 'funding_rate' => '0.0001',
            'open_interest' => '1000000', 'qty_step' => '0.001', 'min_order_qty' => '0.001', 'max_order_qty' => '1000', 'min_notional_value' => '5',
        ]);
    }

    private function candle(string $time, string $open, string $high, string $low, string $close, string $symbol = 'BTCUSDT'): void
    {
        DB::table('market_candles')->insert([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => '5', 'open_time' => new DateTimeImmutable("2026-10-14T{$time}:00Z"),
            'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => '100', 'turnover' => '10000', 'is_closed' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function process(string $time, string $symbol = 'BTCUSDT'): void
    {
        $this->app->make(PaperProcessingService::class)->process(symbol: $symbol, at: new DateTimeImmutable("2026-10-14T{$time}:00Z"));
    }
}
