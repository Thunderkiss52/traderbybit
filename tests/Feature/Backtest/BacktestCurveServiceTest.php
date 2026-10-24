<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Backtest\Application\BacktestCurveService;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\Backtest\Infrastructure\Persistence\BacktestSegmentRecord;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class BacktestCurveServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_curve_is_bounded_and_preserves_bucket_extrema(): void
    {
        $runId = Uuid::uuid4()->toString();
        $segmentId = Uuid::uuid4()->toString();
        BacktestRunRecord::query()->create([
            'id' => $runId, 'fingerprint' => str_repeat('a', 64), 'type' => 'SPLIT', 'status' => 'COMPLETED',
            'from_at' => '2026-01-01T00:00:00Z', 'to_at' => '2026-01-10T00:00:00Z', 'symbols' => ['BTCUSDT'],
            'strategy_config' => [], 'risk_config' => [], 'execution_config' => [], 'strategy_version' => 'test',
            'risk_version' => 'test', 'execution_version' => 'test', 'backtest_version' => 'test', 'schema_version' => '1.0.0',
            'dataset_checksum' => str_repeat('b', 64), 'progress' => 100,
        ]);
        BacktestSegmentRecord::query()->create([
            'id' => $segmentId, 'backtest_run_id' => $runId, 'window_index' => 0, 'kind' => 'TEST',
            'from_at' => '2026-01-01T00:00:00Z', 'to_at' => '2026-01-10T00:00:00Z', 'parameters' => [], 'status' => 'COMPLETED',
        ]);
        $rows = [];
        for ($index = 0; $index < 1201; $index++) {
            $equity = $index === 400 ? '-999' : ($index === 800 ? '999' : (string) $index);
            $rows[] = [
                'backtest_run_id' => $runId, 'backtest_segment_id' => $segmentId,
                'recorded_at' => now('UTC')->startOfDay()->addMinutes($index), 'equity' => $equity,
                'realized_pnl' => '0', 'unrealized_pnl' => '0', 'drawdown_percent' => $index === 400 ? '99' : '0',
                'exposed' => false, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ];
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('backtest_equity_snapshots')->insert($chunk);
        }

        $this->getJson("/api/v1/backtests/{$runId}")->assertOk()->assertJsonPath('data.run.id', $runId);
        $this->getJson("/api/v1/backtests/{$runId}/report")->assertOk()->assertJsonPath('data.run.id', $runId);
        $this->getJson("/api/v1/backtests/{$runId}/trades")->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson("/api/v1/backtests/{$runId}/equity?max_points=100")->assertOk()
            ->assertJsonPath('data.original_points', 1201);
        $this->getJson("/api/v1/backtests/{$runId}/optimizations")->assertOk()->assertJsonPath('data.total', 0);

        $result = app(BacktestCurveService::class)->get($runId, 100);
        $equities = collect($result['points'])->pluck('equity')->map(static fn ($value): string => (string) $value);

        self::assertTrue($result['downsampled']);
        self::assertSame(1201, $result['original_points']);
        self::assertLessThanOrEqual(100, $result['returned_points']);
        self::assertTrue($equities->contains(fn ($value) => BigDecimal::of($value)->isEqualTo('-999')));
        self::assertTrue($equities->contains(fn ($value) => BigDecimal::of($value)->isEqualTo('999')));
    }
}
