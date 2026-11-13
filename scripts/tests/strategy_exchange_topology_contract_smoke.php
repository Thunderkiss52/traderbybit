<?php

declare(strict_types=1);

// Standalone regression smoke for the genuine assignment service WITHOUT
// Laravel/DB/network access. Mock persistence is intentionally in-memory.

namespace Illuminate\Support\Facades {
    final class Schema
    {
        public static bool $hasAssignments = false;

        public static function hasTable(string $name): bool
        {
            return $name === 'strategy_exchange_assignments' && self::$hasAssignments;
        }
    }

    final class DB
    {
        public static array $rows = [];

        public static function table(string $name): FakeQuery
        {
            if ($name !== 'strategy_exchange_assignments') {
                throw new \RuntimeException("Unexpected table: {$name}");
            }

            return new FakeQuery(self::$rows);
        }
    }

    final class FakeQuery
    {
        private array $filters = [];

        public function __construct(private readonly array $rows) {}

        public function where(string $field, mixed $value): self
        {
            $this->filters[$field] = $value;

            return $this;
        }

        private function matched(): array
        {
            return array_values(array_filter($this->rows, function (array $row): bool {
                foreach ($this->filters as $name => $value) {
                    if (($row[$name] ?? null) !== $value) {
                        return false;
                    }
                }

                return true;
            }));
        }

        public function first(): ?object
        {
            $row = $this->matched()[0] ?? null;

            return $row === null ? null : (object) $row;
        }

        public function pluck(string $column): FakeCollection
        {
            return new FakeCollection(array_column($this->matched(), $column));
        }
    }

    final class FakeCollection
    {
        public function __construct(private readonly array $items) {}

        public function all(): array
        {
            return $this->items;
        }
    }
}

namespace App\Exchanges {
    final class ExchangeRegistry
    {
        public function registeredIds(): array
        {
            return ['bybit', 'newvenue'];
        }
    }
}

namespace App\Strategy\Application {
    final class StrategyRegistry
    {
        public function ids(): array
        {
            return ['alpha', 'beta'];
        }

        public function has(string $id): bool
        {
            return in_array($id, $this->ids(), true);
        }

        public function get(string $id): object
        {
            if (! $this->has($id)) {
                throw new \InvalidArgumentException('Unknown strategy');
            }

            // An empty explicit target set denies execution but must not
            // prevent the separate account-provisioning projection.
            return new class($id)
            {
                public function __construct(private readonly string $id) {}

                public function targetExchanges(): ?array
                {
                    return $this->id === 'alpha' ? [] : null;
                }
            };
        }
    }
}

namespace {
    use App\Exchanges\ExchangeRegistry;
    use App\Strategy\Application\StrategyExchangeAssignmentService;
    use App\Strategy\Application\StrategyRegistry;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;

    function app(string $type): object
    {
        return $GLOBALS['container'][$type]
            ?? throw new RuntimeException("Missing fake dependency: {$type}");
    }

    function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    require_once dirname(__DIR__, 2).'/app/Strategy/Application/StrategyExchangeAssignmentService.php';

    $GLOBALS['container'] = [StrategyRegistry::class => new StrategyRegistry];
    $service = new StrategyExchangeAssignmentService(new ExchangeRegistry);
    $tests = 0;
    $verify = static function (bool $condition, string $message) use (&$tests): void {
        check($condition, $message);
        $tests++;
    };

    $verify($service->assignedStrategiesForExchange('newvenue') === ['alpha', 'beta'], 'No assignment table must not suppress N × M provisioning');
    $verify(! $service->hasAssignment('alpha', 'newvenue'), 'Unsupported strategy must not execute');
    $verify($service->hasAssignment('beta', 'newvenue'), 'Compatible strategy may be assigned by default');
    $verify(! $service->hasAssignment('unknown', 'newvenue'), 'Unknown strategy must not be eligible');
    $verify($service->assignedStrategiesForExchange('unknown') === [], 'Unknown exchange must not create accounts');

    Schema::$hasAssignments = true;
    DB::$rows = [
        ['strategy_id' => 'beta', 'exchange_id' => 'bybit', 'market_type' => 'perpetual', 'is_active' => true],
    ];
    $verify($service->assignedStrategiesForExchange('newvenue') === ['alpha', 'beta'], 'An assignment on Bybit cannot narrow a different venue');
    $verify($service->hasAssignment('beta', 'newvenue'), 'No other venue assignment needed for supported strategy');

    DB::$rows[] = ['strategy_id' => 'alpha', 'exchange_id' => 'newvenue', 'market_type' => 'perpetual', 'is_active' => false];
    $verify($service->assignedStrategiesForExchange('newvenue') === ['beta'], 'Operator veto must exclude a pair from auto-provisioning');
    $verify(! $service->hasAssignment('alpha', 'newvenue'), 'Operator veto must deny execution');

    DB::$rows[1]['is_active'] = true;
    $verify($service->assignedStrategiesForExchange('newvenue') === ['alpha', 'beta'], 'Operator approval restores topology scope');
    $verify($service->hasAssignment('alpha', 'newvenue'), 'Explicit operator assignment may override target restriction');

    echo "{$tests} standalone topology policy checks PASS (no database/network)\n";
}
