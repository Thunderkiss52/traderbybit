<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Research\Application\ResearchJobService;
use App\Research\Application\SignalIntelligenceService;
use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use App\Research\Infrastructure\Persistence\SignalOutcomeEvaluationRecord;
use App\Research\Infrastructure\Persistence\SignalQualityProfileRecord;
use App\Research\Jobs\ProcessSignalIntelligenceJob;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SignalIntelligencePipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['paper.topology.auto_sync_enabled' => false, 'research.signal_intelligence.code_revision' => str_repeat('a', 40)]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-11T12:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_go_labels_import_profiles_and_read_apis_preserve_financial_history(): void
    {
        $signal = $this->fixture();
        $financial = $this->financialFingerprint();
        $service = app(SignalIntelligenceService::class);
        $job = $service->scheduleSignal($signal, [1, 3], CarbonImmutable::now());
        self::assertSame('SIGNAL_MARKOUT', $job->kind);
        self::assertSame('HYPOTHETICAL', $job->research_mode);
        self::assertSame($job->job_id, $service->scheduleSignal($signal, [1, 3], CarbonImmutable::now())->job_id);
        $this->execute($job);
        $records = $service->importResult($job);
        self::assertCount(2, $records);
        foreach ($records as $row) {
            self::assertSame('MATURED', $row->status, $row->reason);
            self::assertSame(15, $row->source_timeframe_minutes);
            self::assertSame('HYPOTHETICAL', $row->data_source);
            self::assertTrue(BigDecimal::of($row->gross_pnl)->minus($row->fees)->plus($row->funding)->isEqualTo($row->net_pnl));
            self::assertFalse($row->result['scope']['data_source'] === 'PAPER');
        }
        self::assertSame(array_map(fn ($r) => $r->id, $records), array_map(fn ($r) => $r->id, $service->importResult($job)));
        $cohortJob = $service->scheduleCohort($records[0]);
        $this->execute($cohortJob);
        $profiles = $service->importResult($cohortJob);
        self::assertCount(1, $profiles);
        self::assertSame('INSUFFICIENT_DATA', $profiles[0]->classification);
        self::assertFalse($profiles[0]->result['entry_authority']);
        self::assertSame($financial, $this->financialFingerprint());
        $this->getJson('/api/v1/research/profitability/signal-horizons?source_timeframe_minutes=15&horizon_minutes=1')
            ->assertOk()->assertJsonPath('data_source', 'HYPOTHETICAL')->assertJsonPath('outcomes.per_page', 10)
            ->assertJsonPath('outcomes.total', 1)->assertJsonPath('entry_authority', false);
        $this->getJson('/api/v1/research/profitability/signal-horizons?exchange=binance')->assertOk()->assertJsonPath('outcomes.total', 0);
        $this->getJson('/api/v1/research/profitability/signal-quality-profiles?exchange=bybit&horizon_minutes=1')
            ->assertOk()->assertJsonPath('profiles.total', 1)->assertJsonPath('profiles.per_page', 10);
        $this->getJson('/api/v1/research/profitability/signal-quality-profiles?exchange=binance')->assertOk()->assertJsonPath('profiles.total', 0);
    }

    public function test_missing_candles_and_small_notional_constraints_never_create_fake_returns(): void
    {
        $signal = $this->fixture(2);
        $service = app(SignalIntelligenceService::class);
        $job = $service->scheduleSignal($signal, [1, 3]);
        $this->execute($job);
        $records = $service->importResult($job);
        self::assertSame('MATURED', $records[0]->status);
        self::assertSame('INVALID_DATA', $records[1]->status);
        self::assertNull($records[1]->net_pnl);
        // This fixture changes current instrument requirements, never historical accounting.
        MarketContextRecord::query()->toBase()->update(['min_notional_value' => '10']);
        $new = $service->scheduleSignal($signal, [1]);
        $this->execute($new);
        $unfillable = $service->importResult($new)[0];
        self::assertSame('UNFILLABLE', $unfillable->status);
        self::assertNull($unfillable->net_pnl);
        self::assertTrue(BigDecimal::of($unfillable->configured_notional)->isEqualTo('5'));
    }

    public function test_results_require_matching_checksum_and_scope_and_are_transactional(): void
    {
        $signal = $this->fixture();
        $service = app(SignalIntelligenceService::class);
        $job = $service->scheduleSignal($signal, [1, 3]);
        $metrics = $this->execute($job);
        $job->update(['result_manifest_sha256' => str_repeat('0', 64)]);
        try {
            $service->importResult($job);
            self::fail('Invalid stored worker manifest was accepted.');
        } catch (LogicException $e) {
            self::assertStringContainsString('worker manifest', $e->getMessage());
        }
        $this->replaceMetrics($job, array_replace($metrics, ['signal_intelligence_sha256' => str_repeat('0', 64)]));
        try {
            $service->importResult($job);
            self::fail('Corrupted bytes were accepted.');
        } catch (LogicException $e) {
            self::assertStringContainsString('checksum', $e->getMessage());
        }
        $report = json_decode($metrics['signal_intelligence_json'], true, 32, JSON_THROW_ON_ERROR);
        $report['evaluation']['outcomes'][1]['scope']['exchange'] = 'binance';
        $raw = json_encode($report, JSON_THROW_ON_ERROR);
        $this->replaceMetrics($job, array_replace($metrics, ['signal_intelligence_json' => $raw, 'signal_intelligence_sha256' => hash('sha256', $raw)]));
        try {
            $service->importResult($job);
            self::fail('Foreign exchange scope was accepted.');
        } catch (LogicException $e) {
            self::assertStringContainsString('scope', $e->getMessage());
        }
        self::assertSame(0, SignalOutcomeEvaluationRecord::query()->count());
    }

    public function test_database_rejects_mutation_and_fake_paper_labels(): void
    {
        $signal = $this->fixture();
        $service = app(SignalIntelligenceService::class);
        $job = $service->scheduleSignal($signal, [1]);
        $this->execute($job);
        $record = $service->importResult($job)[0];
        foreach (['update', 'delete'] as $operation) {
            DB::beginTransaction();
            try {
                $query = DB::table('signal_outcome_evaluations')->where('id', $record->id);
                $operation === 'update' ? $query->update(['net_pnl' => '1000']) : $query->delete();
                self::fail('Immutable evidence was mutated.');
            } catch (QueryException $e) {
                self::assertStringContainsString('immutable', $e->getMessage());
            } finally {
                DB::rollBack();
            }
        }
        DB::beginTransaction();
        try {
            $data = $record->getAttributes();
            $data['id'] = (string) Str::uuid();
            $data['evaluation_key'] = str_repeat('f', 64);
            $data['input_hash'] = str_repeat('f', 64);
            $data['data_source'] = 'PAPER';
            DB::table('signal_outcome_evaluations')->insert($data);
            self::fail('Hypothetical evidence was mislabeled as PAPER.');
        } catch (QueryException $e) {
            self::assertStringContainsString('signal_outcome_research_only', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_scheduler_and_invalid_requests_are_bounded_and_read_endpoints_have_no_side_effects(): void
    {
        $this->fixture();
        app(ProcessSignalIntelligenceJob::class)->handle(app(SignalIntelligenceService::class));
        self::assertSame(1, ResearchJobRecord::query()->where('kind', 'SIGNAL_MARKOUT')->count());
        $before = ResearchJobRecord::query()->count();
        $this->getJson('/api/v1/research/profitability/signal-horizons?horizon_minutes=2')->assertUnprocessable();
        $this->getJson('/api/v1/research/profitability/signal-horizons?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/research/profitability/signal-quality-profiles?source_timeframe_minutes=2')->assertUnprocessable();
        self::assertSame($before, ResearchJobRecord::query()->count());
        self::assertSame(0, SignalOutcomeEvaluationRecord::query()->count());
        self::assertSame(0, SignalQualityProfileRecord::query()->count());
    }

    public function test_subminute_availability_uses_entry_anchor_in_quality_cohorts(): void
    {
        $signal = $this->fixture();
        DB::table('signals')->where('id', $signal->id)->update(['created_at' => '2026-11-11 10:00:30+00:00']);
        $signal->refresh();
        $service = app(SignalIntelligenceService::class);
        $job = $service->scheduleSignal($signal, [1]);
        $this->execute($job);
        $outcome = $service->importResult($job)[0];
        self::assertSame('10:00:30', $outcome->available_at->format('H:i:s'));
        self::assertSame('10:01:00', $outcome->entry_at->format('H:i:s'));
        $cohort = $service->scheduleCohort($outcome);
        self::assertSame($outcome->entry_at->getTimestampMs(), $cohort->payload['signal_intelligence']['cohort']['observations'][0]['available_ms']);
        $this->execute($cohort);
        self::assertSame('INSUFFICIENT_DATA', $service->importResult($cohort)[0]->classification);
    }

    public function test_backdated_or_revised_context_cannot_masquerade_as_decision_time_knowledge(): void
    {
        $signal = $this->fixture();
        foreach (['created_at', 'updated_at'] as $column) {
            MarketContextRecord::query()->toBase()->update(['created_at' => '2026-11-11 09:59:59+00:00',
                'updated_at' => '2026-11-11 09:59:59+00:00', $column => '2026-11-11 10:00:01+00:00']);
            try {
                app(SignalIntelligenceService::class)->scheduleSignal($signal, [1]);
                self::fail('Unavailable context was used.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Point-in-time instrument', $e->getMessage());
            }
        }
        self::assertSame(0, ResearchJobRecord::query()->count());
    }

    public function test_delayed_candles_and_missing_funding_do_not_become_validated_evidence(): void
    {
        $signal = $this->fixture();
        DB::table('market_candles')->where('open_time', '2026-11-11 10:00:00+00:00')
            ->update(['created_at' => '2026-11-11 13:00:00+00:00']);
        $service = app(SignalIntelligenceService::class);
        $job = $service->scheduleSignal($signal, [1]);
        $this->execute($job);
        self::assertSame('INVALID_DATA', $service->importResult($job)[0]->status);
        config(['market_data.funding_schedules.bybit.BTCUSDT' => null]);
        $job = $service->scheduleSignal($signal, [1]);
        $this->execute($job);
        $outcome = $service->importResult($job)[0];
        self::assertSame('INVALID_DATA', $outcome->status);
        self::assertSame('FUNDING_INCOMPLETE', $outcome->reason);
        self::assertNull($outcome->net_pnl);
    }

    public function test_valid_looking_result_hashes_must_match_the_submitted_economic_policy(): void
    {
        $signal = $this->fixture();
        $service = app(SignalIntelligenceService::class);
        $job = $service->scheduleSignal($signal, [1]);
        $metrics = $this->execute($job);
        foreach (['execution_hash', 'input_hash'] as $field) {
            $report = json_decode($metrics['signal_intelligence_json'], true, 32, JSON_THROW_ON_ERROR);
            if ($field === 'execution_hash') {
                $report['evaluation']['outcomes'][0]['scope'][$field] = str_repeat('b', 64);
            } else {
                $report['evaluation']['outcomes'][0][$field] = str_repeat('b', 64);
            }
            $raw = json_encode($report, JSON_THROW_ON_ERROR);
            $this->replaceMetrics($job, array_replace($metrics, ['signal_intelligence_json' => $raw, 'signal_intelligence_sha256' => hash('sha256', $raw)]));
            try {
                $service->importResult($job);
                self::fail('Unbound evaluation hash was accepted.');
            } catch (LogicException $e) {
                self::assertStringContainsString('identity', $e->getMessage());
            }
        }
        self::assertSame(0, SignalOutcomeEvaluationRecord::query()->count());
        $this->replaceMetrics($job, $metrics);
        $record = $service->importResult($job)[0];
        config(['research.signal_intelligence.quality_policy.alpha' => 1.0e-7]);
        $cohort = $service->scheduleCohort($record);
        $metrics = $this->execute($cohort);
        foreach (['policy_hash', 'input_hash'] as $field) {
            $report = json_decode($metrics['signal_intelligence_json'], true, 32, JSON_THROW_ON_ERROR);
            $report['cohort'][$field] = str_repeat('c', 64);
            $raw = json_encode($report, JSON_THROW_ON_ERROR);
            $this->replaceMetrics($cohort, array_replace($metrics, ['signal_intelligence_json' => $raw, 'signal_intelligence_sha256' => hash('sha256', $raw)]));
            try {
                $service->importResult($cohort);
                self::fail('Unbound classification policy was accepted.');
            } catch (LogicException $e) {
                self::assertStringContainsString('policy mismatch', $e->getMessage());
            }
        }
        self::assertSame(0, SignalQualityProfileRecord::query()->count());
        $this->replaceMetrics($cohort, $metrics);
        self::assertSame('INSUFFICIENT_DATA', $service->importResult($cohort)[0]->classification);
        config(['research.signal_intelligence.quality_policy.alpha' => 1.0e-6]);
        $decimalPolicy = $service->scheduleCohort($record);
        $this->execute($decimalPolicy);
        self::assertSame('INSUFFICIENT_DATA', $service->importResult($decimalPolicy)[0]->classification);
    }

    public function test_late_imports_are_unavailable_to_backdated_cohorts(): void
    {
        $signal = $this->fixture();
        $service = app(SignalIntelligenceService::class);
        $job = $service->scheduleSignal($signal, [1], CarbonImmutable::now()->subMinute());
        $this->execute($job);
        $record = $service->importResult($job)[0];
        $old = $service->scheduleCohort($record, CarbonImmutable::now()->subMinute());
        self::assertSame([], $old->payload['signal_intelligence']['cohort']['observations']);
        $current = $service->scheduleCohort($record);
        self::assertCount(1, $current->payload['signal_intelligence']['cohort']['observations']);
    }

    public function test_restart_and_duplicate_job_import_reuse_evidence_without_financial_mutation(): void
    {
        $signal = $this->fixture();
        $financial = $this->financialFingerprint();
        $service = app(SignalIntelligenceService::class);
        $job = $service->scheduleSignal($signal, [1]);
        $this->execute($job);
        $first = $service->importResult($job)[0];
        $importAudit = $job->fresh()->runtime_stats['signal_intelligence_import'];
        self::assertSame([$first->id], $importAudit['record_ids']);
        $clone = app(ResearchJobService::class)->submitJob([
            'kind' => 'SIGNAL_MARKOUT', 'idempotency_key' => str_repeat('d', 64),
            'strategy_id' => $job->strategy_id, 'strategy_version' => $job->strategy_version,
            'configuration_hash' => $job->configuration_hash, 'code_revision' => $job->code_revision,
            'dataset_id' => $job->dataset_id, 'dataset_sha256' => $job->dataset_sha256,
            'as_of_utc' => $job->as_of_utc, 'research_mode' => $job->research_mode,
            'limits' => $job->limits, 'payload' => $job->payload,
        ]);
        $this->execute($clone);
        self::assertSame($first->id, $service->importResult($clone)[0]->id);
        self::assertSame([$first->id], $clone->fresh()->runtime_stats['signal_intelligence_import']['record_ids']);
        self::assertSame(1, SignalOutcomeEvaluationRecord::query()->count());
        // Model interruption immediately after import committed, before cohort dispatch.
        (new ProcessSignalIntelligenceJob)->handle($service);
        $cohorts = ResearchJobRecord::query()->where('payload->signal_intelligence->operation', 'classify')->count();
        self::assertSame(1, $cohorts);
        (new ProcessSignalIntelligenceJob)->handle($service);
        self::assertSame($cohorts, ResearchJobRecord::query()->where('payload->signal_intelligence->operation', 'classify')->count());
        self::assertSame($importAudit, $job->fresh()->runtime_stats['signal_intelligence_import']);
        self::assertSame($financial, $this->financialFingerprint());
    }

    public function test_other_label_policy_versions_cannot_be_rewritten_into_the_current_cohort(): void
    {
        $signal = $this->fixture();
        $service = app(SignalIntelligenceService::class);
        $job = $service->scheduleSignal($signal, [1], CarbonImmutable::now()->subMinute());
        $this->execute($job);
        $current = $service->importResult($job)[0];
        // Synthetic future-policy evidence tests that identical trading scope
        // alone cannot substitute for the required immutable labeling policy.
        $other = $current->getAttributes();
        $other['id'] = (string) Str::uuid();
        $other['evaluation_key'] = str_repeat('e', 64);
        $other['policy_version'] = 'FUTURE_POLICY_TEST_ONLY';
        $other['input_hash'] = str_repeat('e', 64);
        $other['evaluated_as_of'] = CarbonImmutable::now();
        $result = $current->result;
        $result['input_hash'] = $other['input_hash'];
        $result['scope']['label_policy_version'] = $other['policy_version'];
        $other['result'] = json_encode($result, JSON_THROW_ON_ERROR);
        DB::table('signal_outcome_evaluations')->insert($other);
        $cohort = $service->scheduleCohort($current);
        $observations = $cohort->payload['signal_intelligence']['cohort']['observations'];
        self::assertCount(1, $observations);
        self::assertSame($signal->id, $observations[0]['id']);
        self::assertSame($current->input_hash, $observations[0]['input_hash']);
    }

    private function replaceMetrics(ResearchJobRecord $job, array $metrics): void
    {
        ksort($metrics, SORT_STRING);
        $job->update(['result_payload' => $metrics, 'result_manifest_sha256' => hash('sha256', json_encode($metrics, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP))]);
    }

    private function execute(ResearchJobRecord $job): array
    {
        $binary = base_path('research-go/bin/signal-intelligence');
        self::assertFileExists($binary, 'Build the bounded Go signal-intelligence CLI before this integration suite.');
        $process = new Process([$binary], base_path(), timeout: 30);
        $process->setInput(json_encode($job->payload['signal_intelligence'], JSON_THROW_ON_ERROR));
        $process->mustRun();
        $raw = trim($process->getOutput());
        $metrics = ['research_only' => true, 'signal_intelligence_json' => $raw, 'signal_intelligence_sha256' => hash('sha256', $raw)];
        $jobs = app(ResearchJobService::class);
        $claim = $jobs->claimNextJob('signal-intelligence-test', 60, ['SIGNAL_MARKOUT']);
        self::assertNotNull($claim);
        self::assertSame($job->job_id, $claim->job_id);
        self::assertTrue($jobs->startJob($job->job_id, 'signal-intelligence-test', $claim->fencing_token));
        self::assertTrue($jobs->completeJob($job->job_id, 'signal-intelligence-test', $claim->fencing_token, $metrics));

        return $metrics;
    }

    private function fixture(int $candles = 60): SignalRecord
    {
        $decision = CarbonImmutable::parse('2026-11-11T10:00:00Z');
        MarketContextRecord::query()->create(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'bid' => '99.99', 'ask' => '100.01', 'turnover_24h' => '100000000',
            'observed_at' => $decision->subSecond(), 'created_at' => $decision->subSecond(), 'updated_at' => $decision->subSecond(),
            'tick_size' => '0.01', 'qty_step' => '0.0001', 'min_order_qty' => '0.0001', 'max_order_qty' => '100', 'min_notional_value' => '1']);
        $rows = [];
        for ($i = 0; $i < $candles; $i++) {
            $rows[] = ['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '1',
                'open_time' => $decision->addMinutes($i), 'open' => '100', 'high' => '102', 'low' => '99', 'close' => '101',
                'volume' => '1000', 'turnover' => '100000', 'is_closed' => true,
                'created_at' => $decision->addMinutes($i + 1), 'updated_at' => $decision->addMinutes($i + 1)];
        }
        DB::table('market_candles')->insert($rows);

        return SignalRecord::query()->create(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'timeframe' => '15m',
            'strategy_id' => 'trend_pullback', 'strategy_version' => '1.0.0', 'side' => 'BUY', 'quality_class' => 'A', 'score' => 90,
            'entry_low' => '100', 'entry_high' => '100', 'stop_loss' => '98', 'take_profit_1' => '104', 'take_profit_2' => '106',
            'decision_time' => $decision, 'candle_close_time' => $decision, 'expires_at' => $decision->addHour(),
            'created_at' => $decision, 'updated_at' => $decision, 'maximum_holding_minutes' => 60,
            'schema_version' => '1.0.0', 'reasons' => [], 'risks' => [], 'indicator_snapshot' => [], 'contract' => []]);
    }

    private function financialFingerprint(): string
    {
        $tables = ['paper_accounts', 'virtual_positions', 'virtual_executions', 'trading_journal_events', 'risk_decisions', 'equity_snapshots', 'position_funding_events'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return hash('sha256', json_encode($counts, JSON_THROW_ON_ERROR));
    }
}
