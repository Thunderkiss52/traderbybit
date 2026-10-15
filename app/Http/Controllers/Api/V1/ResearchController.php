<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Backtest\Infrastructure\Persistence\BacktestEquitySnapshotRecord;
use App\Backtest\Infrastructure\Persistence\BacktestReportRecord;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\Backtest\Infrastructure\Persistence\BacktestSegmentRecord;
use App\Backtest\Infrastructure\Persistence\BacktestTradeRecord;
use App\Backtest\Jobs\RunBacktestJob;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\BacktestStoreRequest;
use App\Http\Requests\V1\MarketSeriesRequest;
use App\Http\Resources\V1\ResearchResource;
use App\Indicators\Application\IndicatorEngine;
use App\MarketData\Domain\Candle;
use App\MarketData\Domain\Interval;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PerformanceStatisticsService;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DateTimeImmutable;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResearchController extends Controller
{
    private function ok(mixed $data): ResearchResource
    {
        return new ResearchResource($data);
    }

    private function symbol(string $symbol): string
    {
        $symbol = strtoupper($symbol);
        abort_unless(in_array($symbol, config('ui.symbols'), true), 404, 'Unsupported symbol.');

        return $symbol;
    }

    public function dashboard(PerformanceStatisticsService $statistics): ResearchResource
    {
        $account = PaperAccountRecord::query()->oldest()->first();
        $contexts = MarketContextRecord::query()->whereIn('symbol', config('ui.symbols'))->latest('observed_at')->get()->unique('symbol')->values();
        $recentSkips = SignalRecord::query()->where('side', 'SKIP')
            ->where('decision_time', '>=', now('UTC')->subDay())
            ->whereIn('symbol', config('ui.symbols'))
            ->get(['reasons']);
        $skipReasons = $recentSkips->flatMap(static fn (SignalRecord $signal): array => $signal->reasons ?? [])
            ->countBy()->sortDesc()->take(8)->all();

        return $this->ok([
            'market' => $contexts,
            'account' => $account,
            'portfolio' => $account ? array_merge($statistics->accountSummary($account), [
                'open_positions' => VirtualPositionRecord::query()->where('paper_account_id', $account->id)->whereNull('closed_at')->count(),
                'pending_entry_signals' => PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->where('status', 'PENDING_ENTRY')->count(),
            ]) : null,
            'signals' => SignalRecord::query()->whereIn('side', ['BUY', 'SELL'])->latest('decision_time')->limit(8)->get(),
            'recent_decisions' => SignalRecord::query()->whereIn('symbol', config('ui.symbols'))->latest('decision_time')->limit(8)->get(),
            'skip_reasons_24h' => $skipReasons,
            'positions' => VirtualPositionRecord::query()->whereNull('closed_at')->get(),
            'performance' => $account ? $statistics->calculate($account->id)->metrics : null,
            'research_settings' => [
                'starting_balance' => config('backtest.starting_balance'),
                'strategy_version' => config('strategy.version'),
                'execution_model_version' => config('paper.execution_version'),
            ],
        ]);
    }

    public function candles(MarketSeriesRequest $r, string $symbol): ResearchResource
    {
        $q = CandleRecord::query()->where(['symbol' => $this->symbol($symbol), 'interval' => (string) $r->integer('interval', 15), 'is_closed' => true])->latest('open_time')->limit($r->integer('limit', 500));
        if ($r->filled('to')) {
            $q->where('open_time', '<', new DateTimeImmutable($r->string('to')));
        }

        return $this->ok($q->get()->reverse()->values());
    }

    public function indicators(MarketSeriesRequest $r, string $symbol, IndicatorEngine $engine): ResearchResource
    {
        $rows = CandleRecord::query()->where(['symbol' => $this->symbol($symbol), 'interval' => (string) $r->integer('interval', 15), 'is_closed' => true])->latest('open_time')->limit($r->integer('limit', 500))->get()->reverse()->values();
        if ($rows->isEmpty()) {
            return $this->ok(['times' => [], 'series' => null]);
        }

        $i = Interval::parse((string) $r->integer('interval', 15));
        $candles = $rows->map(fn ($x) => new Candle($x->exchange, $x->category, $x->symbol, $i, $x->open_time->getTimestamp() * 1000, $x->open, $x->high, $x->low, $x->close, $x->volume, $x->turnover, true))->all();

        return $this->ok(['times' => $rows->pluck('open_time'), 'series' => $engine->calculate($candles)]);
    }

    public function context(string $symbol): ResearchResource
    {
        return $this->ok(MarketContextRecord::query()->where('symbol', $this->symbol($symbol))->latest('observed_at')->first());
    }

    public function signals(Request $r): ResearchResource
    {
        $q = SignalRecord::query();
        foreach (['symbol', 'side', 'quality_class', 'strategy_version'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->string($f));
            }
        }
        if ($r->filled('search')) {
            $q->where('symbol', 'like', '%'.strtoupper($r->string('search')).'%');
        }

        return $this->ok($q->latest('decision_time')->paginate(min(100, max(1, $r->integer('per_page', 25)))));
    }

    public function signal(string $id): ResearchResource
    {
        return $this->ok(SignalRecord::query()->findOrFail($id));
    }

    public function positions(Request $r): ResearchResource
    {
        $q = VirtualPositionRecord::query();
        if ($r->filled('account')) {
            $q->where('paper_account_id', $r->string('account'));
        }
        if ($r->filled('symbol')) {
            $q->where('symbol', $this->symbol($r->string('symbol')));
        }

        $page = $q->latest('opened_at')->paginate(min(100, max(1, $r->integer('per_page', 25))));
        $latestPrices = CandleRecord::query()->whereIn('symbol', collect($page->items())->pluck('symbol')->unique())
            ->where('interval', '5')->where('is_closed', true)->latest('open_time')->get()->unique('symbol')->keyBy('symbol');
        $page->through(function (VirtualPositionRecord $position) use ($latestPrices): array {
            $referenceAt = $position->closed_at ?? $position->last_processed_event_at ?? $position->opened_at;

            return array_merge($position->toArray(), [
                'current_reference_price' => $latestPrices->get($position->symbol)?->close,
                'duration_seconds' => max(0, $referenceAt->getTimestamp() - $position->opened_at->getTimestamp()),
                'maximum_holding_remaining_seconds' => max(0, $position->maximum_holding_until->getTimestamp() - $referenceAt->getTimestamp()),
            ]);
        });

        return $this->ok($page);
    }

    public function position(string $id): ResearchResource
    {
        $p = VirtualPositionRecord::query()->findOrFail($id);

        return $this->ok([
            'mode' => 'FORWARD_PAPER',
            'position' => $p,
            'signal' => SignalRecord::query()->find($p->signal_id),
            'executions' => VirtualExecutionRecord::query()->where('position_id', $id)->oldest('market_event_at')->get(),
            'funding_events' => PositionFundingEventRecord::query()->where('position_id', $id)->oldest('funding_at')->get(),
            'journal' => JournalEventRecord::query()->where('position_id', $id)->oldest('occurred_at')->oldest('sequence')->get(),
        ]);
    }

    public function journal(Request $r): ResearchResource
    {
        $q = JournalEventRecord::query();
        if ($r->filled('account')) {
            $q->where('paper_account_id', $r->string('account'));
        }

        return $this->ok($q->latest('occurred_at')->paginate(min(100, max(1, $r->integer('per_page', 25)))));
    }

    public function statistics(Request $r, PerformanceStatisticsService $s): ResearchResource
    {
        $a = $r->string('account')->toString() ?: PaperAccountRecord::query()->value('id');
        if (! $a) {
            return $this->ok(['metrics' => null, 'equity' => []]);
        }
        $report = $s->calculate($a);
        $closed = VirtualPositionRecord::query()
            ->where('paper_account_id', $a)
            ->whereNotNull('closed_at');
        $group = static fn (string $field) => (clone $closed)
            ->selectRaw($field.', COUNT(*) AS trades, SUM(net_pnl) AS net_pnl, SUM(CASE WHEN net_pnl > 0 THEN 1 ELSE 0 END) AS winning_trades')
            ->groupBy($field)
            ->orderBy($field)
            ->get();

        return $this->ok([
            'metrics' => $report->metrics,
            'data_quality' => $report->dataQuality,
            'equity' => EquitySnapshotRecord::query()->where('paper_account_id', $a)->oldest('recorded_at')->get(),
            'breakdowns' => [
                'symbols' => $group('symbol'),
                'sides' => $group('side'),
                'quality_classes' => $group('quality_class'),
                'r_distribution' => (clone $closed)->whereNotNull('r_multiple')->orderBy('closed_at')->pluck('r_multiple'),
            ],
            'period_pnl' => $this->periodPnl((clone $closed)->oldest('closed_at')->get()),
        ]);
    }

    private function periodPnl($positions): array
    {
        $periods = ['daily' => 'Y-m-d', 'weekly' => 'o-\WW', 'monthly' => 'Y-m'];
        $result = [];
        foreach ($periods as $name => $format) {
            $running = BigDecimal::zero();
            $result[$name] = $positions->groupBy(fn (VirtualPositionRecord $position): string => $position->closed_at->format($format))
                ->map(function ($rows, string $period) use (&$running): array {
                    $net = $rows->reduce(fn (BigDecimal $sum, VirtualPositionRecord $position): BigDecimal => $sum->plus($position->net_pnl), BigDecimal::zero());
                    $running = $running->plus($net);

                    return ['period' => $period, 'net_pnl' => (string) $net, 'cumulative_net_pnl' => (string) $running];
                })->values()->all();
        }

        return $result;
    }

    public function risk(Request $r): ResearchResource
    {
        $a = $r->string('account')->toString() ?: PaperAccountRecord::query()->value('id');

        return $this->ok(['account' => $a ? PaperAccountRecord::query()->find($a) : null, 'decisions' => $a ? RiskDecisionRecord::query()->where('paper_account_id', $a)->latest('decided_at')->limit(50)->get() : [], 'limits' => config('paper.portfolio')]);
    }

    public function backtests(Request $r): ResearchResource
    {
        return $this->ok(BacktestRunRecord::query()->latest()->paginate(min(100, max(1, $r->integer('per_page', 25)))));
    }

    public function backtest(string $id): ResearchResource
    {
        $run = BacktestRunRecord::query()->findOrFail($id);

        return $this->ok(['run' => $run, 'segments' => BacktestSegmentRecord::query()->where('backtest_run_id', $id)->get()]);
    }

    public function report(string $id): ResearchResource
    {
        return $this->ok(['reports' => BacktestReportRecord::query()->where('backtest_run_id', $id)->get(), 'trades' => BacktestTradeRecord::query()->where('backtest_run_id', $id)->paginate(100), 'equity' => BacktestEquitySnapshotRecord::query()->where('backtest_run_id', $id)->oldest('recorded_at')->limit(5000)->get()]);
    }

    public function store(BacktestStoreRequest $r): JsonResponse
    {
        $v = $r->validated();
        RunBacktestJob::dispatch($v['symbols'], $v['from'].'T00:00:00Z', $v['to'].'T00:00:00Z', $v['mode'] === 'walk_forward');

        return response()->json([
            'schema_version' => '1.0.0',
            'research_mode' => true,
            'data' => ['status' => 'QUEUED'],
        ], 202);
    }
}
