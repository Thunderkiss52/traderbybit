<?php

declare(strict_types=1);

namespace App\PaperTrading\Console;

use App\PaperTrading\Application\PaperProcessingService;
use DateTimeImmutable;
use Illuminate\Console\Command;

final class ProcessPaperTradingCommand extends Command
{
    protected $signature = 'paper:process {--account=} {--symbol=} {--at= : Historical UTC ISO-8601 cutoff}';

    protected $description = 'Process deterministic PAPER signal and position events';

    public function handle(PaperProcessingService $service): int
    {
        try {
            $at = $this->option('at') ? new DateTimeImmutable((string) $this->option('at')) : null;
            if ($at !== null && $at->getOffset() !== 0) {
                throw new \InvalidArgumentException('--at must be UTC.');
            }
            $result = $service->process($this->option('account') ?: null, $this->option('symbol') ? strtoupper((string) $this->option('symbol')) : null, $at);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
