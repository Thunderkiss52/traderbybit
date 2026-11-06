<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

use App\Exchanges\Domain\MarketType;

final readonly class TradingMethodDefinition
{
    /**
     * @param list<array{
     *     name: string,
     *     type: string,
     *     default: mixed,
     *     min?: float|int,
     *     max?: float|int,
     *     step?: float|int,
     *     options?: list<string>,
     *     description: string
     * }> $parameters
     * @param  list<string>  $requiredInputData
     * @param  list<string>  $requiredIndicators
     * @param  list<MarketType>  $supportedMarketTypes
     * @param  list<string>  $timeframeRequirements
     * @param  list<string>  $compatibleCategories
     * @param  array<string, mixed>  $composabilityRules
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public MethodCategory $category,
        public MethodType $type,
        public array $parameters,
        public array $requiredInputData,
        public array $requiredIndicators,
        public array $supportedMarketTypes,
        public array $timeframeRequirements,
        public array $compatibleCategories,
        public array $composabilityRules,
        public MethodImplementationStatus $status,
        public ?string $blockerReason = null,
        public bool $researchEligible = false,
        public bool $shadowEligible = false,
        public bool $paperEligible = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category->value,
            'category_display' => $this->category->displayName(),
            'type' => $this->type->value,
            'type_display' => $this->type->displayName(),
            'parameters' => $this->parameters,
            'required_input_data' => $this->requiredInputData,
            'required_indicators' => $this->requiredIndicators,
            'supported_market_types' => array_map(fn ($m) => $m->value, $this->supportedMarketTypes),
            'timeframe_requirements' => $this->timeframeRequirements,
            'compatible_categories' => $this->compatibleCategories,
            'composability_rules' => $this->composabilityRules,
            'status' => $this->status->value,
            'status_display' => $this->status->displayName(),
            'blocker_reason' => $this->blockerReason,
            'is_executable' => $this->status->isExecutable(),
            'research_eligible' => $this->researchEligible,
            'shadow_eligible' => $this->shadowEligible,
            'paper_eligible' => $this->paperEligible,
        ];
    }
}
