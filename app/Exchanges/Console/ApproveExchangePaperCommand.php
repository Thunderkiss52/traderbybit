<?php

declare(strict_types=1);

namespace App\Exchanges\Console;

use App\Exchanges\Application\ExchangeCatalogService;
use Illuminate\Console\Command;
use Throwable;

final class ApproveExchangePaperCommand extends Command
{
    protected $signature = 'exchange:approve-paper
        {id : Exchange identifier}
        {--reference= : Approval reference string}';

    protected $description = 'Explicit owner approval of an exchange for active virtual PAPER execution';

    public function handle(ExchangeCatalogService $catalog): int
    {
        $id = (string) $this->argument('id');
        $reference = (string) ($this->option('reference') ?: 'OWNER_CLI_APPROVAL');

        try {
            $exchange = $catalog->approvePaper($id, true, $reference);
            $this->info("Exchange '{$exchange->id}' approved for PAPER execution successfully!");
            $this->line("Lifecycle Status: {$exchange->lifecycle_status}");
            $this->line('Primary paper accounts activated for trading evaluation.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Approval failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
