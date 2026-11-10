<?php

declare(strict_types=1);

namespace App\Exchanges\Console;

use App\Exchanges\Application\ExchangeCapabilityValidator;
use App\Exchanges\Application\ExchangeCatalogService;
use Illuminate\Console\Command;

final class ValidateExchangeCommand extends Command
{
    protected $signature = 'exchange:validate
        {id : Exchange identifier}
        {--connectivity : Perform active public connectivity probe}';

    protected $description = 'Audit technical capability, readiness and connectivity of an exchange';

    public function handle(ExchangeCatalogService $catalog, ExchangeCapabilityValidator $validator): int
    {
        $id = (string) $this->argument('id');
        $exchange = $catalog->find($id);

        if ($exchange === null) {
            $this->error("Exchange '{$id}' is not registered.");

            return self::FAILURE;
        }

        $this->info("Validating exchange '{$exchange->id}' ({$exchange->name}) [Status: {$exchange->lifecycle_status}]...");

        $eval = $validator->evaluate($exchange);
        $this->line('Eligibility: '.($eval['eligible'] ? '<info>ELIGIBLE</info>' : '<comment>INELIGIBLE</comment>'));

        $checks = [];
        foreach ($eval['checks'] as $check => $passed) {
            $checks[] = [$check, $passed ? 'PASS' : 'FAIL'];
        }
        $this->table(['Capability Check', 'Result'], $checks);

        if (! empty($eval['reasons_missing'])) {
            $this->warn('Missing requirements:');
            foreach ($eval['reasons_missing'] as $reason) {
                $this->line(" - {$reason}");
            }
        }

        if ($this->option('connectivity')) {
            $this->info("Probing live network connectivity for '{$id}'...");
            $conn = $validator->testConnectivity($exchange);
            $this->table(
                ['Reachable', 'Latency (ms)', 'Probe Status', 'Error'],
                [[$conn['reachable'] ? 'YES' : 'NO', $conn['latency_ms'] ?? 'N/A', $conn['probe_status'], $conn['error'] ?? 'None']]
            );
        }

        return $eval['eligible'] ? self::SUCCESS : self::FAILURE;
    }
}
