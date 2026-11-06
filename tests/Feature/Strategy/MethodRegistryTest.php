<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\Exchanges\Domain\MarketType;
use App\Strategy\Domain\MethodCategory;
use App\Strategy\Domain\MethodImplementationStatus;
use App\Strategy\Domain\MethodType;
use App\Strategy\MethodRegistry;
use App\Strategy\Validation\MethodValidator;
use Tests\TestCase;

final class MethodRegistryTest extends TestCase
{
    private MethodRegistry $registry;

    private MethodValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new MethodRegistry;
        $this->validator = new MethodValidator($this->registry);
    }

    /**
     * Test that MethodRegistry contains exactly 50 methods with unique IDs.
     */
    public function test_registry_contains_exactly_fifty_methods(): void
    {
        $all = $this->registry->all();

        $this->assertCount(50, $all, 'Expected MethodRegistry to contain exactly 50 methods.');

        $ids = array_map(fn ($m) => $m->id, $all);
        $uniqueIds = array_unique($ids);

        $this->assertCount(50, $uniqueIds, 'Every method in MethodRegistry must have a unique ID.');
    }

    /**
     * Test that all 6 families are populated with exact method counts and full metadata.
     *
     * - TradingHorizons: 10
     * - PriceTechnical: 10
     * - VolatilityVolume: 8
     * - AutomationRelativeValue: 10
     * - EventsAlternativeData: 9
     * - TradeManagementRisk: 3
     */
    public function test_all_six_families_are_populated_with_full_metadata(): void
    {
        $expectedFamilyCounts = [
            MethodCategory::TradingHorizons->value => 10,
            MethodCategory::PriceTechnical->value => 10,
            MethodCategory::VolatilityVolume->value => 8,
            MethodCategory::AutomationRelativeValue->value => 10,
            MethodCategory::EventsAlternativeData->value => 9,
            MethodCategory::TradeManagementRisk->value => 3,
        ];

        foreach (MethodCategory::cases() as $category) {
            $methods = $this->registry->filter(category: $category);
            $expectedCount = $expectedFamilyCounts[$category->value];

            $this->assertCount(
                $expectedCount,
                $methods,
                "Family '{$category->value}' ({$category->displayName()}) must contain exactly {$expectedCount} methods."
            );
        }

        // Verify full metadata structure for every method
        foreach ($this->registry->all() as $method) {
            $this->assertNotEmpty($method->id, 'Method ID must not be empty.');
            $this->assertNotEmpty($method->name, "Method '{$method->id}' must have a name.");
            $this->assertNotEmpty($method->description, "Method '{$method->id}' must have a description.");
            $this->assertInstanceOf(MethodCategory::class, $method->category);
            $this->assertInstanceOf(MethodType::class, $method->type);
            $this->assertIsArray($method->parameters);
            $this->assertIsArray($method->requiredInputData);
            $this->assertIsArray($method->requiredIndicators);
            $this->assertNotEmpty($method->supportedMarketTypes, "Method '{$method->id}' must support at least one market type.");
            foreach ($method->supportedMarketTypes as $marketType) {
                $this->assertInstanceOf(MarketType::class, $marketType);
            }
            $this->assertNotEmpty($method->timeframeRequirements, "Method '{$method->id}' must specify timeframe requirements.");
            $this->assertIsArray($method->compatibleCategories);
            $this->assertIsArray($method->composabilityRules);
            $this->assertArrayHasKey('role', $method->composabilityRules, "Method '{$method->id}' composability rules must define a role.");
            $this->assertInstanceOf(MethodImplementationStatus::class, $method->status);

            // Serialization contract check
            $array = $method->toArray();
            $this->assertArrayHasKey('id', $array);
            $this->assertArrayHasKey('name', $array);
            $this->assertArrayHasKey('description', $array);
            $this->assertArrayHasKey('category', $array);
            $this->assertArrayHasKey('category_display', $array);
            $this->assertArrayHasKey('type', $array);
            $this->assertArrayHasKey('type_display', $array);
            $this->assertArrayHasKey('parameters', $array);
            $this->assertArrayHasKey('required_input_data', $array);
            $this->assertArrayHasKey('required_indicators', $array);
            $this->assertArrayHasKey('supported_market_types', $array);
            $this->assertArrayHasKey('timeframe_requirements', $array);
            $this->assertArrayHasKey('compatible_categories', $array);
            $this->assertArrayHasKey('composability_rules', $array);
            $this->assertArrayHasKey('status', $array);
            $this->assertArrayHasKey('status_display', $array);
            $this->assertArrayHasKey('blocker_reason', $array);
            $this->assertArrayHasKey('is_executable', $array);
            $this->assertArrayHasKey('research_eligible', $array);
            $this->assertArrayHasKey('shadow_eligible', $array);
            $this->assertArrayHasKey('paper_eligible', $array);
        }
    }

    /**
     * Test that methods with unavailable data dependencies have status BLOCKED_BY_DATA and explicit blocker_reason.
     */
    public function test_methods_with_unavailable_data_dependencies_have_blocked_by_data_status(): void
    {
        $blockedMethods = [
            'cross_exchange_arbitrage',
            'triangular_arbitrage',
            'market_making',
            'options_strategies',
            'news_trading',
            'on_chain_analysis',
            'exchange_flow_analysis',
            'sentiment_analysis',
        ];

        foreach ($blockedMethods as $id) {
            $method = $this->registry->get($id);

            $this->assertSame(
                MethodImplementationStatus::BlockedByData,
                $method->status,
                "Method '{$id}' must have status BLOCKED_BY_DATA."
            );
            $this->assertNotNull(
                $method->blockerReason,
                "Method '{$id}' must have an explicit blocker_reason."
            );
            $this->assertNotEmpty(
                trim($method->blockerReason),
                "Method '{$id}' blocker_reason must not be empty string."
            );
            $this->assertFalse(
                $method->paperEligible,
                "Method '{$id}' must not be paper_eligible while BLOCKED_BY_DATA."
            );
            $this->assertFalse(
                $method->status->isExecutable(),
                "Method '{$id}' with BLOCKED_BY_DATA must not be executable."
            );
        }

        // Verify total blocked count in catalog
        $allBlocked = $this->registry->filter(status: MethodImplementationStatus::BlockedByData);
        $this->assertCount(8, $allBlocked);
    }

    /**
     * Test filtering methods by category, type, and status.
     */
    public function test_filtering_by_category_type_and_status(): void
    {
        // Filter by category
        $priceTechnical = $this->registry->filter(category: MethodCategory::PriceTechnical);
        $this->assertCount(10, $priceTechnical);
        foreach ($priceTechnical as $m) {
            $this->assertSame(MethodCategory::PriceTechnical, $m->category);
        }

        // Filter by type
        $signals = $this->registry->filter(type: MethodType::Signal);
        $this->assertNotEmpty($signals);
        foreach ($signals as $m) {
            $this->assertSame(MethodType::Signal, $m->type);
        }

        // Filter by status
        $paperReady = $this->registry->filter(status: MethodImplementationStatus::PaperReady);
        $this->assertCount(24, $paperReady);
        foreach ($paperReady as $m) {
            $this->assertSame(MethodImplementationStatus::PaperReady, $m->status);
            $this->assertTrue($m->paperEligible);
        }

        // Multi-attribute filter: TradeManagementRisk + PositionManagement
        $riskPositionMgmt = $this->registry->filter(
            category: MethodCategory::TradeManagementRisk,
            type: MethodType::PositionManagement
        );
        $this->assertCount(3, $riskPositionMgmt);

        // Multi-attribute filter with zero matches
        $impossible = $this->registry->filter(
            category: MethodCategory::TradeManagementRisk,
            status: MethodImplementationStatus::BlockedByData
        );
        $this->assertCount(0, $impossible);
    }

    /**
     * Test MethodCatalogController HTTP endpoints (/v1/strategy-methods and /v1/strategy-methods/{id}).
     */
    public function test_method_catalog_controller_http_endpoints(): void
    {
        // 1. Index endpoint without filter
        $response = $this->getJson('/api/v1/strategy-methods');
        $response->assertOk()
            ->assertJsonPath('total', 50)
            ->assertJsonPath('summary.total_methods', 50)
            ->assertJsonPath('summary.by_category.price_technical', 10)
            ->assertJsonPath('summary.by_category.trade_management_risk', 3)
            ->assertJsonPath('summary.by_status.BLOCKED_BY_DATA', 8)
            ->assertJsonPath('summary.by_status.PAPER_READY', 24)
            ->assertJsonCount(50, 'data');

        // 2. Index endpoint with category filter
        $catResponse = $this->getJson('/api/v1/strategy-methods?category=price_technical');
        $catResponse->assertOk()
            ->assertJsonPath('total', 10)
            ->assertJsonCount(10, 'data');

        // 3. Index endpoint with status filter
        $statusResponse = $this->getJson('/api/v1/strategy-methods?status=BLOCKED_BY_DATA');
        $statusResponse->assertOk()
            ->assertJsonPath('total', 8)
            ->assertJsonCount(8, 'data');

        // 4. Show endpoint for existing PAPER_READY method
        $showResponse = $this->getJson('/api/v1/strategy-methods/trend_following');
        $showResponse->assertOk()
            ->assertJsonPath('data.id', 'trend_following')
            ->assertJsonPath('data.name', 'Trend Following')
            ->assertJsonPath('data.status', 'PAPER_READY')
            ->assertJsonPath('data.is_executable', true)
            ->assertJsonPath('data.paper_eligible', true);

        // 5. Show endpoint for existing BLOCKED_BY_DATA method
        $blockedResponse = $this->getJson('/api/v1/strategy-methods/cross_exchange_arbitrage');
        $blockedResponse->assertOk()
            ->assertJsonPath('data.id', 'cross_exchange_arbitrage')
            ->assertJsonPath('data.status', 'BLOCKED_BY_DATA')
            ->assertJsonPath('data.paper_eligible', false);
        $this->assertNotEmpty($blockedResponse->json('data.blocker_reason'));

        // 6. Show endpoint for unknown method (404)
        $notFoundResponse = $this->getJson('/api/v1/strategy-methods/non_existent_method_xyz');
        $notFoundResponse->assertNotFound()
            ->assertJsonPath('error.code', 'METHOD_NOT_FOUND');
    }

    /**
     * Test MethodValidator::validateComposition() with valid compositions.
     * E.g. Trend Following + RSI + Stop Loss, or Breakout + Partial Exit.
     */
    public function test_method_validator_valid_compositions(): void
    {
        // 1. Trend Following (signal) + RSI (signal) + Stop Loss (risk)
        $result = $this->validator->validateComposition(
            ['trend_following', 'rsi_trading', 'stop_loss']
        );

        $this->assertTrue($result['valid'], 'Expected Trend Following + RSI + Stop Loss to be valid.');
        $this->assertEmpty($result['errors']);
        $this->assertSame(MethodImplementationStatus::PaperReady->value, $result['eligible_lifecycle']);
        $this->assertTrue($this->validator->canPromoteToPaper(['trend_following', 'rsi_trading', 'stop_loss']));

        // 2. Style / Signal (Momentum) + Stop Loss (risk) -> PaperReady
        $resultMomentum = $this->validator->validateComposition(['momentum', 'stop_loss']);
        $this->assertTrue($resultMomentum['valid']);
        $this->assertEmpty($resultMomentum['errors']);
        $this->assertSame(MethodImplementationStatus::PaperReady->value, $resultMomentum['eligible_lifecycle']);

        // 3. Style (Swing Trading: ResearchReady) + Stop Loss (PaperReady) -> ResearchReady
        $resultStyle = $this->validator->validateComposition(['swing_trading', 'stop_loss']);
        $this->assertTrue($resultStyle['valid']);
        $this->assertEmpty($resultStyle['errors']);
        $this->assertSame(MethodImplementationStatus::ResearchReady->value, $resultStyle['eligible_lifecycle']);

        // 4. Signal (Breakout) + Partial Exit (risk) -> PaperReady
        $resultPartial = $this->validator->validateComposition(['breakout', 'partial_entry_exit']);
        $this->assertTrue($resultPartial['valid']);
        $this->assertEmpty($resultPartial['errors']);
        $this->assertSame(MethodImplementationStatus::PaperReady->value, $resultPartial['eligible_lifecycle']);

        // 5. Signal (MA Crossover) + Trailing Stop (risk) -> PaperReady
        $resultTrailing = $this->validator->validateComposition(['ma_crossover', 'trailing_stop']);
        $this->assertTrue($resultTrailing['valid']);
        $this->assertEmpty($resultTrailing['errors']);
        $this->assertSame(MethodImplementationStatus::PaperReady->value, $resultTrailing['eligible_lifecycle']);

        // 6. Valid parameters within defined bounds
        $resultParams = $this->validator->validateComposition(
            ['trend_following', 'stop_loss'],
            [
                'stop_loss' => ['stop_atr_multiplier' => 2.5],
                'trend_following' => [
                    'fast_ma_period' => 15,
                    'slow_ma_period' => 60,
                    'adx_threshold' => 25.0,
                ],
            ]
        );
        $this->assertTrue($resultParams['valid']);
        $this->assertEmpty($resultParams['errors']);
    }

    /**
     * Test MethodValidator::validateComposition() with invalid compositions.
     * E.g. only a filter with no signal, or missing stop loss, or parameter out of bounds.
     */
    public function test_method_validator_invalid_compositions(): void
    {
        // 1. Only a filter with no signal or style (e.g. Volume Spike + Stop Loss)
        $resultFilterOnly = $this->validator->validateComposition(['volume_spike', 'stop_loss']);
        $this->assertFalse($resultFilterOnly['valid']);
        $this->assertSame('INELIGIBLE', $resultFilterOnly['eligible_lifecycle']);
        $this->assertContains(
            'Strategy composition must contain at least one signal generator or style.',
            $resultFilterOnly['errors']
        );

        // 2. Missing stop loss or risk management (e.g. Trend Following + RSI)
        $resultNoRisk = $this->validator->validateComposition(['trend_following', 'rsi_trading']);
        $this->assertFalse($resultNoRisk['valid']);
        $this->assertSame('INELIGIBLE', $resultNoRisk['eligible_lifecycle']);
        $this->assertContains(
            'Strategy composition must have risk management (stop loss or partial exit).',
            $resultNoRisk['errors']
        );

        // 3. Empty composition
        $resultEmpty = $this->validator->validateComposition([]);
        $this->assertFalse($resultEmpty['valid']);
        $this->assertSame('INELIGIBLE', $resultEmpty['eligible_lifecycle']);
        $this->assertNotEmpty($resultEmpty['errors']);

        // 4. Unknown method ID
        $resultUnknown = $this->validator->validateComposition(['unknown_indicator_xyz', 'stop_loss']);
        $this->assertFalse($resultUnknown['valid']);
        $this->assertContains("Unknown trading method: 'unknown_indicator_xyz'.", $resultUnknown['errors']);

        // 5. Parameter bounds violated: above maximum
        // stop_atr_multiplier max is 10.0; passing 25.0
        $resultParamHigh = $this->validator->validateComposition(
            ['trend_following', 'stop_loss'],
            ['stop_loss' => ['stop_atr_multiplier' => 25.0]]
        );
        $this->assertFalse($resultParamHigh['valid']);
        $this->assertStringContainsString('stop_atr_multiplier', implode(' ', $resultParamHigh['errors']));
        $this->assertStringContainsString('at most 10', implode(' ', $resultParamHigh['errors']));

        // 6. Parameter bounds violated: below minimum
        // stop_atr_multiplier min is 0.5; passing 0.1
        $resultParamLow = $this->validator->validateComposition(
            ['trend_following', 'stop_loss'],
            ['stop_loss' => ['stop_atr_multiplier' => 0.1]]
        );
        $this->assertFalse($resultParamLow['valid']);
        $this->assertStringContainsString('stop_atr_multiplier', implode(' ', $resultParamLow['errors']));
        $this->assertStringContainsString('at least 0.5', implode(' ', $resultParamLow['errors']));

        // 7. Parameter type mismatch: non-numeric string for float
        $resultTypeMismatch = $this->validator->validateComposition(
            ['trend_following', 'stop_loss'],
            ['stop_loss' => ['stop_atr_multiplier' => 'invalid_string']]
        );
        $this->assertFalse($resultTypeMismatch['valid']);
        $this->assertStringContainsString('must be numeric', implode(' ', $resultTypeMismatch['errors']));
    }

    /**
     * Test that methods with BLOCKED_BY_DATA cannot be promoted to PAPER_READY.
     */
    public function test_methods_with_blocked_by_data_cannot_be_promoted_to_paper_ready(): void
    {
        // 1. Cross-exchange arbitrage + Stop Loss
        $result = $this->validator->validateComposition(['cross_exchange_arbitrage', 'stop_loss']);

        $this->assertNotSame(
            MethodImplementationStatus::PaperReady->value,
            $result['eligible_lifecycle'],
            'A composition containing a BLOCKED_BY_DATA method must never have PAPER_READY eligible lifecycle.'
        );
        $this->assertSame(
            MethodImplementationStatus::BlockedByData->value,
            $result['eligible_lifecycle']
        );
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('cross_exchange_arbitrage', implode(' ', $result['warnings']));
        $this->assertStringContainsString('cannot be promoted to PAPER_READY', implode(' ', $result['warnings']));
        $this->assertFalse(
            $this->validator->canPromoteToPaper(['cross_exchange_arbitrage', 'stop_loss']),
            'canPromoteToPaper() must return false for BLOCKED_BY_DATA methods.'
        );

        // 2. Trend following (paper ready) + News trading (BLOCKED_BY_DATA) + Stop loss
        $mixedResult = $this->validator->validateComposition(
            ['trend_following', 'news_trading', 'stop_loss']
        );
        $this->assertNotSame(
            MethodImplementationStatus::PaperReady->value,
            $mixedResult['eligible_lifecycle']
        );
        $this->assertSame(
            MethodImplementationStatus::BlockedByData->value,
            $mixedResult['eligible_lifecycle']
        );
        $this->assertFalse(
            $this->validator->canPromoteToPaper(['trend_following', 'news_trading', 'stop_loss'])
        );
    }
}
