<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\CounterfactualAnalyzerService;
use App\Research\Infrastructure\Persistence\CounterfactualEvaluationRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CounterfactualLegacyQuarantineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['research.counterfactual.legacy_analysis_enabled' => false]);
    }

    public function test_disabled_engine_does_not_create_evaluations_from_mature_skips(): void
    {
        $t0 = now('UTC')->subHours(5);
        $this->seedCandle($t0);

        StrategyObservationRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_quarantine',
            'side' => 'SKIP',
            'score' => 60,
            'decision_time' => $t0,
            'reasons' => ['adx_below_threshold_20'],
        ]);

        $result = (new CounterfactualAnalyzerService)->analyzeAndResolve(50);

        self::assertSame(0, $result['analyzed_count']);
        self::assertSame(0, $result['resolved_count']);
        self::assertSame('DISABLED', $result['legacy_analysis']);
        self::assertSame('P3_REJECTED_SIGNAL_REPLAY_PROHIBITED', $result['reason']);
        self::assertSame(0, CounterfactualEvaluationRecord::query()->count());
    }

    public function test_disabled_engine_does_not_resolve_pending_rows(): void
    {
        $t0 = now('UTC')->subHours(5);
        $this->seedCandle($t0);

        $record = CounterfactualEvaluationRecord::query()->create([
            'id' => (string) Str::uuid(),
            'signal_id' => null,
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'decision_time' => $t0,
            'skip_reason' => 'adx_below_threshold_20',
            'filter_family' => 'ADX',
            'candidate_direction' => 'BUY',
            'hypothetical_entry' => '60000',
            'hypothetical_sl' => '59100',
            'hypothetical_tp' => '61800',
            'status' => 'PENDING_MATURITY',
            'decision_snapshot' => ['legacy' => true],
        ]);

        $resolved = (new CounterfactualAnalyzerService)->resolveHypotheticalOutcome($record);

        self::assertFalse($resolved);
        $record->refresh();
        self::assertSame('PENDING_MATURITY', $record->status);
        self::assertNull($record->mfe);
        self::assertNull($record->mae);
        self::assertNull($record->r_multiple);
    }

    public function test_report_marks_legacy_rows_read_only(): void
    {
        $report = (new CounterfactualAnalyzerService)->filterEffectivenessReport();

        self::assertSame('DISABLED', $report['legacy_analysis']);
        self::assertSame('LEGACY_READ_ONLY', $report['analysis_status']);
        self::assertSame('P3_REJECTED_SIGNAL_REPLAY_PROHIBITED', $report['analysis_reason']);
    }

    public function test_configuration_cannot_enable_replay_in_operational_mode(): void
    {
        config(['research.counterfactual.legacy_analysis_enabled' => true]);
        $previous = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            self::assertFalse((new CounterfactualAnalyzerService)->legacyAnalysisEnabled());
            self::assertSame('DISABLED', (new CounterfactualAnalyzerService)->analyzeAndResolve()['legacy_analysis']);
        } finally {
            $this->app['env'] = $previous;
        }
    }

    public function test_legacy_metric_signs_include_recorded_timeouts_and_exclude_missing_r(): void
    {
        foreach ([['RESOLVED_TP', '2'], ['RESOLVED_SL', '-1'], ['RESOLVED_TIMEOUT', '-0.25'], ['RESOLVED_TIMEOUT', null], ['AMBIGUOUS', '10']] as $index => [$status, $r]) {
            CounterfactualEvaluationRecord::query()->create([
                'id' => (string) Str::uuid(), 'strategy_id' => 'trend_pullback', 'strategy_version' => '1.0.0',
                'symbol' => 'BTCUSDT', 'timeframe' => '15', 'decision_time' => now()->subMinutes($index * 15),
                'skip_reason' => 'legacy_fixture', 'filter_family' => 'ADX', 'candidate_direction' => 'BUY',
                'hypothetical_entry' => '100', 'hypothetical_sl' => '99', 'hypothetical_tp' => '102',
                'status' => $status, 'r_multiple' => $r, 'decision_snapshot' => [],
            ]);
        }
        $report = (new CounterfactualAnalyzerService)->filterEffectivenessReport();
        self::assertSame(2.0, $report['missed_tp_r']);
        self::assertSame(1.0, $report['avoided_sl_r']);
        self::assertSame(-0.25, $report['timeout_r']);
        self::assertSame(-0.75, $report['net_filter_benefit_r']);
        self::assertSame(-1.0, $report['net_missed_r']);
        self::assertSame(3, $report['metric_contract']['eligible_records']);
        self::assertSame(1, $report['metric_contract']['missing_r_records']);

        $filter = collect($report['filters'])->firstWhere('filter', 'ADX');
        self::assertNotNull($filter);
        self::assertSame(2.0, $filter['missed_tp_r']);
        self::assertSame(1.0, $filter['avoided_sl_r']);
        self::assertSame(-0.25, $filter['timeout_r']);
        self::assertSame(-0.75, $filter['net_filter_benefit_r']);
        self::assertSame(3, $filter['eligible_records']);
        self::assertSame(1, $filter['missing_r_records']);
        self::assertSame(-1.0, $filter['net_r']);
    }

    private function seedCandle(\DateTimeInterface $openTime): void
    {
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $openTime,
            'open' => '60000',
            'high' => '60500',
            'low' => '59800',
            'close' => '60000',
            'volume' => '100',
            'turnover' => '6000000',
            'is_closed' => true,
        ]);
    }
}
