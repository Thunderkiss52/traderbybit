<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Application\PositionSizingService;
use App\PaperTrading\Domain\ExecutionQuote;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\InstrumentConstraints;
use App\PaperTrading\Domain\SignalLifecycle;
use App\PaperTrading\Domain\TradeRiskClass;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Domain\SignalQuality;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FullSystemRemediationAcceptanceVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PaperTopologySynchronizer::class)->sync(true);
    }

    public function test_paper_account_sizing_api_endpoints(): void
    {
        $account = PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('status', 'ACTIVE')->firstOrFail();

        // 1. GET /sizing returns defaults when unconfigured
        $response = $this->getJson("/api/v1/paper/accounts/{$account->id}/sizing");
        $response->assertOk()
            ->assertJsonPath('data.account_id', $account->id)
            ->assertJsonPath('data.configured_sizing_settings', null)
            ->assertJsonPath('data.effective_sizing_settings.mode', 'fixed_notional')
            ->assertJsonPath('data.effective_sizing_settings.amount', '5')
            ->assertJsonPath('data.default_sizing_settings.mode', 'fixed_notional')
            ->assertJsonPath('data.default_sizing_settings.amount', '5');

        // 2. PUT /sizing updates settings
        $updateResponse = $this->putJson("/api/v1/paper/accounts/{$account->id}/sizing", [
            'mode' => 'fixed_margin',
            'amount' => '100',
            'leverage' => '1.5',
        ]);
        $updateResponse->assertOk()
            ->assertJsonPath('data.configured_sizing_settings.mode', 'fixed_margin')
            ->assertJsonPath('data.configured_sizing_settings.amount', '100')
            ->assertJsonPath('data.configured_sizing_settings.leverage', '1.5')
            ->assertJsonPath('data.effective_sizing_settings.mode', 'fixed_margin');

        // 3. GET /effective-sizing returns current effective sizing and costs
        $effectiveResponse = $this->getJson("/api/v1/paper/accounts/{$account->id}/effective-sizing?symbol=BTCUSDT");
        $effectiveResponse->assertOk()
            ->assertJsonPath('data.account_id', $account->id)
            ->assertJsonPath('data.exchange_id', 'bybit')
            ->assertJsonPath('data.symbol', 'BTCUSDT')
            ->assertJsonPath('data.effective_sizing_settings.mode', 'fixed_margin');

        // 4. PUT /sizing with empty payload resets to null (default)
        $resetResponse = $this->putJson("/api/v1/paper/accounts/{$account->id}/sizing", []);
        $resetResponse->assertOk()
            ->assertJsonPath('data.configured_sizing_settings', null)
            ->assertJsonPath('data.effective_sizing_settings.mode', 'fixed_notional')
            ->assertJsonPath('data.effective_sizing_settings.amount', '5');
    }

    public function test_virtual_position_persists_complete_sizing_snapshot(): void
    {
        $account = PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('status', 'ACTIVE')->firstOrFail();
        $account->update([
            'sizing_settings' => [
                'mode' => 'fixed_notional',
                'amount' => '100',
            ],
        ]);

        $now = CarbonImmutable::parse('2026-11-10T12:00:00Z');
        $signal = SignalRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => $account->strategy_id,
            'strategy_version' => $account->strategy_version,
            'schema_version' => '1.0.0',
            'timeframe' => '15',
            'side' => 'BUY',
            'entry_low' => '60000',
            'entry_high' => '60050',
            'stop_loss' => '59000',
            'take_profit_1' => '61000',
            'take_profit_2' => '62000',
            'score' => 90,
            'quality_class' => 'A',
            'reasons' => ['test_setup'],
            'risks' => [],
            'indicator_snapshot' => ['rsi' => '55'],
            'contract' => ['research_mode' => true],
            'signal_quality' => SignalQuality::High->value,
            'trade_risk' => TradeRiskClass::Low->value,
            'model_risk_percent' => '0.50',
            'maximum_holding_minutes' => 120,
            'decision_time' => $now,
            'candle_close_time' => $now,
            'expires_at' => $now->addMinutes(15),
        ]);

        $state = PaperSignalStateRecord::query()->create([
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'status' => SignalLifecycle::PendingEntry->value,
            'processed_at' => $now,
        ]);

        $limits = new InstrumentConstraints('0.001', '0.001', '1000', '5');
        $sizingService = app(PositionSizingService::class);
        $size = $sizingService->calculate(
            equity: (string) $account->current_equity,
            riskPercent: $signal->model_risk_percent,
            entry: '60000',
            stop: $signal->stop_loss,
            limits: $limits,
            executionCosts: ['taker_rate' => '0.00055', 'slippage_rate' => '0.0002'],
            sizingSettings: $account->sizing_settings,
        );

        self::assertTrue($size->allowed, 'Sizing should be allowed: '.$size->reason);

        $quote = new ExecutionQuote(
            type: ExecutionType::Entry,
            price: '60012',
            referencePrice: '60000',
            slippage: '12',
            priceSource: 'historical_quote',
            ambiguous: false,
        );

        $lifecycle = app(PositionLifecycleService::class);
        $position = $lifecycle->open(
            account: $account,
            state: $state,
            signal: $signal,
            size: $size,
            quote: $quote,
            at: $now->toDateTimeImmutable(),
        );

        self::assertInstanceOf(VirtualPositionRecord::class, $position);
        $snapshot = $position->sizing_snapshot;
        self::assertIsArray($snapshot, 'Position must have sizing_snapshot array persisted.');
        self::assertSame('fixed_notional', $snapshot['sizing_mode']);
        self::assertSame('100', $snapshot['requested_notional']);
        self::assertSame((string) $size->notional, $snapshot['actual_notional']);
        self::assertSame((string) $size->allocatedMargin, $snapshot['allocated_margin']);
        self::assertSame((string) $size->leverage, $snapshot['leverage']);
        self::assertSame((string) $signal->model_risk_percent, $snapshot['planned_risk']);
        self::assertSame((string) $size->riskCapital, $snapshot['effective_risk']);
        self::assertSame('1.0.0', $snapshot['settings_version']);
        self::assertSame('1.0.0', $snapshot['instrument_metadata_version']);
        self::assertNotEmpty($snapshot['exchange_cost_model_version']);
    }

    public function test_sizing_rejects_below_minimum_notional_order_with_distinct_reason(): void
    {
        $sizingService = app(PositionSizingService::class);

        // When minimum notional is 50 USDT, but fixed requested notional is 5 USDT:
        // quantity step is 0.0001, so quantity is 5 / 100 = 0.05 (which exceeds minOrderQty 0.001)
        // But notional is 5 USDT, which is strictly below minNotionalValue 50 USDT.
        $limits = new InstrumentConstraints('0.0001', '0.001', '1000', '50');
        $size = $sizingService->calculate(
            equity: '10000',
            riskPercent: '0.50',
            entry: '100',
            stop: '95',
            limits: $limits,
            executionCosts: ['taker_rate' => '0.00055', 'slippage_rate' => '0.0002'],
            sizingSettings: ['mode' => 'fixed_notional', 'amount' => '5'],
        );

        self::assertFalse($size->allowed);
        self::assertSame('below_minimum_notional_order', $size->reason);
    }

    public function test_profitability_summary_active_and_blocked_accounts_counts(): void
    {
        $response = $this->getJson('/api/v1/research/profitability/summary?exchange=all');
        $response->assertOk();

        $pointInTime = $response->json('data.accounting_scopes.point_in_time');
        self::assertNotNull($pointInTime);

        // Across all 10 exchanges (130 accounts): 13 Bybit are ACTIVE, 117 are BLOCKED
        self::assertSame(13, $pointInTime['active_accounts_count']);
        self::assertSame(130, $pointInTime['total_accounts_count']);
        self::assertSame(117, $pointInTime['blocked_accounts_count']);
    }

    public function test_profitability_summary_symbol_attribution_separation(): void
    {
        $response = $this->getJson('/api/v1/research/profitability/summary?exchange=bybit&symbol=BTCUSDT');
        $response->assertOk();

        $pointInTime = $response->json('data.accounting_scopes.point_in_time');
        self::assertSame('BTCUSDT', $pointInTime['symbol_filter']);
        self::assertStringContainsString('Cash balance and equity are account-wide', $pointInTime['symbol_attribution_note']);
    }
}
