<?php

declare(strict_types=1);

namespace Tests\Unit\Exchanges;

use App\Exchanges\ExchangeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class ExchangeRegistryFailClosedTest extends TestCase
{
    public function test_missing_catalog_never_implies_active_or_paper_approved_exchange(): void
    {
        Schema::shouldReceive('hasTable')->with('exchanges')->twice()->andReturn(false);

        $registry = new ExchangeRegistry([]);
        self::assertSame([], $registry->activeIds());
        self::assertSame([], $registry->paperApprovedIds());
    }

    public function test_catalog_query_failure_does_not_resurrect_bybit_or_deactivated_exchanges(): void
    {
        Schema::shouldReceive('hasTable')->with('exchanges')->twice()->andReturn(true);
        DB::shouldReceive('table')->with('exchanges')->twice()
            ->andThrow(new RuntimeException('Test-only catalog outage'));

        $registry = new ExchangeRegistry([]);
        self::assertSame([], $registry->activeIds());
        self::assertSame([], $registry->paperApprovedIds());
    }
}
