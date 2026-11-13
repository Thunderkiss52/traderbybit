<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Domain\TradeRiskClass;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Domain\SignalQuality;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class SignalQualityAnalyticsAndMultiTimeframeProfitabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['paper.topology.auto_sync_enabled' => false]);
        $this->app->make(PaperTopologySynchronizer::class)->sync(true);
    }

    public function test_empty_signal_cohort_has_undefined_metrics_without_server_error(): void
    {
        // A never-used strategy on a registered exchange has no signals,
        // including for 1m/3m/5m/30m/60m. Empty timeframe expectancy must
        // never be sent to BigDecimal::of('') in diagnostics.
        $response = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit&strategy=does_not_exist');
        $response->assertOk()
            ->assertJsonPath('data.overview.total_signals', 0)
            ->assertJsonPath('data.overview.outcome_distribution.good_percent', null)
            ->assertJsonPath('data.overview.win_rate', null)
            ->assertJsonPath('data.overview.profit_factor', null);

        self::assertSame([], $response->json('data.diagnostics'));
        $rows = $response->json('data.timeframe_comparison');
        self::assertCount(6, $rows);
        foreach ($rows as $row) {
            self::assertSame(0, $row['signals']);
            self::assertNull($row['expectancy']);
            self::assertNull($row['win_rate']);
        }
    }

    public function test_signal_explorer_uses_ten_rows_by_default(): void
    {
        $this->getJson('/api/v1/research/profitability/signal-explorer')
            ->assertOk()
            ->assertJsonPath('per_page', 10);
    }

    public function test_signal_analytics_endpoint_returns_six_canonical_timeframes(): void
    {
        $this->seedSampleSignalCohorts();

        $response = $this->getJson('/api/v1/research/profitability/signal-analytics');
        $response->assertOk();

        $data = $response->json('data');
        self::assertNotNull($data);
        self::assertArrayHasKey('overview', $data);
        self::assertArrayHasKey('exchange_comparison', $data);
        self::assertArrayHasKey('strategy_comparison', $data);
        self::assertArrayHasKey('timeframe_comparison', $data);
        self::assertArrayHasKey('quality_risk_matrix', $data);
        self::assertArrayHasKey('diagnostics', $data);

        $timeframes = collect($data['timeframe_comparison'])->pluck('timeframe')->all();
        self::assertSame(['1m', '3m', '5m', '15m', '30m', '60m'], $timeframes);

        // Quality Risk Matrix has all 16 combinations (4 quality x 4 risk)
        self::assertCount(16, $data['quality_risk_matrix']);
    }

    public function test_exchange_filter_strictly_isolates_signal_and_position_metrics(): void
    {
        $this->seedSampleSignalCohorts();

        // 1. Bybit scope
        $bybitRes = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit');
        $bybitRes->assertOk();
        $bybitData = $bybitRes->json('data');
        self::assertSame(5, $bybitData['overview']['total_signals']);
        self::assertSame('50.00', (string) BigDecimal::of((string) $bybitData['overview']['overall_net_pnl'])->toScale(2));

        // 2. Binance scope
        $binanceRes = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=binance');
        $binanceRes->assertOk();
        $binanceData = $binanceRes->json('data');
        self::assertSame(3, $binanceData['overview']['total_signals']);
        self::assertSame('-30.00', (string) BigDecimal::of((string) $binanceData['overview']['overall_net_pnl'])->toScale(2));

        // 3. OKX scope (no signals)
        $okxRes = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=okx');
        $okxRes->assertOk();
        $okxData = $okxRes->json('data');
        self::assertSame(0, $okxData['overview']['total_signals']);
        self::assertSame('0', (string) $okxData['overview']['overall_net_pnl']);
    }

    public function test_instrument_filter_strictly_isolates_btc_and_eth(): void
    {
        $this->seedSampleSignalCohorts();

        // BTC only
        $btcRes = $this->getJson('/api/v1/research/profitability/signal-analytics?symbol=BTCUSDT');
        $btcRes->assertOk();
        $btcData = $btcRes->json('data');
        self::assertSame(5, $btcData['overview']['total_signals']);
        self::assertSame('50.00', (string) BigDecimal::of((string) $btcData['overview']['overall_net_pnl'])->toScale(2));

        // ETH only
        $ethRes = $this->getJson('/api/v1/research/profitability/signal-analytics?symbol=ETHUSDT');
        $ethRes->assertOk();
        $ethData = $ethRes->json('data');
        self::assertSame(3, $ethData['overview']['total_signals']);
        self::assertSame('-30.00', (string) BigDecimal::of((string) $ethData['overview']['overall_net_pnl'])->toScale(2));
    }

    public function test_retrospective_outcome_classifies_good_bad_and_unproven(): void
    {
        $this->seedSampleSignalCohorts();

        $response = $this->getJson('/api/v1/research/profitability/signal-analytics');
        $response->assertOk();

        $overview = $response->json('data.overview');
        self::assertSame(1, $overview['outcome_distribution']['GOOD']);
        self::assertSame(1, $overview['outcome_distribution']['BAD']);
        self::assertSame(6, $overview['outcome_distribution']['UNPROVEN']);
    }

    public function test_signal_explorer_paginates_and_sorts_correctly(): void
    {
        $this->seedSampleSignalCohorts();

        $response = $this->getJson('/api/v1/research/profitability/signal-explorer?per_page=3&sort_by=decision_time&sort_dir=desc');
        $response->assertOk();

        $json = $response->json();
        self::assertSame(1, $json['current_page']);
        self::assertSame(3, $json['per_page']);
        self::assertSame(8, $json['total']);
        self::assertCount(3, $json['data']);

        $item = $json['data'][0];
        self::assertArrayHasKey('id', $item);
        self::assertArrayHasKey('exchange', $item);
        self::assertArrayHasKey('strategy_id', $item);
        self::assertArrayHasKey('symbol', $item);
        self::assertArrayHasKey('timeframe', $item);
        self::assertArrayHasKey('side', $item);
        self::assertArrayHasKey('entry_price', $item);
        self::assertArrayHasKey('signal_quality', $item);
        self::assertArrayHasKey('trade_risk', $item);
        self::assertArrayHasKey('gate_decision', $item);
        self::assertArrayHasKey('retrospective_outcome', $item);
    }

    public function test_signal_detail_endpoint_provides_complete_explainability(): void
    {
        $this->seedSampleSignalCohorts();

        $signal = SignalRecord::query()->where('exchange', 'bybit')->where('symbol', 'BTCUSDT')->firstOrFail();

        $response = $this->getJson("/api/v1/research/profitability/signal-explorer/{$signal->id}");
        $response->assertOk();

        $data = $response->json('data');
        self::assertSame($signal->id, $data['id']);
        self::assertSame('bybit', $data['exchange']);
        self::assertSame('BTCUSDT', $data['symbol']);
        self::assertSame('15m', $data['timeframe']);
        self::assertSame('HIGH', $data['signal_quality']);
        self::assertSame('LOW', $data['trade_risk']);
        self::assertSame('ELIGIBLE', $data['gate_decision']);
        self::assertTrue($data['gate_allowed']);
        self::assertNotNull($data['indicator_snapshot']);
        self::assertNotNull($data['position']);
        self::assertSame('50.00', (string) BigDecimal::of((string) $data['position']['net_pnl'])->toScale(2));
    }

    public function test_signal_explorer_csv_export_streams_valid_content(): void
    {
        $this->seedSampleSignalCohorts();

        $response = $this->get('/api/v1/research/profitability/signal-explorer/export');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        self::assertStringContainsString('Timestamp,Exchange,Strategy,Instrument,Timeframe,Direction', $content);
        self::assertStringContainsString('bybit', $content);
        self::assertStringContainsString('BTCUSDT', $content);
        self::assertStringContainsString('binance', $content);
        self::assertStringContainsString('ETHUSDT', $content);
    }

    public function test_strategy_and_timeframe_filters_do_not_leak_other_signal_positions(): void
    {
        $this->seedSampleSignalCohorts();

        // Introduce a second strategy on the same exchange, symbol and timeframe
        // with a deliberately different PnL to expose independently scoped
        // signal/position queries.
        $source = SignalRecord::query()->where('exchange', 'bybit')->where('retrospective_outcome', 'GOOD')->firstOrFail();
        $sourcePosition = VirtualPositionRecord::query()->where('signal_id', $source->id)->firstOrFail();
        $baselineAccount = PaperAccountRecord::query()->where('exchange_id', 'bybit')
            ->where('primary_strategy_id', 'baseline')->firstOrFail();

        $otherSignal = $source->replicate();
        $otherSignal->id = Uuid::uuid4()->toString();
        $otherSignal->strategy_id = 'baseline';
        $otherSignal->decision_time = CarbonImmutable::now('UTC')->subMinutes(180);
        $otherSignal->candle_close_time = $otherSignal->decision_time;
        $otherSignal->save();

        $otherPosition = $sourcePosition->replicate();
        $otherPosition->id = Uuid::uuid4()->toString();
        $otherPosition->signal_id = $otherSignal->id;
        $otherPosition->paper_account_id = $baselineAccount->id;
        $otherPosition->net_pnl = '999.00000000';
        $otherPosition->save();

        $trend = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit&strategy=trend_pullback');
        $trend->assertOk();
        self::assertSame(5, $trend->json('data.overview.total_signals'));
        self::assertSame(1, $trend->json('data.overview.executed_trades'));
        self::assertSame('50.00', (string) BigDecimal::of((string) $trend->json('data.overview.overall_net_pnl'))->toScale(2));

        $baseline = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit&strategy=baseline');
        $baseline->assertOk();
        self::assertSame(1, $baseline->json('data.overview.total_signals'));
        self::assertSame('999.00', (string) BigDecimal::of((string) $baseline->json('data.overview.overall_net_pnl'))->toScale(2));

        // The 3m signal is rejected/not executed. No 15m or 5m PnL can leak.
        $timeframe = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit&timeframe=3m');
        $timeframe->assertOk();
        self::assertSame(1, $timeframe->json('data.overview.total_signals'));
        self::assertSame(0, $timeframe->json('data.overview.executed_trades'));
        self::assertSame('0', (string) $timeframe->json('data.overview.overall_net_pnl'));
        self::assertNull($timeframe->json('data.overview.max_drawdown'));
    }

    public function test_signal_detail_displays_historical_recorded_risk_decision_not_current_policy(): void
    {
        $this->seedSampleSignalCohorts();
        $signal = SignalRecord::query()->where('exchange', 'bybit')->where('timeframe', '30m')->firstOrFail();
        $account = PaperAccountRecord::query()->where('exchange_id', 'bybit')
            ->where('primary_strategy_id', 'trend_pullback')->firstOrFail();

        RiskDecisionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'risk_version' => 'historical.1',
            'signal_quality' => 'HIGH',
            'trade_risk' => 'LOW',
            'allowed' => false,
            'reasons' => ['ACCOUNT_BLOCKED'],
            'snapshot' => [
                'gate_decision' => 'REJECTED',
                'expected_net_edge' => 0.7,
                'gate_version' => 'historical.1',
            ],
            'decided_at' => CarbonImmutable::now('UTC')->subMinutes(50),
        ]);

        $response = $this->getJson("/api/v1/research/profitability/signal-explorer/{$signal->id}");
        $response->assertOk();
        self::assertSame('REJECTED', $response->json('data.gate_decision'));
        self::assertFalse($response->json('data.gate_allowed'));
        self::assertSame(['ACCOUNT_BLOCKED'], $response->json('data.gate_reasons'));
        self::assertSame('PERSISTED_RISK_DECISION', $response->json('data.gate_evidence_source'));
    }

    public function test_missing_trade_evidence_never_invents_best_exchange_or_timeframe(): void
    {
        $response = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=okx');
        $response->assertOk();

        self::assertNull($response->json('data.overview.best_exchange'));
        self::assertNull($response->json('data.overview.worst_exchange'));
        self::assertNull($response->json('data.overview.best_timeframe'));
        self::assertNull($response->json('data.overview.worst_timeframe'));
    }

    public function test_gate_and_execution_status_filters_are_applied_to_real_signal_cohorts(): void
    {
        $this->seedSampleSignalCohorts();

        $eligible = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit&gate_decision=ELIGIBLE');
        $eligible->assertOk();
        self::assertSame(1, $eligible->json('data.overview.total_signals'));
        self::assertSame('50.00', (string) BigDecimal::of((string) $eligible->json('data.overview.overall_net_pnl'))->toScale(2));

        $executed = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit&execution_status=EXECUTED');
        $executed->assertOk();
        self::assertSame(1, $executed->json('data.overview.total_signals'));
        self::assertSame(1, $executed->json('data.overview.executed_trades'));

        $pending = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit&gate_decision=PENDING');
        $pending->assertOk();
        self::assertSame(4, $pending->json('data.overview.total_signals'));
        self::assertSame(0, $pending->json('data.overview.executed_trades'));

        $nonsense = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit&gate_decision=NOT_A_REAL_STATE');
        $nonsense->assertOk();
        self::assertSame(0, $nonsense->json('data.overview.total_signals'));

        $unknownVenue = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=unregistered_fake_exchange');
        $unknownVenue->assertStatus(422);
    }

    public function test_rejection_count_requires_persisted_risk_decision(): void
    {
        $this->seedSampleSignalCohorts();

        // Historical rejected signals are not inferred from a missing position.
        $initial = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit');
        $initial->assertOk();
        self::assertSame(0, $initial->json('data.overview.rejected_signals'));

        $signal = SignalRecord::query()->where('exchange', 'bybit')->where('timeframe', '30m')->firstOrFail();
        $account = PaperAccountRecord::query()->where('exchange_id', 'bybit')
            ->where('primary_strategy_id', 'trend_pullback')->firstOrFail();
        RiskDecisionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'risk_version' => 'rejected-test',
            'allowed' => false,
            'reasons' => ['ACCOUNT_BLOCKED'],
            'snapshot' => ['gate_decision' => 'REJECTED'],
            'decided_at' => CarbonImmutable::now('UTC'),
        ]);

        $response = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit');
        $response->assertOk();
        self::assertSame(1, $response->json('data.overview.rejected_signals'));
        $matrix = collect($response->json('data.quality_risk_matrix'));
        self::assertSame(1, $matrix->sum('rejected'));

        $rejected = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit&execution_status=REJECTED');
        $rejected->assertOk();
        self::assertSame(1, $rejected->json('data.overview.total_signals'));
        self::assertSame(0, $rejected->json('data.overview.executed_trades'));
    }

    public function test_shared_signal_multiple_accounts_are_not_collapsed_in_financial_comparisons(): void
    {
        $this->seedSampleSignalCohorts();
        $signal = SignalRecord::query()->where('exchange', 'bybit')->where('retrospective_outcome', 'GOOD')->firstOrFail();
        $original = VirtualPositionRecord::query()->where('signal_id', $signal->id)->firstOrFail();
        $secondAccount = PaperAccountRecord::query()->where('exchange_id', 'bybit')
            ->where('primary_strategy_id', 'baseline')->firstOrFail();

        $second = $original->replicate();
        $second->id = Uuid::uuid4()->toString();
        $second->paper_account_id = $secondAccount->id;
        $second->gross_pnl = '8.00000000';
        $second->fees = '0.75000000';
        $second->funding = '0';
        $second->net_pnl = '7.25000000';
        $second->save();

        $response = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit');
        $response->assertOk();
        $overview = $response->json('data.overview');
        self::assertSame(2, $overview['executed_trades']);
        self::assertSame(2, $overview['closed_trades']);
        self::assertSame('57.25', (string) BigDecimal::of($overview['overall_net_pnl'])->toScale(2));

        $exchange = collect($response->json('data.exchange_comparison'))->firstWhere('exchange', 'bybit');
        self::assertSame(2, $exchange['executed']);
        self::assertSame('57.25', (string) BigDecimal::of($exchange['net_pnl'])->toScale(2));
        $strategy = collect($response->json('data.strategy_comparison'))->firstWhere('strategy', 'trend_pullback');
        self::assertSame(2, $strategy['executed']);
        self::assertSame('57.25', (string) BigDecimal::of($strategy['net_pnl'])->toScale(2));
        $timeframe = collect($response->json('data.timeframe_comparison'))->firstWhere('timeframe', '15m');
        self::assertSame(2, $timeframe['executed']);
        self::assertSame('57.25', (string) BigDecimal::of($timeframe['net_pnl'])->toScale(2));
        $matrix = collect($response->json('data.quality_risk_matrix'))
            ->first(fn (array $row) => $row['quality'] === 'HIGH' && $row['trade_risk'] === 'LOW');
        self::assertSame(2, $matrix['executed']);
        self::assertSame('57.25', (string) BigDecimal::of($matrix['net_pnl'])->toScale(2));

        $explorer = $this->getJson('/api/v1/research/profitability/signal-explorer?exchange=bybit');
        $explorer->assertOk();
        $row = collect($explorer->json('data'))->firstWhere('id', $signal->id);
        self::assertSame(2, $row['position_count']);
        self::assertSame('57.25', (string) BigDecimal::of($row['net_pnl'])->toScale(2));

        $detail = $this->getJson("/api/v1/research/profitability/signal-explorer/{$signal->id}");
        $detail->assertOk();
        self::assertSame(2, $detail->json('data.position_count'));
        self::assertCount(2, $detail->json('data.positions'));
        self::assertNull($detail->json('data.position')); // Never display a random account as the only execution.

        $risk = RiskDecisionRecord::query()->where('signal_id', $signal->id)->firstOrFail();
        $secondRisk = $risk->replicate();
        $secondRisk->id = Uuid::uuid4()->toString();
        $secondRisk->paper_account_id = $secondAccount->id;
        $secondRisk->allowed = false;
        $secondRisk->reasons = ['ACCOUNT_BLOCKED'];
        $secondRisk->snapshot = ['gate_decision' => 'REJECTED'];
        $secondRisk->save();

        $detail = $this->getJson("/api/v1/research/profitability/signal-explorer/{$signal->id}");
        $detail->assertOk();
        self::assertSame('MULTIPLE_ACCOUNTS', $detail->json('data.gate_decision'));
        self::assertCount(2, $detail->json('data.risk_decisions'));
        self::assertNull($detail->json('data.risk_decision')); // No arbitrary account risk verdict.
    }

    public function test_partially_closed_position_counts_posted_realized_pnl_but_not_as_closed_win(): void
    {
        $this->seedSampleSignalCohorts();
        $position = VirtualPositionRecord::query()->where('exchange_id', 'bybit')->firstOrFail();
        $position->status = PositionStatus::PartiallyClosed->value;
        $position->net_pnl = '12.00000000';
        $position->gross_pnl = '14.00000000';
        $position->fees = '2.00000000';
        $position->funding = '0';
        $position->remaining_quantity = '0.05';
        $position->closed_at = null;
        $position->save();

        $response = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit');
        $response->assertOk();
        self::assertSame(1, $response->json('data.overview.executed_trades'));
        self::assertSame(0, $response->json('data.overview.closed_trades'));
        self::assertSame('12.00', (string) BigDecimal::of((string) $response->json('data.overview.overall_net_pnl'))->toScale(2));
        self::assertSame('POSTED_REALIZED_TO_DATE_EXCLUDES_UNREALIZED', $response->json('data.overview.pnl_scope'));
        $timeframe = collect($response->json('data.timeframe_comparison'))->firstWhere('timeframe', '15m');
        self::assertSame(0, $timeframe['closed']);
        self::assertSame('12.00', (string) BigDecimal::of((string) $timeframe['net_pnl'])->toScale(2));

        $signal = SignalRecord::query()->where('exchange', 'bybit')->where('retrospective_outcome', 'GOOD')->firstOrFail();
        $explorer = $this->getJson('/api/v1/research/profitability/signal-explorer?exchange=bybit');
        $explorer->assertOk();
        $row = collect($explorer->json('data'))->firstWhere('id', $signal->id);
        self::assertSame('12.00', (string) BigDecimal::of((string) $row['net_pnl'])->toScale(2));
        self::assertSame(0, $row['closed_position_count']);
    }

    public function test_profit_factor_is_not_fabricated_as_999_when_there_are_no_losses(): void
    {
        $this->seedSampleSignalCohorts();
        $response = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit');
        $response->assertOk();
        self::assertNull($response->json('data.overview.profit_factor'));
        $tf = collect($response->json('data.timeframe_comparison'))->firstWhere('timeframe', '15m');
        self::assertNull($tf['profit_factor']);
    }

    public function test_observation_only_counts_require_recorded_gate_evidence(): void
    {
        $this->seedSampleSignalCohorts();
        $before = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit');
        $before->assertOk();
        self::assertSame(0, $before->json('data.overview.observation_only_signals'));

        $signal = SignalRecord::query()->where('exchange', 'bybit')->where('signal_quality', 'LOW')->firstOrFail();
        $account = PaperAccountRecord::query()->where('exchange_id', 'bybit')
            ->where('primary_strategy_id', 'trend_pullback')->firstOrFail();
        RiskDecisionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'risk_version' => 'observation-only-test',
            'allowed' => false,
            'reasons' => ['LOW_SIGNAL_QUALITY'],
            'snapshot' => ['gate_decision' => 'OBSERVATION_ONLY'],
            'decided_at' => CarbonImmutable::now('UTC'),
        ]);
        $after = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit');
        $after->assertOk();
        self::assertSame(1, $after->json('data.overview.observation_only_signals'));
        self::assertSame(0, $after->json('data.overview.rejected_signals'));
    }

    public function test_signal_explorer_csv_export_escapes_spreadsheet_formulas_without_corrupting_numeric_values(): void
    {
        $this->seedSampleSignalCohorts(['=HYPERLINK("https://invalid.example", "click")']);

        $response = $this->get('/api/v1/research/profitability/signal-explorer/export?exchange=bybit');
        $response->assertOk();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $response->streamedContent());
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '');
        self::assertCount(21, $header);
        $reasonIndex = array_search('Rejection Reason', $header, true);
        $netPnlIndex = array_search('Net PnL', $header, true);
        $found = false;
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if (str_contains((string) $row[$reasonIndex], 'HYPERLINK')) {
                self::assertStringStartsWith("'=HYPERLINK", $row[$reasonIndex]);
                self::assertTrue(is_numeric($row[$netPnlIndex]));
                $found = true;
            }
        }
        fclose($stream);
        self::assertTrue($found, 'Fixture formula must appear escaped in the streamed CSV.');
    }

    public function test_signal_explorer_csv_export_does_not_stop_after_first_thousand_signals(): void
    {
        $this->seedSampleSignalCohorts();
        $template = SignalRecord::query()->where('exchange', 'bybit')->firstOrFail();
        $attrs = $template->getAttributes();
        $stamp = CarbonImmutable::now('UTC')->subDays(7);
        $batch = [];
        for ($i = 0; $i < 1003; $i++) {
            $row = $attrs;
            $row['id'] = Uuid::uuid4()->toString();
            $row['decision_time'] = $stamp->addSeconds($i)->toDateTimeString();
            $row['candle_close_time'] = $stamp->addSeconds($i)->toDateTimeString();
            $row['created_at'] = $stamp->toDateTimeString();
            $row['updated_at'] = $stamp->toDateTimeString();
            $batch[] = $row;
            if (count($batch) === 100) {
                DB::table('signals')->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('signals')->insert($batch);
        }

        $response = $this->get('/api/v1/research/profitability/signal-explorer/export?exchange=bybit');
        $response->assertOk();
        $content = $response->streamedContent();
        // Five Bybit fixture signals, plus 1,003 bulk records and the header.
        self::assertSame(1009, substr_count($content, "\n"));
    }

    public function test_analytics_aggregates_large_cohorts_without_hydrating_signal_or_position_models(): void
    {
        $this->seedSampleSignalCohorts();
        $template = SignalRecord::query()->where('exchange', 'bybit')->firstOrFail()->getAttributes();
        $stamp = CarbonImmutable::now('UTC')->subDays(7);
        for ($batch = 0; $batch < 10; $batch++) {
            $rows = [];
            for ($i = 0; $i < 250; $i++) {
                $row = $template;
                $row['id'] = Uuid::uuid4()->toString();
                $row['decision_time'] = $stamp->addSeconds($batch * 250 + $i)->toDateTimeString();
                $row['candle_close_time'] = $row['decision_time'];
                $row['indicator_snapshot'] = json_encode(['payload' => str_repeat('x', 2048)], JSON_THROW_ON_ERROR);
                $rows[] = $row;
            }
            DB::table('signals')->insert($rows);
        }
        $events = app('events');
        $names = ['eloquent.retrieved: '.SignalRecord::class, 'eloquent.retrieved: '.VirtualPositionRecord::class];
        foreach ($names as $name) {
            $events->listen($name, static fn () => throw new \LogicException('Analytics must not hydrate unbounded history.'));
        }
        try {
            $start = hrtime(true);
            $memory = memory_get_usage(true);
            $response = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit');
            $response->assertOk()->assertJsonPath('data.overview.total_signals', 2505)
                ->assertJsonPath('data.overview.executed_trades', 1);
            self::assertDecimalSame('50', $response->json('data.overview.overall_net_pnl'));
            fwrite(STDOUT, json_encode(['analytics_rows' => 2505, 'payload_bytes_per_signal' => 2048,
                'elapsed_ms' => (hrtime(true) - $start) / 1000000,
                'php_allocated_delta_bytes' => memory_get_usage(true) - $memory], JSON_THROW_ON_ERROR)."\n");
        } finally {
            foreach ($names as $name) {
                $events->forget($name);
            }
        }
    }

    public function test_analytics_group_limit_returns_an_explicit_error_instead_of_partial_totals(): void
    {
        $this->seedSampleSignalCohorts();
        config(['research.analytics.maximum_groups' => 1]);
        $this->getJson('/api/v1/research/profitability/signal-analytics')
            ->assertUnprocessable()->assertJsonValidationErrors('filters');
    }

    public function test_legacy_quality_interpretation_matches_explorer_and_aggregate_filters(): void
    {
        $this->seedSampleSignalCohorts();
        $signal = SignalRecord::query()->where('exchange', 'bybit')->where('retrospective_outcome', 'GOOD')->firstOrFail();
        $signal->update(['signal_quality' => null]);
        self::assertSame('HIGH', $signal->refresh()->signal_quality);
        $response = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit&signal_quality=HIGH');
        $response->assertOk();
        self::assertDecimalSame('50', $response->json('data.overview.overall_net_pnl'));
        self::assertSame($response->json('data.overview.total_signals'), $response->json('data.overview.quality_distribution.HIGH'));
        $unknown = $this->getJson('/api/v1/research/profitability/signal-explorer?exchange=bybit&signal_quality=UNKNOWN');
        $unknown->assertOk();
        self::assertNotContains($signal->id, array_column($unknown->json('data'), 'id'));
    }

    public function test_inconsistent_account_exchange_cannot_contaminate_profitability(): void
    {
        $this->seedSampleSignalCohorts();
        $position = VirtualPositionRecord::query()->where('exchange_id', 'bybit')->firstOrFail();
        $foreign = PaperAccountRecord::query()->where('exchange_id', 'binance')->firstOrFail();
        // Deliberately corrupt only a disposable fixture, modelling a legacy attribution defect.
        DB::table('virtual_positions')->where('id', $position->id)
            ->update(['paper_account_id' => $foreign->id]);
        $response = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit');
        $response->assertOk()->assertJsonPath('data.overview.invalid_attribution_positions', 1)
            ->assertJsonPath('data.overview.net_pnl_verification_status', 'INVALID_DATA')
            ->assertJsonPath('data.overview.executed_trades', 0);
        self::assertDecimalSame('0', $response->json('data.overview.overall_net_pnl'));
        self::assertContains('INVALID_POSITION_ATTRIBUTION', array_column($response->json('data.diagnostics'), 'type'));
        $explorer = $this->getJson('/api/v1/research/profitability/signal-explorer?exchange=bybit')->assertOk();
        $row = collect($explorer->json('data'))->firstWhere('id', $position->signal_id);
        self::assertSame(0, $row['position_count']);
        self::assertSame(1, $row['invalid_attribution_positions']);
        self::assertSame('INVALID_DATA', $row['attribution_verification_status']);
        self::assertDecimalSame('0', $row['net_pnl']);
        $this->getJson('/api/v1/research/profitability/signal-explorer/'.$position->signal_id)
            ->assertOk()->assertJsonPath('data.position_count', 0)
            ->assertJsonPath('data.invalid_attribution_positions', 1)
            ->assertJsonPath('data.attribution_verification_status', 'INVALID_DATA');
        $export = $this->get('/api/v1/research/profitability/signal-explorer/export?exchange=bybit')->assertOk();
        self::assertStringContainsString('Data Verification', $export->streamedContent());
        self::assertStringContainsString('INVALID_DATA', $export->streamedContent());
        self::assertSame($foreign->id, $position->refresh()->paper_account_id);
    }

    public function test_an_observation_on_another_account_cannot_hide_a_genuine_rejection(): void
    {
        $this->seedSampleSignalCohorts();
        $signal = SignalRecord::query()->where('exchange', 'bybit')->where('retrospective_outcome', 'UNPROVEN')->firstOrFail();
        foreach (['baseline' => 'REJECTED', 'trend_pullback' => 'OBSERVATION_ONLY'] as $strategy => $decision) {
            $account = PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('primary_strategy_id', $strategy)->firstOrFail();
            RiskDecisionRecord::query()->create([
                'id' => Uuid::uuid4()->toString(), 'paper_account_id' => $account->id, 'signal_id' => $signal->id,
                'risk_version' => '1.0.0', 'allowed' => false, 'reasons' => [$decision],
                'snapshot' => ['gate_decision' => $decision], 'decided_at' => now('UTC'),
            ]);
        }
        $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit')
            ->assertOk()->assertJsonPath('data.overview.rejected_signals', 1)
            ->assertJsonPath('data.overview.observation_only_signals', 1);
    }

    public function test_foreign_account_risk_decisions_cannot_reject_or_filter_a_local_signal(): void
    {
        $this->seedSampleSignalCohorts();
        $signal = SignalRecord::query()->where('exchange', 'bybit')->where('retrospective_outcome', 'UNPROVEN')->firstOrFail();
        $foreign = PaperAccountRecord::query()->where('exchange_id', 'binance')->firstOrFail();
        RiskDecisionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'paper_account_id' => $foreign->id, 'signal_id' => $signal->id,
            'risk_version' => '1.0.0', 'allowed' => false, 'reasons' => ['FOREIGN_ACCOUNT'],
            'snapshot' => ['gate_decision' => 'REJECTED'], 'decided_at' => now('UTC'),
        ]);
        $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit')
            ->assertOk()->assertJsonPath('data.overview.rejected_signals', 0)
            ->assertJsonPath('data.overview.invalid_risk_decisions', 1);
        $this->getJson('/api/v1/research/profitability/signal-explorer?exchange=bybit&gate_decision=REJECTED')
            ->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/research/profitability/signal-explorer/'.$signal->id)
            ->assertOk()->assertJsonCount(0, 'data.risk_decisions')->assertJsonPath('data.gate_allowed', false)
            ->assertJsonPath('data.invalid_risk_decisions', 1)
            ->assertJsonPath('data.attribution_verification_status', 'INVALID_DATA');
    }

    private function seedSampleSignalCohorts(array $approvedReasons = ['GATE_APPROVED']): void
    {
        $bybitAccount = PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('primary_strategy_id', 'trend_pullback')->firstOrFail();
        $binanceAccount = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'trend_pullback')->firstOrFail();

        $now = CarbonImmutable::now('UTC');

        // 1. Bybit BTCUSDT 15m Winning Trade (GOOD outcome)
        $sig1 = SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'signal_quality' => SignalQuality::High->value,
            'trade_risk' => TradeRiskClass::Low->value,
            'retrospective_outcome' => 'GOOD',
            'score' => 90,
            'entry_low' => '60000',
            'entry_high' => '60050',
            'stop_loss' => '59500',
            'take_profit_1' => '61000',
            'take_profit_2' => '62000',
            'risk_reward_1' => '2.0',
            'risk_reward_2' => '4.0',
            'model_risk_percent' => '0.5',
            'decision_time' => $now->subMinutes(120),
            'candle_close_time' => $now->subMinutes(120),
            'expires_at' => $now->addHours(2),
            'maximum_holding_minutes' => 120,
            'schema_version' => '1.0.0',
            'reasons' => ['EMA_PULLBACK_RETEST', 'RSI_OVERSOLD_REVERSAL'],
            'risks' => ['NEAR_LOCAL_RESISTANCE'],
            'indicator_snapshot' => ['ema_50' => 59800, 'rsi' => 38],
            'contract' => ['rules' => ['trend_filter' => true, 'entry_trigger' => true]],
        ]);

        RiskDecisionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $bybitAccount->id,
            'signal_id' => $sig1->id,
            'risk_version' => '1.0.0',
            'signal_quality' => 'HIGH',
            'trade_risk' => 'LOW',
            'allowed' => true,
            'reasons' => $approvedReasons,
            'snapshot' => ['gate_decision' => 'ELIGIBLE', 'expected_net_edge' => 0.15],
            'decided_at' => $now->subMinutes(120),
        ]);

        VirtualPositionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $bybitAccount->id,
            'signal_id' => $sig1->id,
            'exchange_id' => 'bybit',
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'signal_quality' => 'HIGH',
            'trade_risk' => 'LOW',
            'retrospective_outcome' => 'GOOD',
            'status' => PositionStatus::ClosedTp->value,
            'entry_price' => '60025.00',
            'stop_loss' => '59500.00',
            'take_profit_1' => '61000.00',
            'take_profit_2' => '62000.00',
            'initial_quantity' => '0.1',
            'remaining_quantity' => '0',
            'qty_step' => '0.001',
            'risk_capital' => '52.50',
            'gross_pnl' => '52.50000000',
            'fees' => '2.50000000',
            'funding' => '0',
            'net_pnl' => '50.00000000',
            'r_multiple' => '0.9523809524',
            'opened_at' => $now->subMinutes(115),
            'maximum_holding_until' => $now->addHours(2),
            'closed_at' => $now->subMinutes(60),
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'execution_model' => 'FORWARD_PAPER',
            'funding_complete' => true,
        ]);

        // 2. Binance ETHUSDT 5m Losing Trade (BAD outcome)
        $sig2 = SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'timeframe' => '5m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'SELL',
            'quality_class' => 'B',
            'signal_quality' => SignalQuality::Medium->value,
            'trade_risk' => TradeRiskClass::Medium->value,
            'retrospective_outcome' => 'BAD',
            'score' => 70,
            'entry_low' => '3000',
            'entry_high' => '3005',
            'stop_loss' => '3050',
            'take_profit_1' => '2900',
            'take_profit_2' => '2800',
            'risk_reward_1' => '2.0',
            'risk_reward_2' => '4.0',
            'model_risk_percent' => '0.25',
            'decision_time' => $now->subMinutes(100),
            'candle_close_time' => $now->subMinutes(100),
            'expires_at' => $now->addHours(1),
            'maximum_holding_minutes' => 60,
            'schema_version' => '1.0.0',
            'reasons' => ['RESISTANCE_REJECTION'],
            'risks' => ['STRONG_MOMENTUM_AGAINST'],
            'indicator_snapshot' => ['rsi' => 65],
            'contract' => ['rules' => ['trend_filter' => true]],
        ]);

        RiskDecisionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $binanceAccount->id,
            'signal_id' => $sig2->id,
            'risk_version' => '1.0.0',
            'signal_quality' => 'MEDIUM',
            'trade_risk' => 'MEDIUM',
            'allowed' => true,
            'reasons' => ['CONDITIONAL_APPROVAL'],
            'snapshot' => ['gate_decision' => 'CONDITIONALLY_ELIGIBLE', 'expected_net_edge' => 0.06],
            'decided_at' => $now->subMinutes(100),
        ]);

        VirtualPositionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $binanceAccount->id,
            'signal_id' => $sig2->id,
            'exchange_id' => 'binance',
            'symbol' => 'ETHUSDT',
            'side' => 'SELL',
            'quality_class' => 'B',
            'signal_quality' => 'MEDIUM',
            'trade_risk' => 'MEDIUM',
            'retrospective_outcome' => 'BAD',
            'status' => PositionStatus::ClosedTp->value,
            'entry_price' => '3002.50',
            'stop_loss' => '3050.00',
            'take_profit_1' => '2900.00',
            'take_profit_2' => '2800.00',
            'initial_quantity' => '1.0',
            'remaining_quantity' => '0',
            'qty_step' => '0.01',
            'risk_capital' => '47.50',
            'gross_pnl' => '-28.00000000',
            'fees' => '2.00000000',
            'funding' => '0',
            'net_pnl' => '-30.00000000',
            'r_multiple' => '-0.6315789474',
            'opened_at' => $now->subMinutes(95),
            'maximum_holding_until' => $now->addHours(1),
            'closed_at' => $now->subMinutes(40),
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'execution_model' => 'FORWARD_PAPER',
            'funding_complete' => true,
        ]);

        // 3. Bybit BTCUSDT 1m Low Quality Observation Only (UNPROVEN)
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '1m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'C',
            'signal_quality' => SignalQuality::Low->value,
            'trade_risk' => TradeRiskClass::Low->value,
            'retrospective_outcome' => 'UNPROVEN',
            'score' => 45,
            'entry_low' => '60100',
            'entry_high' => '60120',
            'stop_loss' => '59900',
            'decision_time' => $now->subMinutes(80),
            'candle_close_time' => $now->subMinutes(80),
            'expires_at' => $now->addMinutes(30),
            'maximum_holding_minutes' => 30,
            'schema_version' => '1.0.0',
            'indicator_snapshot' => ['rsi' => 45],
            'contract' => ['rules' => ['trend_filter' => true]],
            'reasons' => ['MINOR_BOUNCE'],
            'risks' => ['LOW_CONVICTION'],
        ]);

        // 4. Bybit BTCUSDT 3m High Risk Rejected (UNPROVEN)
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '3m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'signal_quality' => SignalQuality::High->value,
            'trade_risk' => TradeRiskClass::High->value,
            'retrospective_outcome' => 'UNPROVEN',
            'score' => 88,
            'entry_low' => '60200',
            'entry_high' => '60250',
            'stop_loss' => '58000',
            'decision_time' => $now->subMinutes(70),
            'candle_close_time' => $now->subMinutes(70),
            'expires_at' => $now->addHours(1),
            'maximum_holding_minutes' => 60,
            'schema_version' => '1.0.0',
            'indicator_snapshot' => ['rsi' => 70],
            'contract' => ['rules' => ['trend_filter' => true]],
            'reasons' => ['BREAKOUT'],
            'risks' => ['EXCESSIVE_STOP_DISTANCE'],
        ]);

        // 5. Bybit BTCUSDT 30m Open Trade (UNPROVEN)
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '30m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'SELL',
            'quality_class' => 'A',
            'signal_quality' => SignalQuality::High->value,
            'trade_risk' => TradeRiskClass::Low->value,
            'retrospective_outcome' => 'UNPROVEN',
            'score' => 92,
            'entry_low' => '60500',
            'entry_high' => '60520',
            'stop_loss' => '61000',
            'decision_time' => $now->subMinutes(50),
            'candle_close_time' => $now->subMinutes(50),
            'expires_at' => $now->addHours(3),
            'maximum_holding_minutes' => 180,
            'schema_version' => '1.0.0',
            'indicator_snapshot' => ['rsi' => 60],
            'contract' => ['rules' => ['trend_filter' => true]],
            'reasons' => ['HEAD_AND_SHOULDERS'],
            'risks' => [],
        ]);

        // 6. Bybit BTCUSDT 60m Unknown Quality (UNPROVEN)
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '60m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => null,
            'signal_quality' => SignalQuality::Unknown->value,
            'trade_risk' => TradeRiskClass::Unknown->value,
            'retrospective_outcome' => 'UNPROVEN',
            'score' => 10,
            'entry_low' => '60400',
            'entry_high' => '60420',
            'stop_loss' => '59000',
            'decision_time' => $now->subMinutes(30),
            'candle_close_time' => $now->subMinutes(30),
            'expires_at' => $now->addHours(4),
            'maximum_holding_minutes' => 240,
            'schema_version' => '1.0.0',
            'indicator_snapshot' => [],
            'contract' => ['rules' => []],
            'reasons' => ['DATA_INCOMPLETE'],
            'risks' => [],
        ]);

        // 7. Binance ETHUSDT 15m Signal (UNPROVEN)
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'timeframe' => '15m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'signal_quality' => SignalQuality::High->value,
            'trade_risk' => TradeRiskClass::Low->value,
            'retrospective_outcome' => 'UNPROVEN',
            'score' => 85,
            'entry_low' => '3010',
            'entry_high' => '3020',
            'stop_loss' => '2950',
            'decision_time' => $now->subMinutes(20),
            'candle_close_time' => $now->subMinutes(20),
            'expires_at' => $now->addHours(2),
            'maximum_holding_minutes' => 120,
            'schema_version' => '1.0.0',
            'indicator_snapshot' => ['rsi' => 30],
            'contract' => ['rules' => ['trend_filter' => true]],
            'reasons' => ['SUPPORT_BOUNCE'],
            'risks' => [],
        ]);

        // 8. Binance ETHUSDT 60m Signal (UNPROVEN)
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'timeframe' => '60m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'SELL',
            'quality_class' => 'B',
            'signal_quality' => SignalQuality::Medium->value,
            'trade_risk' => TradeRiskClass::Low->value,
            'retrospective_outcome' => 'UNPROVEN',
            'score' => 75,
            'entry_low' => '3040',
            'entry_high' => '3050',
            'stop_loss' => '3100',
            'decision_time' => $now->subMinutes(10),
            'candle_close_time' => $now->subMinutes(10),
            'expires_at' => $now->addHours(5),
            'maximum_holding_minutes' => 300,
            'schema_version' => '1.0.0',
            'indicator_snapshot' => ['rsi' => 60],
            'contract' => ['rules' => ['trend_filter' => true]],
            'reasons' => ['TREND_CONTINUATION'],
            'risks' => [],
        ]);
    }
}
