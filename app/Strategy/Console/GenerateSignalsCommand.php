<?php

declare(strict_types=1);

namespace App\Strategy\Console;

use App\Strategy\Application\SignalGenerator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class GenerateSignalsCommand extends Command
{
    protected $signature = 'signals:generate {--symbol=BTCUSDT} {--at= : UTC ISO-8601 decision time}';

    protected $description = 'Generate and persist a research-only strategy result';

    public function handle(SignalGenerator $generator): int
    {
        $symbol = strtoupper((string) $this->option('symbol'));
        if (! preg_match('/^[A-Z0-9]+$/', $symbol)) {
            $this->error('Symbol must be uppercase alphanumeric.');

            return self::INVALID;
        }
        try {
            $at = $this->option('at') === null ? null : new DateTimeImmutable((string) $this->option('at'), new DateTimeZone('UTC'));
            if ($at !== null && $at->getOffset() !== 0) {
                throw new InvalidArgumentException('The --at value must use UTC (Z or +00:00).');
            }
            $decision = $generator->generate($symbol, $at);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($decision->toContractArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
