<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('autonomy_settings')->where('id', 'default')->first();
        if ($row === null) {
            return;
        }

        $capabilities = json_decode((string) $row->capabilities, true);
        if (! is_array($capabilities)) {
            $capabilities = [];
        }

        $capabilities['auto_promote_paper'] = true;
        $capabilities['live_exchange_trading'] = false;
        $capabilities['change_hard_risk_limits'] = false;

        DB::table('autonomy_settings')->where('id', 'default')->update([
            'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
            'updated_at' => now('UTC'),
        ]);
    }

    public function down(): void
    {
        $row = DB::table('autonomy_settings')->where('id', 'default')->first();
        if ($row === null) {
            return;
        }

        $capabilities = json_decode((string) $row->capabilities, true);
        if (! is_array($capabilities)) {
            $capabilities = [];
        }

        $capabilities['auto_promote_paper'] = false;
        $capabilities['live_exchange_trading'] = false;
        $capabilities['change_hard_risk_limits'] = false;

        DB::table('autonomy_settings')->where('id', 'default')->update([
            'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
            'updated_at' => now('UTC'),
        ]);
    }
};
