<?php

declare(strict_types=1);

namespace App\Exchanges\Console;

use App\Exchanges\Application\ExchangeCatalogService;
use App\Exchanges\Domain\ExchangeManifest;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class OnboardExchangeCommand extends Command
{
    protected $signature = 'exchange:onboard
        {manifest : Path to JSON manifest file or inline JSON payload}
        {--approve-paper : Automatically attempt paper approval if technical eligibility passes}';

    protected $description = 'Declarative onboarding of a new exchange using a versioned exchange manifest';

    public function handle(ExchangeCatalogService $catalog): int
    {
        $manifestInput = (string) $this->argument('manifest');
        $this->info('Starting declarative exchange onboarding...');

        try {
            if (file_exists($manifestInput)) {
                $content = file_get_contents($manifestInput);
                if ($content === false) {
                    throw new InvalidArgumentException("Unable to read manifest file at '{$manifestInput}'.");
                }
                $manifest = ExchangeManifest::fromJson($content);
            } else {
                $manifest = ExchangeManifest::fromJson($manifestInput);
            }

            $this->line("Parsed manifest for exchange: {$manifest->id} ({$manifest->name}) [Version {$manifest->manifestVersion}]");

            $exchange = $catalog->onboardManifest($manifest);
            $this->info("Exchange '{$exchange->id}' onboarded successfully.");
            $this->line("Lifecycle Status: {$exchange->lifecycle_status}");
            $this->line('Independent PAPER accounts automatically provisioned across all registered strategies.');

            if ($this->option('approve-paper')) {
                $this->line('Attempting PAPER approval...');
                try {
                    $approved = $catalog->approvePaper($exchange->id, true, 'CLI_ONBOARD_APPROVAL');
                    $this->info("PAPER trading approved! Lifecycle status: {$approved->lifecycle_status}");
                } catch (Throwable $e) {
                    $this->warn("PAPER approval skipped or rejected: {$e->getMessage()}");
                }
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Onboarding failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
