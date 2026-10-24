<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner-approved M0 Option A: 13 existing Bybit accounts + 13 isolated Binance accounts. */
return new class extends Migration
{
    public function up(): void
    {
        // Additive provenance: existing rows keep every amount, event and identifier.
        foreach (['paper_accounts', 'paper_signal_states', 'virtual_positions', 'virtual_executions',
            'equity_snapshots', 'risk_decisions', 'trading_journal_events', 'position_funding_events'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'exchange_id')) {
                Schema::table($name, static function (Blueprint $table): void {
                    $table->string('exchange_id', 32)->default('bybit');
                });
            }
        }

        // Existing historical PRIMARY mapping was unique by strategy only. Switch
        // to exchange+strategy. This is the single non-additive index change
        // authorized by the owner-approved 13 x 2 amendment.
        Schema::table('paper_accounts', static function (Blueprint $table): void {
            $table->dropUnique('paper_accounts_primary_strategy_unique');
            $table->unique(['exchange_id', 'primary_strategy_id'], 'paper_accounts_exchange_primary_unique');
            $table->index(['exchange_id', 'is_primary'], 'paper_accounts_exchange_cohort_index');
        });

        foreach (['virtual_positions', 'risk_decisions', 'equity_snapshots'] as $name) {
            if (Schema::hasTable($name)) {
                Schema::table($name, static function (Blueprint $table): void {
                    $table->index(['exchange_id', 'paper_account_id']);
                });
            }
        }

        Schema::create('exchange_symbol_mappings', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('exchange_id', 32);
            $table->string('canonical_symbol', 32);
            $table->string('exchange_symbol', 32);
            $table->string('base_asset', 32);
            $table->string('quote_asset', 32);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('effective_from_utc', 3);
            $table->unique(['exchange_id', 'canonical_symbol', 'effective_from_utc'], 'exchange_symbols_canonical_unique');
            $table->unique(['exchange_id', 'exchange_symbol', 'effective_from_utc'], 'exchange_symbols_native_unique');
        });

        // Account provisioning is a separately authorized and auditable command.
        // Merely applying a schema migration cannot create or activate accounts.

    }

    public function down(): void
    {
        // No destructive rollback of Binance paper accounts, snapshots, trades or
        // any account history. Explicit owner-supervised archival is required.
        throw new LogicException('Exchange account migration is intentionally irreversible to preserve paper history.');
    }
};
