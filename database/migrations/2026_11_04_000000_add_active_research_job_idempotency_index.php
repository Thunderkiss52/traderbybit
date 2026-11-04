<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX = 'research_jobs_active_kind_idempotency_unique';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Active research-job index requires PostgreSQL or SQLite.');
        }

        $duplicate = DB::table('research_jobs')
            ->select('kind', 'idempotency_key')
            ->whereNotIn('status', ['SUCCEEDED', 'FAILED', 'CANCELLED', 'EXPIRED'])
            ->groupBy('kind', 'idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicate !== null) {
            throw new RuntimeException('Active research-job duplicates require investigation before adding the unique index.');
        }

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX." ON research_jobs (kind, idempotency_key)
            WHERE status NOT IN ('SUCCEEDED', 'FAILED', 'CANCELLED', 'EXPIRED')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
