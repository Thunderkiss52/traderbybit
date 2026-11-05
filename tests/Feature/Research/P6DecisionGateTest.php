<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Research\Application\DecisionEvidenceService;
use App\Research\Domain\Decision\DecisionState;
use App\Research\Domain\ResearchDecisionRecord;
use App\Research\Domain\ResearchGenerationRecord;
use App\Research\Exceptions\AppendOnlyRecordException;
use App\Research\Exceptions\IdempotencyConflictException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * PROFIT-1 / P-6 acceptance coverage (§14).
 */
final class P6DecisionGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_gate_fails_closed_when_no_accepted_generation_exists(): void
    {
        $result = $this->service()->evaluate([
            'idempotency_key' => 'p6-no-inputs',
            'claim_type' => 'PROFITABILITY_CLAIM',
            'preregistration_hash' => 'prereg-1',
            'evidence_bundle_hash' => 'bundle-1',
        ]);

        self::assertSame('SUCCEEDED', $result['status']);
        self::assertSame(DecisionState::INSUFFICIENT_EVIDENCE, $result['decision']['decision_state']);
        self::assertSame('MISSING_INPUT_PACKAGE', $result['decision']['reason']);
        self::assertSame(
            ['P-1', 'P-2', 'P-4', 'P-5'],
            $result['outcome']['missing_inputs']
        );
    }

    public function test_a_decision_record_is_appended_and_never_updated_or_deleted(): void
    {
        $this->seedAcceptedGenerations();

        $result = $this->service()->evaluate([
            'idempotency_key' => 'p6-append-only',
            'claim_type' => 'PROFITABILITY_CLAIM',
            'preregistration_hash' => 'prereg-1',
            'evidence_bundle_hash' => 'bundle-1',
        ]);

        $record = ResearchDecisionRecord::query()->where('id', $result['decision']['id'])->first();
        self::assertNotNull($record);
        self::assertSame(DecisionState::ELIGIBLE_FOR_OWNER_REVIEW, $record->decision_state);
        self::assertSame('RESEARCH_GENERATION', $record->evidence['inputs'][0]['provenance']);

        $updateRejected = false;
        try {
            $record->decision_state = DecisionState::REJECTED;
            $record->save();
        } catch (AppendOnlyRecordException) {
            $updateRejected = true;
        }
        self::assertTrue($updateRejected, 'A decision transition must never be updated in place.');

        $deleteRejected = false;
        try {
            $record->delete();
        } catch (AppendOnlyRecordException) {
            $deleteRejected = true;
        }
        self::assertTrue($deleteRejected, 'A decision transition must never be deleted.');
    }

    public function test_the_service_never_changes_a_strategy_and_never_creates_an_order(): void
    {
        $this->seedAcceptedGenerations();

        $positionsBefore = DB::table('virtual_positions')->count();
        $executionsBefore = DB::table('virtual_executions')->count();
        $accountsBefore = DB::table('paper_accounts')->count();
        $configBefore = config('strategy');

        $result = $this->service()->evaluate([
            'idempotency_key' => 'p6-no-side-effects',
            'claim_type' => 'PROFITABILITY_CLAIM',
            'preregistration_hash' => 'prereg-1',
            'evidence_bundle_hash' => 'bundle-1',
        ]);

        self::assertSame(DecisionState::ELIGIBLE_FOR_OWNER_REVIEW, $result['decision']['decision_state']);
        self::assertFalse($result['decision']['evidence']['strategy_paths_modified']);
        self::assertSame(0, $result['decision']['evidence']['orders_placed']);
        self::assertSame($positionsBefore, DB::table('virtual_positions')->count());
        self::assertSame($executionsBefore, DB::table('virtual_executions')->count());
        self::assertSame($accountsBefore, DB::table('paper_accounts')->count());
        self::assertSame($configBefore, config('strategy'), 'P-6 must not change any strategy configuration.');
    }

    public function test_an_insufficient_p2_sample_blocks_an_owner_review_conclusion(): void
    {
        $this->seedAcceptedGenerations(['P-2' => ['sample_sufficiency' => 'INSUFFICIENT_DATA']]);

        $result = $this->service()->evaluate([
            'idempotency_key' => 'p6-insufficient-sample',
            'claim_type' => 'PROFITABILITY_CLAIM',
            'preregistration_hash' => 'prereg-1',
            'evidence_bundle_hash' => 'bundle-1',
        ]);

        self::assertSame(DecisionState::COLLECT_MORE_DATA, $result['decision']['decision_state']);
        self::assertSame('SAMPLE_INSUFFICIENT', $result['decision']['reason']);
    }

    public function test_generation_is_idempotent_and_a_conflicting_payload_is_rejected(): void
    {
        $this->seedAcceptedGenerations();

        $payload = [
            'idempotency_key' => 'p6-idempotent',
            'claim_type' => 'PROFITABILITY_CLAIM',
            'preregistration_hash' => 'prereg-1',
            'evidence_bundle_hash' => 'bundle-1',
        ];

        $first = $this->service()->evaluate($payload);
        $this->travel(2)->seconds();
        $second = $this->service()->evaluate($payload);

        self::assertSame($first['generation_id'], $second['generation_id']);
        self::assertTrue($second['idempotent_replay']);
        self::assertSame(1, ResearchDecisionRecord::query()->count());

        $this->expectException(IdempotencyConflictException::class);
        $this->service()->evaluate(array_merge($payload, ['evidence_bundle_hash' => 'bundle-2']));
    }

    public function test_the_read_model_returns_the_latest_decision_without_writing(): void
    {
        $this->seedAcceptedGenerations();

        $before = DB::table('research_decision_records')->count();

        $empty = $this->service()->getLatestDecision();
        self::assertSame('NO_DECISION_AVAILABLE', $empty['status']);

        $this->service()->evaluate([
            'idempotency_key' => 'p6-read-model',
            'claim_type' => 'PROFITABILITY_CLAIM',
            'preregistration_hash' => 'prereg-1',
            'evidence_bundle_hash' => 'bundle-1',
        ]);

        $latest = $this->service()->getLatestDecision();
        self::assertSame('OK', $latest['status']);
        self::assertSame(DecisionState::ELIGIBLE_FOR_OWNER_REVIEW, $latest['decision']['decision_state']);
        self::assertSame($before + 1, DB::table('research_decision_records')->count());
    }

    public function test_a_registered_negative_test_outcome_produces_a_negative_state(): void
    {
        $this->seedAcceptedGenerations();

        $result = $this->service()->evaluate([
            'idempotency_key' => 'p6-negative',
            'claim_type' => 'PROFITABILITY_CLAIM',
            'preregistration_hash' => 'prereg-1',
            'evidence_bundle_hash' => 'bundle-1',
            'test_outcome' => 'NEGATIVE',
        ]);

        self::assertSame(DecisionState::EVIDENCE_NEGATIVE, $result['decision']['decision_state']);
    }

    private function service(): DecisionEvidenceService
    {
        return $this->app->make(DecisionEvidenceService::class);
    }

    /**
     * @param  array<string, array<string, mixed>>  $overrides
     */
    private function seedAcceptedGenerations(array $overrides = []): void
    {
        $asOf = CarbonImmutable::now('UTC')->subHour();

        $defaults = [
            'P-1' => ['reconciled' => true],
            'P-2' => ['sample_sufficiency' => 'SUFFICIENT'],
            'P-4' => ['sample_sufficiency' => 'SUFFICIENT', 'reconciled' => true, 'coverage_met' => true],
            'P-5' => ['sample_sufficiency' => 'SUFFICIENT', 'coverage_met' => true],
        ];

        foreach ($defaults as $packageCode => $metadata) {
            $metadata = array_merge($metadata, $overrides[$packageCode] ?? []);

            ResearchGenerationRecord::query()->create([
                'id' => Uuid::uuid4()->toString(),
                'package_code' => $packageCode,
                'accounting_scope' => 'FULL_LIFECYCLE',
                'as_of' => $asOf,
                'status' => 'SUCCEEDED',
                'idempotency_key' => 'seed-'.$packageCode,
                'request_payload_hash' => hash('sha256', $packageCode),
                'versioned_config' => ['seeded' => true],
                'metadata' => $metadata,
                'heartbeat_at' => $asOf,
                'stale_after_seconds' => 600,
            ]);
        }
    }
}
