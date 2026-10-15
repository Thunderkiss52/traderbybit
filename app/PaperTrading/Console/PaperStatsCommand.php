<?php

declare(strict_types=1);

namespace App\PaperTrading\Console;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PerformanceStatisticsService;
use Illuminate\Console\Command;

final class PaperStatsCommand extends Command
{
    protected $signature = 'paper:stats {--account=} {--symbol=} {--side=} {--quality=} {--strategy-version=} {--from=} {--to=}';

    protected $description = 'Calculate PAPER performance statistics';

    public function handle(PaperAccountService $accounts, PerformanceStatisticsService $statistics): int
    {
        $account = $this->option('account') ?: $accounts->default()->id;
        $filters = array_filter([
            'symbol' => $this->option('symbol'), 'side' => $this->option('side'), 'quality_class' => $this->option('quality'), 'strategy_version' => $this->option('strategy-version'),
            'from' => $this->option('from') ? new \DateTimeImmutable((string) $this->option('from')) : null,
            'to' => $this->option('to') ? new \DateTimeImmutable((string) $this->option('to')) : null,
        ], fn ($value) => $value !== null && $value !== false);
        $this->line(json_encode($statistics->calculate($account, $filters)->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
