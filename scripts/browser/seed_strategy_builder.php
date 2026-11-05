<?php

declare(strict_types=1);
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Synthetic browser fixture; this script cannot run against ordinary application databases.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'bybit_builder_browser') {
    throw new RuntimeException('Only the task-owned bybit_builder_browser fixture is allowed.');
}
foreach ([5, 15, 30, 60] as $minutes) {
    $from = new DateTimeImmutable('2026-01-15T00:00:00Z');
    $to = new DateTimeImmutable('2026-01-16T00:00:00Z');
    for ($at = $from->modify('-'.(221 * $minutes).' minutes'); $at < $to; $at = $at->modify("+{$minutes} minutes")) {
        $price = (string) (100 + (intdiv($at->getTimestamp(), $minutes * 60) % 20));
        CandleRecord::query()->firstOrCreate(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => (string) $minutes, 'open_time' => $at], ['open' => $price, 'high' => '125', 'low' => '95', 'close' => $price, 'volume' => '10', 'turnover' => '1000', 'is_closed' => true]);
    }
}
foreach (['2026-01-15T08:00:00Z', '2026-01-15T16:00:00Z'] as $at) {
    DB::table('market_funding_events')->insertOrIgnore(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'funding_at' => $at, 'rate' => '0.0001', 'mark_price' => '100', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
}
config(['exchanges.binance_paper_provisioning_approved' => true]);
app(PaperTopologySynchronizer::class)->sync(true);
DB::table('paper_accounts')->where('is_primary', true)->where('strategy_id', 'not like', 'user_%')->update(['status' => 'BLOCKED']);
echo "Task-owned synthetic browser coverage, funding and BLOCKED baseline topology ready.\n";
