<?php

declare(strict_types=1);

namespace Tests\Unit\Exchanges;

use App\Exchanges\Binance\BinancePublicAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BinanceSymbolInventoryTest extends TestCase
{
    public function test_unsupported_unrelated_symbols_do_not_disable_supported_contracts(): void
    {
        Http::preventStrayRequests();
        $contract = [
            'contractType' => 'PERPETUAL', 'quoteAsset' => 'USDT', 'status' => 'TRADING',
            'filters' => [
                ['filterType' => 'PRICE_FILTER', 'tickSize' => '0.01'],
                ['filterType' => 'LOT_SIZE', 'stepSize' => '0.001', 'minQty' => '0.001', 'maxQty' => '1000'],
                ['filterType' => 'MIN_NOTIONAL', 'notional' => '5'],
            ],
        ];
        Http::fake(['https://fapi.binance.com/fapi/v1/exchangeInfo' => Http::response(['symbols' => [
            ['symbol' => "\u{6D4B}\u{8BD5}USDT", ...$contract],
            ['symbol' => 'BTCUSDT', ...$contract],
            ['symbol' => 'ETHUSDT', ...$contract],
        ]])]);

        $rows = app(BinancePublicAdapter::class)->fetchSymbols();
        self::assertSame(['BTCUSDT', 'ETHUSDT'], array_column($rows, 'symbol'));
        self::assertSame(['binance', 'binance'], array_column($rows, 'exchange_id'));
        self::assertSame('0.01', $rows[0]['tick_size']);
        self::assertSame('0.001', $rows[1]['lot_size']);
        Http::assertSentCount(1);
    }
}
