<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\Research\Application\ResearchReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ResearchReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_comprehensive_research_report(): void
    {
        $this->app->make(PaperAccountService::class)->default();
        $service = $this->app->make(ResearchReportService::class);

        $report = $service->generateReport('daily');

        self::assertSame('DAILY_QUANT_RESEARCH_AUDIT', $report['report_type']);
        self::assertArrayHasKey('manifest', $report);
        self::assertNotEmpty($report['manifest']['content_sha256']);
        self::assertSame('PAPER_TRADING_RESEARCH_ONLY', $report['manifest']['operating_mode']);
        self::assertArrayHasKey('system_freshness', $report);
        self::assertArrayHasKey('strategy_funnel_health', $report);
        self::assertArrayHasKey('strategy_degradation_watchlist', $report);
        self::assertArrayHasKey('counterfactual_filter_effectiveness', $report);
        self::assertArrayHasKey('probability_calibration', $report);
        self::assertArrayHasKey('portfolio_correlation_and_risk', $report);
        self::assertArrayHasKey('actionable_recommendations', $report);
    }
}
