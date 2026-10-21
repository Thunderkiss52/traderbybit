package backtest

import (
	"fmt"
	"math"
	"time"

	"github.com/traderbybit/research-go/internal/algorithms"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

// Signal is a causal entry instruction produced by a research candidate.
//
// The decision is taken when DecisionBar is closed (DecisionTime is the close
// timestamp of that bar) and the fill happens at the open of EntryBar. EntryBar
// must be strictly greater than DecisionBar, so no fill price is ever known at
// decision time.
type Signal struct {
	Side         string          `json:"side"`
	DecisionTime time.Time       `json:"decision_time"`
	DecisionBar  int             `json:"decision_bar"`
	EntryBar     int             `json:"entry_bar"`
	StopLoss     decimal.Decimal `json:"stop_loss"`
	TakeProfit   decimal.Decimal `json:"take_profit"`
	Reason       string          `json:"reason"`
}

// RunSignals replays externally generated signals over closed candles.
//
// It reuses the cost model, risk sizing, conservative intrabar ambiguity rule
// and metric computation of Engine.Run, so a candidate and a baseline strategy
// are measured with exactly the same assumptions.
func (e *Engine) RunSignals(candles []datasets.Candle, signals []Signal, strategyID string) (report *BacktestReport, retErr error) {
	defer func() {
		if r := recover(); r != nil {
			retErr = fmt.Errorf("signal backtest engine panic: %v", r)
		}
	}()

	n := len(candles)
	if n < 10 {
		return nil, fmt.Errorf("insufficient candles for signal backtest: got %d, need >= 10", n)
	}

	atr := algorithms.CalculateATR(candles, 14)

	byEntryBar := make(map[int]Signal, len(signals))
	for _, s := range signals {
		if s.Side != "BUY" && s.Side != "SELL" {
			continue
		}
		if s.DecisionBar < 0 || s.DecisionBar >= n {
			continue
		}
		if s.EntryBar <= s.DecisionBar || s.EntryBar >= n {
			// A fill that is not strictly after the decision bar would be look-ahead.
			continue
		}
		if _, exists := byEntryBar[s.EntryBar]; !exists {
			byEntryBar[s.EntryBar] = s
		}
	}

	equity := e.config.StartingBalance
	peakEquity := equity
	maxDrawdown := decimal.Zero()
	maxDrawdownPct := 0.0

	trades := make([]Trade, 0, 100)
	equitySeries := make([]EquityPoint, 0, n)

	var pos *activePosition
	tradeCounter := 0

	for i := 0; i < n; i++ {
		c := candles[i]

		if pos != nil {
			barsHeld := i - pos.entryBar
			exitPrice, exitReason, ambiguous, hasExit := e.evaluateExit(pos, c, barsHeld)

			if hasExit {
				tradeCounter++
				exitFee := pos.quantity.Mul(exitPrice).Mul(e.config.TakerFeeRate)
				totalFees := pos.entryFee.Add(exitFee)

				grossPnL := decimal.Zero()
				if pos.side == "BUY" {
					grossPnL = pos.quantity.Mul(exitPrice.Sub(pos.entryPrice))
				} else {
					grossPnL = pos.quantity.Mul(pos.entryPrice.Sub(exitPrice))
				}

				netPnL := grossPnL.Sub(totalFees)
				equity = equity.Add(netPnL)

				rMult := 0.0
				if pos.riskAmount.IsPositive() {
					rMult = netPnL.Float64() / pos.riskAmount.Float64()
				}

				trades = append(trades, Trade{
					ID:         tradeCounter,
					Symbol:     c.Symbol,
					Side:       pos.side,
					EntryTime:  pos.entryTime,
					ExitTime:   c.OpenTime,
					EntryPrice: pos.entryPrice,
					ExitPrice:  exitPrice,
					Quantity:   pos.quantity,
					StopLoss:   pos.stopLoss,
					TakeProfit: pos.takeProfit,
					GrossPnL:   grossPnL,
					Fees:       totalFees,
					NetPnL:     netPnL,
					RMultiple:  rMult,
					ExitReason: exitReason,
					Ambiguous:  ambiguous,
				})

				pos = nil
			}
		}

		if pos == nil {
			if s, ok := byEntryBar[i]; ok {
				// The fill price is the open of the entry bar moved against the trader by the
				// configured slippage. The stop distance is measured from that filled price, so the
				// sized risk is exactly the configured share of the equity.
				fillPrice := c.Open
				if s.Side == "BUY" {
					fillPrice = fillPrice.Add(fillPrice.Mul(e.config.SlippageRate))
				} else {
					fillPrice = fillPrice.Sub(fillPrice.Mul(e.config.SlippageRate))
				}

				slDistance := decimal.Zero()
				stopLoss := s.StopLoss
				takeProfit := s.TakeProfit

				if !stopLoss.IsZero() {
					slDistance = fillPrice.Sub(stopLoss)
					if slDistance.IsNegative() {
						slDistance = slDistance.Neg()
					}
					// A stop on the wrong side of the fill would be stopped out immediately and
					// would make the risk budget meaningless.
					if s.Side == "BUY" && stopLoss.Cmp(fillPrice) >= 0 {
						continue
					}
					if s.Side == "SELL" && stopLoss.Cmp(fillPrice) <= 0 {
						continue
					}
				} else if i > 0 && atr[i-1] != nil && atr[i-1].IsPositive() {
					// The ATR of the bar BEFORE the fill is the last value known at decision time.
					slDistance = atr[i-1].Mul(decimal.FromFloat64(1.5))
				}

				if slDistance.IsPositive() {
					if stopLoss.IsZero() {
						if s.Side == "BUY" {
							stopLoss = fillPrice.Sub(slDistance)
						} else {
							stopLoss = fillPrice.Add(slDistance)
						}
					}
					if takeProfit.IsZero() {
						if s.Side == "BUY" {
							takeProfit = fillPrice.Add(slDistance.Mul(decimal.FromFloat64(2)))
						} else {
							takeProfit = fillPrice.Sub(slDistance.Mul(decimal.FromFloat64(2)))
						}
					}

					opened := e.openPosition(s.Side, fillPrice, slDistance, equity, c.OpenTime, i)
					if opened != nil {
						opened.stopLoss = stopLoss
						opened.takeProfit = takeProfit
						pos = opened
					}
				}
			}
		}

		if equity.Cmp(peakEquity) > 0 {
			peakEquity = equity
		}
		dd := peakEquity.Sub(equity)
		if dd.Cmp(maxDrawdown) > 0 {
			maxDrawdown = dd
		}
		ddPct := 0.0
		if peakEquity.IsPositive() {
			ddPct = (dd.Float64() / peakEquity.Float64()) * 100.0
		}
		if ddPct > maxDrawdownPct {
			maxDrawdownPct = ddPct
		}

		if i%10 == 0 || i == n-1 {
			equitySeries = append(equitySeries, EquityPoint{
				Time:            c.OpenTime,
				Equity:          equity,
				DrawdownPercent: math.Round(ddPct*100) / 100,
			})
		}
	}

	return e.buildReport(strategyID, trades, equitySeries, equity, peakEquity, maxDrawdown, maxDrawdownPct), nil
}

// buildReport aggregates trades into the shared report structure.
func (e *Engine) buildReport(strategyID string, trades []Trade, equitySeries []EquityPoint, equity, peakEquity, maxDrawdown decimal.Decimal, maxDrawdownPct float64) *BacktestReport {
	wins := 0
	losses := 0
	grossProfit := decimal.Zero()
	grossLoss := decimal.Zero()
	totalFees := decimal.Zero()
	sumR := 0.0

	for _, t := range trades {
		totalFees = totalFees.Add(t.Fees)
		sumR += t.RMultiple
		if t.NetPnL.IsPositive() {
			wins++
			grossProfit = grossProfit.Add(t.NetPnL)
		} else if t.NetPnL.IsNegative() {
			losses++
			grossLoss = grossLoss.Add(t.NetPnL.Neg())
		}
	}

	totalTrades := len(trades)
	winRate := 0.0
	avgR := 0.0
	expectancy := 0.0

	if totalTrades > 0 {
		winRate = float64(wins) / float64(totalTrades)
		avgR = sumR / float64(totalTrades)
		expectancy = avgR * winRate
	}

	var profitFactor *float64
	if grossLoss.IsPositive() {
		pf := grossProfit.Float64() / grossLoss.Float64()
		profitFactor = &pf
	}

	return &BacktestReport{
		StrategyID:         strategyID,
		TotalTrades:        totalTrades,
		Wins:               wins,
		Losses:             losses,
		WinRate:            math.Round(winRate*10000) / 10000,
		GrossProfit:        grossProfit,
		GrossLoss:          grossLoss,
		TotalFees:          totalFees,
		NetPnL:             equity.Sub(e.config.StartingBalance),
		ProfitFactor:       profitFactor,
		MaxDrawdown:        maxDrawdown,
		MaxDrawdownPercent: math.Round(maxDrawdownPct*100) / 100,
		AverageR:           math.Round(avgR*1000) / 1000,
		Expectancy:         math.Round(expectancy*1000) / 1000,
		Trades:             trades,
		EquitySeries:       equitySeries,
		Metadata: map[string]interface{}{
			"starting_balance": e.config.StartingBalance.String(),
			"ending_balance":   equity.String(),
			"taker_fee":        e.config.TakerFeeRate.String(),
			"slippage":         e.config.SlippageRate.String(),
		},
	}
}
