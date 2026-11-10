<?php

declare(strict_types=1);

namespace App\Exchanges\Console;

use App\Exchanges\Application\ExchangeCatalogService;
use Illuminate\Console\Command;
use Throwable;

final class DeactivateExchangeCommand extends Command
{
    protected $signature = 'exchange:deactivate
        {id : Exchange identifier}
        {--reason= : Reason for deactivation}';

    protected $description = 'Deactivate an exchange, blocking paper accounts while preserving all historical data';

    public function handle(ExchangeCatalogService $catalog): int
    {
        $id = (string) $this->argument('id');
        $reason = (string) ($this->option('reason') ?: 'OPERATOR_CLI_DEACTIVATED');

        try {
            $exchange = $catalog->deactivate($id, $reason);
            $this->info("Exchange '{$exchange->id}' deactivated successfully.");
            $this->line("Lifecycle Status: {$exchange->lifecycle_status}");
            $this->line('All historical accounts, positions, snapshots, and ledger journals remain intact.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Deactivation failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
