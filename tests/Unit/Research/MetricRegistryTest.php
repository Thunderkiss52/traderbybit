<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\MetricRegistryService;
use App\Research\Domain\MetricRegistry;
use Tests\TestCase;

final class MetricRegistryTest extends TestCase
{
    public function test_metric_registry_contains_mandatory_economic_metrics(): void
    {
        $all = MetricRegistry::all();

        $requiredKeys = [
            'gross_pnl',
            'fees',
            'signed_funding',
            'net_pnl',
            'slippage_price_delta',
            'slippage_drag_attribution',
            'profit_factor',
            'win_rate',
            'payoff_ratio',
            'twae',
            'maximum_drawdown_percent',
            'average_margin_used_pct',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $all, "Metric [{$key}] must be defined in MetricRegistry.");
            $this->assertNotEmpty($all[$key]['unit'], "Metric [{$key}] must have an explicit unit.");
            $this->assertNotEmpty($all[$key]['accounting_scope'], "Metric [{$key}] must declare accounting_scope.");
            $this->assertNotEmpty($all[$key]['formula'], "Metric [{$key}] must declare formula.");
            $this->assertNotEmpty($all[$key]['provenance'], "Metric [{$key}] must declare provenance.");
        }
    }

    public function test_slippage_is_defined_as_price_delta_and_prohibits_second_deduction(): void
    {
        $slippageDelta = MetricRegistry::get('slippage_price_delta');
        $this->assertNotNull($slippageDelta);
        $this->assertSame('USDT_PER_CONTRACT_PRICE_DELTA', $slippageDelta['unit']);
        $this->assertTrue($slippageDelta['double_deduction_prohibited']);

        $slippageDrag = MetricRegistry::get('slippage_drag_attribution');
        $this->assertNotNull($slippageDrag);
        $this->assertSame('USDT', $slippageDrag['unit']);
        $this->assertSame('WINDOW_FLOW', $slippageDrag['accounting_scope']);
        $this->assertTrue($slippageDrag['double_deduction_prohibited']);
    }

    public function test_profit_factor_and_payoff_ratio_require_null_on_zero_losses(): void
    {
        $pf = MetricRegistry::get('profit_factor');
        $this->assertNotNull($pf);
        $this->assertStringContainsString('UNDEFINED_NO_LOSSES', $pf['denominator_rule']);

        $payoff = MetricRegistry::get('payoff_ratio');
        $this->assertNotNull($payoff);
        $this->assertStringContainsString('UNDEFINED_NO_LOSSES', $payoff['denominator_rule']);
    }

    public function test_margin_metric_is_explicitly_flagged_as_not_recorded_in_ledger(): void
    {
        $margin = MetricRegistry::get('average_margin_used_pct');
        $this->assertNotNull($margin);
        $this->assertStringContainsString('NOT_RECORDED_IN_LEDGER', $margin['missing_data_semantics']);
        $this->assertSame('NOT_APPLICABLE', $margin['provenance']);
    }

    public function test_metric_registry_service_catalog_enforces_scope_prohibitions(): void
    {
        $service = new MetricRegistryService;
        $catalog = $service->getCatalog();

        $this->assertSame(MetricRegistry::VERSION, $catalog['version']);
        $this->assertArrayHasKey('FULL_LIFECYCLE', $catalog['accounting_scope_rules']);
        $this->assertArrayHasKey('WINDOW_FLOW', $catalog['accounting_scope_rules']);

        $prohibitions = implode(' ', $catalog['prohibitions']);
        $this->assertStringContainsString('Do not aggregate FULL_LIFECYCLE and WINDOW_FLOW', $prohibitions);
        $this->assertStringContainsString('Do not deduct slippage from Net PnL a second time', $prohibitions);
        $this->assertStringContainsString('Do not use 0 as a fallback for missing denominators', $prohibitions);
    }
}
