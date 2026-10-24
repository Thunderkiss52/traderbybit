<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\MarketData\Infrastructure\Persistence\CandleRecord;

/** M6 readiness inventory: historical count alone cannot establish data quality. */
final class BinancePortabilityMatrix
{
    public function report(): array
    {
        $rows = [];
        $intervals = ['5', '15', '30', '60'];
        $symbols = (array) config('market_data.symbols', []);
        $now = time();
        // Only 16 queries (two exchanges, two symbols, four timeframes)
        // instead of repeating the same source read for all 13 strategies.
        // Always use the exact same cutoff for comparable readiness results.
        $history = [];
        foreach (['bybit', 'binance'] as $exchange) {
            foreach ($symbols as $symbol) {
                foreach ($intervals as $interval) {
                    $candles = CandleRecord::query()->where('exchange', $exchange)->where('category', 'linear')
                        ->where('symbol', $symbol)->where('interval', $interval)->where('is_closed', true)
                        ->orderByDesc('open_time')->limit(220)->get(['open_time']);
                    $timestamps = $candles->map(fn ($c): int => $c->open_time->getTimestamp())->all();
                    $history[$exchange][$symbol][$interval] = BinanceCandleWindow::status($timestamps, (int) $interval * 60, $now);
                }
            }
        }
        foreach ((array) config('paper.primary_strategies', []) as $strategy) {
            foreach (['bybit', 'binance'] as $exchange) {
                $unready = [];
                $historyStatuses = [];
                foreach ($symbols as $symbol) {
                    foreach ($intervals as $interval) {
                        $status = $history[$exchange][$symbol][$interval];
                        if ($status !== 'READY') {
                            $series = $symbol.':'.$interval;
                            $unready[] = $series;
                            $historyStatuses[$series] = $status;
                        }
                    }
                }
                $reason = $unready === [] ? 'SIGNAL_RESEARCH_POSSIBLE'
                    : (in_array('CANDLE_HISTORY_INSUFFICIENT', $historyStatuses, true)
                        ? 'CANDLE_HISTORY_INSUFFICIENT' : reset($historyStatuses));
                $executionBlockers = [];
                if ($strategy === 'btc_eth_lead_lag') {
                    $executionBlockers[] = 'TWO_LEG_EXECUTION_UNVALIDATED';
                }
                if ($strategy === 'funding_open_interest_squeeze' && $exchange === 'binance') {
                    $executionBlockers[] = 'OPEN_INTEREST_SEMANTICS_UNVALIDATED';
                }
                $rows[] = ['strategy_id' => $strategy, 'exchange_id' => $exchange,
                    'status' => $reason, 'missing_candle_series' => $unready,
                    'history_statuses' => $historyStatuses, 'execution_blockers' => $executionBlockers,
                    'paper_execution_verified' => false];
            }
        }

        return ['scope' => '13_STRATEGIES_X_2_EXCHANGES', 'rows' => $rows,
            'notice' => 'Candle readiness requires 220 contiguous, closed and recent bars per series; it does not prove causal signals, costs or paper fills.'];
    }
}
