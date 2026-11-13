<?php

declare(strict_types=1);

namespace App\Exchanges\Domain;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable value object representing a versioned exchange onboarding manifest.
 */
final readonly class ExchangeManifest implements JsonSerializable
{
    /**
     * @param  list<string>  $supportedTimeframes
     * @param  array<string, mixed>  $costModel
     * @param  array<string, mixed>  $paperExecutionAssumptions
     * @param  array<string, mixed>  $rateLimits
     * @param  array<string, mixed>  $endpoints
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $manifestVersion,
        public string $id,
        public string $name,
        public string $marketType,
        public ?string $description = null,
        public ?string $baseUrl = null,
        public ?string $adapterClass = null,
        public array $supportedTimeframes = ['5m', '15m', '30m', '1h'],
        public ?int $pricePrecision = null,
        public ?int $qtyPrecision = null,
        public ?string $minQty = null,
        public ?string $minNotional = null,
        public array $costModel = [],
        public array $paperExecutionAssumptions = [
            'execution_model' => 'NEXT_CANDLE_OPEN',
            'ambiguous_execution_policy' => 'FLAG_AND_HOLD',
        ],
        public array $rateLimits = [
            'requests_per_minute' => 600,
            'max_candles_per_request' => 500,
        ],
        public array $endpoints = [],
        public array $metadata = [],
        public array $assignedStrategies = [],
    ) {}

    /**
     * Build an ExchangeManifest from an associative array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = strtolower(trim((string) ($data['id'] ?? '')));
        if ($id === '') {
            throw new InvalidArgumentException('Exchange manifest must contain a non-empty "id" field.');
        }

        // No manufactured exchange fee schedules. Cost configuration is mandatory
        // and is checked by ExchangeManifestValidator before onboarding.
        $costModel = (array) ($data['cost_model'] ?? []);

        $capabilities = (array) ($data['capabilities'] ?? []);

        return new self(
            manifestVersion: (string) ($data['manifest_version'] ?? '1.0'),
            id: $id,
            name: trim((string) ($data['name'] ?? ucfirst($id))),
            marketType: (string) ($data['market_type'] ?? 'linear_perpetual'),
            description: isset($data['description']) ? (string) $data['description'] : null,
            baseUrl: isset($data['base_url']) ? rtrim((string) $data['base_url'], '/') : null,
            adapterClass: isset($data['adapter_class']) ? (string) $data['adapter_class'] : ($capabilities['adapter_class'] ?? null),
            supportedTimeframes: (array) ($data['supported_timeframes'] ?? ($capabilities['supported_timeframes'] ?? ['5m', '15m', '30m', '1h'])),
            pricePrecision: isset($data['price_precision']) ? (int) $data['price_precision'] : (isset($capabilities['price_precision']) ? (int) $capabilities['price_precision'] : null),
            qtyPrecision: isset($data['qty_precision']) ? (int) $data['qty_precision'] : (isset($capabilities['qty_precision']) ? (int) $capabilities['qty_precision'] : null),
            minQty: isset($data['min_qty']) ? (string) $data['min_qty'] : (isset($capabilities['min_qty']) ? (string) $capabilities['min_qty'] : null),
            minNotional: isset($data['min_notional']) ? (string) $data['min_notional'] : (isset($capabilities['min_notional']) ? (string) $capabilities['min_notional'] : null),
            costModel: $costModel,
            paperExecutionAssumptions: (array) ($data['paper_execution_assumptions'] ?? [
                'execution_model' => 'NEXT_CANDLE_OPEN',
                'ambiguous_execution_policy' => 'FLAG_AND_HOLD',
            ]),
            rateLimits: (array) ($data['rate_limits'] ?? [
                'requests_per_minute' => 600,
                'max_candles_per_request' => 500,
            ]),
            endpoints: (array) ($data['endpoints'] ?? []),
            metadata: (array) ($data['metadata'] ?? []),
            assignedStrategies: (array) ($data['assigned_strategies'] ?? []),
        );
    }

    /**
     * Build an ExchangeManifest from a JSON string.
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Malformed JSON in exchange manifest payload.');
        }

        return self::fromArray($decoded);
    }

    /**
     * Export manifest to array format compatible with database storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'manifest_version' => $this->manifestVersion,
            'id' => $this->id,
            'name' => $this->name,
            'market_type' => $this->marketType,
            'description' => $this->description,
            'base_url' => $this->baseUrl,
            'adapter_class' => $this->adapterClass,
            'capabilities' => [
                'has_candles' => $this->adapterClass !== null || isset($this->endpoints['candles']),
                'has_funding' => str_contains($this->marketType, 'perpetual') && ($this->adapterClass !== null || isset($this->endpoints['funding'])),
                'has_open_interest' => str_contains($this->marketType, 'perpetual') && ($this->adapterClass !== null || isset($this->endpoints['open_interest'])),
                'has_live_adapter' => $this->adapterClass !== null || ($this->baseUrl !== null && $this->endpoints !== []),
                'adapter_class' => $this->adapterClass,
                'supported_timeframes' => $this->supportedTimeframes,
                'price_precision' => $this->pricePrecision,
                'qty_precision' => $this->qtyPrecision,
                'min_qty' => $this->minQty,
                'min_notional' => $this->minNotional,
                'market_types' => [$this->marketType],
                'base_url' => $this->baseUrl,
                'rate_limits' => $this->rateLimits,
                'endpoints' => $this->endpoints,
            ],
            'cost_model' => $this->costModel,
            'paper_execution_assumptions' => $this->paperExecutionAssumptions,
            'assigned_strategies' => $this->assignedStrategies,
            'metadata' => array_merge($this->metadata, [
                'manifest_version' => $this->manifestVersion,
                'onboarded_at' => now('UTC')->toISOString(),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
