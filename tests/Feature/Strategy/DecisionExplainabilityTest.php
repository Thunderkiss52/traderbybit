<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\Strategy\Application\StrategyEngine;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Application\StrategyObservationService;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DecisionExplainabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_engine_produces_structured_rule_evaluations_on_evaluation(): void
    {
        $engine = app(StrategyEngine::class);
        $inputs = app(StrategyInputFactory::class);

        // Even with synthetic or minimal input, evaluateRules produces structured array
        $input = $inputs->make('bybit', 'linear', 'BTCUSDT', new DateTimeImmutable('2026-11-08 12:00:00'));
        $decision = $engine->evaluate($input);

        $rules = $decision->indicatorSnapshot['rules'] ?? [];
        $this->assertIsArray($rules);
        $this->assertNotEmpty($rules);

        foreach ($rules as $rule) {
            $this->assertArrayHasKey('rule', $rule);
            $this->assertArrayHasKey('passed', $rule);
            $this->assertArrayHasKey('operator', $rule);
            $this->assertArrayHasKey('actual', $rule);
            $this->assertArrayHasKey('threshold', $rule);
            $this->assertArrayHasKey('blocking', $rule);
            $this->assertArrayHasKey('category', $rule);
            $this->assertIsBool($rule['passed']);
            $this->assertIsBool($rule['blocking']);
        }

        // Verify contract JSON array contains rules
        $contract = $decision->toContractArray();
        $this->assertArrayHasKey('rules', $contract);
        $this->assertEquals($rules, $contract['rules']);
    }

    public function test_strategy_observation_service_persists_rules_in_analysis(): void
    {
        $inputs = app(StrategyInputFactory::class);
        $service = app(StrategyObservationService::class);
        $engine = app(StrategyEngine::class);

        $at = new DateTimeImmutable('2026-11-08 12:00:00');
        $input = $inputs->make('bybit', 'linear', 'BTCUSDT', $at);
        $decision = $engine->evaluate($input);

        $service->observe('BTCUSDT', $at, 'bybit', 'linear');

        $record = StrategyObservationRecord::query()
            ->where('exchange', 'bybit')
            ->where('symbol', 'BTCUSDT')
            ->where('strategy_id', 'baseline')
            ->first();

        $this->assertNotNull($record);
        $analysis = $record->analysis;
        $this->assertIsArray($analysis);
        $this->assertArrayHasKey('rules', $analysis);
        $this->assertNotEmpty($analysis['rules']);

        $firstRule = $analysis['rules'][0];
        $this->assertSame('warmup_completeness', $firstRule['rule']);
        $this->assertSame('PRECONDITION', $firstRule['category']);
    }
}
