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
        public ?int $pricePrecision = 2,
        public ?int $qtyPrecision = 3,
        public ?string $minQty = '0.001',
        public ?string $minNotional = '5.0',
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

        $costModel = (array) ($data['cost_model'] ?? []);
        if (empty($costModel)) {
            $costModel = [
                'version' => "{$id}-default-2026.1",
                'market' => (string) ($data['market_type'] ?? 'Linear Perpetuals'),
                'fee_tier' => 'Standard',
                'maker_rate' => (string) ($data['maker_rate'] ?? '0.0002'),
                'taker_rate' => (string) ($data['taker_rate'] ?? '0.0005'),
                'slippage_rate' => (string) ($data['slippage_rate'] ?? '0.0002'),
            ];
        }

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
            pricePrecision: isset($data['price_precision']) ? (int) $data['price_precision'] : (isset($capabilities['price_precision']) ? (int) $capabilities['price_precision'] : 2),
            qtyPrecision: isset($data['qty_precision']) ? (int) $data['qty_precision'] : (isset($capabilities['qty_precision']) ? (int) $capabilities['qty_precision'] : 3),
            minQty: isset($data['min_qty']) ? (string) $data['min_qty'] : (isset($capabilities['min_qty']) ? (string) $capabilities['min_qty'] : '0.001'),
            minNotional: isset($data['min_notional']) ? (string) $data['min_notional'] : (isset($capabilities['min_notional']) ? (string) $capabilities['min_notional'] : '5.0'),
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
                'has_candles' => true,
                'has_funding' => str_contains($this->marketType, 'perpetual'),
                'has_open_interest' => str_contains($this->marketType, 'perpetual'),
                'has_live_adapter' => $this->adapterClass !== null || $this->baseUrl !== null,
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
