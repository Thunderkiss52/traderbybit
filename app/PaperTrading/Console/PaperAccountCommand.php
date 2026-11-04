<?php

declare(strict_types=1);

namespace App\PaperTrading\Console;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use Illuminate\Console\Command;

final class PaperAccountCommand extends Command
{
    protected $signature = 'paper:account {--account=} {--resume : Explicitly resume a blocked or halted account} {--owner-approved : Owner explicitly approved reactivation}';

    protected $description = 'Show or administratively resume a PAPER account';

    public function handle(PaperAccountService $accounts): int
    {
        $account = $this->option('account') ? PaperAccountRecord::query()->findOrFail((string) $this->option('account')) : $accounts->default();
        if ($this->option('resume')) {
            $account = $accounts->resume($account->id, (bool) $this->option('owner-approved'));
        }
        $this->line(json_encode($account->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
