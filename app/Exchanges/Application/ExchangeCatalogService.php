<?php

declare(strict_types=1);

namespace App\Exchanges\Application;

use App\Exchanges\Domain\ExchangeLifecycleStatus;
use App\Exchanges\Domain\ExchangeManifest;
use App\Exchanges\Domain\ExchangeManifestValidator;
use App\Exchanges\ExchangeRegistry;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ExchangeCatalogService
{
    public function __construct(
        private ExchangeRegistry $registry,
        private ExchangeCapabilityValidator $validator,
        private ExchangeStrategyCompatibilityService $compatibility,
    ) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return ExchangeRecord::query()->orderBy('id')->get()->map(function (ExchangeRecord $exchange): array {
            $candleStats = CandleRecord::query()
                ->where('exchange', $exchange->id)
                ->selectRaw('count(*) as count, count(distinct symbol) as symbols, min(open_time) as earliest, max(open_time) as latest')
                ->first();

            $eval = $this->validator->evaluate($exchange);

            return [
                'id' => $exchange->id,
                'name' => $exchange->name,
                'market_type' => $exchange->market_type,
                'description' => $exchange->description,
                'lifecycle_status' => $exchange->lifecycle_status,
                'lifecycle_display' => ExchangeLifecycleStatus::tryFrom($exchange->lifecycle_status)?->displayName() ?? $exchange->lifecycle_status,
                'capabilities' => $exchange->capabilities ?? [],
                'cost_model' => $exchange->cost_model,
                'paper_execution_assumptions' => $exchange->paper_execution_assumptions,
                'paper_eligible' => $exchange->paper_eligible,
                'paper_approved' => $exchange->paper_approved,
                'paper_approved_at' => $exchange->paper_approved_at?->format(DATE_ATOM),
                'has_live_adapter' => $this->registry->hasAdapter($exchange->id),
                'candle_count' => (int) ($candleStats->count ?? 0),
                'distinct_symbols' => (int) ($candleStats->symbols ?? 0),
                'earliest_candle' => $candleStats->earliest ? (string) $candleStats->earliest : null,
                'latest_candle' => $candleStats->latest ? (string) $candleStats->latest : null,
                'eligibility' => $eval,
                'created_at' => $exchange->created_at?->format(DATE_ATOM),
                'updated_at' => $exchange->updated_at?->format(DATE_ATOM),
            ];
        })->all();
    }

    public function find(string $id): ?ExchangeRecord
    {
        return ExchangeRecord::query()->find(strtolower(trim($id)));
    }

    public function get(string $id): ExchangeRecord
    {
        return $this->find($id) ?? throw new InvalidArgumentException("Exchange '{$id}' is not registered.");
    }

    /**
     * Declarative onboarding of a new exchange via versioned manifest.
     * Enforces capability validation, persists metadata/cost model, and automatically provisions
     * independent PAPER accounts across all registered standalone strategies.
     */
    public function onboardManifest(ExchangeManifest|array $manifest): ExchangeRecord
    {
        $manifestObj = $manifest instanceof ExchangeManifest ? $manifest : ExchangeManifest::fromArray($manifest);
        (new ExchangeManifestValidator)->assertValid($manifestObj);

        $id = $manifestObj->id;
        if (ExchangeRecord::query()->where('id', $id)->exists()) {
            throw new InvalidArgumentException("Exchange '{$id}' is already registered.");
        }

        // Test connectivity if baseUrl is configured
        $initialLifecycle = ExchangeLifecycleStatus::OfflineDataSource->value;
        if ($manifestObj->baseUrl !== null) {
            $conn = $this->validator->testConnectivity($id);
            if ($conn['reachable']) {
                $initialLifecycle = ExchangeLifecycleStatus::ConnectedPublicData->value;
            }
        }

        return DB::transaction(function () use ($manifestObj, $id, $initialLifecycle): ExchangeRecord {
            $data = $manifestObj->toArray();
            $created = ExchangeRecord::query()->create([
                'id' => $id,
                'name' => $manifestObj->name,
                'market_type' => $manifestObj->marketType,
                'description' => $manifestObj->description,
                'lifecycle_status' => $initialLifecycle,
                'capabilities' => $data['capabilities'],
                'cost_model' => $data['cost_model'],
                'paper_execution_assumptions' => $data['paper_execution_assumptions'],
                'paper_eligible' => false,
                'paper_approved' => false,
                'paper_approved_at' => null,
                'metadata' => array_merge($data['metadata'], [
                    'manifest_version' => $manifestObj->manifestVersion,
                    'onboarded_declaratively' => true,
                    'onboarded_at' => now('UTC')->toISOString(),
                ]),
            ]);

            $this->compatibility->reconcileExchange($created->id);
            // Topology scaling: automatically provision 1 independent paper account for every strategy
            app(PaperTopologySynchronizer::class)->sync(true);

            return $created;
        }, attempts: 3);
    }

    public function register(array $data): ExchangeRecord
    {
        $id = strtolower(trim((string) ($data['id'] ?? '')));
        if (! preg_match('/^[a-z0-9_-]{2,32}$/D', $id)) {
            throw new InvalidArgumentException('Exchange identifier must be 2-32 characters of lowercase letters, digits, underscores, or hyphens.');
        }

        if (ExchangeRecord::query()->where('id', $id)->exists()) {
            throw new InvalidArgumentException("Exchange '{$id}' is already registered.");
        }

        $name = trim((string) ($data['name'] ?? ucfirst($id)));
        if ($name === '') {
            throw new InvalidArgumentException('Exchange name cannot be empty.');
        }

        $marketType = (string) ($data['market_type'] ?? 'linear_perpetual');
        if (! in_array($marketType, ['linear_perpetual', 'spot', 'inverse_perpetual'], true)) {
            throw new InvalidArgumentException("Invalid market type '{$marketType}'.");
        }

        $capabilities = array_merge([
            'has_candles' => false,
            'has_funding' => false,
            'has_open_interest' => false,
            'has_live_adapter' => $this->registry->hasAdapter($id),
            'adapter_class' => null,
            'supported_timeframes' => ['5', '15', '30', '60'],
            'price_precision' => null,
            'qty_precision' => null,
            'min_qty' => null,
            'min_notional' => null,
        ], (array) ($data['capabilities'] ?? []));

        $costModel = $data['cost_model'] ?? null;

        return DB::transaction(function () use ($id, $name, $marketType, $data, $capabilities, $costModel): ExchangeRecord {
            $created = ExchangeRecord::query()->create([
                'id' => $id,
                'name' => $name,
                'market_type' => $marketType,
                'description' => $data['description'] ?? null,
                'lifecycle_status' => ExchangeLifecycleStatus::OfflineDataSource->value,
                'capabilities' => $capabilities,
                'cost_model' => $costModel,
                'paper_execution_assumptions' => $data['paper_execution_assumptions'] ?? [
                    'execution_model' => 'NEXT_CANDLE_OPEN',
                    'ambiguous_execution_policy' => 'FLAG_AND_HOLD',
                ],
                'paper_eligible' => false,
                'paper_approved' => false,
                'paper_approved_at' => null,
                'metadata' => ['onboarded_at' => now('UTC')->toISOString()],
            ]);

            $this->compatibility->reconcileExchange($created->id);
            app(PaperTopologySynchronizer::class)->sync(true);

            return $created;
        }, attempts: 3);
    }

    public function updateLifecycle(string $id, string $status): ExchangeRecord
    {
        $exchange = $this->get($id);
        $targetStatus = ExchangeLifecycleStatus::tryFrom($status)
            ?? throw new InvalidArgumentException("Invalid lifecycle status '{$status}'.");

        if ($targetStatus === ExchangeLifecycleStatus::ActivePaper) {
            throw new DomainException('Cannot directly set ACTIVE_PAPER lifecycle status; explicit owner approval via approvePaper is required.');
        }

        if ($targetStatus === ExchangeLifecycleStatus::ResearchEnabled) {
            $hasCandles = CandleRecord::query()->where('exchange', $exchange->id)->exists();
            if (! $hasCandles) {
                throw new DomainException("Cannot enable research for '{$exchange->id}': at least one symbol with historical candle data must be imported first.");
            }
        }

        if ($targetStatus === ExchangeLifecycleStatus::PaperEligible) {
            $eval = $this->validator->evaluate($exchange);
            if (! $eval['eligible']) {
                throw new DomainException('Cannot mark exchange as PAPER_ELIGIBLE: '.implode('; ', $eval['reasons_missing']));
            }
            $exchange->paper_eligible = true;
        }

        $exchange->lifecycle_status = $targetStatus->value;
        $exchange->save();

        $fresh = $exchange->fresh();
        $this->compatibility->reconcileExchange($fresh->id);

        return $fresh;
    }

    public function approvePaper(string $id, bool $ownerApproved, string $reference = 'OWNER_APPROVAL'): ExchangeRecord
    {
        if (! $ownerApproved) {
            throw new DomainException('Explicit owner approval boolean is required for PAPER activation.');
        }

        $exchange = $this->get($id);
        $eval = $this->validator->evaluate($exchange);
        if (! $eval['eligible']) {
            throw new DomainException('Cannot approve PAPER execution: exchange fails technical eligibility: '.implode('; ', $eval['reasons_missing']));
        }

        $now = now('UTC');

        return DB::transaction(function () use ($exchange, $now, $reference, $id): ExchangeRecord {
            $exchange->update([
                'paper_eligible' => true,
                'paper_approved' => true,
                'paper_approved_at' => $now,
                'lifecycle_status' => ExchangeLifecycleStatus::ActivePaper->value,
                'metadata' => array_merge((array) ($exchange->metadata ?? []), [
                    'approved_by_owner_at' => $now->toISOString(),
                    'approval_reference' => $reference,
                ]),
            ]);

            // Activate primary paper accounts for this exchange
            PaperAccountRecord::query()
                ->where('exchange_id', $id)
                ->where('is_primary', true)
                ->where('status', 'BLOCKED')
                ->update([
                    'status' => 'ACTIVE',
                    'updated_at' => $now,
                ]);

            $fresh = $exchange->fresh();
            $this->compatibility->reconcileExchange($fresh->id);
            app(PaperTopologySynchronizer::class)->sync(true);

            return $fresh;
        }, attempts: 3);
    }

    /**
     * Deactivate an exchange, transitioning its lifecycle to DEACTIVATED and blocking its paper accounts.
     * All historical financial records, positions, equity snapshots, and accounts are 100% preserved.
     */
    public function deactivate(string $id, string $reason = 'OPERATOR_DEACTIVATED'): ExchangeRecord
    {
        $exchange = $this->get($id);
        $now = now('UTC');

        return DB::transaction(function () use ($exchange, $now, $reason, $id): ExchangeRecord {
            $exchange->update([
                'paper_approved' => false,
                'lifecycle_status' => ExchangeLifecycleStatus::Deactivated->value,
                'metadata' => array_merge((array) ($exchange->metadata ?? []), [
                    'deactivated_at' => $now->toISOString(),
                    'deactivation_reason' => $reason,
                ]),
            ]);

            // Block active paper accounts for this exchange to prevent new orders/executions
            PaperAccountRecord::query()
                ->where('exchange_id', $id)
                ->where('status', 'ACTIVE')
                ->update([
                    'status' => 'BLOCKED',
                    'updated_at' => $now,
                ]);

            $fresh = $exchange->fresh();
            $this->compatibility->reconcileExchange($fresh->id);

            return $fresh;
        }, attempts: 3);
    }

    /**
     * Pause an exchange, transitioning its lifecycle to PAUSED and temporarily blocking paper accounts.
     * All historical records and active positions remain serviced while new entries are blocked.
     */
    public function pause(string $id, string $reason = 'OPERATOR_PAUSED'): ExchangeRecord
    {
        $exchange = $this->get($id);
        $now = now('UTC');

        return DB::transaction(function () use ($exchange, $now, $reason, $id): ExchangeRecord {
            $exchange->update([
                'paper_approved' => false,
                'lifecycle_status' => ExchangeLifecycleStatus::Paused->value,
                'metadata' => array_merge((array) ($exchange->metadata ?? []), [
                    'paused_at' => $now->toISOString(),
                    'pause_reason' => $reason,
                ]),
            ]);

            PaperAccountRecord::query()
                ->where('exchange_id', $id)
                ->where('status', 'ACTIVE')
                ->update([
                    'status' => 'BLOCKED',
                    'updated_at' => $now,
                ]);

            $fresh = $exchange->fresh();
            $this->compatibility->reconcileExchange($fresh->id);

            return $fresh;
        }, attempts: 3);
    }
}
