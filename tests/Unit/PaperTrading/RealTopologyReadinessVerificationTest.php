<?php

declare(strict_types=1);

namespace Tests\Unit\PaperTrading;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Application\TopologyMatrixAuditService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class RealTopologyReadinessVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePostgresTestDatabaseReady('paper_accounts');
        app(PaperTopologySynchronizer::class)->sync(true);
    }

    public function test_readiness_distinguishes_real_states_without_synthetic_defaults(): void
    {
        $auditService = app(TopologyMatrixAuditService::class);
        $result = $auditService->audit();

        self::assertSame(130, $result['total_expected']);
        self::assertSame(130, $result['total_provisioned']);

        $matrix = collect($result['matrix'])->keyBy(fn ($r) => "{$r['exchange_id']}:{$r['strategy_id']}");

        // For accounts without candles seeded, market_data_ready must be FALSE (not synthetic true)
        $coinbaseBaseline = $matrix->get('coinbase:baseline');
        self::assertNotNull($coinbaseBaseline);
        self::assertFalse($coinbaseBaseline['market_data_ready']);
        self::assertSame('INSUFFICIENT_HISTORY', $coinbaseBaseline['market_data_readiness']);
        self::assertSame('RISK_BLOCKED', $coinbaseBaseline['operational_state']);

        // For Bybit baseline with no signals, operational state must reflect real status (AWAITING_SIGNAL or READY)
        $bybitBaseline = $matrix->get('bybit:baseline');
        self::assertNotNull($bybitBaseline);
        self::assertTrue($bybitBaseline['account_provisioned']);
        self::assertTrue($bybitBaseline['strategy_ready']);
    }

    public function test_seeding_real_candles_updates_market_data_readiness_deterministically(): void
    {
        // Seed 60 closed candles for Bybit BTCUSDT
        $baseTime = CarbonImmutable::now('UTC');
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $t = $baseTime->subMinutes($i * 5);
            $rows[] = [
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'interval' => '5',
                'open_time' => $t,
                'open' => '60000',
                'high' => '60500',
                'low' => '59800',
                'close' => '60200',
                'volume' => '10',
                'turnover' => '600000',
                'is_closed' => true,
                'created_at' => $t,
                'updated_at' => $t,
            ];
        }
        CandleRecord::query()->insert($rows);

        try {
            $auditService = app(TopologyMatrixAuditService::class);
            $result = $auditService->audit();
            $bybitBaseline = collect($result['matrix'])->firstWhere('exchange_id', 'bybit');

            self::assertTrue($bybitBaseline['market_data_ready']);
            self::assertSame('DATA_READY', $bybitBaseline['market_data_readiness']);
        } finally {
            CandleRecord::query()->where('exchange', 'bybit')->delete();
        }
    }
}
