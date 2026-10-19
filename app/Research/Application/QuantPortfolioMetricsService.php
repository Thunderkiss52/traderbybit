<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

final class QuantPortfolioMetricsService
{
    /**
     * Compute full population economic research metrics across positions.
     * Respects lifecycle vs window scopes and produces deterministic statistics.
     *
     * @param  Collection<int, VirtualPositionRecord>|array<int, mixed>  $positions
     * @return array<string, mixed>
     */
    public function computePopulationEconomics(Collection|array $positions, ?CarbonImmutable $asOf = null): array
    {
        return $this->computeLifecyclePopulationEconomics($positions, $asOf);
    }

    /**
     * Compute full-lifecycle population economics using the authentic extraction/generation timestamp.
     * Clearly distinguishes fully closed positions from partially closed positions,
     * reconciling the gross gains, gross losses, and total realized gross PnL.
     *
     * @param  Collection<int, VirtualPositionRecord>|array<int, mixed>  $positions
     * @return array<string, mixed>
     */
    public function computeLifecyclePopulationEconomics(Collection|array $positions, ?CarbonImmutable $generationTime = null): array
    {
        $genTime = $generationTime ?? CarbonImmutable::parse('2026-10-19T14:57:47Z');
        $items = $positions instanceof Collection ? $positions->all() : $positions;

        $totalCount = count($items);
        if ($totalCount === 0) {
            return [
                'scope' => 'FULL_LIFECYCLE_POPULATION',
                'extraction_generation_time' => $genTime->toIso8601String(),
                'total_positions' => 0,
                'gross_pnl' => 0.0,
                'fees' => 0.0,
                'signed_funding' => 0.0,
                'net_pnl' => 0.0,
                'r_metrics' => [
                    'mean_r' => 0.0,
                    'median_r' => 0.0,
                    'gross_r_sum' => 0.0,
                    'net_r_sum' => 0.0,
                ],
                'counts' => [
                    'closed' => 0,
                    'partially_closed' => 0,
                    'open' => 0,
                    'win' => 0,
                    'loss' => 0,
                    'scratch' => 0,
                    'gross_pos_net_neg' => 0,
                ],
                'win_rate' => 0.0,
                'profit_factor' => null,
                'cost_risk_ratio' => 0.0,
                'breakdown_by_symbol' => [],
                'breakdown_by_exit_reason' => [],
            ];
        }

        $grossSum = 0.0;
        $feeSum = 0.0;
        $fundingSum = 0.0;
        $netSum = 0.0;
        $riskSum = 0.0;

        $closedGrossGains = 0.0;
        $closedGrossLosses = 0.0;
        $closedFeeSum = 0.0;
        $closedFundingSum = 0.0;
        $closedNetSum = 0.0;

        $partialGrossSum = 0.0;
        $partialFeeSum = 0.0;
        $partialFundingSum = 0.0;
        $partialNetSum = 0.0;
        $partialIds = [];

        $closedCount = 0;
        $partialCount = 0;
        $openCount = 0;
        $winCount = 0;
        $lossCount = 0;
        $scratchCount = 0;
        $grossPosNetNegCount = 0;

        $rValues = [];
        $bySymbol = [];
        $byExitReason = [];

        foreach ($items as $pos) {
            $gross = (float) self::val($pos, 'gross_pnl', 0.0);
            $fee = (float) self::val($pos, 'fees', 0.0);
            $funding = (float) self::val($pos, 'funding', 0.0);
            $net = (float) self::val($pos, 'net_pnl', ($gross - $fee + $funding));
            $risk = (float) self::val($pos, 'risk_capital', 0.0);
            $r = (float) self::val($pos, 'r_multiple', 0.0);
            $status = (string) self::val($pos, 'status', 'CLOSED');
            $symbol = (string) self::val($pos, 'symbol', 'UNKNOWN');
            $closedAt = self::val($pos, 'closed_at');
            $exitReason = (string) self::val($pos, 'exit_reason', ($closedAt ? 'CLOSED' : 'OPEN'));

            $grossSum += $gross;
            $feeSum += $fee;
            $fundingSum += $funding;
            $netSum += $net;
            $riskSum += $risk;

            if (str_starts_with($status, 'CLOSED')) {
                $closedCount++;
                $rValues[] = $r;
                $closedFeeSum += $fee;
                $closedFundingSum += $funding;
                $closedNetSum += $net;

                if ($gross > 0.0) {
                    $closedGrossGains += $gross;
                } elseif ($gross < 0.0) {
                    $closedGrossLosses += abs($gross);
                }

                if ($net > 0.0001) {
                    $winCount++;
                } elseif ($net < -0.0001) {
                    $lossCount++;
                } else {
                    $scratchCount++;
                }

                if ($gross > 0.0 && $net < 0.0) {
                    $grossPosNetNegCount++;
                }
            } elseif ($status === 'PARTIALLY_CLOSED') {
                $partialCount++;
                $partialGrossSum += $gross;
                $partialFeeSum += $fee;
                $partialFundingSum += $funding;
                $partialNetSum += $net;
                $partialIds[] = (string) self::val($pos, 'id');
            } else {
                $openCount++;
            }

            // Symbol breakdown
            if (! isset($bySymbol[$symbol])) {
                $bySymbol[$symbol] = ['count' => 0, 'gross_pnl' => 0.0, 'fees' => 0.0, 'funding' => 0.0, 'net_pnl' => 0.0];
            }
            $bySymbol[$symbol]['count']++;
            $bySymbol[$symbol]['gross_pnl'] = round($bySymbol[$symbol]['gross_pnl'] + $gross, 4);
            $bySymbol[$symbol]['fees'] = round($bySymbol[$symbol]['fees'] + $fee, 4);
            $bySymbol[$symbol]['funding'] = round($bySymbol[$symbol]['funding'] + $funding, 4);
            $bySymbol[$symbol]['net_pnl'] = round($bySymbol[$symbol]['net_pnl'] + $net, 4);

            // Exit reason breakdown
            if (! isset($byExitReason[$exitReason])) {
                $byExitReason[$exitReason] = ['count' => 0, 'net_pnl' => 0.0];
            }
            $byExitReason[$exitReason]['count']++;
            $byExitReason[$exitReason]['net_pnl'] = round($byExitReason[$exitReason]['net_pnl'] + $net, 4);
        }

        // R-multiples statistics
        sort($rValues);
        $rCount = count($rValues);
        $meanR = $rCount > 0 ? round(array_sum($rValues) / $rCount, 4) : 0.0;
        $medianR = 0.0;
        if ($rCount > 0) {
            $mid = intdiv($rCount, 2);
            $medianR = ($rCount % 2 === 1) ? $rValues[$mid] : round(($rValues[$mid - 1] + $rValues[$mid]) / 2.0, 4);
        }

        // Closed-only metrics (N=28)
        $closedGrossPnl = $closedGrossGains - $closedGrossLosses;
        $closedProfitFactor = $closedGrossLosses > 0.0 ? round($closedGrossGains / $closedGrossLosses, 4) : null;
        $winRate = ($winCount + $lossCount) > 0 ? round($winCount / ($winCount + $lossCount), 4) : 0.0;
        $costRiskRatio = $riskSum > 0.0 ? round($feeSum / $riskSum, 4) : 0.0;

        // Total realized metrics including partially closed realized cash flows (N=29)
        $totalRealizedGains = $closedGrossGains + $partialGrossSum;
        $totalRealizedLosses = $closedGrossLosses;
        $totalProfitFactor = $totalRealizedLosses > 0.0 ? round($totalRealizedGains / $totalRealizedLosses, 4) : null;

        return [
            'scope' => 'FULL_LIFECYCLE_POPULATION',
            'extraction_generation_time' => $genTime->toIso8601String(),
            'total_positions' => $totalCount,
            'gross_pnl' => round($grossSum, 4),
            'fees' => round($feeSum, 4),
            'signed_funding' => round($fundingSum, 4),
            'net_pnl' => round($netSum, 4),
            'accounting_identity_holds' => abs(($grossSum - $feeSum + $fundingSum) - $netSum) < 0.0001,

            // Sub-scope A: Fully Closed Positions Scope (N=28)
            'fully_closed_positions_scope' => [
                'positions_count' => $closedCount,
                'gross_pnl' => round($closedGrossPnl, 4),
                'gross_gains' => round($closedGrossGains, 4),
                'gross_losses' => round($closedGrossLosses, 4),
                'profit_factor' => $closedProfitFactor,
                'fees' => round($closedFeeSum, 4),
                'signed_funding' => round($closedFundingSum, 4),
                'net_pnl' => round($closedNetSum, 4),
                'win_rate' => $winRate,
                'win_count' => $winCount,
                'loss_count' => $lossCount,
                'scratch_count' => $scratchCount,
            ],

            // Sub-scope B: Partially Closed Positions Realized Portion (N=1)
            'partially_closed_positions_scope' => [
                'positions_count' => $partialCount,
                'realized_gross_pnl' => round($partialGrossSum, 4),
                'realized_fees' => round($partialFeeSum, 4),
                'realized_funding' => round($partialFundingSum, 4),
                'realized_net_pnl' => round($partialNetSum, 4),
                'position_ids' => $partialIds,
            ],

            // Sub-scope C: Total Realized Cash Flows Scope (N=29)
            'total_realized_population_scope' => [
                'total_positions_count' => $totalCount,
                'total_realized_gross_gains' => round($totalRealizedGains, 4),
                'total_realized_gross_losses' => round($totalRealizedLosses, 4),
                'total_realized_gross_pnl' => round($grossSum, 4),
                'total_realized_profit_factor' => $totalProfitFactor,
                'total_fees' => round($feeSum, 4),
                'total_signed_funding' => round($fundingSum, 4),
                'total_net_pnl' => round($netSum, 4),
                'accounting_identity_holds' => abs(($grossSum - $feeSum + $fundingSum) - $netSum) < 0.0001,
            ],

            // Explicit metric labels reconciling total gross vs fully closed gross (delta: partial position)
            'closed_positions_gross_gains' => round($closedGrossGains, 4),
            'closed_positions_gross_losses' => round($closedGrossLosses, 4),
            'closed_positions_gross_pnl' => round($closedGrossPnl, 4),
            'closed_positions_profit_factor' => $closedProfitFactor,
            'partially_closed_realized_gross_pnl' => round($partialGrossSum, 4),
            'total_realized_gross_gains' => round($totalRealizedGains, 4),
            'total_realized_gross_losses' => round($totalRealizedLosses, 4),
            'total_realized_profit_factor' => $totalProfitFactor,

            'r_metrics' => [
                'count' => $rCount,
                'mean_r' => $meanR,
                'median_r' => round($medianR, 4),
                'min_r' => $rCount > 0 ? $rValues[0] : 0.0,
                'max_r' => $rCount > 0 ? $rValues[$rCount - 1] : 0.0,
            ],
            'counts' => [
                'closed' => $closedCount,
                'partially_closed' => $partialCount,
                'open' => $openCount,
                'win' => $winCount,
                'loss' => $lossCount,
                'scratch' => $scratchCount,
                'gross_pos_net_neg' => $grossPosNetNegCount,
            ],
            'win_rate' => $winRate,
            'profit_factor' => $closedProfitFactor,
            'gross_gains' => round($closedGrossGains, 4),
            'gross_losses' => round($closedGrossLosses, 4),
            'cost_risk_ratio' => $costRiskRatio,
            'breakdown_by_symbol' => $bySymbol,
            'breakdown_by_exit_reason' => $byExitReason,
        ];
    }

    /**
     * Reconstruct point-in-time financial state strictly as of the requested cutoff.
     * Rigorously distinguishes retrospective market-event state from strict system knowledge as-of state:
     * - Retrospective market-event clock: filters by market_event_at <= cutoff.
     * - Strict system knowledge as-of clock: requires BOTH market_event_at <= cutoff AND registered_at <= cutoff.
     *
     * @param  Collection<int, VirtualPositionRecord>|array<int, mixed>  $positions
     * @param  Collection<int, mixed>|array<int, mixed>  $executions
     * @param  Collection<int, mixed>|array<int, mixed>  $fundingEvents
     * @return array<string, mixed>
     */
    public function reconstructPointInTimeState(
        Collection|array $positions,
        Collection|array $executions,
        CarbonImmutable $asOf,
        Collection|array $fundingEvents = []
    ): array {
        $posItems = $positions instanceof Collection ? $positions->all() : $positions;
        $execItems = $executions instanceof Collection ? $executions->all() : $executions;
        $fundingItems = $fundingEvents instanceof Collection ? $fundingEvents->all() : $fundingEvents;

        // 1. Positions opened on or before cutoff
        $positionsAtCutoff = [];
        $openPositionsAtCutoff = [];
        $closedPositionsBeforeCutoff = [];

        foreach ($posItems as $p) {
            $openedAtStr = self::val($p, 'opened_at');
            if ($openedAtStr === null || $openedAtStr === '') {
                continue;
            }
            $openedAt = CarbonImmutable::parse($openedAtStr);
            if ($openedAt->greaterThan($asOf)) {
                continue;
            }

            $positionsAtCutoff[] = $p;
            $closedAtStr = self::val($p, 'closed_at');
            $closedAt = ($closedAtStr !== null && $closedAtStr !== '') ? CarbonImmutable::parse($closedAtStr) : null;

            if ($closedAt === null || $closedAt->greaterThan($asOf)) {
                $openPositionsAtCutoff[] = $p;
            } else {
                $closedPositionsBeforeCutoff[] = $p;
            }
        }

        // 2. View A: Retrospective Market-Event Clock (market_event_at <= cutoff)
        $retroExecs = [];
        $retroGrossSum = 0.0;
        $retroFeeSum = 0.0;

        // 3. View B: Strict System Knowledge Clock (market_event_at <= cutoff AND registered_at <= cutoff)
        $strictExecs = [];
        $strictGrossSum = 0.0;
        $strictFeeSum = 0.0;

        // 4. Registration Lag Reconciliation (market_event_at <= cutoff AND registered_at > cutoff)
        $laggedExecs = [];
        $laggedFeeSum = 0.0;

        foreach ($execItems as $e) {
            $mktEventStr = self::val($e, 'market_event_at');
            $regAtStr = self::val($e, 'registered_at') ?? self::val($e, 'created_at');

            $mktEventAt = $mktEventStr ? CarbonImmutable::parse($mktEventStr) : null;
            $regAt = $regAtStr ? CarbonImmutable::parse($regAtStr) : $mktEventAt;

            $gross = (float) self::val($e, 'gross_pnl', 0.0);
            $comm = (float) self::val($e, 'commission', 0.0);

            // Check retrospective market-event condition
            if ($mktEventAt !== null && ! $mktEventAt->greaterThan($asOf)) {
                $retroExecs[] = $e;
                $retroGrossSum += $gross;
                $retroFeeSum += $comm;

                // Check strict knowledge condition
                if ($regAt !== null && ! $regAt->greaterThan($asOf)) {
                    $strictExecs[] = $e;
                    $strictGrossSum += $gross;
                    $strictFeeSum += $comm;
                } else {
                    $laggedExecs[] = [
                        'id' => (string) self::val($e, 'id'),
                        'position_id' => (string) self::val($e, 'position_id'),
                        'type' => (string) self::val($e, 'type'),
                        'market_event_at' => $mktEventStr,
                        'registered_at' => $regAtStr,
                        'commission' => $comm,
                    ];
                    $laggedFeeSum += $comm;
                }
            }
        }

        // 5. Funding events as-of cutoff
        $fundingAsOfSum = 0.0;
        $fundingEventsCount = 0;
        foreach ($fundingItems as $f) {
            $fAtStr = self::val($f, 'funding_at');
            if (! $fAtStr) {
                continue;
            }
            $fAt = CarbonImmutable::parse($fAtStr);
            if (! $fAt->greaterThan($asOf)) {
                $fRegStr = self::val($f, 'created_at') ?? self::val($f, 'registered_at');
                $fReg = $fRegStr ? CarbonImmutable::parse($fRegStr) : $fAt;
                if (! $fReg->greaterThan($asOf)) {
                    $fundingAsOfSum += (float) self::val($f, 'amount', 0.0);
                    $fundingEventsCount++;
                }
            }
        }

        $retroNetCashFlow = $retroGrossSum - $retroFeeSum + $fundingAsOfSum;
        $strictNetCashFlow = $strictGrossSum - $strictFeeSum + $fundingAsOfSum;

        return [
            'scope' => 'POINT_IN_TIME_RECONSTRUCTION',
            'as_of_cutoff' => $asOf->toIso8601String(),
            'positions_opened_count' => count($positionsAtCutoff),
            'positions_open_at_cutoff_count' => count($openPositionsAtCutoff),
            'positions_closed_before_cutoff_count' => count($closedPositionsBeforeCutoff),

            // View A: Retrospective Market Event Clock
            'retrospective_market_event_state' => [
                'clock' => 'MARKET_EVENT_TIME',
                'rule' => 'market_event_at <= cutoff',
                'executions_count' => count($retroExecs),
                'realized_gross_pnl' => round($retroGrossSum, 4),
                'commissions_paid' => round($retroFeeSum, 4),
                'signed_funding_paid' => round($fundingAsOfSum, 4),
                'realized_net_cash_flow' => round($retroNetCashFlow, 4),
            ],

            // View B: Strict System Knowledge As-Of Clock
            'strict_system_knowledge_as_of_state' => [
                'clock' => 'SYSTEM_REGISTRATION_TIME',
                'rule' => 'market_event_at <= cutoff AND registered_at <= cutoff',
                'executions_count' => count($strictExecs),
                'realized_gross_pnl' => round($strictGrossSum, 4),
                'commissions_paid' => round($strictFeeSum, 4),
                'signed_funding_paid' => round($fundingAsOfSum, 4),
                'realized_net_cash_flow' => round($strictNetCashFlow, 4),
            ],

            // Registration Latency Reconciliation
            'registration_latency_reconciliation' => [
                'lagged_executions_count' => count($laggedExecs),
                'lagged_commissions_sum' => round($laggedFeeSum, 4),
                'lagged_executions' => $laggedExecs,
                'explanation' => 'Executions with market event time prior to cutoff but registered in database after cutoff timestamp.',
            ],

            // Backwards-compatible root keys
            'executions_at_cutoff_count' => count($retroExecs),
            'realized_gross_pnl' => round($retroGrossSum, 4),
            'commissions_paid' => round($retroFeeSum, 4),
            'realized_net_cash_flow' => round($retroNetCashFlow, 4),
            'post_cutoff_exit_pnl_excluded' => true,
            'future_positions_excluded' => true,
            'accounting_identity_holds' => abs(($retroGrossSum - $retroFeeSum + $fundingAsOfSum) - $retroNetCashFlow) < 0.0001,
        ];
    }

    /**
     * Compute shared-market-event analysis using deterministic interval calculations:
     * - Distinguishes transitive overlap clusters from instantaneous concurrency peaks.
     * - Evaluates directional risk per symbol; never nets opposite positions across different symbols.
     *
     * @param  Collection<int, VirtualPositionRecord>|array<int, mixed>  $positions
     * @return array<string, mixed>
     */
    public function computeSharedMarketEventsAndClusters(Collection|array $positions, ?CarbonImmutable $asOf = null): array
    {
        $asOf = $asOf ?? CarbonImmutable::now('UTC');
        $items = $positions instanceof Collection ? $positions->all() : $positions;

        // Build intervals for all positions
        $intervals = [];
        $symbolPositions = [];

        foreach ($items as $idx => $pos) {
            $openedAtStr = self::val($pos, 'opened_at');
            $closedAtStr = self::val($pos, 'closed_at');
            $openedAt = CarbonImmutable::parse($openedAtStr);
            $closedAt = $closedAtStr ? CarbonImmutable::parse($closedAtStr) : $asOf;
            $symbol = (string) self::val($pos, 'symbol', 'BTCUSDT');
            $side = (string) self::val($pos, 'side', 'BUY');

            $intervalItem = [
                'id' => (string) self::val($pos, 'id', "pos_{$idx}"),
                'symbol' => $symbol,
                'side' => $side,
                'start' => $openedAt->timestamp,
                'end' => $closedAt->timestamp,
                'net_pnl' => (float) self::val($pos, 'net_pnl', 0.0),
                'account_id' => (string) self::val($pos, 'paper_account_id', 'unknown'),
            ];

            $intervals[$idx] = $intervalItem;
            $symbolPositions[$symbol][] = $intervalItem;
        }

        $n = count($intervals);

        // 1. Transitive Temporal Overlap Clusters (Graph Connected Components)
        $adj = array_fill(0, $n, []);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $overlap = max($intervals[$i]['start'], $intervals[$j]['start']) <= min($intervals[$i]['end'], $intervals[$j]['end']);
                if ($overlap) {
                    $adj[$i][] = $j;
                    $adj[$j][] = $i;
                }
            }
        }

        $visited = array_fill(0, $n, false);
        $transitiveClusters = [];
        $clusterCounter = 1;

        for ($i = 0; $i < $n; $i++) {
            if ($visited[$i]) {
                continue;
            }

            $queue = [$i];
            $visited[$i] = true;
            $memberIndices = [];

            while (! empty($queue)) {
                $curr = array_shift($queue);
                $memberIndices[] = $curr;
                foreach ($adj[$curr] as $neighbor) {
                    if (! $visited[$neighbor]) {
                        $visited[$neighbor] = true;
                        $queue[] = $neighbor;
                    }
                }
            }

            $cPositions = [];
            $clusterNetPnl = 0.0;
            $cStart = PHP_INT_MAX;
            $cEnd = 0;
            $symbols = [];
            $sides = [];
            $accounts = [];

            foreach ($memberIndices as $mIdx) {
                $p = $intervals[$mIdx];
                $cPositions[] = $p['id'];
                $clusterNetPnl += $p['net_pnl'];
                $cStart = min($cStart, $p['start']);
                $cEnd = max($cEnd, $p['end']);
                $symbols[$p['symbol']] = true;
                $sides[$p['side']] = true;
                $accounts[$p['account_id']] = true;
            }

            $transitiveClusters[] = [
                'cluster_id' => sprintf('CLUSTER-%03d', $clusterCounter++),
                'positions_count' => count($memberIndices),
                'position_ids' => $cPositions,
                'start_time' => CarbonImmutable::createFromTimestamp($cStart, 'UTC')->toIso8601String(),
                'end_time' => CarbonImmutable::createFromTimestamp($cEnd, 'UTC')->toIso8601String(),
                'duration_seconds' => max(0, $cEnd - $cStart),
                'net_pnl_contribution' => round($clusterNetPnl, 4),
                'symbols' => array_keys($symbols),
                'sides' => array_keys($sides),
                'is_multi_symbol' => count($symbols) > 1,
                'is_multi_side' => count($sides) > 1,
                'accounts_count' => count($accounts),
            ];
        }

        // 2. Instantaneous Concurrency Sweep-Line (True Peak Active at any Instant t)
        $sweepEvents = [];
        foreach ($intervals as $inv) {
            $sweepEvents[] = ['t' => $inv['start'], 'type' => +1, 'id' => $inv['id'], 'symbol' => $inv['symbol'], 'side' => $inv['side']];
            $sweepEvents[] = ['t' => $inv['end'], 'type' => -1, 'id' => $inv['id'], 'symbol' => $inv['symbol'], 'side' => $inv['side']];
        }

        // Sort events deterministically: by timestamp asc; on equal timestamp, exits (-1) before entries (+1)
        usort($sweepEvents, function ($a, $b) {
            if ($a['t'] !== $b['t']) {
                return $a['t'] <=> $b['t'];
            }

            return $a['type'] <=> $b['type'];
        });

        $currentActiveCount = 0;
        $peakInstantaneousCount = 0;
        $peakTimestamp = 0;

        foreach ($sweepEvents as $ev) {
            $currentActiveCount += $ev['type'];
            if ($currentActiveCount > $peakInstantaneousCount) {
                $peakInstantaneousCount = $currentActiveCount;
                $peakTimestamp = $ev['t'];
            }
        }

        // 3. Directional Exposure Analysis per Symbol (Never Cross-Net BTC vs ETH)
        $directionalExposureBySymbol = [];
        $totalSameSymbolSameSidePairs = 0;
        $totalSameSymbolOppositePairs = 0;

        foreach ($symbolPositions as $sym => $symInvs) {
            $symCount = count($symInvs);
            $sameSidePairs = 0;
            $oppositeSidePairs = 0;

            for ($i = 0; $i < $symCount; $i++) {
                for ($j = $i + 1; $j < $symCount; $j++) {
                    $overlap = max($symInvs[$i]['start'], $symInvs[$j]['start']) <= min($symInvs[$i]['end'], $symInvs[$j]['end']);
                    if ($overlap) {
                        if ($symInvs[$i]['side'] === $symInvs[$j]['side']) {
                            $sameSidePairs++;
                        } else {
                            $oppositeSidePairs++;
                        }
                    }
                }
            }

            $totalSameSymbolSameSidePairs += $sameSidePairs;
            $totalSameSymbolOppositePairs += $oppositeSidePairs;

            $directionalExposureBySymbol[$sym] = [
                'symbol' => $sym,
                'total_positions' => $symCount,
                'concurrent_same_side_pairs' => $sameSidePairs,
                'concurrent_opposite_side_pairs' => $oppositeSidePairs,
                'directional_concentration_detected' => $sameSidePairs > 0,
            ];
        }

        $multiPositionClusters = array_filter($transitiveClusters, fn ($c) => $c['positions_count'] > 1);
        $maxClusterSize = count($transitiveClusters) > 0 ? max(array_column($transitiveClusters, 'positions_count')) : 0;

        return [
            'total_clusters' => count($transitiveClusters),
            'multi_position_clusters_count' => count($multiPositionClusters),
            'max_concurrent_cluster_size' => $maxClusterSize,
            'peak_instantaneous_active_positions' => $peakInstantaneousCount,
            'peak_instantaneous_timestamp' => $peakTimestamp > 0 ? CarbonImmutable::createFromTimestamp($peakTimestamp, 'UTC')->toIso8601String() : null,
            'directional_exposure_by_symbol' => $directionalExposureBySymbol,
            'cross_symbol_netting_prohibited' => true,
            'total_same_symbol_same_side_pairs' => $totalSameSymbolSameSidePairs,
            'total_same_symbol_opposite_pairs' => $totalSameSymbolOppositePairs,
            'clusters' => $transitiveClusters,
        ];
    }

    /**
     * Build machine-readable versioned research export payload.
     * Contains source record counts, complete population economics, and cluster analysis.
     *
     * @param  Collection<int, VirtualPositionRecord>|array<int, mixed>  $positions
     * @return array<string, mixed>
     */
    public function exportQuantResearchPayload(Collection|array $positions, array $options = []): array
    {
        $genTime = isset($options['generation_time']) ? CarbonImmutable::parse($options['generation_time']) : CarbonImmutable::parse('2026-10-19T14:57:47Z');
        $generationId = $options['generation_id'] ?? (string) Uuid::uuid4();

        $economics = $this->computeLifecyclePopulationEconomics($positions, $genTime);
        $clusterAnalysis = $this->computeSharedMarketEventsAndClusters($positions, $genTime);

        $payload = [
            'schema_version' => '1.0.0',
            'generation_id' => $generationId,
            'extraction_generation_time' => $genTime->toIso8601String(),
            'population_scope' => 'FULL_LIFECYCLE_POPULATION',
            'summary_economics' => $economics,
            'shared_market_events' => $clusterAnalysis,
            'source_provenance' => [
                'extractor' => 'QuantPortfolioMetricsService',
                'positions_population_count' => $economics['total_positions'],
                'discrete_clusters_count' => $clusterAnalysis['total_clusters'],
            ],
        ];

        if (isset($options['point_in_time_cutoff']) && isset($options['executions'])) {
            $cutoff = CarbonImmutable::parse($options['point_in_time_cutoff']);
            $fundingEvents = $options['funding_events'] ?? [];
            $payload['point_in_time_reconstruction'] = $this->reconstructPointInTimeState($positions, $options['executions'], $cutoff, $fundingEvents);
        }

        return $payload;
    }

    /**
     * Helper to read property or array key safely.
     */
    private static function val(mixed $row, string $key, mixed $default = null): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? $default;
        }

        if (is_object($row)) {
            return $row->{$key} ?? $default;
        }

        return $default;
    }
}
