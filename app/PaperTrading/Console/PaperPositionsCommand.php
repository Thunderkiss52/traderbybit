<?php

declare(strict_types=1);

namespace App\PaperTrading\Console;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use Brick\Math\BigDecimal;
use Illuminate\Console\Command;

final class PaperPositionsCommand extends Command
{
    protected $signature = 'paper:positions {--account=} {--symbol=} {--at=}';

    protected $description = 'List PAPER virtual positions';

    public function handle(PaperAccountService $accounts): int
    {
        $account = $this->option('account') ?: $accounts->default()->id;
        $at = $this->option('at') ? new \DateTimeImmutable((string) $this->option('at')) : null;
        if ($at !== null && $at->getOffset() !== 0) {
            $this->error('--at must be UTC.');

            return self::INVALID;
        }
        $rows = VirtualPositionRecord::query()->where('paper_account_id', $account)
            ->when($this->option('symbol'), fn ($q) => $q->where('symbol', strtoupper((string) $this->option('symbol'))))
            ->when($at, fn ($q) => $q->where('opened_at', '<=', $at))
            ->oldest('opened_at')->get(['id', 'symbol', 'side', 'status', 'entry_price', 'remaining_quantity', 'net_pnl', 'opened_at', 'closed_at']);
        if ($at !== null) {
            $rows = $rows->map(function (VirtualPositionRecord $position) use ($at): array {
                $executions = VirtualExecutionRecord::query()->where('position_id', $position->id)->where('market_event_at', '<=', $at)->oldest('market_event_at')->get();
                $entry = $executions->firstWhere('type', 'ENTRY');
                $exits = $executions->where('type', '!=', 'ENTRY');
                $remaining = BigDecimal::of($entry?->quantity ?? '0');
                $gross = BigDecimal::zero();
                $fees = BigDecimal::zero();
                foreach ($executions as $execution) {
                    $fees = $fees->plus($execution->commission);
                    $gross = $gross->plus($execution->gross_pnl);
                    if ($execution->type !== 'ENTRY') {
                        $remaining = $remaining->minus($execution->quantity);
                    }
                }
                $funding = PositionFundingEventRecord::query()->where('position_id', $position->id)->where('funding_at', '<=', $at)->get()->reduce(fn (BigDecimal $sum, $event): BigDecimal => $sum->plus($event->amount), BigDecimal::zero());
                $last = $exits->last();
                $status = match ($last?->type) {
                    'TAKE_PROFIT_1' => 'PARTIALLY_CLOSED', 'TAKE_PROFIT_2' => 'CLOSED_TP', 'STOP_LOSS' => 'CLOSED_SL', 'TIMEOUT' => 'CLOSED_TIMEOUT', 'RISK_CLOSURE' => 'CLOSED_RISK', default => 'OPEN'
                };

                return ['id' => $position->id, 'symbol' => $position->symbol, 'side' => $position->side, 'status' => $status, 'entry_price' => $position->entry_price, 'remaining_quantity' => (string) $remaining, 'net_pnl' => (string) $gross->plus($funding)->minus($fees), 'opened_at' => $position->opened_at];
            });
        }
        $this->line($rows->toJson(JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
