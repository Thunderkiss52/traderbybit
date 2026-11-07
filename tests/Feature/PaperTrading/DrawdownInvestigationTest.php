<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\DrawdownInvestigationService;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\Research\Jobs\InvestigatePaperDrawdownJob;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use ReflectionMethod;
use Tests\TestCase;

final class DrawdownInvestigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_threshold_records_one_durable_risk_event_without_changing_financial_history(): void
    {
        Bus::fake([InvestigatePaperDrawdownJob::class]);
        $account = app(PaperAccountService::class)->default();
        $at = CarbonImmutable::parse('2026-11-07T12:00:00Z');
        $refresh = new ReflectionMethod(PositionLifecycleService::class, 'refreshAccountMetrics');
        $refresh->invoke(app(PositionLifecycleService::class), $account, BigDecimal::of('-499'), BigDecimal::zero(), null, $at);
        self::assertSame(0, JournalEventRecord::query()->where('event_type', 'DRAWDOWN_ENTRY_BLOCKED')->count());
        $refresh->invoke(app(PositionLifecycleService::class), $account, BigDecimal::of('-500'), BigDecimal::zero(), null, $at);
        $refresh->invoke(app(PositionLifecycleService::class), $account->fresh(), BigDecimal::of('-600'), BigDecimal::zero(), null, $at);
        self::assertSame('BLOCKED', $account->fresh()->status);
        self::assertDecimalSame('10000', $account->fresh()->starting_balance);
        self::assertDecimalSame('9400', $account->fresh()->current_equity);
        $event = JournalEventRecord::query()->where('event_type', 'DRAWDOWN_ENTRY_BLOCKED')->sole();
        self::assertSame($account->id, $event->paper_account_id);
        self::assertSame('bybit', $event->exchange_id);
        self::assertTrue($event->payload['exits_and_settlement_enabled']);
        self::assertDecimalSame('5', (string) $event->payload['drawdown_percent']);
        Bus::assertDispatchedTimes(InvestigatePaperDrawdownJob::class, 1);
        self::assertSame(0, JournalEventRecord::query()->where('event_type', 'OWNER_APPROVED_PAPER_RESUME')->count());
    }

    public function test_bounded_investigation_excludes_open_future_and_foreign_candles_and_observations(): void
    {
        Bus::fake([InvestigatePaperDrawdownJob::class]);
        $account = app(PaperAccountService::class)->default();
        $account->update(['status' => 'BLOCKED', 'current_drawdown_percent' => '5', 'current_equity' => '9500', 'realized_pnl' => '-500']);
        $at = CarbonImmutable::parse('2026-11-07T12:00:00Z');
        $this->travelTo($at);
        $service = app(DrawdownInvestigationService::class);
        $service->request($account, $at);
        foreach ([['bybit', '11:57', true], ['bybit', '12:00', true], ['bybit', '11:54', false], ['binance', '11:54', true]] as [$exchange, $time, $closed]) {
            CandleRecord::query()->create([
                'exchange' => $exchange, 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '3',
                'open_time' => CarbonImmutable::parse('2026-11-07T'.$time.':00Z'), 'open' => '100', 'high' => '101',
                'low' => '99', 'close' => '100', 'volume' => '1', 'turnover' => '100', 'is_closed' => $closed,
            ]);
        }
        // A closed candle/old decision discovered after the risk event was not available then.
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '3',
            'open_time' => $at->subMinutes(12), 'open' => '100', 'high' => '101', 'low' => '99',
            'close' => '100', 'volume' => '1', 'turnover' => '100', 'is_closed' => true,
            'created_at' => $at->addMinutes(1), 'updated_at' => $at->addMinutes(1),
        ]);
        foreach ([['bybit', '11:45'], ['bybit', '12:15'], ['binance', '11:45']] as [$exchange, $time]) {
            StrategyObservationRecord::query()->create([
                'exchange' => $exchange, 'category' => 'linear', 'symbol' => 'BTCUSDT',
                'strategy_id' => 'baseline', 'strategy_version' => $account->strategy_version,
                'configuration_hash' => str_repeat('a', 64), 'side' => 'SKIP', 'score' => 0,
                'decision_time' => CarbonImmutable::parse('2026-11-07T'.$time.':00Z'),
                'reasons' => ['insufficient_history'], 'analysis' => ['diagnostics' => ['warmup_ready' => false]],
            ]);
        }
        $late = StrategyObservationRecord::query()->where('exchange', 'bybit')->where('decision_time', '<', $at)->firstOrFail()->replicate();
        $late->decision_time = $at->subMinutes(30);
        $late->created_at = $at->addMinute();
        $late->updated_at = $at->addMinute();
        $late->save();
        $before = $account->fresh()->getAttributes();
        self::assertSame(1, $service->investigatePending());
        self::assertSame(0, $service->investigatePending());
        self::assertSame($before, $account->fresh()->getAttributes());
        $event = JournalEventRecord::query()->where('event_type', 'DRAWDOWN_INVESTIGATION_CAPTURED')->sole();
        self::assertCount(1, $event->payload['observations']);
        self::assertSame(1, $event->payload['timeframes']['BTCUSDT']['3']['closed_bars_sampled']);
        self::assertSame('AVAILABLE', $event->payload['timeframes']['BTCUSDT']['3']['status']);
        self::assertSame('UNAVAILABLE', $event->payload['timeframes']['BTCUSDT']['1']['status']);
        self::assertFalse($event->payload['candidate_generated']);
        self::assertFalse($event->payload['account_reactivation_allowed']);
        self::assertSame([], $event->payload['losses']);
        self::assertSame('OUT_OF_SAMPLE_VALIDATION_REQUIRED', $event->payload['parameter_evaluation']);
    }

    public function test_a_later_risk_episode_after_explicit_owner_resume_is_audited_independently(): void
    {
        Bus::fake([InvestigatePaperDrawdownJob::class]);
        $account = app(PaperAccountService::class)->default();
        $at = CarbonImmutable::parse('2026-11-07T12:00:00Z');
        $this->travelTo($at);
        $refresh = new ReflectionMethod(PositionLifecycleService::class, 'refreshAccountMetrics');
        $lifecycle = app(PositionLifecycleService::class);
        $refresh->invoke($lifecycle, $account, BigDecimal::of('-500'), BigDecimal::zero(), null, $at);
        self::assertSame(1, app(DrawdownInvestigationService::class)->investigatePending());
        $refresh->invoke($lifecycle, $account->fresh(), BigDecimal::of('-400'), BigDecimal::zero(), null, $at);
        self::assertSame('BLOCKED', $account->fresh()->status);
        app(PaperAccountService::class)->resume($account->id, ownerApproved: true);
        $refresh->invoke($lifecycle, $account->fresh(), BigDecimal::of('-500'), BigDecimal::zero(), null, $at->addMinute());
        self::assertSame('BLOCKED', $account->fresh()->status);
        self::assertSame(1, app(DrawdownInvestigationService::class)->investigatePending());
        self::assertSame(2, JournalEventRecord::query()->where('event_type', 'DRAWDOWN_ENTRY_BLOCKED')->count());
        self::assertSame(2, JournalEventRecord::query()->where('event_type', 'DRAWDOWN_INVESTIGATION_CAPTURED')->count());
        Bus::assertDispatchedTimes(InvestigatePaperDrawdownJob::class, 2);
    }
}
