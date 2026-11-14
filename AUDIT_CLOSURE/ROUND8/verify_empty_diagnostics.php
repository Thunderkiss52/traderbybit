<?php

declare(strict_types=1);

// Dependency-free regression of the actual private analytics diagnostics method.
// Stubs exercise the zero-sample path without claiming Laravel/DB integration.

namespace Illuminate\Support {
    final class Collection
    {
        public function __construct(private array $items = []) {}

        public function count(): int
        {
            return count($this->items);
        }

        public function where(string $key, mixed $value): self
        {
            return new self(array_values(array_filter(
                $this->items,
                static fn (array $row) => ($row[$key] ?? null) === $value,
            )));
        }
    }
}

namespace Brick\Math {
    final class BigDecimal
    {
        private function __construct(private string $value) {}

        public static function of(string $value): self
        {
            if ($value === '' || ! is_numeric($value)) {
                throw new \InvalidArgumentException('Invalid numeric expectancy: '.var_export($value, true));
            }

            return new self($value);
        }

        public function isNegative(): bool
        {
            return (float) $this->value < 0;
        }
    }
}

namespace {
    use App\Research\Application\SignalQualityAnalyticsService;
    use Illuminate\Support\Collection;

    require __DIR__.'/../../app/Research/Application/SignalQualityAnalyticsService.php';

    $class = new ReflectionClass(SignalQualityAnalyticsService::class);
    $service = $class->newInstanceWithoutConstructor();
    $diagnostics = $class->getMethod('computeDiagnostics');
    $empty = new Collection;

    $emptyFrames = array_map(
        static fn (string $tf) => ['timeframe' => $tf, 'signals' => 0, 'expectancy' => null],
        SignalQualityAnalyticsService::CANONICAL_TIMEFRAMES,
    );
    $result = $diagnostics->invoke($service, $empty, $empty, $emptyFrames);
    if ($result !== []) {
        throw new RuntimeException('Empty timeframe cohorts must not produce diagnostics.');
    }
    echo "PASS 1: all six empty timeframe cohorts yield no exception or fabricated warning\n";

    $negativeFrames = [...$emptyFrames, ['timeframe' => '15m', 'signals' => 3, 'expectancy' => '-0.25']];
    $result = $diagnostics->invoke($service, $empty, $empty, $negativeFrames);
    if (count($result) !== 1 || ($result[0]['type'] ?? null) !== 'NEGATIVE_TIMEFRAME_EXPECTANCY') {
        throw new RuntimeException('Valid negative expectancy warning regressed.');
    }
    echo "PASS 2: present negative expectancy still yields its warning\n";
}
