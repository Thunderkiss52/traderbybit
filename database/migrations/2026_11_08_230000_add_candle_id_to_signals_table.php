<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disable transaction wrapping so PostgreSQL CREATE INDEX CONCURRENTLY
     * and staged non-blocking DDL can execute without holding exclusive locks.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        // Stage A: Add nullable column (catalog metadata update only, < 5ms)
        if (! Schema::hasColumn('signals', 'candle_id')) {
            if ($isPgsql) {
                DB::statement("SET lock_timeout = '5s'");
            }

            Schema::table('signals', function (Blueprint $table): void {
                $table->unsignedBigInteger('candle_id')->nullable()->after('symbol');
            });
        }

        // Stage B: Create index safely (CONCURRENTLY on PostgreSQL, outside transaction)
        if ($isPgsql) {
            $indexStatus = DB::selectOne("
                SELECT c.relname, i.indisvalid
                FROM pg_class c
                JOIN pg_index i ON c.oid = i.indexrelid
                JOIN pg_class t ON t.oid = i.indrelid
                WHERE t.relname = 'signals' AND c.relname = 'signals_candle_id_index'
            ");

            if ($indexStatus !== null && ! (bool) $indexStatus->indisvalid) {
                // Invalid index left behind by an interrupted concurrent build. Drop and recreate.
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS signals_candle_id_index');
                $indexStatus = null;
            }

            if ($indexStatus === null) {
                DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS signals_candle_id_index ON signals (candle_id)');
            }
        } else {
            Schema::table('signals', function (Blueprint $table): void {
                $table->index(['candle_id'], 'signals_candle_id_index');
            });
        }

        // Stage C: Add foreign key constraint with NOT VALID (avoids scanning table under exclusive lock)
        if ($isPgsql) {
            $fkExists = DB::selectOne("
                SELECT 1 FROM information_schema.table_constraints
                WHERE constraint_name = 'signals_candle_id_foreign'
                  AND table_name = 'signals'
            ");

            if (! $fkExists) {
                DB::statement("SET lock_timeout = '5s'");
                DB::statement('
                    ALTER TABLE signals
                    ADD CONSTRAINT signals_candle_id_foreign
                    FOREIGN KEY (candle_id) REFERENCES market_candles (id)
                    ON DELETE SET NULL NOT VALID
                ');
            }

            // Stage D: Validate foreign key in separate transaction (ShareUpdateExclusiveLock, non-blocking for writers)
            $fkValidated = DB::selectOne("
                SELECT convalidated
                FROM pg_constraint
                WHERE conname = 'signals_candle_id_foreign'
            ");

            if ($fkValidated !== null && ! (bool) $fkValidated->convalidated) {
                DB::statement("SET lock_timeout = '5s'");
                DB::statement("SET statement_timeout = '30s'");
                DB::statement('ALTER TABLE signals VALIDATE CONSTRAINT signals_candle_id_foreign');
                DB::statement("SET statement_timeout = '0'");
            }

            DB::statement("SET lock_timeout = '0'");
        } else {
            Schema::table('signals', function (Blueprint $table): void {
                $table->foreign('candle_id', 'signals_candle_id_foreign')
                    ->references('id')
                    ->on('market_candles')
                    ->nullOnDelete();
            });
        }

        // NOTE: The catastrophic mass UPDATE query has been permanently removed.
        // Historical signal-to-candle linkage is performed independently via:
        // php artisan signals:backfill-candle-ids
    }

    public function down(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            $fkExists = DB::selectOne("
                SELECT 1 FROM information_schema.table_constraints
                WHERE constraint_name = 'signals_candle_id_foreign'
                  AND table_name = 'signals'
            ");

            if ($fkExists) {
                DB::statement('ALTER TABLE signals DROP CONSTRAINT IF EXISTS signals_candle_id_foreign');
            }

            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS signals_candle_id_index');
        } else {
            Schema::table('signals', function (Blueprint $table): void {
                $table->dropForeign('signals_candle_id_foreign');
                $table->dropIndex('signals_candle_id_index');
            });
        }

        if (Schema::hasColumn('signals', 'candle_id')) {
            Schema::table('signals', function (Blueprint $table): void {
                $table->dropColumn('candle_id');
            });
        }
    }
};
