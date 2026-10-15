<?php

use App\Http\Controllers\Api\V1\ResearchController as C;
use App\Http\Middleware\RequireResearchToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::get('dashboard', [C::class, 'dashboard']);
    Route::get('market/{symbol}/candles', [C::class, 'candles']);
    Route::get('market/{symbol}/indicators', [C::class, 'indicators']);
    Route::get('market/{symbol}/context', [C::class, 'context']);
    Route::get('market/{symbol}/analysis', [C::class, 'analysis']);
    Route::get('signals', [C::class, 'signals']);
    Route::get('signals/{id}', [C::class, 'signal']);
    Route::get('positions', [C::class, 'positions']);
    Route::get('positions/{id}', [C::class, 'position']);
    Route::get('journal', [C::class, 'journal']);
    Route::get('statistics', [C::class, 'statistics']);
    Route::get('risk', [C::class, 'risk']);
    Route::get('backtests', [C::class, 'backtests']);
    Route::get('backtests/{id}', [C::class, 'backtest']);
    Route::get('backtests/{id}/report', [C::class, 'report']);
    Route::get('backtests/{id}/trades', [C::class, 'backtestTrades']);
    Route::get('backtests/{id}/equity', [C::class, 'backtestEquity']);
    Route::get('backtests/{id}/optimizations', [C::class, 'backtestOptimizations']);
    Route::post('backtests', [C::class, 'store'])->middleware(RequireResearchToken::class);
});
