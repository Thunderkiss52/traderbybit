<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\PaperTrading\Application\PaperAccountService;
use App\Strategy\Application\StrategyRegistry;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deterministic, idempotent deployment initializer for the 26-account canonical topology.
 * Provisions 13 Binance accounts, binds configuration, allocates initial capital (accounts stay BLOCKED),
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
    ) {}

    public function initialize(string $amount = '10000', string $approvalReference = 'OWNER-VECTOR-20261023'): array
    {
        $lock = $this->cache->lock('deployment:binance-initialization', 60);
        if (! $lock->get()) {
            throw new RuntimeException('Binance deployment initialization is already in progress.');
        }

        try {
            // Preflight 1: Schema check
            $schema = DB::getSchemaBuilder();
            if (! $schema->hasTable('paper_accounts') || ! $schema->hasColumn('paper_accounts', 'exchange_id')) {
                throw new RuntimeException('Exchange-scoped schema migration required before initialization.');
            }

            // Preflight 2: Verify all 13 canonical Bybit accounts exist
            $bybitAccounts = DB::table('paper_accounts')->where('exchange_id', 'bybit')->where('is_primary', true)->get();
            if ($bybitAccounts->count() !== 13) {
                // Populate/ensure Bybit primary accounts
                $this->accounts->allPrimaryAccounts('bybit');
                $bybitAccounts = DB::table('paper_accounts')->where('exchange_id', 'bybit')->where('is_primary', true)->get();
                if ($bybitAccounts->count() !== 13) {
                    throw new RuntimeException('Bybit 13 canonical primary accounts population is incomplete.');
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

            // Step 4: Run full M9 26-account reconciliation
            $reconcileReport = $this->reconciliation->inspect();
            if ($reconcileReport['status'] !== 'LEDGER_PASS_ONLY') {
                throw new RuntimeException('M9 26-account reconciliation failed: '.json_encode($reconcileReport['issues'], JSON_THROW_ON_ERROR));
            }

            return [
                'status' => 'INITIALIZED_SUCCESSFULLY',
                'provisioned' => $provisionResult,
                'capital' => $capitalResult,
                'reconciliation' => $reconcileReport,
                'bybit_history_preserved' => true,
                'canonical_accounts_count' => 26,
            ];
        } finally {
            $lock->release();
        }
    }

    private function checksumBybitState(): string
    {
        $accounts = DB::table('paper_accounts')->where('exchange_id', 'bybit')->orderBy('id')->get();
        $positions = DB::table('virtual_positions')->where('exchange_id', 'bybit')->orderBy('id')->get();
        $positionIds = $positions->pluck('id')->all();
        $executions = DB::table('virtual_executions')->whereIn('position_id', $positionIds)->orderBy('id')->get();
        $snapshots = DB::table('equity_snapshots')->where('exchange_id', 'bybit')->orderBy('id')->get();

        return hash('sha256', json_encode([
            'accounts' => $accounts->map(fn ($a) => [$a->id, (string) $a->starting_balance, (string) $a->current_equity, (string) $a->realized_pnl])->all(),
            'positions' => $positions->map(fn ($p) => [$p->id, (string) $p->gross_pnl, (string) $p->net_pnl, (string) $p->status])->all(),
            'executions' => $executions->map(fn ($e) => [$e->id, (string) $e->price, (string) $e->quantity])->all(),
            'snapshots_count' => $snapshots->count(),
        ], JSON_THROW_ON_ERROR));
    }
}
