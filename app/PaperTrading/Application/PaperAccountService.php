<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Domain\AccountStatus;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final readonly class PaperAccountService
{
    public function __construct(private array $config) {}

    public function default(): PaperAccountRecord
    {
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:paper:default')->toString();

        $account = PaperAccountRecord::query()->firstOrCreate(['id' => $id], [
            'name' => (string) $this->config['default_account']['name'],
            'status' => AccountStatus::Active->value,
            'starting_balance' => (string) $this->config['default_account']['starting_balance'],
            'current_equity' => (string) $this->config['default_account']['starting_balance'],
            'peak_equity' => (string) $this->config['default_account']['starting_balance'],
            'realized_pnl' => '0', 'unrealized_pnl' => '0', 'reserved_risk' => '0', 'maximum_drawdown_percent' => '0', 'current_drawdown_percent' => '0',
            'mode' => 'FORWARD_PAPER', 'strategy_id' => 'baseline', 'strategy_version' => (string) config('strategy.version'),
        ]);

        $this->ensureInitialSnapshot($account);

        return $account;
    }

    public function strategyForward(string $strategyId, string $strategyVersion, array $parameters): PaperAccountRecord
    {
        if ($strategyId === 'baseline') {
            return $this->default();
        }

        $hash = $this->hash($parameters);
        // Signal IDs are strategy-version scoped. A changed configuration must bump
        // the version, otherwise stored signals would silently use the wrong rules.
        if (PaperAccountRecord::query()->where('mode', 'STRATEGY_FORWARD_PAPER')
            ->where('strategy_id', $strategyId)->where('strategy_version', $strategyVersion)
            ->whereNotNull('configuration_hash')->where('configuration_hash', '!=', $hash)->exists()) {
            throw new \DomainException("Strategy {$strategyId} parameters changed without a version bump.");
        }
        // Reuse the legacy account only for its original version and configuration.
        $existing = PaperAccountRecord::query()->where('mode', 'STRATEGY_FORWARD_PAPER')
            ->where('strategy_id', $strategyId)->where('strategy_version', $strategyVersion)
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
            'strategy_id' => $strategyId,
            'strategy_version' => $strategyVersion,
            'configuration_hash' => $hash,
            'strategy_parameters' => $parameters,
        ]);

        $this->ensureInitialSnapshot($account);

        return $account;
    }

    public function resume(string $id): PaperAccountRecord
    {
        return DB::transaction(function () use ($id): PaperAccountRecord {
            $account = PaperAccountRecord::query()->lockForUpdate()->findOrFail($id);
            $drawdown = BigDecimal::of($account->current_drawdown_percent);
            $halt = BigDecimal::of((string) config('paper.drawdown.halt_percent'));
            $block = BigDecimal::of((string) config('paper.drawdown.block_new_entries_percent'));
            $status = $drawdown->isGreaterThanOrEqualTo($halt) ? AccountStatus::Halted->value
                : ($drawdown->isGreaterThanOrEqualTo($block) ? AccountStatus::Blocked->value : AccountStatus::Active->value);
            $account->update(['status' => $status, 'drawdown_override' => false]);

            return $account->refresh();
        });
    }

    private function ensureInitialSnapshot(PaperAccountRecord $account): void
    {
        EquitySnapshotRecord::query()->insertOrIgnore([[
            'paper_account_id' => $account->id,
            'recorded_at' => $account->created_at,
            'equity' => $account->starting_balance,
            'realized_pnl' => '0', 'unrealized_pnl' => '0', 'drawdown_percent' => '0',
            'source_event_key' => 'account:initial', 'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    private function hash(array $parameters): string
    {
        $sort = function (mixed &$value) use (&$sort): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as &$item) {
                $sort($item);
            }
            unset($item);
            if (! array_is_list($value)) {
                ksort($value);
            }
        };
        $sort($parameters);

        return hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));
    }
}
