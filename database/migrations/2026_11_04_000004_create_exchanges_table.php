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
        if (! Schema::hasTable('exchanges')) {
            Schema::create('exchanges', static function (Blueprint $table): void {
                $table->string('id', 32)->primary();
                $table->string('name', 64);
                $table->string('market_type', 32)->default('linear_perpetual');
                $table->text('description')->nullable();
                $table->string('lifecycle_status', 32)->default('OFFLINE_DATA_SOURCE');
                $table->json('capabilities');
                $table->json('cost_model')->nullable();
                $table->json('paper_execution_assumptions')->nullable();
                $table->boolean('paper_eligible')->default(false);
                $table->boolean('paper_approved')->default(false);
                $table->timestampTz('paper_approved_at', 3)->nullable();
                $table->json('metadata')->nullable();
                $table->timestampsTz(3);
            });
        }

        $now = now('UTC')->format('Y-m-d H:i:s.uP');

        // Seed canonical Bybit exchange
        DB::table('exchanges')->insertOrIgnore([
            'id' => 'bybit',
            'name' => 'Bybit Linear Perpetual',
            'market_type' => 'linear_perpetual',
            'description' => 'Bybit USDT-perpetual linear contracts with 13 canonical PRIMARY forward paper accounts.',
            'lifecycle_status' => 'ACTIVE_PAPER',
            'capabilities' => json_encode([
                'has_candles' => true,
                'has_funding' => true,
                'has_open_interest' => true,
                'has_live_adapter' => true,
                'adapter_class' => 'App\\Exchanges\\Bybit\\BybitAdapter',
                'supported_timeframes' => ['5', '15', '30', '60'],
                'price_precision' => 2,
                'qty_precision' => 3,
                'min_qty' => '0.001',
                'min_notional' => '5.0',
            ], JSON_THROW_ON_ERROR),
            'cost_model' => json_encode([
                'version' => 'bybit-linear-perpetual-2026.1',
                'market' => 'USDT Perpetual (Linear)',
                'fee_tier' => 'VIP 0',
                'maker_rate' => '0.0002',
                'taker_rate' => '0.00055',
                'slippage_rate' => '0.0002',
            ], JSON_THROW_ON_ERROR),
            'paper_execution_assumptions' => json_encode([
                'execution_model' => 'NEXT_CANDLE_OPEN',
                'ambiguous_execution_policy' => 'FLAG_AND_HOLD',
            ], JSON_THROW_ON_ERROR),
            'paper_eligible' => true,
            'paper_approved' => true,
            'paper_approved_at' => $now,
            'metadata' => json_encode(['provisioned_primary_accounts' => 13], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Seed canonical Binance exchange
        $binanceApproved = (bool) config('exchanges.binance_paper_provisioning_approved', false);
        DB::table('exchanges')->insertOrIgnore([
            'id' => 'binance',
            'name' => 'Binance USD(S)-M Futures',
            'market_type' => 'linear_perpetual',
            'description' => 'Binance USD-M linear futures public market data and 13 provisioned BLOCKED paper accounts.',
            'lifecycle_status' => $binanceApproved ? 'ACTIVE_PAPER' : 'PAPER_ELIGIBLE',
            'capabilities' => json_encode([
                'has_candles' => true,
                'has_funding' => true,
                'has_open_interest' => true,
                'has_live_adapter' => true,
                'adapter_class' => 'App\\Exchanges\\Binance\\BinancePublicAdapter',
                'supported_timeframes' => ['5', '15', '30', '60'],
                'price_precision' => 2,
                'qty_precision' => 3,
                'min_qty' => '0.001',
                'min_notional' => '5.0',
            ], JSON_THROW_ON_ERROR),
            'cost_model' => json_encode(config('exchanges.binance.cost_model', [
                'version' => 'binance-usdm-perpetual-2026.1',
                'market' => 'USD(S)-M Futures (Linear Perpetuals)',
                'fee_tier' => 'VIP 0 (Regular User)',
                'maker_rate' => '0.0002',
                'taker_rate' => '0.0005',
                'slippage_rate' => '0.0002',
            ]), JSON_THROW_ON_ERROR),
            'paper_execution_assumptions' => json_encode([
                'execution_model' => 'NEXT_CANDLE_OPEN',
                'ambiguous_execution_policy' => 'FLAG_AND_HOLD',
            ], JSON_THROW_ON_ERROR),
            'paper_eligible' => true,
            'paper_approved' => $binanceApproved,
            'paper_approved_at' => $binanceApproved ? $now : null,
            'metadata' => json_encode(['provisioned_primary_accounts' => 13], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        throw new LogicException('Exchange registrations cannot be dropped automatically.');
    }
};
