<?php

declare(strict_types=1);

// Tiny, dependency-free query-builder double. Executes the actual service
// query-construction method, not a copy of the source under test.

namespace Illuminate\Database\Eloquent {
    class Builder
    {
        public array $calls = [];

        public function __construct(public string $table) {}

        public function where($column, $operator = null, $value = null): static
        {
            if (is_callable($column)) {
                $column($this);
            } else {
                $this->calls[] = ['where', $column, $operator, $value];
            }

            return $this;
        }

        public function whereNull(string $column): static
        {
            $this->calls[] = ['whereNull', $column];

            return $this;
        }

        public function orWhere(string $column, $operator = null, $value = null): static
        {
            $this->calls[] = ['orWhere', $column, $operator, $value];

            return $this;
        }

        public function whereRaw(string $statement, array $bindings = []): static
        {
            $this->calls[] = ['whereRaw', $statement, $bindings];

            return $this;
        }

        public function whereExists($callback): static
        {
            $q = new self('subquery');
            $callback($q);
            $this->calls[] = ['whereExists', $q];

            return $this;
        }

        public function whereNotExists($callback): static
        {
            $q = new self('subquery');
            $callback($q);
            $this->calls[] = ['whereNotExists', $q];

            return $this;
        }

        public function whereIn(string $column, $values): static
        {
            $this->calls[] = ['whereIn', $column, $values];

            return $this;
        }

        public function whereColumn(string $first, string $second): static
        {
            $this->calls[] = ['whereColumn', $first, $second];

            return $this;
        }

        public function from(string $name): static
        {
            $this->table = $name;

            return $this;
        }

        public function selectRaw(string $sql): static
        {
            $this->calls[] = ['selectRaw', $sql];

            return $this;
        }

        public function select(string $column): static
        {
            $this->calls[] = ['select', $column];

            return $this;
        }
    }
}

namespace App\Strategy\Infrastructure\Persistence {
    use Illuminate\Database\Eloquent\Builder;

    final class SignalRecord
    {
        public static function query(): Builder
        {
            return new Builder('signals');
        }
    }
}

namespace App\PaperTrading\Infrastructure\Persistence {
    use Illuminate\Database\Eloquent\Builder;

    final class VirtualPositionRecord
    {
        public static function query(): Builder
        {
            return new Builder('virtual_positions');
        }
    }
}

namespace {
    use App\Research\Application\SignalQualityAnalyticsService;

    require dirname(__DIR__, 2).'/app/Research/Application/SignalQualityAnalyticsService.php';
    $klass = new ReflectionClass(SignalQualityAnalyticsService::class);
    $instance = $klass->newInstanceWithoutConstructor();
    $method = $klass->getMethod('buildFilteredPositionsQuery');

    $filters = [
        'exchange' => 'bybit', 'strategy' => 'baseline', 'symbol' => 'BTCUSDT',
        'timeframe' => '15m', 'side' => 'BUY', 'signal_quality' => 'HIGH',
        'trade_risk' => 'LOW', 'outcome' => 'GOOD', 'gate_decision' => 'REJECTED',
        'execution_status' => 'REJECTED', 'from' => '2026-11-01', 'to' => '2026-11-10',
    ];
    $query = $method->invoke($instance, $filters);
    if ($query->table !== 'virtual_positions') {
        throw new RuntimeException('Wrong position table.');
    }
    $joins = array_values(array_filter($query->calls, fn ($call) => $call[0] === 'whereIn' && $call[1] === 'signal_id'));
    if (count($joins) !== 1 || $joins[0][2]->table !== 'signals') {
        throw new RuntimeException('Position query is not constrained by signal IDs.');
    }
    $sql = $joins[0][2]->calls;
    foreach (['exchange', 'strategy_id', 'symbol', 'timeframe', 'side', 'signal_quality', 'trade_risk', 'retrospective_outcome', 'decision_time'] as $column) {
        if (! array_filter($sql, fn ($call) => $call[0] === 'where' && $call[1] === $column)) {
            throw new RuntimeException('Missing signal filter '.$column);
        }
    }
    if (! array_filter($sql, fn ($call) => $call[0] === 'whereExists' && $call[1]->table === 'risk_decisions')) {
        throw new RuntimeException('Missing persisted risk-gate filter.');
    }
    if (! array_filter($sql, fn ($call) => $call[0] === 'whereNotExists' && $call[1]->table === 'virtual_positions')) {
        throw new RuntimeException('Missing nonexecution evidence.');
    }

    $unknown = $method->invoke($instance, ['gate_decision' => 'IMAGINARY']);
    $sub = $unknown->calls[0][2];
    if (! array_filter($sub->calls, fn ($call) => $call[0] === 'whereRaw' && $call[1] === '1 = 0')) {
        throw new RuntimeException('Invalid status broadened results.');
    }
    echo "Signal analytics query-construction smoke: 13/13 checks PASS (no database access)\n";
}
