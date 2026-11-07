<?php

declare(strict_types=1);

namespace App\Exchanges;

use App\Exchanges\Contracts\ExchangeAdapter;
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
        return $this->adapters[$id] ?? throw new InvalidArgumentException("Unsupported live exchange adapter for '{$id}'. Dedicated adapter code required.");
    }

    public function hasAdapter(string $id): bool
    {
        return isset($this->adapters[$id]);
    }

    public function register(string $id, ExchangeAdapter $adapter): void
    {
        $this->adapters[$id] = $adapter;
    }

    /** @return list<string> */
    public function supported(): array
    {
        return array_keys($this->adapters);
    }

    /** Registration is independent of public-adapter and PAPER execution readiness. */
    public function registeredIds(): array
    {
        $catalog = Schema::hasTable('exchanges') ? DB::table('exchanges')->orderBy('id')->pluck('id')->all() : [];

        return array_values(array_unique(array_merge((array) config('exchanges.enabled', []), $catalog)));
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
