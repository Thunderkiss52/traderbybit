<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class MarketCommandsTest extends TestCase
{
    public function test_market_data_commands_are_registered(): void
    {
        self::assertArrayHasKey('market:sync-candles', Artisan::all());
        self::assertArrayHasKey('market:stream-candles', Artisan::all());
    }
}
