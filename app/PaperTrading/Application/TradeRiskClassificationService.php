<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Domain\TradeRiskClass;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Throwable;

/**
 * Evaluates trade-level risk across exposure, leverage, stop-loss distance, drawdown, and market dynamics.
 * Strictly decoupled from signal quality / setup edge.
 */
final readonly class TradeRiskClassificationService
{
    public const VERSION = '1.0.0';

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @param array{
     *     equity?: string|float|int|null,
     *     entry_price?: string|float|int|null,
     *     stop_loss?: string|float|int|null,
     *     take_profit_1?: string|float|int|null,
     *     risk_capital?: string|float|int|null,
     *     notional?: string|float|int|null,
     *     current_drawdown_percent?: string|float|int|null,
     *     open_positions_count?: int|null,
     *     atr?: string|float|int|null,
     *     bid?: string|float|int|null,
     *     ask?: string|float|int|null,
     *     correlation?: string|float|int|null,
     *     estimated_costs?: string|float|int|null
     * } $input
     * @return array{
     *     risk_class: TradeRiskClass,
     *     risk_score: int,
     *     factors: array<string, array{value: string|float|int|null, level: string, weight: int, score: int}>,
     *     warnings: list<string>
     * }
     */
    public function classify(array $input): array
    {
        try {
            $equity = BigDecimal::of((string) ($input['equity'] ?? '0'));
            if ($equity->isLessThanOrEqualTo(0)) {
                return $this->unknownResult('Equity must be positive.');
            }

            $entry = BigDecimal::of((string) ($input['entry_price'] ?? '0'));
            $stop = BigDecimal::of((string) ($input['stop_loss'] ?? '0'));
            if ($entry->isLessThanOrEqualTo(0) || $stop->isLessThanOrEqualTo(0) || $entry->isEqualTo($stop)) {
                return $this->unknownResult('Valid entry and stop-loss prices are required.');
            }

            $factors = [];
            $warnings = [];
            $totalScore = 0;
            $totalWeight = 0;
            $highCount = 0;

            // 1. Risk Capital as % of Equity
            $riskCapital = BigDecimal::of((string) ($input['risk_capital'] ?? '0'));
            if ($riskCapital->isPositive()) {
                $riskEquityPct = $riskCapital->dividedBy($equity, 6, RoundingMode::HalfEven)->multipliedBy(100)->toFloat();
                if ($riskEquityPct > 0.55) {
                    $level = 'HIGH';
                    $score = 90;
                    $highCount++;
                    $warnings[] = "Risk capital ({$riskEquityPct}%) exceeds standard 0.50% ceiling.";
                } elseif ($riskEquityPct > 0.20) {
                    $level = 'MEDIUM';
                    $score = 45;
                } else {
                    $level = 'LOW';
                    $score = 15;
                }
                $factors['risk_equity_percent'] = ['value' => round($riskEquityPct, 4), 'level' => $level, 'weight' => 20, 'score' => $score];
                $totalScore += $score * 20;
                $totalWeight += 20;
            }

            // 2. Stop-Loss Distance %
            $slDistPct = $entry->minus($stop)->abs()->dividedBy($entry, 6, RoundingMode::HalfEven)->multipliedBy(100)->toFloat();
            if ($slDistPct > 6.00) {
                $level = 'HIGH';
                $score = 85;
                $highCount++;
                $warnings[] = "Wide stop loss ({$slDistPct}%) increases tail risk.";
            } elseif ($slDistPct > 1.80) {
                $level = 'MEDIUM';
                $score = 45;
            } else {
                $level = 'LOW';
                $score = 15;
            }
            $factors['stop_loss_distance_percent'] = ['value' => round($slDistPct, 3), 'level' => $level, 'weight' => 15, 'score' => $score];
            $totalScore += $score * 15;
            $totalWeight += 15;

            // 3. Notional Leverage
            $notional = BigDecimal::of((string) ($input['notional'] ?? '0'));
            if ($notional->isPositive()) {
                $leverage = $notional->dividedBy($equity, 4, RoundingMode::HalfEven)->toFloat();
                if ($leverage > 1.20) {
                    $level = 'HIGH';
                    $score = 90;
                    $highCount++;
                    $warnings[] = "Position notional leverage ({$leverage}x) is elevated.";
                } elseif ($leverage > 0.60) {
                    $level = 'MEDIUM';
                    $score = 50;
                } else {
                    $level = 'LOW';
                    $score = 15;
                }
                $factors['notional_leverage'] = ['value' => round($leverage, 2), 'level' => $level, 'weight' => 15, 'score' => $score];
                $totalScore += $score * 15;
                $totalWeight += 15;
            }

            // 4. Current Account Drawdown
            $dd = (float) ($input['current_drawdown_percent'] ?? 0.0);
            if ($dd >= 3.0) {
                $level = 'HIGH';
                $score = 85;
                $highCount++;
                $warnings[] = "Account in elevated drawdown ({$dd}%).";
            } elseif ($dd >= 1.0) {
                $level = 'MEDIUM';
                $score = 50;
            } else {
                $level = 'LOW';
                $score = 10;
            }
            $factors['drawdown_percent'] = ['value' => round($dd, 2), 'level' => $level, 'weight' => 15, 'score' => $score];
            $totalScore += $score * 15;
            $totalWeight += 15;

            // 5. Portfolio Open Positions Count
            $openPos = (int) ($input['open_positions_count'] ?? 0);
            if ($openPos >= 2) {
                $level = 'HIGH';
                $score = 75;
                $highCount++;
            } elseif ($openPos === 1) {
                $level = 'MEDIUM';
                $score = 40;
            } else {
                $level = 'LOW';
                $score = 10;
            }
            $factors['open_positions'] = ['value' => $openPos, 'level' => $level, 'weight' => 10, 'score' => $score];
            $totalScore += $score * 10;
            $totalWeight += 10;

            // 6. Volatility (ATR %)
            if (isset($input['atr']) && is_numeric($input['atr'])) {
                $atr = BigDecimal::of((string) $input['atr']);
                if ($atr->isPositive()) {
                    $atrPct = $atr->dividedBy($entry, 6, RoundingMode::HalfEven)->multipliedBy(100)->toFloat();
                    if ($atrPct > 3.0 || $atrPct < 0.20) {
                        $level = 'HIGH';
                        $score = 85;
                        $highCount++;
                        $warnings[] = "Abnormal ATR volatility ({$atrPct}%).";
                    } elseif ($atrPct > 2.0) {
                        $level = 'MEDIUM';
                        $score = 50;
                    } else {
                        $level = 'LOW';
                        $score = 15;
                    }
                    $factors['volatility_atr_percent'] = ['value' => round($atrPct, 3), 'level' => $level, 'weight' => 10, 'score' => $score];
                    $totalScore += $score * 10;
                    $totalWeight += 10;
                }
            }

            // 7. Liquidity Spread
            if (isset($input['bid'], $input['ask']) && is_numeric($input['bid']) && is_numeric($input['ask'])) {
                $bid = BigDecimal::of((string) $input['bid']);
                $ask = BigDecimal::of((string) $input['ask']);
                $mid = $bid->plus($ask)->dividedBy(2, 6, RoundingMode::HalfEven);
                if ($mid->isPositive()) {
                    $spreadPct = $ask->minus($bid)->dividedBy($mid, 6, RoundingMode::HalfEven)->multipliedBy(100)->toFloat();
                    if ($spreadPct > 0.08) {
                        $level = 'HIGH';
                        $score = 80;
                        $highCount++;
                        $warnings[] = "Elevated bid/ask spread ({$spreadPct}%).";
                    } elseif ($spreadPct > 0.03) {
                        $level = 'MEDIUM';
                        $score = 45;
                    } else {
                        $level = 'LOW';
                        $score = 10;
                    }
                    $factors['spread_drag_percent'] = ['value' => round($spreadPct, 4), 'level' => $level, 'weight' => 5, 'score' => $score];
                    $totalScore += $score * 5;
                    $totalWeight += 5;
                }
            }

            // 8. Correlation with Existing Positions
            if (isset($input['correlation']) && is_numeric($input['correlation'])) {
                $corr = abs((float) $input['correlation']);
                if ($corr >= 0.70) {
                    $level = 'HIGH';
                    $score = 90;
                    $highCount++;
                    $warnings[] = "High portfolio correlation ({$corr}) detected.";
                } elseif ($corr >= 0.50) {
                    $level = 'MEDIUM';
                    $score = 50;
                } else {
                    $level = 'LOW';
                    $score = 15;
                }
                $factors['correlation'] = ['value' => round($corr, 3), 'level' => $level, 'weight' => 5, 'score' => $score];
                $totalScore += $score * 5;
                $totalWeight += 5;
            }

            // 9. Execution Cost Drag
            if (isset($input['estimated_costs'], $input['take_profit_1']) && is_numeric($input['estimated_costs']) && is_numeric($input['take_profit_1'])) {
                $tp1 = BigDecimal::of((string) $input['take_profit_1']);
                $costs = BigDecimal::of((string) $input['estimated_costs']);
                $targetGain = $tp1->minus($entry)->abs();
                if ($targetGain->isPositive()) {
                    $costDrag = $costs->dividedBy($targetGain, 4, RoundingMode::HalfEven)->toFloat();
                    if ($costDrag > 0.20) {
                        $level = 'HIGH';
                        $score = 80;
                        $highCount++;
                        $warnings[] = "High execution cost drag ({$costDrag} of TP1).";
                    } elseif ($costDrag > 0.10) {
                        $level = 'MEDIUM';
                        $score = 45;
                    } else {
                        $level = 'LOW';
                        $score = 10;
                    }
                    $factors['execution_cost_drag'] = ['value' => round($costDrag, 3), 'level' => $level, 'weight' => 5, 'score' => $score];
                    $totalScore += $score * 5;
                    $totalWeight += 5;
                }
            }

            $compositeScore = $totalWeight > 0 ? (int) round($totalScore / $totalWeight) : 50;

            // Overall Classification Rule:
            // - If >= 3 High factors OR compositeScore >= 65 OR (>= 2 High factors AND compositeScore >= 50) => HIGH
            // - If compositeScore <= 35 AND highCount === 0 => LOW
            // - Otherwise => MEDIUM
            if ($highCount >= 3 || $compositeScore >= 65 || ($highCount >= 2 && $compositeScore >= 50)) {
                $riskClass = TradeRiskClass::High;
            } elseif ($compositeScore <= 35 && $highCount === 0) {
                $riskClass = TradeRiskClass::Low;
            } else {
                $riskClass = TradeRiskClass::Medium;
            }

            return [
                'risk_class' => $riskClass,
                'risk_score' => $compositeScore,
                'factors' => $factors,
                'warnings' => $warnings,
            ];
        } catch (Throwable $e) {
            return $this->unknownResult('Risk classification error: '.$e->getMessage());
        }
    }

    private function unknownResult(string $warning): array
    {
        return [
            'risk_class' => TradeRiskClass::Unknown,
            'risk_score' => 50,
            'factors' => [],
            'warnings' => [$warning],
        ];
    }
}
