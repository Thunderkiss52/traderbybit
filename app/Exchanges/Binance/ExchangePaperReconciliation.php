<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Read-only M9 proof, no account mutation and no implicit portfolio aggregation. */
final class ExchangePaperReconciliation
{
    public function inspect(): array
    {
        $strategies = (array) config('paper.primary_strategies', []);
        $exchanges = ['bybit', 'binance'];
        // Count every PRIMARY row, not only the two expected exchanges. A
        // foreign/NULL exchange row must not disappear from the 26-account
        // census merely because the supported pairs are all present.
        $allPrimary = DB::table('paper_accounts')->where('is_primary', true)->get();
        $accounts = $allPrimary->filter(static fn ($account): bool => in_array($account->exchange_id, $exchanges, true));
        $issues = [];
        $coverage = [];
        if ($allPrimary->count() !== count($strategies) * count($exchanges)) {
            $issues[] = ['exchange_id' => null, 'strategy_id' => null, 'code' => 'TOTAL_PRIMARY_ACCOUNT_COUNT_MISMATCH'];
        }
        foreach ($allPrimary as $account) {
            if (! in_array($account->exchange_id, $exchanges, true)) {
                $issues[] = ['exchange_id' => $account->exchange_id, 'strategy_id' => $account->primary_strategy_id,
                    'code' => 'UNRECOGNIZED_PRIMARY_EXCHANGE'];
            }
        }
        foreach ($strategies as $strategy) {
            foreach ($exchanges as $exchange) {
                $matches = $accounts->filter(fn ($a) => $a->exchange_id === $exchange && $a->primary_strategy_id === $strategy);
                if ($matches->count() !== 1) {
                    $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy, 'code' => 'ACCOUNT_COUNT_NOT_ONE'];

                    continue;
                }
                $account = $matches->first();
                if ($account->strategy_id !== $strategy || $account->research_experiment_id !== null) {
                    $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy, 'code' => 'INVALID_ACCOUNT_PROVENANCE'];
                }
                foreach (['paper_signal_states', 'risk_decisions', 'trading_journal_events', 'equity_snapshots'] as $table) {
                    // SQL `NULL != 'binance'` is UNKNOWN, not TRUE. Missing
                    // provenance must fail reconciliation, not disappear.
                    $mismatches = DB::table($table)->where('paper_account_id', $account->id)
                        ->where(static fn ($q) => $q->whereNull('exchange_id')
                            ->orWhere('exchange_id', '!=', $exchange))->get();
                    if ($mismatches->isNotEmpty()) {
                        $issues[] = [
                            'exchange_id' => $exchange,
                            'strategy_id' => $strategy,
                            'code' => 'CROSS_EXCHANGE_'.strtoupper($table),
                            'mismatched_count' => $mismatches->count(),
                            'diagnostics' => $mismatches->take(5)->map(fn ($row) => [
                                'id' => $row->id ?? null,
                                'stored_exchange_id' => $row->exchange_id ?? null,
                                'source_event_key' => $row->source_event_key ?? null,
                                'recorded_at' => (string) ($row->recorded_at ?? $row->occurred_at ?? $row->created_at ?? ''),
                            ])->all(),
                        ];
                    }
                }
                // Read *all* capital events for the account. Filtering by
                // exchange before validating would conceal a foreign or NULL
                // transfer; a zero-balance account must not conceal one either.
                $transfers = DB::table('paper_capital_events')->where('paper_account_id', $account->id)->get();
                $approvedCapital = BigDecimal::zero();
                foreach ($transfers as $transfer) {
                    if ($transfer->exchange_id !== $exchange) {
                        $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy,
                            'code' => 'CROSS_EXCHANGE_PAPER_CAPITAL_EVENT'];

                        continue;
                    }
                    if ($exchange === 'binance') {
                        $approvedCapital = $approvedCapital->plus((string) $transfer->amount);
                    }
                }
                if ($exchange === 'binance'
                    && ! $approvedCapital->isEqualTo(BigDecimal::of((string) $account->starting_balance))) {
                    $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy, 'code' => 'PAPER_CAPITAL_PROVENANCE_MISMATCH'];
                }
                $positions = DB::table('virtual_positions')->where('paper_account_id', $account->id)->get();
                if ($exchange === 'binance' && $positions->isNotEmpty()
                    && ! BigDecimal::of((string) $account->starting_balance)->isPositive()) {
                    $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy,
                        'code' => 'UNFUNDED_BINANCE_TRADING_HISTORY'];
                }
                $gross = BigDecimal::zero();
                $fees = BigDecimal::zero();
                $funding = BigDecimal::zero();
                $unrealized = BigDecimal::zero();
                foreach ($positions as $position) {
                    if ($position->exchange_id !== $exchange) {
                        $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy, 'code' => 'CROSS_EXCHANGE_POSITION'];

                        continue;
                    }
                    $expectedPositionNet = BigDecimal::of((string) $position->gross_pnl)->plus((string) $position->funding)->minus((string) $position->fees);
                    if (! $expectedPositionNet->isEqualTo(BigDecimal::of((string) $position->net_pnl))) {
                        $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy, 'code' => 'POSITION_NET_MISMATCH'];
                    }
                    $gross = $gross->plus((string) $position->gross_pnl);
                    $fees = $fees->plus((string) $position->fees);
                    $funding = $funding->plus((string) $position->funding);
                    $unrealized = $unrealized->plus((string) $position->unrealized_pnl);
                    $executions = DB::table('virtual_executions')->where('position_id', $position->id)->get();
                    $recordedFees = BigDecimal::zero();
                    $entryQuantity = BigDecimal::zero();
                    $exitQuantity = BigDecimal::zero();
                    $recordedGross = BigDecimal::zero();
                    $entryCount = 0;
                    $executionTypes = ['TAKE_PROFIT_1', 'TAKE_PROFIT_2', 'STOP_LOSS', 'TIMEOUT', 'RISK_CLOSURE'];
                    foreach ($executions as $execution) {
                        if ($execution->exchange_id !== $exchange) {
                            $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy, 'code' => 'CROSS_EXCHANGE_EXECUTION'];
                        }
                        $recordedFees = $recordedFees->plus((string) $execution->commission);
                        $quantity = BigDecimal::of((string) $execution->quantity);
                        if (! $quantity->isPositive()) {
                            $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy,
                                'code' => 'INVALID_EXECUTION_QUANTITY'];
                        }
                        if ($execution->type === 'ENTRY') {
                            // ENTRY must never contain realized gross PnL.
                            if (! BigDecimal::of((string) $execution->gross_pnl)->isZero()) {
                                $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy,
                                    'code' => 'EXECUTION_GROSS_PNL_MISMATCH'];
                            }
                            $entryCount++;
                            $entryQuantity = $entryQuantity->plus($quantity);
                        } elseif (in_array($execution->type, $executionTypes, true)) {
                            $exitQuantity = $exitQuantity->plus($quantity);
                            $recordedGross = $recordedGross->plus((string) $execution->gross_pnl);
                        } else {
                            $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy,
                                'code' => 'UNKNOWN_EXECUTION_TYPE'];
                        }
                    }
                    if ($entryCount !== 1 || ! $entryQuantity->isEqualTo(BigDecimal::of((string) $position->initial_quantity))) {
                        $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy,
                            'code' => 'EXECUTION_ENTRY_QUANTITY_MISMATCH'];
                    }
                    $remaining = BigDecimal::of((string) $position->remaining_quantity);
                    if ($remaining->isNegative() || ! BigDecimal::of((string) $position->initial_quantity)
                        ->minus($exitQuantity)->isEqualTo($remaining)) {
                        $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy,
                            'code' => 'EXECUTION_REMAINING_QUANTITY_MISMATCH'];
                    }
                    if (! $recordedGross->isEqualTo(BigDecimal::of((string) $position->gross_pnl))) {
                        $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy,
                            'code' => 'EXECUTION_GROSS_PNL_MISMATCH'];
                    }
                    if (! $recordedFees->isEqualTo(BigDecimal::of((string) $position->fees))) {
                        $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy, 'code' => 'FEE_LEDGER_MISMATCH'];
                    }
                    $fundingEvents = DB::table('position_funding_events as settlement')
                        ->leftJoin('market_funding_events as source', 'settlement.market_funding_event_id', '=', 'source.id')
                        ->where('settlement.position_id', $position->id)
                        ->select('settlement.*', 'source.exchange as source_exchange',
                            'source.category as source_category', 'source.symbol as source_symbol',
                            'source.rate as source_rate', 'source.mark_price as source_mark_price')->get();
                    $recordedFunding = BigDecimal::zero();
                    foreach ($fundingEvents as $event) {
                        if ($event->exchange_id !== $exchange) {
                            $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy, 'code' => 'CROSS_EXCHANGE_FUNDING'];
                        }
                        // An apparently Binance-scoped settlement may reference a
                        // *Bybit* market funding event through its foreign key.
                        // Validate the underlying source, not just the denormalized
                        // exchange_id on the financial row.
                        if ($event->source_exchange !== $exchange || $event->source_category !== 'linear'
                            || $event->source_symbol !== $position->symbol) {
                            $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy,
                                'code' => 'FUNDING_SOURCE_PROVENANCE_MISMATCH'];
                        } elseif (! BigDecimal::of((string) $event->rate)->isEqualTo(BigDecimal::of((string) $event->source_rate))
                            || ! BigDecimal::of((string) $event->mark_price)->isEqualTo(BigDecimal::of((string) $event->source_mark_price))) {
                            $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy,
                                'code' => 'FUNDING_SOURCE_TERMS_MISMATCH'];
                        }
                        $recordedFunding = $recordedFunding->plus((string) $event->amount);
                    }
                    if (! $recordedFunding->isEqualTo(BigDecimal::of((string) $position->funding))) {
                        $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy, 'code' => 'FUNDING_LEDGER_MISMATCH'];
                    }
                }
                $expectedRealized = $gross->plus($funding)->minus($fees);
                if ($expectedRealized->minus((string) $account->realized_pnl)->abs()->isGreaterThan('0.000001')) {
                    $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy, 'code' => 'ACCOUNT_REALIZED_MISMATCH'];
                }
                $expectedEquity = BigDecimal::of((string) $account->starting_balance)->plus((string) $account->realized_pnl)->plus((string) $account->unrealized_pnl);
                if ($expectedEquity->minus((string) $account->current_equity)->abs()->isGreaterThan('0.000001')) {
                    $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy, 'code' => 'ACCOUNT_EQUITY_MISMATCH'];
                }
                if (! $unrealized->isEqualTo(BigDecimal::of((string) $account->unrealized_pnl))) {
                    $issues[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy, 'code' => 'UNREALIZED_MISMATCH'];
                }
                $coverage[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy,
                    'positions' => $positions->count(), 'current_equity' => (string) $account->current_equity,
                    'gross_pnl' => (string) $gross, 'fees' => (string) $fees,
                    'funding' => (string) $funding, 'net_realized' => (string) $expectedRealized];
            }
        }

        return [
            'status' => $issues === [] && count($coverage) === count($strategies) * 2 ? 'LEDGER_PASS_ONLY' : 'FAIL',
            'm9_acceptance' => 'NOT_VERIFIED_API_UI_EXPORT_AND_PG_E2E',
            'aggregation_scope' => 'PER_EXCHANGE_PER_STRATEGY',
            'expected_pairs' => count($strategies) * 2,
            'verified_pairs' => count($coverage), 'issues' => $issues, 'accounts' => $coverage,
        ];
    }
}
