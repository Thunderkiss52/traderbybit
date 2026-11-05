<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Explicit activation of a validated, funded PAPER-only cohort. */
final class BinancePaperActivation
{
    public function activate(): array
    {
        BinancePaperSafety::assertCostEvidence();
        if (config('exchanges.binance.research_cycle_enabled') !== true) {
            throw new DomainException('Binance closed-candle research schedule is disabled.');
        }
        $strategies = PrimaryStrategies::ids('binance');
        if ($strategies === [] || count($strategies) !== count(array_unique($strategies))) {
            throw new DomainException('Nonempty, unique PRIMARY strategy registry required.');
        }
        $symbols = (array) config('market_data.symbols', []);
        if (count($symbols) < 2 || ! in_array('BTCUSDT', $symbols, true) || ! in_array('ETHUSDT', $symbols, true)) {
            throw new DomainException('BTCUSDT and ETHUSDT both required for portability.');
        }
        // Readiness and PAPER activation share the same strict closed-bar gate.
        // Independently verify the most recent context and settled funding
        // with both lower and upper UTC bounds; future-dated rows are not proof.
        $now = now('UTC');
        $nowSeconds = $now->getTimestamp();
        foreach ($symbols as $symbol) {
            foreach (['5', '15', '30', '60'] as $interval) {
                $candles = CandleRecord::query()->where('exchange', 'binance')->where('category', 'linear')
                    ->where('symbol', $symbol)->where('interval', $interval)->where('is_closed', true)
                    ->orderByDesc('open_time')->limit(220)->get(['open_time']);
                $timestamps = $candles->map(fn ($c): int => $c->open_time->getTimestamp())->all();
                $readiness = BinanceCandleWindow::status($timestamps, (int) $interval * 60, $nowSeconds, 220, app(MarketCandleConflictService::class), 'binance', $symbol, $interval);
                if ($readiness !== 'READY') {
                    throw new DomainException("Binance activation rejected {$symbol}:{$interval} ({$readiness}). Required: 220 contiguous closed candles. Run 'php artisan binance:ingest-research {$symbol}' to backfill.");
                }
            }
            if (! MarketContextRecord::query()->where('exchange', 'binance')->where('category', 'linear')
                ->where('symbol', $symbol)->whereBetween('observed_at', [$now->copy()->subMinutes(15), $now])->exists()) {
                throw new DomainException("Recent, non-future Binance market context is unavailable for {$symbol}. Run 'php artisan binance:capture-context {$symbol}' to capture.");
            }
            if (! MarketFundingEventRecord::query()->where('exchange', 'binance')->where('category', 'linear')
                ->where('symbol', $symbol)->whereBetween('funding_at', [$now->copy()->subHours(24), $now])->exists()) {
                throw new DomainException("Recent, non-future Binance funding settlement is unavailable for {$symbol}. Run 'php artisan binance:ingest-funding {$symbol}' to ingest.");
            }
        }

        return DB::transaction(static function () use ($strategies): array {
            $accounts = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('is_primary', true)
                ->lockForUpdate()->get()->keyBy('primary_strategy_id');
            if ($accounts->count() !== count($strategies)) {
                throw new DomainException('Binance PRIMARY account set incomplete.');
            }
            $activated = 0;
            $alreadyActive = 0;
            $toActivate = [];
            foreach ($strategies as $id) {
                $account = $accounts->get($id);
                if ($account === null) {
                    throw new DomainException('Binance PRIMARY account missing.');
                }
                BinancePaperSafety::assertProvenance($account);
                if (in_array($id, (array) config('research.forward_paper.signal_only_strategies', []), true)) {
                    // Signal-only strategies cannot be activated for execution.
                    continue;
                }
                if ($account->status === 'ACTIVE') {
                    $alreadyActive++;

                    continue;
                }
                if (BigDecimal::of($account->current_equity)->isLessThanOrEqualTo('0')
                    || $account->status !== 'BLOCKED'
                    || DB::table('paper_capital_events')->where('paper_account_id', $account->id)->doesntExist()) {
                    throw new DomainException('Explicit initial paper capital and blocked account required.');
                }
                if (DB::table('virtual_positions')->where('paper_account_id', $account->id)->exists()) {
                    throw new DomainException('Previously traded account requires dedicated recovery.');
                }
                $toActivate[] = $account;
            }
            foreach ($toActivate as $account) {
                $account->update(['status' => 'ACTIVE']);
                $activated++;
            }

            $executionApproved = (bool) config('exchanges.binance.paper_execution_approved', false);

            return [
                'exchange_id' => 'binance',
                'paper_activated' => $activated,
                'already_active' => $alreadyActive,
                'still_signal_only' => count($strategies) - ($activated + $alreadyActive),
                'real_order_paths' => 'DISABLED',
                'paper_execution_approved' => $executionApproved,
                'execution_readiness' => $executionApproved ? 'READY_FOR_PAPER_EXECUTION' : 'EXECUTION_BLOCKED_PENDING_APPROVAL',
            ];
        });
    }
}
