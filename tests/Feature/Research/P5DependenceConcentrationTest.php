<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\DependenceConcentrationService;
use App\Research\Domain\ResearchConcentrationClusterMemberRecord;
use App\Research\Domain\ResearchConcentrationClusterRecord;
use App\Research\Exceptions\IdempotencyConflictException;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * PROFIT-1 / P-5 acceptance coverage (§10.5).
 */
final class P5DependenceConcentrationTest extends TestCase
{
    use RefreshDatabase;

    private const STRATEGIES = [
        'baseline',
        'trend_pullback',
        'bollinger_mean_reversion',
        'donchian_atr_breakout',
        'regime_switching',
        'momentum',
        'support_resistance_bounce',
        'support_resistance_breakout',
    ];

    private int $signalSequence = 1;

    public function test_generation_is_idempotent_and_never_mutates_the_paper_ledger(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $this->seedPosition($accounts[1], [
            'id' => '11111111-1111-4111-8111-111111111111',
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 02:00:00+00:00',
            'net_pnl' => '10',
        ]);

        $accountsBefore = DB::table('paper_accounts')->count();
        $positionsBefore = DB::table('virtual_positions')->count();

        $generation = $this->service()->generate([
            'idempotency_key' => 'p5-idempotent',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);

        self::assertSame('SUCCEEDED', $generation->status);
        self::assertSame($accountsBefore, DB::table('paper_accounts')->count(), 'P-5 must not create, merge or delete paper accounts.');
        self::assertSame($positionsBefore, DB::table('virtual_positions')->count(), 'P-5 must not mutate paper positions.');

        $repeat = $this->service()->generate([
            'idempotency_key' => 'p5-idempotent',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);
        self::assertSame($generation->id, $repeat->id, 'The same idempotency key and payload must return the same generation.');

        $this->expectException(IdempotencyConflictException::class);
        $this->service()->generate([
            'idempotency_key' => 'p5-idempotent',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-03T00:00:00Z',
        ]);
    }

    public function test_same_side_and_opposite_side_clusters_are_distinct_and_traceable_to_position_ids(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $account = $accounts[1];

        $buyA = $this->seedPosition($account, [
            'id' => 'aaaaaaaa-1111-4111-8111-111111111111',
            'symbol' => 'BTCUSDT', 'side' => 'BUY',
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 02:00:00+00:00',
            'net_pnl' => '10',
        ]);
        $buyB = $this->seedPosition($account, [
            'id' => 'bbbbbbbb-2222-4222-8222-222222222222',
            'symbol' => 'BTCUSDT', 'side' => 'BUY',
            'opened_at' => '2026-01-01 01:00:00+00:00',
            'closed_at' => '2026-01-01 03:00:00+00:00',
            'net_pnl' => '5',
        ]);
        $sell = $this->seedPosition($account, [
            'id' => 'cccccccc-3333-4333-8333-333333333333',
            'symbol' => 'BTCUSDT', 'side' => 'SELL',
            'opened_at' => '2026-01-01 01:30:00+00:00',
            'closed_at' => '2026-01-01 04:00:00+00:00',
            'net_pnl' => '-2',
        ]);

        $generation = $this->service()->generate([
            'idempotency_key' => 'p5-views',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);

        $rows = ResearchConcentrationClusterRecord::query()
            ->where('generation_id', $generation->id)
            ->where('scope_code', 'ACCOUNT')
            ->where('paper_account_id', $account->id)
            ->get();

        $sameSide = $rows->where('view_code', 'SAME_SIDE');
        $oppositeSide = $rows->where('view_code', 'OPPOSITE_SIDE');
        self::assertGreaterThan(0, $sameSide->count());
        self::assertGreaterThan(0, $oppositeSide->count());

        $sameSideIds = $sameSide->pluck('cluster_id')->all();
        $oppositeSideIds = $oppositeSide->pluck('cluster_id')->all();
        self::assertSame([], array_intersect($sameSideIds, $oppositeSideIds), 'Same-side and opposite-side clusters must be distinct.');

        $memberIds = ResearchConcentrationClusterMemberRecord::query()
            ->where('generation_id', $generation->id)
            ->pluck('position_id')
            ->unique()
            ->all();
        self::assertContains($buyA->id, $memberIds);
        self::assertContains($buyB->id, $memberIds);
        self::assertContains($sell->id, $memberIds);

        $oppositeCluster = $oppositeSide->first();
        $oppositeMembers = ResearchConcentrationClusterMemberRecord::query()
            ->where('cluster_id', $oppositeCluster->cluster_id)
            ->pluck('position_id')
            ->all();
        self::assertCount(3, $oppositeMembers, 'The opposite-side bipartite component merges the two overlapping BUY positions only through the overlapping SELL position.');
        self::assertContains($buyA->id, $oppositeMembers);
        self::assertContains($buyB->id, $oppositeMembers);
        self::assertContains($sell->id, $oppositeMembers);

        $edges = $generation->metadata['opposite_side_edges'];
        self::assertCount(2, $edges, 'Only opposite-side overlaps create edges.');
        foreach ($edges as $edge) {
            self::assertContains($edge['buy_position_id'], [$buyA->id, $buyB->id]);
            self::assertSame($sell->id, $edge['sell_position_id']);
        }
    }

    public function test_open_position_is_clipped_to_the_cutoff_and_keeps_only_realized_pnl_in_concentration(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $account = $accounts[1];

        $this->seedPosition($account, [
            'id' => 'dddddddd-4444-4444-8444-444444444444',
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => null,
            'remaining_quantity' => '1',
            'net_pnl' => '0',
            'unrealized_pnl' => '25',
        ]);

        $generation = $this->service()->generate([
            'idempotency_key' => 'p5-open-at-cutoff',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-01 06:00:00Z',
        ]);

        $member = ResearchConcentrationClusterMemberRecord::query()
            ->where('generation_id', $generation->id)
            ->first();

        self::assertNotNull($member);
        self::assertTrue((bool) $member->open_at_cutoff);
        self::assertSame('2026-01-01T06:00:00+00:00', $member->ended_at->toIso8601String(), 'An open position ends at the generation cutoff.');
        self::assertSame('0.000000000000', $member->net_pnl, 'An open position contributes no realized net PnL to concentration.');
        self::assertSame('25.000000000000', $member->unrealized_pnl, 'Unrealized PnL is reported separately.');
    }

    public function test_partial_close_does_not_end_the_position_interval(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $account = $accounts[1];

        $this->seedPosition($account, [
            'id' => 'eeeeeeee-5555-4555-8555-555555555555',
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 02:00:00+00:00',
            'initial_quantity' => '2',
            'remaining_quantity' => '1',
            'net_pnl' => '0',
        ]);

        $generation = $this->service()->generate([
            'idempotency_key' => 'p5-partial-close',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-01 06:00:00Z',
        ]);

        $member = ResearchConcentrationClusterMemberRecord::query()
            ->where('generation_id', $generation->id)
            ->first();

        self::assertNotNull($member);
        self::assertTrue((bool) $member->partial_close_not_ending);
        self::assertSame('2026-01-01T06:00:00+00:00', $member->ended_at->toIso8601String(), 'A partial close must not end the position interval.');
    }

    public function test_cluster_ids_are_deterministic_across_runs(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $account = $accounts[1];
        $this->seedPosition($account, [
            'id' => 'ffffffff-6666-4666-8666-666666666666',
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 02:00:00+00:00',
            'net_pnl' => '7',
        ]);

        $first = $this->service()->generate([
            'idempotency_key' => 'p5-determinism-1',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);
        $second = $this->service()->generate([
            'idempotency_key' => 'p5-determinism-2',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);

        $firstIds = ResearchConcentrationClusterRecord::query()->where('generation_id', $first->id)->orderBy('cluster_id')->pluck('cluster_id')->all();
        $secondIds = ResearchConcentrationClusterRecord::query()->where('generation_id', $second->id)->orderBy('cluster_id')->pluck('cluster_id')->all();

        self::assertNotEmpty($firstIds);
        self::assertSame($firstIds, $secondIds, 'Re-running with the same generation inputs must yield identical cluster ids and membership.');
    }

    public function test_cross_symbol_exposure_is_reported_gross_without_netting(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $account = $accounts[1];

        $this->seedPosition($account, [
            'id' => '11111111-aaaa-4aaa-8aaa-111111111111',
            'symbol' => 'BTCUSDT', 'side' => 'BUY',
            'entry_price' => '100', 'initial_quantity' => '1',
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 02:00:00+00:00',
        ]);
        $this->seedPosition($account, [
            'id' => '22222222-bbbb-4bbb-8bbb-222222222222',
            'symbol' => 'ETHUSDT', 'side' => 'SELL',
            'entry_price' => '50', 'initial_quantity' => '2',
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 02:00:00+00:00',
        ]);

        $generation = $this->service()->generate([
            'idempotency_key' => 'p5-cross-symbol',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);

        $exposure = $generation->metadata['cross_symbol_exposure'];
        self::assertFalse($exposure['netting_applied']);
        self::assertSame(['BTCUSDT', 'ETHUSDT'], $exposure['symbols']);
        self::assertSame('100.000000000000', $exposure['per_symbol']['BTCUSDT']['peak_gross_entry_notional']);
        self::assertSame('100.000000000000', $exposure['per_symbol']['ETHUSDT']['peak_gross_entry_notional']);
        self::assertSame('200.000000000000', $exposure['peak_combined_gross_entry_notional'], 'Cross-symbol notionals are summed gross, never netted.');
    }

    public function test_missing_equity_snapshots_make_the_cross_account_view_not_comparable(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $this->seedEquitySnapshot($accounts[1], '2026-01-01 00:00:00+00:00', '10000');
        $this->seedEquitySnapshot($accounts[2], '2026-01-01 00:00:00+00:00', '10000');
        $this->seedEquitySnapshot($accounts[2], '2026-01-01 03:00:00+00:00', '9000');

        $generation = $this->service()->generate([
            'idempotency_key' => 'p5-equity-gap',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-01 04:00:00Z',
            'alignment_interval_seconds' => 3600,
            'max_carry_forward_age_seconds' => 3600,
        ]);

        $alignment = $generation->metadata['equity_alignment'];
        self::assertSame('NOT_COMPARABLE', $alignment['status']);
        self::assertGreaterThan(0, $alignment['missing_points']);
        self::assertFalse($alignment['zero_fill_applied']);
        self::assertFalse($alignment['interpolation_applied']);
    }

    public function test_top_k_concentration_is_traceable_to_source_position_ids(): void
    {
        $accounts = $this->seedEightPrimaryAccounts();
        $account = $accounts[1];

        $this->seedPosition($account, [
            'id' => '33333333-cccc-4ccc-8ccc-333333333333',
            'symbol' => 'BTCUSDT', 'side' => 'BUY',
            'opened_at' => '2026-01-01 00:00:00+00:00',
            'closed_at' => '2026-01-01 02:00:00+00:00',
            'net_pnl' => '30',
        ]);
        $this->seedPosition($account, [
            'id' => '44444444-dddd-4ddd-8ddd-444444444444',
            'symbol' => 'BTCUSDT', 'side' => 'BUY',
            'opened_at' => '2026-01-01 01:00:00+00:00',
            'closed_at' => '2026-01-01 03:00:00+00:00',
            'net_pnl' => '10',
        ]);

        $generation = $this->service()->generate([
            'idempotency_key' => 'p5-top-k',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);

        $combined = collect($generation->metadata['views'])->firstWhere('scope_code', 'RESEARCH_COMBINED');
        $top1 = $combined['same_side_summary']['top_k']['1'];
        self::assertSame('40.000000000000', $top1['net_pnl']);
        self::assertSame('1.000000000000', $top1['share_of_total_net_pnl']);
        self::assertContains('33333333-cccc-4ccc-8ccc-333333333333', $top1['clusters'][0]['member_position_ids']);
        self::assertContains('44444444-dddd-4ddd-8ddd-444444444444', $top1['clusters'][0]['member_position_ids']);
    }

    public function test_generation_without_positions_is_reported_as_insufficient_data(): void
    {
        $this->seedEightPrimaryAccounts();

        $generation = $this->service()->generate([
            'idempotency_key' => 'p5-empty',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ]);

        self::assertSame('INSUFFICIENT_DATA', $generation->status);
        self::assertContains('NO_ELIGIBLE_POSITIONS_IN_SCOPE', $generation->metadata['status_reasons']);
        self::assertSame(0, ResearchConcentrationClusterRecord::query()->count());
    }

    private function service(): DependenceConcentrationService
    {
        return new DependenceConcentrationService;
    }

    /**
     * @return Collection<int, PaperAccountRecord>
     */
    private function seedEightPrimaryAccounts(): Collection
    {
        $accounts = collect();
        foreach (self::STRATEGIES as $strategyId) {
            $accounts->push(PaperAccountRecord::query()->create([
                'id' => (string) Uuid::uuid4(),
                'name' => 'Forward Paper '.$strategyId,
                'status' => 'ACTIVE',
                'starting_balance' => '10000',
                'current_equity' => '10000',
                'peak_equity' => '10000',
                'realized_pnl' => '0',
                'unrealized_pnl' => '0',
                'reserved_risk' => '0',
                'maximum_drawdown_percent' => '0',
                'current_drawdown_percent' => '0',
                'mode' => 'STRATEGY_FORWARD_PAPER',
                'strategy_id' => $strategyId,
                'primary_strategy_id' => $strategyId,
                'is_primary' => true,
                'strategy_version' => '1.0.0',
            ]));
        }

        return $accounts;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedPosition(PaperAccountRecord $account, array $attributes = []): VirtualPositionRecord
    {
        $openedAt = $attributes['opened_at'] ?? '2026-01-01 00:00:00+00:00';
        $closedAt = array_key_exists('closed_at', $attributes) ? $attributes['closed_at'] : '2026-01-01 02:00:00+00:00';

        return VirtualPositionRecord::query()->create(array_merge([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'signal_id' => $this->seedSignal($openedAt)->id,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'CLOSED_TP',
            'entry_price' => '100',
            'stop_loss' => '90',
            'take_profit_1' => '110',
            'take_profit_2' => '120',
            'initial_quantity' => '1',
            'remaining_quantity' => '0',
            'qty_step' => '0.001',
            'risk_capital' => '100',
            'gross_pnl' => '0',
            'net_pnl' => '0',
            'unrealized_pnl' => '0',
            'fees' => '0',
            'funding' => '0',
            'r_multiple' => null,
            'opened_at' => $openedAt,
            'maximum_holding_until' => CarbonImmutable::parse($openedAt, 'UTC')->addHours(4)->toIso8601String(),
            'closed_at' => $closedAt,
            'last_processed_event_at' => $closedAt ?? $openedAt,
            'execution_model' => 'NEXT_CANDLE_OPEN',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ], $attributes));
    }

    private function seedEquitySnapshot(PaperAccountRecord $account, string $recordedAt, string $equity): EquitySnapshotRecord
    {
        return EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'recorded_at' => $recordedAt,
            'equity' => $equity,
            'realized_pnl' => '0',
            'unrealized_pnl' => '0',
            'drawdown_percent' => '0',
            'source_event_key' => 'seed-'.$account->id.'-'.$recordedAt,
        ]);
    }

    private function seedSignal(string $openedAt): SignalRecord
    {
        $decisionTime = CarbonImmutable::parse($openedAt, 'UTC');

        return SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 80,
            'decision_time' => $decisionTime->toIso8601String(),
            'candle_close_time' => $decisionTime->addMicroseconds($this->signalSequence++)->toIso8601String(),
            'entry_low' => '100',
            'entry_high' => '100',
            'stop_loss' => '90',
            'take_profit_1' => '110',
            'take_profit_2' => '120',
            'risk_reward_1' => '1',
            'risk_reward_2' => '2',
            'model_risk_percent' => '1',
            'expires_at' => $decisionTime->addHours(4)->toIso8601String(),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'strategy_id' => 'p5-test-'.$this->signalSequence,
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
    }
}
