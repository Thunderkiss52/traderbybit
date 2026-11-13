<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalQuality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only, exact-decimal SQL aggregation; never materializes signal/trade IDs. */
final class SignalQualitySummary
{
    private const DIMENSIONS = ['exchange', 'strategy_id', 'symbol', 'timeframe', 'side', 'signal_quality', 'trade_risk'];

    public function signals(Builder $scope, array $dimensions = []): array
    {
        return $this->rows($this->signalQuery($scope, $dimensions), $dimensions);
    }

    /** Every panel shares one PostgreSQL statement snapshot, including nested READ COMMITTED callers. */
    public function snapshot(Builder $signals, Builder $accounts): array
    {
        $panels = [
            'signal_totals' => [$this->signalQuery($signals), []],
            'position_totals' => [$this->positionQuery($signals), []],
        ];
        foreach (['exchange', 'strategy_id', 'symbol', 'timeframe'] as $dimension) {
            $panels['signals_'.$dimension] = [$this->signalQuery($signals, [$dimension]), [$dimension]];
            $panels['positions_'.$dimension] = [$this->positionQuery($signals, [$dimension]), [$dimension]];
        }
        $panels['positions_side'] = [$this->positionQuery($signals, ['side']), ['side']];
        $panels['signals_quality_risk'] = [$this->signalQuery($signals, ['signal_quality', 'trade_risk']), ['signal_quality', 'trade_risk']];
        $panels['positions_quality_risk'] = [$this->positionQuery($signals, ['signal_quality', 'trade_risk']), ['signal_quality', 'trade_risk']];
        $panels['invalid_positions'] = [$this->invalidPositionScope($signals)->selectRaw('COUNT(*) AS total'), []];
        $panels['invalid_risk'] = [$this->invalidRiskScope($signals)->selectRaw('COUNT(*) AS total'), []];
        $panels['drawdowns'] = [(clone $accounts)->selectRaw('exchange_id, MAX(maximum_drawdown_percent)::text AS drawdown')->groupBy('exchange_id')->toBase(), ['exchange_id']];

        $statement = null;
        $limit = $this->groupLimit();
        foreach ($panels as $name => [$query, $dimensions]) {
            $query = $this->bounded($query, $dimensions, $limit);
            $order = $dimensions === [] ? '' : ' ORDER BY '.implode(', ', array_map(static fn (string $field): string => 'bounded.'.$field, $dimensions));
            $panel = DB::query()->fromSub($query, 'bounded')
                ->selectRaw("?::text AS panel, COALESCE(jsonb_agg(to_jsonb(bounded){$order}), '[]'::jsonb)::text AS rows", [$name]);
            $statement = $statement === null ? $panel : $statement->unionAll($panel);
        }

        $result = [];
        foreach ($statement->get() as $panel) {
            $rows = json_decode($panel->rows, true, flags: JSON_THROW_ON_ERROR);
            $this->assertGroupLimit(count($rows), $limit);
            $result[$panel->panel] = $rows;
        }

        return $result;
    }

    private function signalQuery(Builder $scope, array $dimensions = []): Query
    {
        $query = DB::query()->fromSub((clone $scope)->toBase(), 's');
        $this->group($query, $dimensions);
        $query->selectRaw('COUNT(*) AS signals');
        foreach (['side' => ['BUY', 'SELL'], 'retrospective_outcome' => ['GOOD', 'BAD', 'UNPROVEN'], 'signal_quality' => ['HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'], 'trade_risk' => ['LOW', 'MEDIUM', 'HIGH', 'UNKNOWN']] as $field => $values) {
            foreach ($values as $value) {
                $expression = self::expression($field);
                $query->selectRaw("COUNT(*) FILTER (WHERE {$expression} = ?) AS {$field}_".strtolower($value), [$value]);
            }
        }
        $riskScope = 'FROM risk_decisions r JOIN paper_accounts ra ON ra.id = r.paper_account_id AND ra.exchange_id = s.exchange WHERE r.signal_id = s.id';
        $observation = "EXISTS (SELECT 1 {$riskScope} AND r.snapshot->>'gate_decision' = 'OBSERVATION_ONLY')";
        $rejected = "EXISTS (SELECT 1 {$riskScope} AND r.allowed = false AND COALESCE(r.snapshot->>'gate_decision', '') <> 'OBSERVATION_ONLY')";
        $query->selectRaw("COUNT(*) FILTER (WHERE {$observation}) AS observations")
            ->selectRaw("COUNT(*) FILTER (WHERE {$rejected}) AS rejected");

        return $query;
    }

    public function positions(Builder $scope, array $dimensions = []): array
    {
        return $this->rows($this->positionQuery($scope, $dimensions), $dimensions);
    }

    private function positionQuery(Builder $scope, array $dimensions = []): Query
    {
        $query = $this->positionScope($scope);
        $this->group($query, $dimensions);
        $closed = "(p.status = 'CLOSED' OR LEFT(p.status, 7) = 'CLOSED_')";
        $query->selectRaw('COUNT(*) AS executed');
        foreach (['net_pnl', 'gross_pnl', 'fees', 'funding'] as $column) {
            $query->selectRaw("COALESCE(SUM(p.{$column}), 0)::text AS {$column}");
        }
        $query->selectRaw("COUNT(*) FILTER (WHERE {$closed}) AS closed")
            ->selectRaw("COUNT(*) FILTER (WHERE {$closed} AND p.net_pnl > 0) AS wins")
            ->selectRaw("COUNT(*) FILTER (WHERE {$closed} AND p.net_pnl < 0) AS losses")
            ->selectRaw("COALESCE(SUM(p.net_pnl) FILTER (WHERE {$closed} AND p.net_pnl > 0), 0)::text AS winning_pnl")
            ->selectRaw("COALESCE(-SUM(p.net_pnl) FILTER (WHERE {$closed} AND p.net_pnl < 0), 0)::text AS losing_pnl");

        return $query;
    }

    public function positionScope(Builder $scope): Query
    {
        return DB::table('virtual_positions as p')
            ->joinSub((clone $scope)->select('id', 'exchange', 'symbol', 'strategy_id', 'timeframe', 'side', 'signal_quality', 'trade_risk', 'quality_class', 'score')->toBase(), 's', function ($join): void {
                $join->on('p.signal_id', '=', 's.id');
            })
            ->whereRaw(self::positionIdentity('p', 's'));
    }

    public static function positionIdentity(string $position, string $signal): string
    {
        if (! in_array($position, ['p', 'virtual_positions'], true) || ! in_array($signal, ['s', 'signals'], true)) {
            throw new \InvalidArgumentException('Unsupported attribution alias.');
        }

        return "{$position}.exchange_id = {$signal}.exchange AND {$position}.symbol = {$signal}.symbol"
            ." AND EXISTS (SELECT 1 FROM paper_accounts attribution_account WHERE attribution_account.id = {$position}.paper_account_id AND attribution_account.exchange_id = {$position}.exchange_id)";
    }

    public function riskScope(Builder $scope): Query
    {
        return DB::table('risk_decisions as r')
            ->joinSub((clone $scope)->select('id', 'exchange')->toBase(), 's', 's.id', '=', 'r.signal_id')
            ->join('paper_accounts as ra', function ($join): void {
                $join->on('ra.id', '=', 'r.paper_account_id')->on('ra.exchange_id', '=', 's.exchange');
            });
    }

    public static function key(array $row, array $dimensions): string
    {
        return json_encode(array_map(static fn (string $field): mixed => $row[$field], $dimensions), JSON_THROW_ON_ERROR);
    }

    public static function expression(string $field, string $alias = 's'): string
    {
        if (! in_array($field, [...self::DIMENSIONS, 'retrospective_outcome'], true)) {
            throw new \InvalidArgumentException('Unsupported signal analytics field.');
        }
        if (! in_array($alias, ['s', 'signals'], true)) {
            throw new \InvalidArgumentException('Unsupported signal table alias.');
        }
        if ($field === 'trade_risk') {
            return "COALESCE(NULLIF({$alias}.trade_risk, ''), 'UNKNOWN')";
        }
        if ($field !== 'signal_quality') {
            return "{$alias}.{$field}";
        }
        $classes = [];
        foreach (QualityClass::cases() as $class) {
            $classes[] = "WHEN {$alias}.quality_class = '{$class->value}' THEN '".SignalQuality::fromQualityClass($class)->value."'";
        }
        $scores = [];
        foreach (SignalQuality::scoreThresholds() as $quality => $minimum) {
            $scores[] = "WHEN {$alias}.score >= {$minimum} THEN '{$quality}'";
        }

        return "COALESCE(NULLIF({$alias}.signal_quality, ''), CASE ".implode(' ', $classes).' '.implode(' ', $scores)." ELSE 'UNKNOWN' END)";
    }

    public function invalidPositions(Builder $scope): int
    {
        return $this->invalidPositionScope($scope)->count();
    }

    public function invalidPositionScope(Builder $scope): Query
    {
        return DB::table('virtual_positions as p')
            ->joinSub((clone $scope)->select('id', 'exchange', 'symbol')->toBase(), 's', 's.id', '=', 'p.signal_id')
            ->leftJoin('paper_accounts as a', 'a.id', '=', 'p.paper_account_id')
            ->where(function (Query $query): void {
                $query->whereColumn('p.exchange_id', '!=', 's.exchange')
                    ->orWhereColumn('p.symbol', '!=', 's.symbol')
                    ->orWhereColumn('a.exchange_id', '!=', 'p.exchange_id')->orWhereNull('a.id');
            });
    }

    public function invalidRiskScope(Builder $scope): Query
    {
        return DB::table('risk_decisions as r')
            ->joinSub((clone $scope)->select('id', 'exchange')->toBase(), 's', 's.id', '=', 'r.signal_id')
            ->leftJoin('paper_accounts as ra', 'ra.id', '=', 'r.paper_account_id')
            ->where(fn (Query $query) => $query->whereColumn('ra.exchange_id', '!=', 's.exchange')->orWhereNull('ra.id'));
    }

    private function group(Query $query, array $dimensions): void
    {
        foreach ($dimensions as $field) {
            if (! in_array($field, self::DIMENSIONS, true)) {
                throw new \InvalidArgumentException('Unsupported signal analytics dimension.');
            }
            $expression = self::expression($field);
            $query->selectRaw("{$expression} AS {$field}")->groupByRaw($expression);
        }
    }

    private function rows(Query $query, array $dimensions): array
    {
        // The API response itself must also have a bound when strategies or symbols grow.
        $limit = $this->groupLimit();
        $rows = $this->bounded($query, $dimensions, $limit)->get();
        $this->assertGroupLimit($rows->count(), $limit);

        return $rows->map(static fn (object $row): array => (array) $row)->all();
    }

    private function bounded(Query $query, array $dimensions, int $limit): Query
    {
        foreach ($dimensions as $field) {
            $query->orderBy($field);
        }

        return $query->limit($limit + 1);
    }

    private function groupLimit(): int
    {
        return max(1, min(10000, (int) config('research.analytics.maximum_groups', 1000)));
    }

    private function assertGroupLimit(int $observed, int $limit): void
    {
        if ($observed > $limit) {
            throw ValidationException::withMessages(['filters' => 'Analytics cohort exceeds the configured group limit; narrow the filters.']);
        }
    }
}
