<?php

declare(strict_types=1);

namespace App\PaperTrading\Console;

use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Console\Command;

final class PaperReconcileFundingCommand extends Command
{
    protected $signature = 'paper:reconcile-funding
        {--exchange=bybit : Target exchange (e.g. bybit, binance)}
        {--symbol=* : Filter by symbol(s)}
        {--position-id= : Target a single position UUID}
        {--dry-run : Preview matching positions and estimated adjustments without mutating database records}
        {--force : Execute reconciliation changes}';

    protected $description = 'Safely and idempotently reconcile pending funding settlements for closed PAPER positions';

    public function handle(PositionLifecycleService $lifecycle): int
    {
        $exchange = (string) ($this->option('exchange') ?: 'bybit');
        $symbols = (array) $this->option('symbol');
        $positionId = $this->option('position-id');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! $force) {
            $this->warn('Safety protection: specify --dry-run to preview pending settlements safely, or --force to execute changes.');

            return self::INVALID;
        }

        if (empty($symbols) && $positionId === null) {
            $symbols = ['BTCUSDT', 'ETHUSDT'];
        }

        if ($dryRun) {
            return $this->previewReconciliation($lifecycle, $exchange, $symbols, $positionId);
        }

        return $this->executeReconciliation($lifecycle, $exchange, $symbols, $positionId);
    }

    private function previewReconciliation(
        PositionLifecycleService $lifecycle,
        string $exchange,
        array $symbols,
        ?string $positionId
    ): int {
        $this->info("=== DRY-RUN PREVIEW: Pending Funding Settlements [Exchange: {$exchange}] ===");

        $positions = VirtualPositionRecord::query()
            ->where('exchange_id', $exchange)
            ->when($positionId, fn ($q) => $q->where('id', $positionId))
            ->when(! empty($symbols), fn ($q) => $q->whereIn('symbol', $symbols))
            ->whereNotNull('closed_at')
            ->orderBy('closed_at')
            ->get();

        $rows = [];
        $totalEstimatedCashflow = BigDecimal::zero();
        $candidateCount = 0;

        foreach ($positions as $position) {
            $mfes = MarketFundingEventRecord::query()
                ->where('exchange', $exchange)
                ->where('category', 'linear')
                ->where('symbol', $position->symbol)
                ->where('funding_at', '>', $position->opened_at)
                ->where('funding_at', '<=', $position->closed_at)
                ->orderBy('funding_at')
                ->get();

            foreach ($mfes as $mfe) {
                $alreadySettled = PositionFundingEventRecord::query()
                    ->where('position_id', $position->id)
                    ->where('market_funding_event_id', $mfe->id)
                    ->exists();

                if ($alreadySettled) {
                    continue;
                }

                $heldQty = $lifecycle->quantityHeldAt($position, $mfe->funding_at->toDateTimeImmutable());
                if (BigDecimal::of($heldQty)->isLessThanOrEqualTo(0)) {
                    continue;
                }

                $multiplier = $position->side === 'BUY' ? -1 : 1;
                $amount = BigDecimal::of($heldQty)
                    ->multipliedBy($mfe->mark_price)
                    ->multipliedBy($mfe->rate)
                    ->multipliedBy($multiplier)
                    ->toScale(12, RoundingMode::HalfEven);

                $totalEstimatedCashflow = $totalEstimatedCashflow->plus($amount);
                $candidateCount++;

                $rows[] = [
                    substr((string) $position->id, 0, 8).'...',
                    $position->symbol,
                    $position->side,
                    $position->opened_at->format('Y-m-d H:i'),
                    $position->closed_at->format('Y-m-d H:i'),
                    (string) BigDecimal::of($heldQty)->toScale(4, RoundingMode::HalfEven),
                    $mfe->funding_at->format('Y-m-d H:i'),
                    (string) $mfe->rate,
                    (string) BigDecimal::of($mfe->mark_price)->toScale(2, RoundingMode::HalfEven),
                    (string) $amount->toScale(6, RoundingMode::HalfEven).' USDT',
                ];
            }
        }

        if (empty($rows)) {
            $this->info('No unapplied funding events found for matching positions.');

            return self::SUCCESS;
        }

        $this->table([
            'Position', 'Symbol', 'Side', 'Opened At', 'Closed At',
            'Held Qty', 'Funding At', 'Rate', 'Mark Price', 'Est. Cashflow',
        ], $rows);

        $this->info("Dry-run preview complete: {$candidateCount} pending settlements found.");
        $this->info("Net estimated cashflow adjustment: {$totalEstimatedCashflow->toScale(6, RoundingMode::HalfEven)} USDT.");
        $this->warn('Zero database records were modified (dry-run mode).');

        return self::SUCCESS;
    }

    private function executeReconciliation(
        PositionLifecycleService $lifecycle,
        string $exchange,
        array $symbols,
        ?string $positionId
    ): int {
        $this->info("Executing live funding reconciliation for exchange [{$exchange}]...");

        $totalApplied = 0;
        foreach ($symbols as $symbol) {
            $applied = $lifecycle->reconcilePendingFunding($exchange, $symbol);
            $totalApplied += $applied;
            $this->line("  {$exchange}:{$symbol} -> {$applied} funding events applied.");
        }

        $this->info("Reconciliation complete. Total funding settlements applied: {$totalApplied}.");

        return self::SUCCESS;
    }
}
