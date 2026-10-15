<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('status', 20)->default('ACTIVE');
            $table->decimal('starting_balance', 36, 12);
            $table->decimal('current_equity', 36, 12);
            $table->decimal('peak_equity', 36, 12);
            $table->decimal('realized_pnl', 36, 12)->default(0);
            $table->decimal('unrealized_pnl', 36, 12)->default(0);
            $table->decimal('reserved_risk', 36, 12)->default(0);
            $table->decimal('maximum_drawdown_percent', 16, 8)->default(0);
            $table->decimal('current_drawdown_percent', 16, 8)->default(0);
            $table->timestampTz('cooldown_until', precision: 3)->nullable();
            $table->boolean('drawdown_override')->default(false);
            $table->timestampsTz(precision: 3);
        });

        Schema::create('paper_signal_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('paper_account_id');
            $table->uuid('signal_id');
            $table->string('status', 24);
            $table->string('reason', 100)->nullable();
            $table->timestampTz('processed_at', precision: 3)->nullable();
            $table->timestampsTz(precision: 3);
            $table->foreign('paper_account_id')->references('id')->on('paper_accounts')->cascadeOnDelete();
            $table->foreign('signal_id')->references('id')->on('signals')->cascadeOnDelete();
            $table->unique(['paper_account_id', 'signal_id'], 'paper_signal_account_unique');
        });

        Schema::create('virtual_positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('paper_account_id');
            $table->uuid('signal_id');
            $table->string('symbol', 32);
            $table->string('side', 8);
            $table->string('quality_class', 1);
            $table->string('status', 32);
            $table->decimal('entry_price', 30, 12);
            $table->decimal('stop_loss', 30, 12);
            $table->decimal('take_profit_1', 30, 12);
            $table->decimal('take_profit_2', 30, 12);
            $table->decimal('initial_quantity', 30, 12);
            $table->decimal('remaining_quantity', 30, 12);
            $table->decimal('qty_step', 30, 12);
            $table->decimal('risk_capital', 36, 12);
            $table->decimal('gross_pnl', 36, 12)->default(0);
            $table->decimal('net_pnl', 36, 12)->default(0);
            $table->decimal('unrealized_pnl', 36, 12)->default(0);
            $table->decimal('fees', 36, 12)->default(0);
            $table->decimal('funding', 36, 12)->default(0);
            $table->decimal('r_multiple', 20, 10)->nullable();
            $table->timestampTz('opened_at', precision: 3);
            $table->timestampTz('maximum_holding_until', precision: 3);
            $table->timestampTz('closed_at', precision: 3)->nullable();
            $table->timestampTz('last_processed_event_at', precision: 3)->nullable();
            $table->unsignedInteger('timeout_delay_seconds')->nullable();
            $table->boolean('ambiguous_execution')->default(false);
            $table->boolean('funding_complete')->default(true);
            $table->boolean('awaiting_market_data')->default(false);
            $table->string('execution_model', 40);
            $table->string('strategy_version', 32);
            $table->string('risk_version', 32);
            $table->string('execution_version', 32);
            $table->string('schema_version', 32);
            $table->timestampsTz(precision: 3);
            $table->foreign('paper_account_id')->references('id')->on('paper_accounts')->cascadeOnDelete();
            $table->foreign('signal_id')->references('id')->on('signals')->cascadeOnDelete();
            $table->unique(['paper_account_id', 'signal_id']);
            $table->index(['paper_account_id', 'status', 'symbol']);
        });

        Schema::create('virtual_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('position_id');
            $table->string('type', 24);
            $table->string('side', 8);
            $table->decimal('quantity', 30, 12);
            $table->decimal('price', 30, 12);
            $table->decimal('gross_pnl', 36, 12)->default(0);
            $table->decimal('commission', 36, 12);
            $table->decimal('slippage', 36, 12);
            $table->timestampTz('market_event_at', precision: 3);
            $table->timestampTz('registered_at', precision: 3);
            $table->string('price_source', 40);
            $table->boolean('ambiguous_execution')->default(false);
            $table->string('idempotency_key', 160)->unique();
            $table->string('execution_version', 32);
            $table->string('schema_version', 32);
            $table->timestampsTz(precision: 3);
            $table->foreign('position_id')->references('id')->on('virtual_positions')->cascadeOnDelete();
        });

        Schema::create('market_funding_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('exchange', 24);
            $table->string('category', 24);
            $table->string('symbol', 32);
            $table->timestampTz('funding_at', precision: 3);
            $table->decimal('rate', 20, 12);
            $table->decimal('mark_price', 30, 12);
            $table->timestampsTz(precision: 3);
            $table->unique(['exchange', 'category', 'symbol', 'funding_at'], 'market_funding_event_unique');
        });

        Schema::create('position_funding_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('position_id');
            $table->unsignedBigInteger('market_funding_event_id');
            $table->decimal('quantity', 30, 12);
            $table->decimal('mark_price', 30, 12);
            $table->decimal('rate', 20, 12);
            $table->decimal('amount', 36, 12);
            $table->timestampTz('funding_at', precision: 3);
            $table->timestampsTz(precision: 3);
            $table->foreign('position_id')->references('id')->on('virtual_positions')->cascadeOnDelete();
            $table->foreign('market_funding_event_id')->references('id')->on('market_funding_events')->cascadeOnDelete();
            $table->unique(['position_id', 'market_funding_event_id']);
        });

        Schema::create('risk_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('paper_account_id');
            $table->uuid('signal_id');
            $table->boolean('allowed');
            $table->json('reasons');
            $table->json('snapshot');
            $table->string('risk_version', 32);
            $table->timestampTz('decided_at', precision: 3);
            $table->timestampsTz(precision: 3);
            $table->foreign('paper_account_id')->references('id')->on('paper_accounts')->cascadeOnDelete();
            $table->foreign('signal_id')->references('id')->on('signals')->cascadeOnDelete();
            $table->unique(['paper_account_id', 'signal_id', 'risk_version']);
        });

        Schema::create('equity_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('paper_account_id');
            $table->timestampTz('recorded_at', precision: 3);
            $table->decimal('equity', 36, 12);
            $table->decimal('realized_pnl', 36, 12);
            $table->decimal('unrealized_pnl', 36, 12);
            $table->decimal('drawdown_percent', 16, 8);
            $table->string('source_event_key', 160);
            $table->timestampsTz(precision: 3);
            $table->foreign('paper_account_id')->references('id')->on('paper_accounts')->cascadeOnDelete();
            $table->unique(['paper_account_id', 'source_event_key']);
        });

        Schema::create('trading_journal_events', function (Blueprint $table): void {
            $table->bigIncrements('sequence');
            $table->uuid('id')->unique();
            $table->uuid('paper_account_id');
            $table->uuid('signal_id')->nullable();
            $table->uuid('position_id')->nullable();
            $table->string('event_type', 40);
            $table->timestampTz('occurred_at', precision: 3);
            $table->json('payload');
            $table->string('idempotency_key', 180)->unique();
            $table->timestampsTz(precision: 3);
            $table->foreign('paper_account_id')->references('id')->on('paper_accounts')->cascadeOnDelete();
            $table->index(['paper_account_id', 'occurred_at', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_journal_events');
        Schema::dropIfExists('equity_snapshots');
        Schema::dropIfExists('risk_decisions');
        Schema::dropIfExists('position_funding_events');
        Schema::dropIfExists('market_funding_events');
        Schema::dropIfExists('virtual_executions');
        Schema::dropIfExists('virtual_positions');
        Schema::dropIfExists('paper_signal_states');
        Schema::dropIfExists('paper_accounts');
    }
};
