<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Domain\AccountStatus;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Application\StrategyRegistry;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final readonly class PaperAccountService
{
    public function __construct(
        private array $config,
        private TradingJournalService $journal,
        private ?StrategyRegistry $registry = null,
    ) {}

    public function default(): PaperAccountRecord
    {
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:paper:default')->toString();
        $baselineConfig = (array) config('strategy');
        $baselineVersion = (string) config('strategy.version');
        $baselineHash = StrategyRegistry::hash($baselineConfig);

        $account = PaperAccountRecord::query()->where('primary_strategy_id', 'baseline')->where('exchange_id', 'bybit')->first()
            ?? PaperAccountRecord::query()->find($id);

        if ($account !== null) {
            if (! $account->is_primary || $account->primary_strategy_id !== 'baseline' || $account->research_experiment_id !== null || $account->exchange_id !== 'bybit') {
                throw new \DomainException('Default account provenance requires explicit reconciliation.');
            }

            if ($account->configuration_hash === null && $account->strategy_version === $baselineVersion) {
                $account->update([
                    'configuration_hash' => $baselineHash,
                    'strategy_parameters' => $baselineConfig,
                ]);
            }

            $this->ensureInitialSnapshot($account);

            return $account;
        }

        $account = PaperAccountRecord::query()->create([
            'id' => $id,
            'name' => (string) $this->config['default_account']['name'],
            'status' => AccountStatus::Active->value,
            'starting_balance' => (string) $this->config['default_account']['starting_balance'],
            'current_equity' => (string) $this->config['default_account']['starting_balance'],
            'peak_equity' => (string) $this->config['default_account']['starting_balance'],
            'realized_pnl' => '0', 'unrealized_pnl' => '0', 'reserved_risk' => '0', 'maximum_drawdown_percent' => '0', 'current_drawdown_percent' => '0',
            'mode' => 'FORWARD_PAPER', 'strategy_id' => 'baseline', 'strategy_version' => $baselineVersion,
            'primary_strategy_id' => 'baseline', 'is_primary' => true, 'exchange_id' => 'bybit',
            'configuration_hash' => $baselineHash,
            'strategy_parameters' => $baselineConfig,
        ]);

        $this->ensureInitialSnapshot($account);

        return $account;
    }

    public function strategyForward(string $strategyId, string $strategyVersion, array $parameters): PaperAccountRecord
    {
        if ($strategyId === 'baseline' && $strategyVersion === (string) config('strategy.version') && $this->hash($parameters) === $this->hash((array) config('strategy'))) {
            return $this->default();
        }

        $hash = $this->hash($parameters);
        // PRIMARY is an explicit cohort, never inferred from a matching strategy ID.
        $primary = PaperAccountRecord::query()->where('primary_strategy_id', $strategyId)->where('exchange_id', 'bybit')->where('is_primary', true)->first();
        if ($primary !== null && $primary->strategy_id === $strategyId && $primary->mode === 'STRATEGY_FORWARD_PAPER' && $primary->research_experiment_id === null && $primary->strategy_version === $strategyVersion && $primary->configuration_hash === $hash) {
            $this->ensureInitialSnapshot($primary);

            return $primary;
        }
        if ($primary !== null && $primary->configuration_hash === null) {
            return DB::transaction(function () use ($primary, $strategyId, $strategyVersion, $hash, $parameters): PaperAccountRecord {
                $primary = PaperAccountRecord::query()->lockForUpdate()->findOrFail($primary->id);
                $canonicalIds = array_filter([
                    $strategyId === 'baseline' ? Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:paper:default')->toString() : null,
                    Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:paper:primary:{$strategyId}")->toString(),
                    Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:paper:strategy:{$strategyId}")->toString(),
                ]);
                if (! in_array($primary->id, $canonicalIds, true) || $primary->mode !== 'STRATEGY_FORWARD_PAPER'
                    || $primary->strategy_id !== $strategyId || $primary->research_experiment_id !== null || $primary->configuration_hash !== null) {
                    throw new \DomainException('Primary account provenance requires explicit reconciliation.');
                }
                if ($primary->strategy_parameters !== null && StrategyRegistry::hash($primary->strategy_parameters) !== $hash) {
                    throw new \DomainException('Primary account configuration parameter conflict requires explicit reconciliation.');
                }
                $primary->update(['configuration_hash' => $hash, 'strategy_parameters' => $parameters, 'strategy_version' => $strategyVersion]);
                $this->ensureInitialSnapshot($primary);

                return $primary;
            });
        }
        // Signal IDs are strategy-version scoped. A changed configuration must bump
        // the version, otherwise stored signals would silently use the wrong rules.
        if (PaperAccountRecord::query()->where('mode', 'STRATEGY_FORWARD_PAPER')
            ->where('strategy_id', $strategyId)->where('exchange_id', 'bybit')->where('strategy_version', $strategyVersion)
            ->whereNotNull('configuration_hash')->where('configuration_hash', '!=', $hash)->exists()) {
            throw new \DomainException("Strategy {$strategyId} parameters changed without a version bump.");
        }
        // Reuse the legacy account only for its original version and configuration.
        $existing = PaperAccountRecord::query()->where('mode', 'STRATEGY_FORWARD_PAPER')
            ->where('strategy_id', $strategyId)->where('exchange_id', 'bybit')->where('strategy_version', $strategyVersion)
            ->where('configuration_hash', $hash)->oldest('created_at')->first();
        $id = $existing?->id ?? Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:paper:strategy:{$strategyId}:{$strategyVersion}:{$hash}")->toString();
        $starting = (string) $this->config['default_account']['starting_balance'];

        $account = PaperAccountRecord::query()->firstOrCreate(['id' => $id], [
            'name' => 'Forward Paper '.$strategyId,
            'status' => AccountStatus::Active->value,
            'starting_balance' => $starting,
            'current_equity' => $starting,
            'peak_equity' => $starting,
            'realized_pnl' => '0',
            'unrealized_pnl' => '0',
            'reserved_risk' => '0',
            'maximum_drawdown_percent' => '0',
            'current_drawdown_percent' => '0',
            'mode' => 'STRATEGY_FORWARD_PAPER',
            'exchange_id' => 'bybit',
            'strategy_id' => $strategyId,
            'strategy_version' => $strategyVersion,
            'configuration_hash' => $hash,
            'strategy_parameters' => $parameters,
        ]);

        $this->ensureInitialSnapshot($account);

        return $account;
    }

    public function primaryAccount(string $strategyId, string $exchangeId = 'bybit'): PaperAccountRecord
    {
        return DB::transaction(function () use ($strategyId, $exchangeId): PaperAccountRecord {
            if (! in_array($exchangeId, ['bybit', 'binance'], true)) {
                throw new \DomainException('Unsupported paper exchange.');
            }
            if (! in_array($strategyId, (array) config('paper.primary_strategies'), true)) {
                throw new \DomainException('Unregistered primary strategy.');
            }
            if ($strategyId === 'baseline' && $exchangeId === 'bybit') {
                return $this->default();
            }

            $account = PaperAccountRecord::query()
                ->where('primary_strategy_id', $strategyId)
                ->where('exchange_id', $exchangeId)
                ->first();

            $registry = $this->registry ?? app(StrategyRegistry::class);
            $strategy = $registry->get($strategyId);
            $version = $strategy->version();
            $parameters = $strategy->parameters();
            $hash = StrategyRegistry::hash($parameters);

            if ($account !== null) {
                if (! $account->is_primary || $account->strategy_id !== $strategyId || $account->research_experiment_id !== null) {
                    throw new \DomainException('Incompatible primary account provenance.');
                }

                return $account;
            }

            if ($exchangeId === 'binance') {
                // Binance accounts may only be provisioned by the reviewed, evidence-gated migration.
                throw new \DomainException('Binance PRIMARY account is not provisioned.');
            }
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:paper:primary:{$strategyId}")->toString();
            $starting = app(NewPrimaryCapitalPolicy::class)->openingBalance($exchangeId, $strategyId);

            $account = PaperAccountRecord::query()->firstOrCreate(['id' => $id], [
                'name' => 'Forward Paper '.$strategyId,
                'status' => in_array($strategyId, (array) config('paper.initially_approved_bybit_strategies', []), true)
                    ? AccountStatus::Active->value : AccountStatus::Blocked->value,
                'starting_balance' => $starting,
                'current_equity' => $starting,
                'peak_equity' => $starting,
                'realized_pnl' => '0',
                'unrealized_pnl' => '0',
                'reserved_risk' => '0',
                'maximum_drawdown_percent' => '0',
                'current_drawdown_percent' => '0',
                'mode' => 'STRATEGY_FORWARD_PAPER',
                'exchange_id' => $exchangeId,
                'strategy_id' => $strategyId,
                'primary_strategy_id' => $strategyId,
                'is_primary' => true,
                'strategy_version' => $version,
                'configuration_hash' => $hash,
                'strategy_parameters' => $parameters,
            ]);

            if (! $account->is_primary || $account->primary_strategy_id !== $strategyId || $account->research_experiment_id !== null || $account->exchange_id !== $exchangeId) {
                throw new \DomainException('Canonical primary identity is occupied by an incompatible account.');
            }
            $this->ensureInitialSnapshot($account);

            return $account;
        });
    }

    public function allPrimaryAccounts(string $exchangeId = 'bybit'): Collection
    {
        return collect((array) config('paper.primary_strategies', ['baseline']))->map(fn (string $s) => $this->primaryAccount($s, $exchangeId));
    }

    public function resume(string $id, bool $ownerApproved = false): PaperAccountRecord
    {
        return DB::transaction(function () use ($id, $ownerApproved): PaperAccountRecord {
            $account = PaperAccountRecord::query()->lockForUpdate()->findOrFail($id);
            if ($account->exchange_id !== 'bybit') {
                throw new \DomainException('Binance paper execution remains blocked pending verified exchange isolation.');
            }
            $drawdown = BigDecimal::of($account->current_drawdown_percent);
            if ($drawdown->isNegative()) {
                throw new \DomainException('Invalid PAPER account drawdown; reactivation is prohibited.');
            }
            $halt = BigDecimal::min(BigDecimal::of((string) config('paper.drawdown.halt_percent')), BigDecimal::of('10'));
            $block = BigDecimal::min(BigDecimal::of((string) config('paper.drawdown.block_new_entries_percent')), BigDecimal::of('5'));
            $status = $drawdown->isGreaterThanOrEqualTo($halt) ? AccountStatus::Halted->value
                : ($drawdown->isGreaterThanOrEqualTo($block) ? AccountStatus::Blocked->value : AccountStatus::Active->value);
            if ($status === AccountStatus::Active->value && $account->status !== AccountStatus::Active->value && ! $ownerApproved) {
                throw new \DomainException('Explicit owner approval is required to reactivate a blocked PAPER account.');
            }
            $previousStatus = $account->status;
            $account->update(['status' => $status, 'drawdown_override' => false]);
            if ($status === AccountStatus::Active->value && $previousStatus !== $status) {
                $at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $this->journal->append(
                    $account->id, null, null, 'OWNER_APPROVED_PAPER_RESUME', $at,
                    ['from_status' => $previousStatus, 'to_status' => $status, 'drawdown_percent' => (string) $drawdown],
                    'owner-approved-resume:'.$account->id.':'.$at->format('U.u'),
                );
            }

            return $account->refresh();
        });
    }

    private function ensureInitialSnapshot(PaperAccountRecord $account): void
    {
        if (empty($account->exchange_id)) {
            throw new \RuntimeException("Paper account {$account->id} is missing exchange_id.");
        }

        EquitySnapshotRecord::query()->insertOrIgnore([[
            'paper_account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'recorded_at' => $account->created_at,
            'equity' => $account->starting_balance,
            'realized_pnl' => '0', 'unrealized_pnl' => '0', 'drawdown_percent' => '0',
            'source_event_key' => 'account:initial',
            'valued_through' => $account->created_at,
            'valuation_fresh' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    private function hash(array $parameters): string
    {
        return StrategyRegistry::hash($parameters);
    }
}
