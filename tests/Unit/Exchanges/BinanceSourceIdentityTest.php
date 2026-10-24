<?php

declare(strict_types=1);

namespace Tests\Unit\Exchanges;

use App\Exchanges\Binance\BinanceSourceIdentity;
use DomainException;
use PHPUnit\Framework\TestCase;

final class BinanceSourceIdentityTest extends TestCase
{
    public function test_equivalent_exact_decimals_do_not_cause_false_conflicts(): void
    {
        $stored = (object) [
            'open' => '100.000000000000', 'high' => '105.000000000000',
            'low' => '99.000000000000', 'close' => '102.000000000000',
            'volume' => '10.000000000000', 'turnover' => '1010.000000000000',
            'is_closed' => true,
        ];
        BinanceSourceIdentity::assertSameCandle($stored, [
            'open' => '100.0', 'high' => '105', 'low' => '99', 'close' => '102.000',
            'volume' => '10', 'turnover' => '1010',
        ]);
        BinanceSourceIdentity::assertSameFunding((object) [
            'rate' => '-0.000100000000', 'mark_price' => '25000.000000000000',
        ], ['rate' => '-0.0001', 'mark_price' => '25000']);
        self::assertTrue(true);
    }

    public function test_replayed_conflicting_candle_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Conflicting immutable Binance close observation.');
        BinanceSourceIdentity::assertSameCandle((object) [
            'open' => '100', 'high' => '105', 'low' => '99', 'close' => '102',
            'volume' => '10', 'turnover' => '1010', 'is_closed' => true,
        ], [
            'open' => '100', 'high' => '105', 'low' => '99', 'close' => '102.000000000001',
            'volume' => '10', 'turnover' => '1010',
        ]);
    }

    public function test_replayed_conflicting_funding_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        BinanceSourceIdentity::assertSameFunding((object) ['rate' => '0.0001', 'mark_price' => '100'],
            ['rate' => '0.0002', 'mark_price' => '100']);
    }

    public function test_nonrepresentable_fraction_fails_before_persistence(): void
    {
        $this->expectException(DomainException::class);
        BinanceSourceIdentity::assertPersistable(['price' => '100.0000000000001']);
    }
}
