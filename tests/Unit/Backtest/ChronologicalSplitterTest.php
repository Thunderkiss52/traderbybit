<?php

declare(strict_types=1);

namespace Tests\Unit\Backtest;

use App\Backtest\Application\ChronologicalSplitter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ChronologicalSplitterTest extends TestCase
{
    public function test_split_is_chronological_and_never_shuffled(): void
    {
        $segments = (new ChronologicalSplitter)->split(new DateTimeImmutable('2025-01-01T00:00:00Z'), new DateTimeImmutable('2025-11-02T00:00:00Z'), ['train' => 60, 'validation' => 20, 'test' => 20]);
        self::assertSame('train', $segments[0]->kind);
        self::assertSame($segments[0]->to, $segments[1]->from);
        self::assertSame($segments[1]->to, $segments[2]->from);
        self::assertSame('2025-11-02T00:00:00+00:00', $segments[2]->to->format(DATE_ATOM));
    }

    public function test_walk_forward_uses_independent_six_two_two_month_windows(): void
    {
        $windows = (new ChronologicalSplitter)->walkForward(new DateTimeImmutable('2025-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T00:00:00Z'), ['train_months' => 6, 'validation_months' => 2, 'test_months' => 2, 'step_months' => 2]);
        self::assertCount(2, $windows);
        self::assertSame('2025-09-01', $windows[0][2]->from->format('Y-m-d'));
        self::assertSame('2025-03-01', $windows[1][0]->from->format('Y-m-d'));
        self::assertSame('2025-11-01', $windows[1][2]->from->format('Y-m-d'));
    }

    public function test_walk_forward_supports_minute_units(): void
    {
        $windows = (new ChronologicalSplitter)->walkForward(
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-01-01T01:00:00Z'),
            ['train_minutes' => 30, 'validation_minutes' => 15, 'test_minutes' => 15, 'step_minutes' => 15]
        );
        self::assertCount(1, $windows);
        self::assertSame('train', $windows[0][0]->kind);
        self::assertSame('validation', $windows[0][1]->kind);
        self::assertSame('test', $windows[0][2]->kind);
        self::assertSame('2026-01-01T00:00:00+00:00', $windows[0][0]->from->format(DATE_ATOM));
        self::assertSame('2026-01-01T00:30:00+00:00', $windows[0][0]->to->format(DATE_ATOM));
        self::assertSame('2026-01-01T00:45:00+00:00', $windows[0][1]->to->format(DATE_ATOM));
        self::assertSame('2026-01-01T01:00:00+00:00', $windows[0][2]->to->format(DATE_ATOM));
    }
}
