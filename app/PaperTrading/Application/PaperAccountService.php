<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Domain\AccountStatus;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final readonly class PaperAccountService
{
    public function __construct(private array $config) {}

    public function default(): PaperAccountRecord
    {
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:paper:default')->toString();

        return PaperAccountRecord::query()->firstOrCreate(['id' => $id], [
            'name' => (string) $this->config['default_account']['name'],
            'status' => AccountStatus::Active->value,
            'starting_balance' => (string) $this->config['default_account']['starting_balance'],
            'current_equity' => (string) $this->config['default_account']['starting_balance'],
            'peak_equity' => (string) $this->config['default_account']['starting_balance'],
            'realized_pnl' => '0', 'unrealized_pnl' => '0', 'reserved_risk' => '0', 'maximum_drawdown_percent' => '0', 'current_drawdown_percent' => '0',
            'mode' => 'FORWARD_PAPER', 'strategy_id' => 'baseline', 'strategy_version' => (string) config('strategy.version'),
        ]);
    }

    public function strategyForward(string $strategyId, string $strategyVersion, array $parameters): PaperAccountRecord
    {
        if ($strategyId === 'baseline') {
            return $this->default();
        }

        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:paper:strategy:{$strategyId}")->toString();
        $starting = (string) $this->config['default_account']['starting_balance'];

        return PaperAccountRecord::query()->firstOrCreate(['id' => $id], [
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
            'configuration_hash' => $this->hash($parameters),
            'strategy_parameters' => $parameters,
        ]);
    }

    public function resume(string $id): PaperAccountRecord
    {
        return DB::transaction(function () use ($id): PaperAccountRecord {
            $account = PaperAccountRecord::query()->lockForUpdate()->findOrFail($id);
            $account->update(['status' => AccountStatus::Active->value, 'cooldown_until' => null, 'drawdown_override' => true]);

            return $account->refresh();
        });
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
