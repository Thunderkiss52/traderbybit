<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->string('strategy_id', 64)->default('baseline')->after('schema_version');
            $table->index(['strategy_id', 'decision_time', 'id'], 'signals_strategy_time_index');
        });
        Schema::table('paper_accounts', function (Blueprint $table): void {
            $table->string('mode', 32)->default('FORWARD_PAPER');
            $table->string('strategy_id', 64)->default('baseline');
            $table->string('strategy_version', 32)->nullable();
            $table->uuid('research_experiment_id')->nullable();
            $table->string('configuration_hash', 64)->nullable();
            $table->json('strategy_parameters')->nullable();
            $table->foreign('research_experiment_id')->references('id')->on('research_experiments')->nullOnDelete();
            $table->index(['mode', 'strategy_id', 'status'], 'paper_accounts_strategy_index');
        });
    }

    public function down(): void
    {
        Schema::table('paper_accounts', function (Blueprint $table): void {
            $table->dropIndex('paper_accounts_strategy_index');
            $table->dropForeign(['research_experiment_id']);
            $table->dropColumn(['mode', 'strategy_id', 'strategy_version', 'research_experiment_id', 'configuration_hash', 'strategy_parameters']);
        });
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropIndex('signals_strategy_time_index');
            $table->dropColumn('strategy_id');
        });
    }
};
