<?php

declare(strict_types=1);

namespace App\PaperTrading\Jobs;

use App\PaperTrading\Domain\PositionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** Regular valuation checkpoints, including when there are no open positions. */
final class CapturePaperEquityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function handle(): void
    {
        $at = now('UTC')->startOfMinute();
        $atImmutable = $at->toDateTimeImmutable();
        $key = 'checkpoint:'.$at->format('Y-m-d\\TH:i:00\\Z');

        DB::table('paper_accounts')->orderBy('id')->chunk(100, function ($accounts) use ($at, $atImmutable, $key): void {
            foreach ($accounts as $account) {
                if (empty($account->exchange_id)) {
                    throw new \RuntimeException("Paper account {$account->id} is missing exchange_id.");
                }

                $openPositions = DB::table('virtual_positions')
                    ->where('paper_account_id', $account->id)
                    ->whereIn('status', [PositionStatus::Open->value, PositionStatus::PartiallyClosed->value])
                    ->get(['id', 'symbol', 'opened_at', 'last_processed_event_at']);

                $isFlat = $openPositions->isEmpty();
                $valuationFresh = true;
                $valuedThrough = $atImmutable;

                if (! $isFlat) {
                    $oldestMark = null;
                    foreach ($openPositions as $pos) {
                        $markTime = $pos->last_processed_event_at !== null
                            ? CarbonImmutable::parse($pos->last_processed_event_at)
                            : CarbonImmutable::parse($pos->opened_at);

                        if ($oldestMark === null || $markTime->isBefore($oldestMark)) {
                            $oldestMark = $markTime;
                        }
                    }

                    $valuedThrough = $oldestMark?->toDateTimeImmutable() ?? $atImmutable;
                    // If valuation mark is older than 5 minutes (300s) from checkpoint time, mark as stale
                    $lagSeconds = max(0, $atImmutable->getTimestamp() - $valuedThrough->getTimestamp());
                    if ($lagSeconds > 300) {
                        $valuationFresh = false;
                    }
                }

                DB::table('equity_snapshots')->insertOrIgnore([
                    'paper_account_id' => $account->id,
                    'exchange_id' => $account->exchange_id,
                    'recorded_at' => $at,
                    'equity' => $account->current_equity,
                    'realized_pnl' => $account->realized_pnl,
                    'unrealized_pnl' => $account->unrealized_pnl,
                    'drawdown_percent' => $account->current_drawdown_percent,
                    'source_event_key' => $key,
                    'valued_through' => $valuedThrough,
                    'valuation_fresh' => $valuationFresh,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
