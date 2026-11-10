<?php

declare(strict_types=1);

namespace App\Exchanges;

use App\Exchanges\Contracts\ExchangeAdapter;
use App\Exchanges\Infrastructure\DeclarativeExchangeAdapter;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

final class ExchangeRegistry
{
    /** @param array<string, ExchangeAdapter> $adapters */
    public function __construct(private array $adapters) {}

    public function resolve(string $id): ExchangeAdapter
    {
        if (isset($this->adapters[$id])) {
            return $this->adapters[$id];
        }

        // Dynamically discover adapter from database or declarative configuration
        try {
            $record = ExchangeRecord::query()->find($id);
            if ($record !== null) {
                $capabilities = (array) ($record->capabilities ?? []);
                $adapterClass = $capabilities['adapter_class'] ?? null;
                if ($adapterClass && class_exists($adapterClass)) {
                    $adapter = app($adapterClass);
                    if ($adapter instanceof ExchangeAdapter) {
                        $this->adapters[$id] = $adapter;

                        return $adapter;
                    }
                }

                // If baseUrl or endpoints are present, instantiate DeclarativeExchangeAdapter
                $baseUrl = $capabilities['base_url'] ?? config("exchanges.{$id}.base_url");
                if ($baseUrl) {
                    $adapter = new DeclarativeExchangeAdapter(
                        exchangeId: $record->id,
                        exchangeName: $record->name,
                        exchangeBaseUrl: (string) $baseUrl,
                        config: $record->toArray(),
                    );
                    $this->adapters[$id] = $adapter;

                    return $adapter;
                }
            }
        } catch (Throwable) {
            // Fall through to exception
        }

        throw new InvalidArgumentException("Unsupported live exchange adapter for '{$id}'. Dedicated adapter code required.");
    }

    public function hasAdapter(string $id): bool
    {
        if (isset($this->adapters[$id])) {
            return true;
        }

        try {
            $record = ExchangeRecord::query()->find($id);
            if ($record !== null) {
                $capabilities = (array) ($record->capabilities ?? []);
                $adapterClass = $capabilities['adapter_class'] ?? null;
                if ($adapterClass && class_exists($adapterClass)) {
                    return is_subclass_of($adapterClass, ExchangeAdapter::class);
                }
                if (! empty($capabilities['base_url']) || config("exchanges.{$id}.base_url")) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    public function register(string $id, ExchangeAdapter $adapter): void
    {
        $this->adapters[$id] = $adapter;
    }

    public function unregister(string $id): void
    {
        unset($this->adapters[$id]);
    }

    /** @return list<string> */
    public function supported(): array
    {
        return array_keys($this->adapters);
    }

    /** Registration is independent of public-adapter and PAPER execution readiness. */
    public function registeredIds(): array
    {
        try {
            $catalog = Schema::hasTable('exchanges') ? DB::table('exchanges')->orderBy('id')->pluck('id')->all() : [];
        } catch (Throwable) {
            $catalog = [];
        }

        return array_values(array_unique(array_merge((array) config('exchanges.enabled', []), $catalog)));
    }

    /** Returns exchanges that are not in DEACTIVATED status. */
    public function activeIds(): array
    {
        try {
            if (! Schema::hasTable('exchanges')) {
                return (array) config('exchanges.enabled', []);
            }

            return DB::table('exchanges')
                ->where('lifecycle_status', '!=', 'DEACTIVATED')
                ->orderBy('id')
                ->pluck('id')
                ->all();
        } catch (Throwable) {
            return (array) config('exchanges.enabled', []);
        }
    }

    /** Returns exchanges that are approved for active PAPER execution. */
    public function paperApprovedIds(): array
    {
        try {
            if (! Schema::hasTable('exchanges')) {
                return ['bybit'];
            }

            return DB::table('exchanges')
                ->where('paper_approved', true)
                ->where('lifecycle_status', 'ACTIVE_PAPER')
                ->orderBy('id')
                ->pluck('id')
                ->all();
        } catch (Throwable) {
            return ['bybit'];
        }
    }

    public function isSupportedForBacktest(string $exchange): bool
    {
        if (isset($this->adapters[$exchange])) {
            return true;
        }

        try {
            return DB::table('exchanges')
                ->where('id', $exchange)
                ->whereIn('lifecycle_status', ['RESEARCH_ENABLED', 'PAPER_ELIGIBLE', 'ACTIVE_PAPER'])
                ->exists()
                || CandleRecord::query()->where('exchange', $exchange)->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
