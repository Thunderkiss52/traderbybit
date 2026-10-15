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
}
