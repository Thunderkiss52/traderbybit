<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\Exchanges\ExchangeRegistry;
use App\PaperTrading\Application\EquitySnapshotRepairService;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\Strategy\Application\StrategyRegistry;
use App\StrategyBuilder\PrimaryStrategies;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Idempotent legacy Binance funding initializer with complete registered topology.
 * Provisions Binance accounts, binds configuration, allocates authorized capital (accounts stay BLOCKED),
 * verifies Bybit financial history checksums are unchanged, and confirms M9 reconciliation.
 */
final class BinanceDeploymentInitializer
{
    public function __construct(
        private BinancePaperProvisioner $provisioner,
        private BinancePaperCapital $capital,
        private ExchangePaperReconciliation $reconciliation,
        private PaperAccountService $accounts,
        private StrategyRegistry $registry,
        private CacheFactory $cache,
        private ?EquitySnapshotRepairService $snapshotRepair = null,
    ) {}

    public function initialize(string $amount = '10000', string $approvalReference = 'OWNER-VECTOR-20261023'): array
    {
        $lock = $this->cache->lock('deployment:binance-initialization', 60);
        if (! $lock->get()) {
            throw new RuntimeException('Binance deployment initialization is already in progress.');
        }

        try {
            // Provisioning, capital allocation, snapshot repair and M9 must commit together.
            // An application rollback cannot undo earlier committed PAPER records.
            return DB::transaction(function () use ($amount, $approvalReference): array {
                // This must be the first SQL in the top-level deployment transaction.
                // M9 and both Bybit checksums then see one PostgreSQL MVCC snapshot.
                if (DB::connection()->transactionLevel() === 1) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }
                // Preflight 1: Schema check
                $schema = DB::getSchemaBuilder();
                if (! $schema->hasTable('paper_accounts') || ! $schema->hasColumn('paper_accounts', 'exchange_id')) {
                    throw new RuntimeException('Exchange-scoped schema migration required before initialization.');
                }

                // Preflight 2: Verify all registered canonical Bybit accounts exist
                $bybitAccounts = DB::table('paper_accounts')->where('exchange_id', 'bybit')->where('is_primary', true)->get();
                if ($bybitAccounts->count() !== count(PrimaryStrategies::ids('bybit'))) {
                    // Populate/ensure Bybit primary accounts
                    $this->accounts->allPrimaryAccounts('bybit');
                    $bybitAccounts = DB::table('paper_accounts')->where('exchange_id', 'bybit')->where('is_primary', true)->get();
                    if ($bybitAccounts->count() !== count(PrimaryStrategies::ids('bybit'))) {
                        throw new RuntimeException('Bybit canonical primary account population is incomplete.');
                    }
                }

                // Capture Bybit financial state checksum before provisioning
                $bybitChecksumBefore = $this->checksumBybitState();

                // Step 1: Provision 13 Binance canonical accounts (idempotent, accounts are BLOCKED)
                config(['exchanges.binance_paper_provisioning_approved' => true]);
                $provisionResult = $this->provisioner->provision();

                // Step 2: Allocate owner-approved paper capital (idempotent, accounts remain BLOCKED)
                $capitalResult = $this->capital->allocate($amount, $approvalReference);

                // Step 3: Verify Bybit financial state was completely untouched
                $bybitChecksumAfter = $this->checksumBybitState();
                if ($bybitChecksumBefore !== $bybitChecksumAfter) {
                    throw new RuntimeException('Bybit financial state integrity violation during Binance initialization.');
                }

                // Existing PAPER checkpoints require separate approval for repair.
                // Deployment initialization must not rewrite historical evidence.
                $repairService = $this->snapshotRepair ?? app(EquitySnapshotRepairService::class);
                $repairResult = $repairService->repair(apply: false);
                if ($repairResult['candidates_count'] !== 0) {
                    throw new RuntimeException('Binance checkpoint provenance requires separate review before initialization.');
                }

                // Complete the registered topology without funding or activating new venues.
                app(PaperTopologySynchronizer::class)->sync(true);

                // Step 4: Reconcile every registered exchange and standalone strategy.
                $reconcileReport = $this->reconciliation->inspect();
                if ($reconcileReport['status'] !== 'LEDGER_PASS_ONLY') {
                    throw new RuntimeException('M9 full-cohort reconciliation failed: '.json_encode($reconcileReport['issues'], JSON_THROW_ON_ERROR));
                }

                return [
                    'status' => 'INITIALIZED_SUCCESSFULLY',
                    'provisioned' => $provisionResult,
                    'capital' => $capitalResult,
                    'snapshot_repair' => $repairResult,
                    'reconciliation' => $reconcileReport,
                    'bybit_history_preserved' => true,
                    'canonical_accounts_count' => array_sum(array_map('count', PrimaryStrategies::byExchange(app(ExchangeRegistry::class)->registeredIds()))),
                ];
            }, attempts: 1);
        } finally {
            $lock->release();
        }
    }

    private function checksumBybitState(): string
    {
        $accounts = DB::table('paper_accounts')->where('exchange_id', 'bybit')->orderBy('id')->get();
        $accountIds = $accounts->pluck('id')->all();
        $positions = DB::table('virtual_positions')->where('exchange_id', 'bybit')->orderBy('id')->get();
        $positionIds = $positions->pluck('id')->all();
        $executions = DB::table('virtual_executions')->whereIn('position_id', $positionIds)->orderBy('id')->get();
        $snapshots = DB::table('equity_snapshots')->where('exchange_id', 'bybit')->orderBy('id')->get();
        $capitalEvents = DB::table('paper_capital_events')->where('exchange_id', 'bybit')->orderBy('id')->get();
        $journalEvents = DB::table('trading_journal_events')->where('exchange_id', 'bybit')->orderBy('id')->get();
        $riskDecisions = DB::table('risk_decisions')->whereIn('paper_account_id', $accountIds)->orderBy('id')->get();

        return hash('sha256', json_encode([
            'accounts' => $accounts->map(fn ($a) => [
                $a->id, (string) $a->starting_balance, (string) $a->current_equity, (string) $a->peak_equity,
                (string) $a->realized_pnl, (string) $a->unrealized_pnl, (string) $a->reserved_risk,
                (string) $a->maximum_drawdown_percent, (string) $a->current_drawdown_percent,
                $a->mode, $a->strategy_id, $a->strategy_version, $a->configuration_hash, $a->status, (bool) $a->is_primary,
            ])->all(),
            'positions' => $positions->map(fn ($p) => [
                $p->id, $p->symbol, $p->side, $p->status, (string) $p->initial_quantity, (string) $p->remaining_quantity,
                (string) $p->entry_price, (string) ($p->exit_price ?? ''),
                (string) $p->gross_pnl, (string) $p->net_pnl, (string) $p->fees, (string) $p->funding,
            ])->all(),
            'executions' => $executions->map(fn ($e) => [
                $e->id, $e->position_id, $e->type, $e->side, (string) $e->price, (string) $e->quantity,
                (string) $e->commission, (string) $e->gross_pnl,
            ])->all(),
            'snapshots' => $snapshots->map(fn ($s) => [
                $s->id, $s->paper_account_id, (string) $s->equity, (string) $s->realized_pnl,
                (string) $s->unrealized_pnl, (string) $s->drawdown_percent, $s->source_event_key,
            ])->all(),
            'capital_events' => $capitalEvents->map(fn ($c) => [
                $c->id, $c->paper_account_id, (string) $c->amount, $c->approval_reference,
            ])->all(),
            'journal_events' => $journalEvents->map(fn ($j) => [
                $j->id, $j->paper_account_id, $j->event_type, (string) $j->occurred_at,
            ])->all(),
            'risk_decisions' => $riskDecisions->map(fn ($r) => [
                $r->id, $r->paper_account_id, (bool) $r->allowed, (string) $r->decided_at,
            ])->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
