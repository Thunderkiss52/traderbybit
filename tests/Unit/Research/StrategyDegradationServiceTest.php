<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\StrategyDegradationService;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StrategyDegradationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluates_degradation_with_insufficient_data(): void
    {
        $service = new StrategyDegradationService;
        $snap = $service->evaluate('trend_pullback', '14d');

        self::assertSame('INSUFFICIENT_DATA', $snap->status);
        self::assertSame('NONE', $snap->degradation_type);
        self::assertSame(0, $snap->sample_size);
    }

    public function test_detects_regime_mismatch_degradation(): void
    {
        $service = new StrategyDegradationService;
        $account = $this->app->make(PaperAccountService::class)->default();
        $account->update(['strategy_id' => 'trend_pullback']);
        $now = CarbonImmutable::now();

        // Seed 4 losing positions with negative PnL
        for ($i = 0; $i < 4; $i++) {
            $signalId = (string) Str::uuid();
            SignalRecord::query()->create([
                'id' => $signalId,
                'strategy_id' => 'trend_pullback',
                'strategy_version' => '1.0.0',
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'side' => 'BUY',
                'quality_class' => 'A',
                'score' => 90,
                'decision_time' => $now->subDays(5 - $i),
                'candle_close_time' => $now->subDays(5 - $i),
                'entry_low' => '59900',
                'entry_high' => '60000',
                'stop_loss' => '59000',
                'take_profit_1' => '62000',
                'take_profit_2' => '63000',
                'risk_reward_1' => '2.0',
                'risk_reward_2' => '3.0',
                'model_risk_percent' => '1.0',
                'expires_at' => $now->subDays(5 - $i)->addMinutes(30),
                'maximum_holding_minutes' => 240,
                'reasons' => ['pullback'],
                'risks' => [],
                'indicator_snapshot' => [],
                'contract' => [],
                'schema_version' => '1.0.0',
            ]);

            VirtualPositionRecord::query()->create([
                'id' => (string) Str::uuid(),
                'paper_account_id' => $account->id,
                'signal_id' => $signalId,
                'strategy_version' => '1.0.0',
                'risk_version' => '1.0.0',
                'quality_class' => 'A',
                'execution_model' => 'paper',
                'execution_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'symbol' => 'BTCUSDT',
                'side' => 'BUY',
                'status' => 'CLOSED',
                'entry_price' => '60000.0',
                'stop_loss' => '59000.0',
                'take_profit_1' => '62000.0',
                'take_profit_2' => '63000.0',
                'initial_quantity' => '0.1',
                'remaining_quantity' => '0.0',
                'qty_step' => '0.001',
                'risk_capital' => '100.0',
                'gross_pnl' => '-100.0',
                'net_pnl' => '-102.0',
                'unrealized_pnl' => '0.0',
                'fees' => '2.0',
                'funding' => '0.0',
                'opened_at' => $now->subDays(5 - $i),
                'closed_at' => $now->subDays(4 - $i),
                'maximum_holding_until' => $now->addDay(),
            ]);
        }

        // Seed observations showing ranging market regime
        for ($i = 0; $i < 5; $i++) {
            StrategyObservationRecord::query()->create([
                'id' => (string) Str::uuid(),
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'strategy_id' => 'trend_pullback',
                'strategy_version' => '1.0.0',
                'configuration_hash' => 'hash_test',
                'side' => 'SKIP',
                'score' => 40,
                'decision_time' => $now->subDays(2)->addMinutes($i * 15),
                'reasons' => ['ranging_market'],
                'analysis' => ['regime' => 'RANGE_BOUND'],
            ]);
        }

        $snap = $service->evaluate('trend_pullback', '14d');

        self::assertSame('DEGRADING', $snap->status);
        self::assertSame('REGIME_MISMATCH', $snap->degradation_type);
        self::assertSame(4, $snap->sample_size);
        self::assertSame('0.0000', (string) $snap->profit_factor);
    }
}
