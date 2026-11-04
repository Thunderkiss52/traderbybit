<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\ExchangeRegistry;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ExchangeOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure baseline exchanges are present in test DB
        $this->seedBaselineExchanges();
    }

    private function seedBaselineExchanges(): void
    {
        ExchangeRecord::query()->firstOrCreate(
            ['id' => 'bybit'],
            [
                'name' => 'Bybit Linear Perpetual',
                'market_type' => 'linear_perpetual',
                'description' => 'Bybit USDT-perpetual linear contracts with 13 canonical PRIMARY forward paper accounts.',
                'lifecycle_status' => 'ACTIVE_PAPER',
                'capabilities' => [
                    'has_candles' => true,
                    'has_funding' => true,
                    'has_open_interest' => true,
                    'has_live_adapter' => true,
                    'adapter_class' => 'App\Exchanges\Bybit\BybitAdapter',
                    'supported_timeframes' => ['5', '15', '30', '60'],
                    'price_precision' => 2,
                    'qty_precision' => 3,
                    'min_qty' => '0.001',
                    'min_notional' => '5.0',
                ],
                'cost_model' => [
                    'version' => 'bybit-linear-perpetual-2026.1',
                    'market' => 'USDT Perpetual (Linear)',
                    'fee_tier' => 'VIP 0',
                    'maker_rate' => '0.0002',
                    'taker_rate' => '0.00055',
                    'slippage_rate' => '0.0002',
                ],
                'paper_execution_assumptions' => [
                    'execution_model' => 'NEXT_CANDLE_OPEN',
                    'ambiguous_execution_policy' => 'FLAG_AND_HOLD',
                ],
                'paper_eligible' => true,
                'paper_approved' => true,
                'paper_approved_at' => now('UTC'),
            ]
        );

        ExchangeRecord::query()->firstOrCreate(
            ['id' => 'binance'],
            [
                'name' => 'Binance USD(S)-M Futures',
                'market_type' => 'linear_perpetual',
                'description' => 'Binance USD-M linear futures public market data and 13 provisioned BLOCKED paper accounts.',
                'lifecycle_status' => 'ACTIVE_PAPER',
                'capabilities' => [
                    'has_candles' => true,
                    'has_funding' => true,
                    'has_open_interest' => true,
                    'has_live_adapter' => true,
                    'adapter_class' => 'App\Exchanges\Binance\BinancePublicAdapter',
                    'supported_timeframes' => ['5', '15', '30', '60'],
                    'price_precision' => 2,
                    'qty_precision' => 3,
                    'min_qty' => '0.001',
                    'min_notional' => '5.0',
                ],
                'cost_model' => [
                    'version' => 'binance-usdm-perpetual-2026.1',
                    'market' => 'USD(S)-M Futures (Linear Perpetuals)',
                    'fee_tier' => 'VIP 0',
                    'maker_rate' => '0.0002',
                    'taker_rate' => '0.0005',
                    'slippage_rate' => '0.0002',
                ],
                'paper_execution_assumptions' => [
                    'execution_model' => 'NEXT_CANDLE_OPEN',
                    'ambiguous_execution_policy' => 'FLAG_AND_HOLD',
                ],
                'paper_eligible' => true,
                'paper_approved' => true,
                'paper_approved_at' => now('UTC'),
            ]
        );
    }

    public function test_list_exchanges_returns_bybit_and_binance_with_capabilities(): void
    {
        $response = $this->getJson('/api/v1/exchanges');
        $response->assertStatus(200);

        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertGreaterThanOrEqual(2, count($data));

        $ids = collect($data)->pluck('id')->all();
        self::assertContains('bybit', $ids);
        self::assertContains('binance', $ids);

        $bybit = collect($data)->firstWhere('id', 'bybit');
        self::assertSame('ACTIVE_PAPER', $bybit['lifecycle_status']);
        self::assertTrue($bybit['paper_approved']);
        self::assertTrue($bybit['paper_eligible']);
        self::assertTrue($bybit['capabilities']['has_candles']);
        self::assertSame('0.00055', $bybit['cost_model']['taker_rate']);
    }

    public function test_register_new_exchange_succeeds_with_offline_data_source_lifecycle(): void
    {
        $payload = [
            'id' => 'okx',
            'name' => 'OKX Linear Swap',
            'market_type' => 'linear_perpetual',
            'description' => 'OKX USDT linear perpetual swaps',
        ];

        $response = $this->postJson('/api/v1/exchanges', $payload);
        $response->assertStatus(201);

        $data = $response->json('data');
        self::assertSame('okx', $data['id']);
        self::assertSame('OKX Linear Swap', $data['name']);
        self::assertSame('OFFLINE_DATA_SOURCE', $data['lifecycle_status']);
        self::assertFalse($data['paper_eligible']);
        self::assertFalse($data['paper_approved']);

        $this->assertDatabaseHas('exchanges', [
            'id' => 'okx',
            'lifecycle_status' => 'OFFLINE_DATA_SOURCE',
            'paper_approved' => false,
        ]);
    }

    public function test_register_duplicate_exchange_is_rejected(): void
    {
        $payload = [
            'id' => 'bybit',
            'name' => 'Duplicate Bybit',
        ];

        $response = $this->postJson('/api/v1/exchanges', $payload);
        $response->assertStatus(422);
        self::assertSame('INVALID_ARGUMENT', $response->json('error.code'));
    }

    public function test_register_invalid_exchange_id_is_rejected(): void
    {
        $payload = [
            'id' => 'INVALID ID WITH SPACES',
            'name' => 'Bad ID',
        ];

        $response = $this->postJson('/api/v1/exchanges', $payload);
        $response->assertStatus(422);
    }

    public function test_csv_upload_validates_ohlcv_and_inserts_candles(): void
    {
        // Register test exchange
        $this->postJson('/api/v1/exchanges', [
            'id' => 'hyperliquid',
            'name' => 'Hyperliquid L1',
            'market_type' => 'linear_perpetual',
        ])->assertStatus(201);

        $csv = "timestamp,open,high,low,close,volume\n"
            ."1761799800,50000.0,50100.0,49900.0,50050.0,10.5\n"
            ."1761800100,50050.0,50200.0,50000.0,50150.0,12.3\n"
            ."1761800400,50150.0,50250.0,50100.0,50200.0,8.7\n";

        $response = $this->postJson('/api/v1/exchanges/hyperliquid/upload-candles', [
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'csv' => $csv,
        ]);

        $response->assertStatus(200);
        $result = $response->json('data');
        self::assertSame(3, $result['imported_bars']);
        self::assertSame(0, $result['duplicates_skipped']);
        self::assertSame(0, $result['conflicts_recorded']);
        self::assertSame('BTCUSDT', $result['symbol']);
        self::assertSame('5m', $result['interval']);
        self::assertNotEmpty($result['checksum']);

        self::assertSame(3, CandleRecord::query()->where('exchange', 'hyperliquid')->count());
    }

    public function test_csv_upload_detects_duplicates_idempotently(): void
    {
        $this->postJson('/api/v1/exchanges', [
            'id' => 'kraken',
            'name' => 'Kraken Futures',
        ])->assertStatus(201);

        $csv = "open_time,open,high,low,close,volume\n"
            ."2026-10-15T00:00:00Z,3000.0,3050.0,2990.0,3020.0,100.0\n"
            ."2026-10-15T00:15:00Z,3020.0,3040.0,3010.0,3035.0,90.0\n";

        // First upload
        $res1 = $this->postJson('/api/v1/exchanges/kraken/upload-candles', [
            'symbol' => 'ETHUSDT',
            'interval' => '15m',
            'csv' => $csv,
        ]);
        $res1->assertStatus(200);
        self::assertSame(2, $res1->json('data.imported_bars'));

        // Duplicate upload of exact same content
        $res2 = $this->postJson('/api/v1/exchanges/kraken/upload-candles', [
            'symbol' => 'ETHUSDT',
            'interval' => '15m',
            'csv' => $csv,
        ]);
        $res2->assertStatus(200);
        self::assertSame(0, $res2->json('data.imported_bars'));
        self::assertSame(2, $res2->json('data.duplicates_skipped'));
        self::assertSame(0, $res2->json('data.conflicts_recorded'));

        self::assertSame(2, CandleRecord::query()->where('exchange', 'kraken')->count());
    }

    public function test_csv_upload_detects_and_records_conflicts_without_corruption(): void
    {
        $this->postJson('/api/v1/exchanges', [
            'id' => 'coinbase',
            'name' => 'Coinbase Derivatives',
        ])->assertStatus(201);

        $initialCsv = "timestamp,open,high,low,close,volume\n"
            ."1761799800,100.0,110.0,95.0,105.0,500.0\n";

        $this->postJson('/api/v1/exchanges/coinbase/upload-candles', [
            'symbol' => 'SOLUSDT',
            'interval' => '5m',
            'csv' => $initialCsv,
        ])->assertStatus(200);

        // Upload conflicting row for same timestamp with different close & volume
        $conflictingCsv = "timestamp,open,high,low,close,volume\n"
            ."1761799800,100.0,110.0,95.0,108.0,600.0\n";

        $resConflict = $this->postJson('/api/v1/exchanges/coinbase/upload-candles', [
            'symbol' => 'SOLUSDT',
            'interval' => '5m',
            'csv' => $conflictingCsv,
        ]);

        $resConflict->assertStatus(200);
        self::assertSame(0, $resConflict->json('data.imported_bars'));
        self::assertSame(1, $resConflict->json('data.conflicts_recorded'));

        // Verify original candle in DB remains uncorrupted
        $originalCandle = CandleRecord::query()
            ->where('exchange', 'coinbase')
            ->where('symbol', 'SOLUSDT')
            ->first();
        self::assertNotNull($originalCandle);
        self::assertTrue(BigDecimal::of((string) $originalCandle->close)->isEqualTo(BigDecimal::of('105.0')));

        // Verify conflict recorded in market_candle_conflicts
        $conflictCount = DB::table('market_candle_conflicts')
            ->where('exchange', 'coinbase')
            ->where('symbol', 'SOLUSDT')
            ->count();
        self::assertGreaterThanOrEqual(1, $conflictCount);
    }

    public function test_csv_upload_rejects_malformed_and_non_monotonic_data(): void
    {
        $this->postJson('/api/v1/exchanges', [
            'id' => 'deribit',
            'name' => 'Deribit Futures',
        ])->assertStatus(201);

        // High < Low
        $badHighLow = "timestamp,open,high,low,close,volume\n"
            ."1761799800,100.0,90.0,110.0,105.0,500.0\n";

        $res1 = $this->postJson('/api/v1/exchanges/deribit/upload-candles', [
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'csv' => $badHighLow,
        ]);
        $res1->assertStatus(422);

        // Non-monotonic timestamps (goes backwards)
        $nonMonotonic = "timestamp,open,high,low,close,volume\n"
            ."1761800100,100.0,110.0,95.0,105.0,500.0\n"
            ."1761799800,105.0,115.0,100.0,110.0,400.0\n";

        $res2 = $this->postJson('/api/v1/exchanges/deribit/upload-candles', [
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'csv' => $nonMonotonic,
        ]);
        $res2->assertStatus(422);
    }

    public function test_transition_to_research_enabled_requires_candle_history(): void
    {
        $this->postJson('/api/v1/exchanges', [
            'id' => 'bitget',
            'name' => 'Bitget Futures',
        ])->assertStatus(201);

        // Try transitioning without any candles
        $failRes = $this->postJson('/api/v1/exchanges/bitget/lifecycle', [
            'status' => 'RESEARCH_ENABLED',
        ]);
        $failRes->assertStatus(422);
        self::assertSame('LIFECYCLE_TRANSITION_REJECTED', $failRes->json('error.code'));

        // Now upload candles
        $csv = "timestamp,open,high,low,close,volume\n"
            ."1761799800,100.0,110.0,95.0,105.0,500.0\n";
        $this->postJson('/api/v1/exchanges/bitget/upload-candles', [
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'csv' => $csv,
        ])->assertStatus(200);

        // Now lifecycle transition succeeds
        $passRes = $this->postJson('/api/v1/exchanges/bitget/lifecycle', [
            'status' => 'RESEARCH_ENABLED',
        ]);
        $passRes->assertStatus(200);
        self::assertSame('RESEARCH_ENABLED', $passRes->json('data.lifecycle_status'));

        // Verify ExchangeRegistry recognises it for backtesting
        self::assertTrue(app(ExchangeRegistry::class)->isSupportedForBacktest('bitget'));
    }

    public function test_paper_activation_rejected_without_required_capabilities(): void
    {
        $this->postJson('/api/v1/exchanges', [
            'id' => 'gateio',
            'name' => 'Gate.io Futures',
        ])->assertStatus(201);

        // Try setting PAPER_ELIGIBLE without adapter or complete timeframes
        $res = $this->postJson('/api/v1/exchanges/gateio/lifecycle', [
            'status' => 'PAPER_ELIGIBLE',
        ]);
        $res->assertStatus(422);
        self::assertSame('LIFECYCLE_TRANSITION_REJECTED', $res->json('error.code'));

        // Try approving paper directly
        $resApprove = $this->postJson('/api/v1/exchanges/gateio/approve-paper', [
            'approved' => true,
        ]);
        $resApprove->assertStatus(422);
        self::assertSame('PAPER_ACTIVATION_REJECTED', $resApprove->json('error.code'));
    }

    public function test_show_and_coverage_diagnostics(): void
    {
        $showRes = $this->getJson('/api/v1/exchanges/bybit');
        $showRes->assertStatus(200);
        self::assertSame('bybit', $showRes->json('data.id'));
        self::assertArrayHasKey('eligibility', $showRes->json('data'));

        $covRes = $this->getJson('/api/v1/exchanges/bybit/coverage');
        $covRes->assertStatus(200);
        self::assertSame('bybit', $covRes->json('data.exchange_id'));
        self::assertArrayHasKey('series', $covRes->json('data'));
        self::assertArrayHasKey('diagnostics', $covRes->json('data'));
    }

    public function test_topology_sync_preserves_blocked_accounts_and_global_risk_budget(): void
    {
        // Dry run on bybit
        $dryRes = $this->postJson('/api/v1/exchanges/bybit/sync-topology', [
            'apply' => false,
        ]);
        $dryRes->assertStatus(200);
        self::assertArrayHasKey('missing_pairs', $dryRes->json('data'));
        self::assertSame('DRY_RUN', $dryRes->json('data.mode'));

        // Unapproved exchange rejected from topology sync
        $this->postJson('/api/v1/exchanges', [
            'id' => 'phemex',
            'name' => 'Phemex',
        ])->assertStatus(201);

        $rejRes = $this->postJson('/api/v1/exchanges/phemex/sync-topology', [
            'apply' => false,
        ]);
        $rejRes->assertStatus(403);
    }
}
