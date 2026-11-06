<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exchange_capabilities')) {
            Schema::create('exchange_capabilities', static function (Blueprint $table): void {
                $table->id();
                $table->string('exchange_id', 32);
                $table->string('market_type', 32);
                $table->string('instrument', 32)->default('*');
                $table->string('capability', 64);
                $table->string('status', 32)->default('NOT_ASSESSED');
                $table->json('metadata')->nullable();
                $table->timestampsTz(3);

                $table->unique(['exchange_id', 'market_type', 'instrument', 'capability'], 'uniq_exch_mkt_inst_cap');
                $table->index(['exchange_id', 'market_type']);
                $table->index(['capability', 'status']);
            });
        }

        if (! Schema::hasTable('strategy_exchange_assignments')) {
            Schema::create('strategy_exchange_assignments', static function (Blueprint $table): void {
                $table->id();
                $table->string('strategy_id', 64);
                $table->string('exchange_id', 32);
                $table->string('market_type', 32)->default('perpetual');
                $table->string('assigned_by', 64)->default('owner');
                $table->timestampTz('assigned_at', 3)->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestampsTz(3);

                $table->unique(['strategy_id', 'exchange_id', 'market_type'], 'uniq_strat_exch_mkt');
                $table->index(['exchange_id', 'market_type']);
            });
        }

        $now = now('UTC')->format('Y-m-d H:i:s.uP');

        // Seed 8 additional target exchanges (preserves existing Binance & Bybit rows)
        $exchanges = [
            [
                'id' => 'coinbase',
                'name' => 'Coinbase Exchange',
                'market_type' => 'spot',
                'description' => 'Coinbase Exchange institutional public spot market data. (Note: Coinbase International derivatives is a separate venue).',
                'lifecycle_status' => 'CONNECTED_PUBLIC_DATA',
                'capabilities' => json_encode([
                    'has_candles' => true,
                    'has_funding' => false,
                    'has_open_interest' => false,
                    'has_live_adapter' => true,
                    'adapter_class' => 'App\\Exchanges\\Coinbase\\CoinbaseAdapter',
                    'supported_timeframes' => ['1', '5', '15', '60', '360', '1440'],
                    'price_precision' => 2,
                    'qty_precision' => 8,
                    'min_qty' => '0.00001',
                    'min_notional' => '1.0',
                    'market_types' => ['spot'],
                ], JSON_THROW_ON_ERROR),
                'cost_model' => json_encode([
                    'version' => 'coinbase-spot-2026.1',
                    'market' => 'Spot',
                    'fee_tier' => 'Tier 1 (<$10k)',
                    'maker_rate' => '0.0040',
                    'taker_rate' => '0.0060',
                    'slippage_rate' => '0.0003',
                ], JSON_THROW_ON_ERROR),
                'paper_eligible' => false,
                'paper_approved' => false,
                'paper_approved_at' => null,
            ],
            [
                'id' => 'upbit',
                'name' => 'Upbit Korea',
                'market_type' => 'spot',
                'description' => 'Upbit KRW/USDT spot public market data. South Korean exchange strictly operating in spot mode.',
                'lifecycle_status' => 'CONNECTED_PUBLIC_DATA',
                'capabilities' => json_encode([
                    'has_candles' => true,
                    'has_funding' => false,
                    'has_open_interest' => false,
                    'has_live_adapter' => true,
                    'adapter_class' => 'App\\Exchanges\\Upbit\\UpbitAdapter',
                    'supported_timeframes' => ['1', '5', '15', '30', '60', '240', 'D'],
                    'price_precision' => 0,
                    'qty_precision' => 8,
                    'min_qty' => '0.0001',
                    'min_notional' => '5000', // KRW
                    'market_types' => ['spot'],
                ], JSON_THROW_ON_ERROR),
                'cost_model' => json_encode([
                    'version' => 'upbit-spot-2026.1',
                    'market' => 'KRW/USDT Spot',
                    'fee_tier' => 'Standard',
                    'maker_rate' => '0.0005',
                    'taker_rate' => '0.0005',
                    'slippage_rate' => '0.0004',
                ], JSON_THROW_ON_ERROR),
                'paper_eligible' => false,
                'paper_approved' => false,
                'paper_approved_at' => null,
            ],
            [
                'id' => 'okx',
                'name' => 'OKX Global',
                'market_type' => 'linear_perpetual',
                'description' => 'OKX v5 public API for Spot, Margin, Perpetual Swaps, Dated Futures, and European Options.',
                'lifecycle_status' => 'CONNECTED_PUBLIC_DATA',
                'capabilities' => json_encode([
                    'has_candles' => true,
                    'has_funding' => true,
                    'has_open_interest' => true,
                    'has_live_adapter' => true,
                    'adapter_class' => 'App\\Exchanges\\Okx\\OkxAdapter',
                    'supported_timeframes' => ['1m', '5m', '15m', '30m', '1H', '4H', '1D'],
                    'price_precision' => 2,
                    'qty_precision' => 2,
                    'min_qty' => '0.01',
                    'min_notional' => '10.0',
                    'market_types' => ['spot', 'perpetual', 'futures', 'options'],
                ], JSON_THROW_ON_ERROR),
                'cost_model' => json_encode([
                    'version' => 'okx-derivatives-2026.1',
                    'market' => 'USDT Margined Swaps',
                    'fee_tier' => 'Regular User VIP 0',
                    'maker_rate' => '0.0002',
                    'taker_rate' => '0.0005',
                    'slippage_rate' => '0.0002',
                ], JSON_THROW_ON_ERROR),
                'paper_eligible' => false,
                'paper_approved' => false,
                'paper_approved_at' => null,
            ],
            [
                'id' => 'bitget',
                'name' => 'Bitget Global',
                'market_type' => 'linear_perpetual',
                'description' => 'Bitget v2 public market data for USDT-M Futures, Coin-M Futures, and Spot markets.',
                'lifecycle_status' => 'CONNECTED_PUBLIC_DATA',
                'capabilities' => json_encode([
                    'has_candles' => true,
                    'has_funding' => true,
                    'has_open_interest' => true,
                    'has_live_adapter' => true,
                    'adapter_class' => 'App\\Exchanges\\Bitget\\BitgetAdapter',
                    'supported_timeframes' => ['1m', '5m', '15m', '30m', '1h', '4h', '1d'],
                    'price_precision' => 2,
                    'qty_precision' => 3,
                    'min_qty' => '0.001',
                    'min_notional' => '5.0',
                    'market_types' => ['spot', 'perpetual', 'futures'],
                ], JSON_THROW_ON_ERROR),
                'cost_model' => json_encode([
                    'version' => 'bitget-futures-2026.1',
                    'market' => 'USDT-M Futures',
                    'fee_tier' => 'VIP 0',
                    'maker_rate' => '0.0002',
                    'taker_rate' => '0.0006',
                    'slippage_rate' => '0.0002',
                ], JSON_THROW_ON_ERROR),
                'paper_eligible' => false,
                'paper_approved' => false,
                'paper_approved_at' => null,
            ],
            [
                'id' => 'gate',
                'name' => 'Gate.io',
                'market_type' => 'linear_perpetual',
                'description' => 'Gate.io v4 public market data API covering Spot, Delivery Futures, USDT Perpetuals, and Options.',
                'lifecycle_status' => 'CONNECTED_PUBLIC_DATA',
                'capabilities' => json_encode([
                    'has_candles' => true,
                    'has_funding' => true,
                    'has_open_interest' => true,
                    'has_live_adapter' => true,
                    'adapter_class' => 'App\\Exchanges\\Gate\\GateAdapter',
                    'supported_timeframes' => ['5m', '15m', '30m', '1h', '4h', '1d'],
                    'price_precision' => 2,
                    'qty_precision' => 0,
                    'min_qty' => '1',
                    'min_notional' => '5.0',
                    'market_types' => ['spot', 'perpetual', 'futures', 'options'],
                ], JSON_THROW_ON_ERROR),
                'cost_model' => json_encode([
                    'version' => 'gate-perpetual-2026.1',
                    'market' => 'USDT Futures',
                    'fee_tier' => 'VIP 0',
                    'maker_rate' => '0.00015',
                    'taker_rate' => '0.0005',
                    'slippage_rate' => '0.00025',
                ], JSON_THROW_ON_ERROR),
                'paper_eligible' => false,
                'paper_approved' => false,
                'paper_approved_at' => null,
            ],
            [
                'id' => 'kucoin',
                'name' => 'KuCoin',
                'market_type' => 'linear_perpetual',
                'description' => 'KuCoin public REST API for Spot and KuCoin Futures (Perpetual contracts).',
                'lifecycle_status' => 'CONNECTED_PUBLIC_DATA',
                'capabilities' => json_encode([
                    'has_candles' => true,
                    'has_funding' => true,
                    'has_open_interest' => true,
                    'has_live_adapter' => true,
                    'adapter_class' => 'App\\Exchanges\\KuCoin\\KuCoinAdapter',
                    'supported_timeframes' => ['5', '15', '30', '60', '240', '1440'],
                    'price_precision' => 2,
                    'qty_precision' => 0,
                    'min_qty' => '1',
                    'min_notional' => '5.0',
                    'market_types' => ['spot', 'perpetual', 'futures'],
                ], JSON_THROW_ON_ERROR),
                'cost_model' => json_encode([
                    'version' => 'kucoin-futures-2026.1',
                    'market' => 'USDT-M Futures',
                    'fee_tier' => 'Level 0',
                    'maker_rate' => '0.0002',
                    'taker_rate' => '0.0006',
                    'slippage_rate' => '0.0003',
                ], JSON_THROW_ON_ERROR),
                'paper_eligible' => false,
                'paper_approved' => false,
                'paper_approved_at' => null,
            ],
            [
                'id' => 'kraken',
                'name' => 'Kraken',
                'market_type' => 'spot',
                'description' => 'Kraken Public REST API for Spot and Kraken Derivatives (Futures).',
                'lifecycle_status' => 'CONNECTED_PUBLIC_DATA',
                'capabilities' => json_encode([
                    'has_candles' => true,
                    'has_funding' => true,
                    'has_open_interest' => true,
                    'has_live_adapter' => true,
                    'adapter_class' => 'App\\Exchanges\\Kraken\\KrakenAdapter',
                    'supported_timeframes' => ['5', '15', '30', '60', '240', '1440'],
                    'price_precision' => 1,
                    'qty_precision' => 8,
                    'min_qty' => '0.0001',
                    'min_notional' => '10.0',
                    'market_types' => ['spot', 'perpetual', 'futures'],
                ], JSON_THROW_ON_ERROR),
                'cost_model' => json_encode([
                    'version' => 'kraken-derivatives-2026.1',
                    'market' => 'Multi-Collateral Futures',
                    'fee_tier' => 'Base Tier',
                    'maker_rate' => '0.0002',
                    'taker_rate' => '0.0005',
                    'slippage_rate' => '0.0002',
                ], JSON_THROW_ON_ERROR),
                'paper_eligible' => false,
                'paper_approved' => false,
                'paper_approved_at' => null,
            ],
            [
                'id' => 'htx',
                'name' => 'HTX (Huobi)',
                'market_type' => 'linear_perpetual',
                'description' => 'HTX / Huobi DM public API for Spot, Coin Swaps, and USDT-Margined Linear Contracts.',
                'lifecycle_status' => 'CONNECTED_PUBLIC_DATA',
                'capabilities' => json_encode([
                    'has_candles' => true,
                    'has_funding' => true,
                    'has_open_interest' => true,
                    'has_live_adapter' => true,
                    'adapter_class' => 'App\\Exchanges\\Htx\\HtxAdapter',
                    'supported_timeframes' => ['5min', '15min', '30min', '60min', '4hour', '1day'],
                    'price_precision' => 2,
                    'qty_precision' => 0,
                    'min_qty' => '1',
                    'min_notional' => '10.0',
                    'market_types' => ['spot', 'perpetual', 'futures'],
                ], JSON_THROW_ON_ERROR),
                'cost_model' => json_encode([
                    'version' => 'htx-linear-swap-2026.1',
                    'market' => 'Linear Swap',
                    'fee_tier' => 'Standard',
                    'maker_rate' => '0.0002',
                    'taker_rate' => '0.0005',
                    'slippage_rate' => '0.0003',
                ], JSON_THROW_ON_ERROR),
                'paper_eligible' => false,
                'paper_approved' => false,
                'paper_approved_at' => null,
            ],
        ];

        foreach ($exchanges as $ex) {
            DB::table('exchanges')->insertOrIgnore(array_merge($ex, [
                'metadata' => json_encode(['onboarding_version' => 'v2.0'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_exchange_assignments');
        Schema::dropIfExists('exchange_capabilities');
    }
};
