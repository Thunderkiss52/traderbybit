<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_capital_events', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('paper_account_id');
            $table->string('exchange_id', 32);
            $table->decimal('amount', 36, 12);
            $table->string('approval_reference', 160);
            $table->string('reason', 50)->default('OWNER_APPROVED_PAPER_ALLOCATION');
            $table->timestampTz('occurred_at', 3);
            $table->timestampsTz(3);
            $table->foreign('paper_account_id')->references('id')->on('paper_accounts');
            $table->unique(['paper_account_id', 'approval_reference'], 'paper_capital_account_approval_unique');
        });
    }

    public function down(): void
    {
        // Historical virtual allocations are immutable accounting evidence.
        throw new LogicException('Paper capital provenance cannot be discarded by down migration.');
    }
};
