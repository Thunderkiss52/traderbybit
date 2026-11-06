<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use App\MarketData\Application\FundingHistorySynchronizer;
use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Contracts\FundingMarkPriceProvider;
use App\MarketData\Domain\CandleQuery;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\LedgerReconciliationService;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class Rem03FundingCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private function createStubClient(array $rates, array $markPrices = []): BybitMarketDataClient
    {
        return new class($rates, $markPrices) implements BybitMarketDataClient, FundingMarkPriceProvider
        {
            public function __construct(
                private readonly array $rates,
                private readonly array $markPrices,
            ) {}

            public function fundingRates(string $category, string $symbol, ?int $fromMs = null, ?int $toMs = null): array
            {
                return $this->rates;
            }

            public function fundingMarkPrice(string $symbol, int $atMilliseconds): ?string
            {
                return $this->markPrices[$atMilliseconds] ?? null;
            }

            public function candles(CandleQuery $query): array
            {
                return [];
            }

            public function ticker(string $category, string $symbol): array
            {
                return [];
            }

            public function orderBook(string $category, string $symbol, int $limit = 50): array
            {
                return [];
            }

            public function openInterest(string $category, string $symbol, string $intervalTime, ?string $cursor = null): array
            {
                return [];
            }
        };
    }

    public function test_complete_funding_interval_synchronization(): void
    {
        $t1 = (new DateTimeImmutable('2026-10-15T00:00:00Z'))->getTimestamp() * 1000;
        $t2 = (new DateTimeImmutable('2026-10-15T08:00:00Z'))->getTimestamp() * 1000;

        $client = $this->createStubClient(
            rates: [
                ['symbol' => 'BTCUSDT', 'fundingRate' => '0.00010000', 'fundingRateTimestamp' => (string) $t1],
                ['symbol' => 'BTCUSDT', 'fundingRate' => '0.00015000', 'fundingRateTimestamp' => (string) $t2],
            ],
            markPrices: [
                $t1 => '60000.00',
                $t2 => '60500.00',
            ]
        );

        $synchronizer = new FundingHistorySynchronizer($client, ['initial_lookback_days' => 30]);
        $through = new DateTimeImmutable('2026-10-15T08:05:00Z');

        $result = $synchronizer->sync('BTCUSDT', $through);

        self::assertSame(2, $result['received']);
        self::assertSame(2, $result['inserted']);
        self::assertSame(0, $result['missing_mark_price']);
        self::assertFalse($result['funding_gap_detected']);
        self::assertSame('complete', $result['diagnostic']);
        self::assertSame([], $result['missing_intervals']);

        self::assertSame(2, MarketFundingEventRecord::query()->where('symbol', 'BTCUSDT')->count());

        // Incremental sync with next continuous interval (16:00 UTC)
        $t3 = (new DateTimeImmutable('2026-10-15T16:00:00Z'))->getTimestamp() * 1000;
        $client2 = $this->createStubClient(
            rates: [
                ['symbol' => 'BTCUSDT', 'fundingRate' => '0.00012000', 'fundingRateTimestamp' => (string) $t3],
            ],
            markPrices: [
                $t3 => '61000.00',
            ]
        );

        $synchronizer2 = new FundingHistorySynchronizer($client2, ['initial_lookback_days' => 30]);
        $result2 = $synchronizer2->sync('BTCUSDT', new DateTimeImmutable('2026-10-15T16:05:00Z'));

        self::assertSame(1, $result2['inserted']);
        self::assertFalse($result2['funding_gap_detected']);
        self::assertSame('complete', $result2['diagnostic']);
        self::assertSame(3, MarketFundingEventRecord::query()->where('symbol', 'BTCUSDT')->count());
    }

    public function test_gap_detection_and_explicit_diagnostic_logging_when_funding_interval_is_missing(): void
    {
        Log::spy();

        // 00:00 and 16:00 are present, but intermediate 08:00 is missing!
        $t1 = (new DateTimeImmutable('2026-10-15T00:00:00Z'))->getTimestamp() * 1000;
        $t3 = (new DateTimeImmutable('2026-10-15T16:00:00Z'))->getTimestamp() * 1000;

        $client = $this->createStubClient(
            rates: [
                ['symbol' => 'BTCUSDT', 'fundingRate' => '0.00010000', 'fundingRateTimestamp' => (string) $t1],
                ['symbol' => 'BTCUSDT', 'fundingRate' => '0.00015000', 'fundingRateTimestamp' => (string) $t3],
            ],
            markPrices: [
                $t1 => '60000.00',
                $t3 => '61000.00',
            ]
        );

        $synchronizer = new FundingHistorySynchronizer($client, ['initial_lookback_days' => 30]);
        $through = new DateTimeImmutable('2026-10-15T16:05:00Z');

        $result = $synchronizer->sync('BTCUSDT', $through);

        self::assertTrue($result['funding_gap_detected']);
        self::assertSame('funding_gap_detected', $result['diagnostic']);
        self::assertContains('2026-10-15T08:00:00+00:00', $result['missing_intervals']);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'funding_gap_detected')
                    && ($context['diagnostic'] ?? null) === 'funding_gap_detected'
                    && ($context['symbol'] ?? null) === 'BTCUSDT';
            })
            ->atLeast()->once();
    }

    public function test_gap_detection_and_explicit_diagnostic_logging_when_mark_price_is_missing(): void
    {
        Log::spy();

        $t1 = (new DateTimeImmutable('2026-10-15T08:00:00Z'))->getTimestamp() * 1000;

        // Funding rate exists, but mark price provider returns null (missing mark candle)
        $client = $this->createStubClient(
            rates: [
                ['symbol' => 'BTCUSDT', 'fundingRate' => '0.00010000', 'fundingRateTimestamp' => (string) $t1],
            ],
            markPrices: []
        );

        $synchronizer = new FundingHistorySynchronizer($client, ['initial_lookback_days' => 30]);
        $through = new DateTimeImmutable('2026-10-15T08:05:00Z');

        $result = $synchronizer->sync('BTCUSDT', $through);

        self::assertSame(1, $result['missing_mark_price']);
        self::assertSame(0, $result['inserted']);
        self::assertTrue($result['funding_gap_detected']);
        self::assertSame('funding_gap_detected', $result['diagnostic']);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'funding_gap_detected')
                    && ($context['diagnostic'] ?? null) === 'funding_gap_detected';
            })
            ->atLeast()->once();
    }

    public function test_gap_detection_and_explicit_diagnostic_logging_when_funding_cannot_be_fetched(): void
    {
        Log::spy();

        $failingClient = new class implements BybitMarketDataClient
        {
            public function fundingRates(string $category, string $symbol, ?int $fromMs = null, ?int $toMs = null): array
            {
                throw new \RuntimeException('Bybit upstream API 503 service unavailable');
            }

            public function candles(CandleQuery $query): array
            {
                return [];
            }

            public function ticker(string $category, string $symbol): array
            {
                return [];
            }

            public function orderBook(string $category, string $symbol, int $limit = 50): array
            {
                return [];
            }

            public function openInterest(string $category, string $symbol, string $intervalTime, ?string $cursor = null): array
            {
                return [];
            }
        };

        $synchronizer = new FundingHistorySynchronizer($failingClient, ['initial_lookback_days' => 30]);
        $result = $synchronizer->sync('BTCUSDT', new DateTimeImmutable('2026-10-15T08:05:00Z'));

        self::assertSame(0, $result['received']);
        self::assertSame(0, $result['inserted']);
        self::assertTrue($result['funding_gap_detected']);
        self::assertSame('funding_gap_detected', $result['diagnostic']);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'funding_gap_detected')
                    && ($context['diagnostic'] ?? null) === 'funding_gap_detected';
            })
            ->atLeast()->once();
    }

    public function test_safe_ledger_reconciliation_behavior_with_detected_gaps(): void
    {
        // 1. Create isolated paper account with initial capital
        $account = PaperAccountRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'REM-03 Funding Gap Safety Account',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'starting_balance' => '10000.00',
            'current_equity' => '10197.00',
            'peak_equity' => '10197.00',
            'realized_pnl' => '197.00',
            'unrealized_pnl' => '0.00',
            'current_drawdown_percent' => '0.00',
            'maximum_drawdown_percent' => '0.00',
            'status' => 'ACTIVE',
        ]);

        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'recorded_at' => new DateTimeImmutable('2026-10-15T06:00:00Z'),
            'equity' => '10000.00',
            'realized_pnl' => '0.00',
            'unrealized_pnl' => '0.00',
            'drawdown_percent' => '0.00',
            'source_event_key' => 'account:initial',
        ]);

        // 2. Create signal
        $signal = SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'decision_time' => new DateTimeImmutable('2026-10-15T06:55:00Z'),
            'candle_close_time' => new DateTimeImmutable('2026-10-15T06:55:00Z'),
            'expires_at' => new DateTimeImmutable('2026-10-16T07:00:00Z'),
            'maximum_holding_minutes' => 300,
            'reasons' => ['test_fixture'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);

        // 3. Create closed position that spanned across the 08:00:00Z funding boundary
        $position = VirtualPositionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'signal_id' => $signal->id,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => PositionStatus::ClosedTp->value,
            'entry_price' => '60000.00',
            'stop_loss' => '59000.00',
            'take_profit_1' => '62000.00',
            'take_profit_2' => '63000.00',
            'initial_quantity' => '0.10',
            'remaining_quantity' => '0.00',
            'qty_step' => '0.001',
            'risk_capital' => '100.00',
            'gross_pnl' => '200.00',
            'fees' => '3.00',
            'funding' => '0.00',
            'net_pnl' => '197.00',
            'unrealized_pnl' => '0.00',
            'r_multiple' => '1.97',
            'opened_at' => new DateTimeImmutable('2026-10-15T07:00:00Z'),
            'maximum_holding_until' => new DateTimeImmutable('2026-10-15T12:00:00Z'),
            'closed_at' => new DateTimeImmutable('2026-10-15T09:00:00Z'),
            'last_processed_event_at' => new DateTimeImmutable('2026-10-15T09:00:00Z'),
            'execution_model' => 'BAR_HIGH_LOW',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'funding_complete' => false,
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $position->id,
            'type' => ExecutionType::Entry->value,
            'side' => 'BUY',
            'quantity' => '0.10',
            'price' => '60000.00',
            'gross_pnl' => '0.00',
            'commission' => '1.50',
            'slippage' => '0.00',
            'market_event_at' => new DateTimeImmutable('2026-10-15T07:00:00Z'),
            'registered_at' => new DateTimeImmutable('2026-10-15T07:00:00Z'),
            'price_source' => 'NEXT_CANDLE_OPEN',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:entry:'.$position->id,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $position->id,
            'type' => ExecutionType::TakeProfit1->value,
            'side' => 'SELL',
            'quantity' => '0.10',
            'price' => '62000.00',
            'gross_pnl' => '200.00',
            'commission' => '1.50',
            'slippage' => '0.00',
            'market_event_at' => new DateTimeImmutable('2026-10-15T09:00:00Z'),
            'registered_at' => new DateTimeImmutable('2026-10-15T09:00:00Z'),
            'price_source' => 'LIMIT_ORDER',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:tp1:'.$position->id,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // Notice: NO MarketFundingEventRecord exists for 08:00:00Z boundary (funding gap detected!)
        $reconciliation = app(LedgerReconciliationService::class);
        $result = $reconciliation->reconcileAccount($account->id);

        // Fail-safe audit behavior: must NOT report PASSED
        self::assertSame('INCOMPLETE', $result['status']);
        self::assertContains('funding_gap_detected', $result['incomplete_reasons']);
        self::assertContains('funding_history_incomplete', $result['incomplete_reasons']);
        self::assertContains('funding_gap_detected', $result['findings']['incomplete_reasons']);

        // Now heal the gap: ingest the missing market funding event
        $mfe = MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'funding_at' => new DateTimeImmutable('2026-10-15T08:00:00Z'),
            'rate' => '0.00010000',
            'mark_price' => '60000.00',
            'mark_price_source' => 'BYBIT_MARK_KLINE_OPEN',
        ]);

        PositionFundingEventRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $position->id,
            'exchange_id' => 'bybit',
            'market_funding_event_id' => $mfe->id,
            'quantity' => '0.10',
            'mark_price' => '60000.00',
            'rate' => '0.00010000',
            'amount' => '-0.60',
            'funding_at' => new DateTimeImmutable('2026-10-15T08:00:00Z'),
        ]);

        // Update position funding and net pnl: 200 gross - 3 fees - 0.60 funding = 196.40
        $position->update([
            'funding' => '-0.60',
            'net_pnl' => '196.40',
            'funding_complete' => true,
        ]);
        $account->update([
            'realized_pnl' => '196.40',
            'current_equity' => '10196.40',
            'peak_equity' => '10196.40',
        ]);

        $healedResult = $reconciliation->reconcileAccount($account->id);

        self::assertSame('PASSED', $healedResult['status']);
        self::assertNotContains('funding_gap_detected', $healedResult['incomplete_reasons']);
        self::assertNotContains('funding_history_incomplete', $healedResult['incomplete_reasons']);
        self::assertSame(0, $healedResult['discrepancy_count']);
    }
}
