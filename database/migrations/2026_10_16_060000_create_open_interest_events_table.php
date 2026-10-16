<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_open_interest_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('exchange', 24);
            $table->string('category', 24);
            $table->string('symbol', 32);
            $table->string('interval', 8);
            $table->timestampTz('observed_at', precision: 3);
            $table->decimal('open_interest', 36, 12);
            $table->timestampsTz(precision: 3);
            $table->unique(['exchange', 'category', 'symbol', 'interval', 'observed_at'], 'open_interest_event_identity');
            $table->index(['symbol', 'interval', 'observed_at'], 'open_interest_event_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_open_interest_events');
    }
};
