<?php

declare(strict_types=1);

namespace App\Exchanges\Http;

use App\Exchanges\Binance\BinancePublicAdapter;
use App\Exchanges\ExchangeComparisonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ExchangeComparisonController extends Controller
{
    public function index(ExchangeComparisonService $comparison): JsonResponse
    {
        return response()->json(['data' => $comparison->report()]);
    }

    public function csv(ExchangeComparisonService $comparison): StreamedResponse
    {
        $report = $comparison->report();

        return response()->streamDownload(static function () use ($report): void {
            $file = fopen('php://output', 'wb');
            if ($file === false) {
                throw new \RuntimeException('Unable to stream CSV.');
            }
            fputcsv($file, ['exchange_id', 'strategy_id', 'account_id', 'status', 'evaluation_status', 'inactivity_reason', 'starting_balance', 'current_equity', 'realized_pnl', 'unrealized_pnl', 'drawdown_percent']);
            foreach ($report['accounts'] as $row) {
                // Preserve null as an empty cell; never fabricate zero or combine exchange balances.
                fputcsv($file, array_map(static fn ($value): string => $value === null ? '' : (string) $value, [
                    $row['exchange_id'], $row['strategy_id'], $row['account_id'], $row['status'],
                    $row['evaluation_status'], $row['inactivity_reason'] ?? '', $row['starting_balance'], $row['current_equity'],
                    $row['realized_pnl'], $row['unrealized_pnl'], $row['drawdown_percent'],
                ]));
            }
            fclose($file);
        }, 'vector-paper-by-exchange.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function binanceCandles(Request $request, string $symbol, BinancePublicAdapter $adapter): JsonResponse
    {
        $input = $request->validate([
            'interval' => 'sometimes|string|in:1m,3m,5m,15m,30m,1h,2h,4h,6h,8h,12h,1d',
            'limit' => 'sometimes|integer|min:1|max:500',
        ]);
        if (! in_array(strtoupper($symbol), (array) config('ui.symbols', []), true)) {
            abort(404, 'Symbol not configured.');
        }
        try {
            $candles = $adapter->fetchCandles(strtoupper($symbol), $input['interval'] ?? '5m', (int) ($input['limit'] ?? 100));
        } catch (Throwable) {
            return response()->json(['error' => ['code' => 'BINANCE_PUBLIC_DATA_UNAVAILABLE', 'message' => 'Binance public data unavailable.']], 503);
        }

        return response()->json(['data' => [
            'exchange_id' => 'binance', 'provenance' => 'PUBLIC_USDM_FUTURES_REST',
            'persistence_status' => 'NOT_PERSISTED_BY_THIS_ENDPOINT', 'candles' => $candles,
        ]]);
    }
}
