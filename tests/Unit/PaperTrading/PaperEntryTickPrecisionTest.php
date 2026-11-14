<?php

declare(strict_types=1);

namespace Tests\Unit\PaperTrading;

use App\PaperTrading\Application\PaperExecutionEngine;
use App\PaperTrading\Domain\MarketBar;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaperEntryTickPrecisionTest extends TestCase
{
    public static function entryTicks(): iterable
    {
        yield 'BUY rounds adverse after slippage' => ['BUY', '0.1', '101', '101.0202', '101.1'];
        yield 'SELL rounds adverse after slippage' => ['SELL', '0.1', '100', '99.98', '99.9'];
        yield 'BUY finer tick' => ['BUY', '0.01', '101', '101.0202', '101.03'];
        yield 'SELL exact tick unchanged' => ['SELL', '0.01', '100', '99.98', '99.98'];
    }

    #[DataProvider('entryTicks')]
    public function test_tick_rounding_preserves_reference_and_records_actual_slippage(string $side, string $tick, string $reference, string $slipped, string $expected): void
    {
        $engine = new PaperExecutionEngine(require dirname(__DIR__, 3).'/config/paper.php');
        $quote = $engine->entry($side, '100', '101', $side === 'BUY' ? '90' : '110', '120', '130',
            new DateTimeImmutable('2026-11-09T12:00:00Z'), new DateTimeImmutable('2026-11-09T12:30:00Z'),
            new MarketBar(new DateTimeImmutable('2026-11-09T12:00:00Z'), new DateTimeImmutable('2026-11-09T12:05:00Z'), '100', '105', '95', '102', true),
            'bybit', $tick);
        self::assertNotNull($quote);
        self::assertTrue(BigDecimal::of($quote->price)->isEqualTo($expected));
        self::assertSame($reference, $quote->referencePrice);
        self::assertTrue(BigDecimal::of($quote->price)->remainder($tick)->isZero());
        self::assertTrue(BigDecimal::of($quote->slippage)->isEqualTo(BigDecimal::of($expected)->minus($reference)->abs()));
        self::assertTrue($side === 'BUY' ? BigDecimal::of($quote->price)->isGreaterThanOrEqualTo($slipped) : BigDecimal::of($quote->price)->isLessThanOrEqualTo($slipped));
    }

    public static function invalidTicks(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-0.1'];
        yield 'nonfinite' => ['NaN'];
    }

    #[DataProvider('invalidTicks')]
    public function test_invalid_tick_cannot_produce_a_fill(string $tick): void
    {
        $engine = new PaperExecutionEngine(require dirname(__DIR__, 3).'/config/paper.php');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('invalid_instrument_constraints');
        $engine->entry('BUY', '100', '101', '90', '120', '130', new DateTimeImmutable('2026-11-09T12:00:00Z'),
            new DateTimeImmutable('2026-11-09T12:30:00Z'),
            new MarketBar(new DateTimeImmutable('2026-11-09T12:00:00Z'), new DateTimeImmutable('2026-11-09T12:05:00Z'), '100', '105', '95', '102', true),
            'bybit', $tick);
    }
}
