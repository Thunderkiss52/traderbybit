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
	Partial      *PartialTarget     `json:"partial,omitempty"`
	RiskRate     *decimal.Decimal   `json:"risk_rate,omitempty"`
	Features     map[string]float64 `json:"features,omitempty"`
	Side         string             `json:"side"`
	DecisionTime time.Time          `json:"decision_time"`
	DecisionBar  int                `json:"decision_bar"`
	EntryBar     int                `json:"entry_bar"`
	StopLoss     decimal.Decimal    `json:"stop_loss"`
	TakeProfit   decimal.Decimal    `json:"take_profit"`
	Reason       string             `json:"reason"`
}

// RunSignals replays externally generated signals over closed candles.
//
// It reuses the cost model, risk sizing, conservative intrabar ambiguity rule
// and metric computation of Engine.Run, so a candidate and a baseline strategy
// are measured with exactly the same assumptions.
func (e *Engine) RunSignals(candles []datasets.Candle, signals []Signal, strategyID string) (report *BacktestReport, retErr error) {
	return e.runSignals(candles, signals, strategyID, nil)
}

func (e *Engine) RunSignalsWithPolicy(candles []datasets.Candle, signals []Signal, strategyID string, policy ReplayPolicy) (*BacktestReport, error) {
	if err := policy.validate(); err != nil {
		return nil, err
	}
	return e.runSignals(candles, signals, strategyID, &policy)
}

func (e *Engine) runSignals(candles []datasets.Candle, signals []Signal, strategyID string, policy *ReplayPolicy) (report *BacktestReport, retErr error) {
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
		if s.RiskRate != nil && (!s.RiskRate.IsPositive() || s.RiskRate.Cmp(e.config.RiskPerTradeRate) > 0) {
			return nil, fmt.Errorf("signal risk exceeds explicit execution bound")
		}
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
	var detail *replayPosition
	executions := []ReplayExecution{}
	fundingTotal := decimal.Zero()
	entryBlocks := 0

	finish := func(c datasets.Candle, exitPrice decimal.Decimal, exitReason string, ambiguous bool) {
		tradeCounter++
		exitFee := pos.quantity.Mul(exitPrice).Mul(e.config.TakerFeeRate)
		totalFees := pos.entryFee.Add(exitFee)

		grossPnL := legProfit(pos.side, pos.quantity, pos.entryPrice, exitPrice)
		netPnL := grossPnL.Sub(totalFees)
		if policy != nil {
			raw := c.Close
			switch exitReason {
			case "STOP_LOSS":
				raw = pos.stopLoss
			case "TAKE_PROFIT":
				raw = pos.takeProfit
			case "RULE_EXIT":
				raw = c.Open
			}
			legSlippage := absoluteReplayDecimal(exitPrice.Sub(raw)).Mul(pos.quantity)
			detail.slippage = detail.slippage.Add(legSlippage)
			equity = equity.Add(grossPnL).Sub(exitFee)
			grossPnL = grossPnL.Add(detail.gross)
			totalFees = detail.fees.Add(exitFee)
			netPnL = grossPnL.Sub(totalFees).Add(detail.funding)
			executions = append(executions, ReplayExecution{Kind: exitReason, At: c.OpenTime, Side: pos.side, Price: exitPrice, Quantity: pos.quantity, Fee: exitFee, Funding: decimal.Zero()})
		} else {
			equity = equity.Add(netPnL)
		}

		rMult := 0.0
		if pos.riskAmount.IsPositive() {
			rMult = netPnL.Float64() / pos.riskAmount.Float64()
		}

		var fundingPointer, slippagePointer *decimal.Decimal
		if policy != nil {
			fundingPointer = &detail.funding
			slippagePointer = &detail.slippage
		}
		trades = append(trades, Trade{Funding: fundingPointer, Slippage: slippagePointer,
			ID:         tradeCounter,
			Symbol:     c.Symbol,
			Side:       pos.side,
			EntryTime:  pos.entryTime,
			ExitTime:   c.OpenTime,
			EntryPrice: pos.entryPrice,
			ExitPrice:  exitPrice,
			Quantity:   detail.initial,
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

	fundingByTime := map[int64][]FundingEvent{}
	if policy != nil {
		for _, event := range policy.Funding {
			fundingByTime[event.At.UnixNano()] = append(fundingByTime[event.At.UnixNano()], event)
		}
	}
	for i := 0; i < n; i++ {
		c := candles[i]
		if policy != nil && pos != nil {
			for _, event := range fundingByTime[c.OpenTime.UnixNano()] {
				if event.At.Equal(c.OpenTime) && event.At.After(pos.entryTime) {
					amount, amountError := exactFundingAmount(event, pos.quantity)
					if amountError != nil {
						return nil, amountError
					}
					if pos.side == "BUY" {
						amount = amount.Neg()
					}
					detail.funding = detail.funding.Add(amount)
					fundingTotal = fundingTotal.Add(amount)
					equity = equity.Add(amount)
					executions = append(executions, ReplayExecution{Kind: "FUNDING", At: event.At, Side: pos.side, Price: event.Mark, Quantity: pos.quantity, Fee: decimal.Zero(), Funding: amount})
				}
			}
		}

		if pos != nil {
			barsHeld := i - pos.entryBar
			exitPrice, exitReason, ambiguous, hasExit := e.evaluateExit(pos, c, barsHeld)
			if policy != nil {
				if exitReason == "TIMEOUT" {
					exitPrice = e.exitSlip(c.Close, pos.side)
				}
				if policy.ExitAtOpen[i][pos.side] {
					exitPrice = e.exitSlip(c.Open, pos.side)
					exitReason = "RULE_EXIT"
					hasExit = true
					ambiguous = false
				}
				if i == n-1 && !hasExit {
					exitPrice = e.exitSlip(c.Close, pos.side)
					exitReason = "END_OF_DATA"
					hasExit = true
				}
				if exitReason == "TIMEOUT" {
					exitPrice = e.exitSlip(exitPrice, pos.side)
				}
				if detail.partial != nil && !detail.partialDone && exitReason != "STOP_LOSS" && exitReason != "RULE_EXIT" {
					t := detail.partial
					hit := (pos.side == "BUY" && c.High.Cmp(t.Price) >= 0) || (pos.side == "SELL" && c.Low.Cmp(t.Price) <= 0)
					if hit {
						qty := detail.initial.Mul(t.Fraction)
						if qty.IsPositive() && qty.Cmp(pos.quantity) < 0 {
							price := e.exitSlip(t.Price, pos.side)
							fee := qty.Mul(price).Mul(e.config.TakerFeeRate)
							gross := legProfit(pos.side, qty, pos.entryPrice, price)
							detail.slippage = detail.slippage.Add(absoluteReplayDecimal(price.Sub(t.Price)).Mul(qty))
							detail.gross = detail.gross.Add(gross)
							detail.fees = detail.fees.Add(fee)
							pos.quantity = pos.quantity.Sub(qty)
							equity = equity.Add(gross).Sub(fee)
							detail.partialDone = true
							executions = append(executions, ReplayExecution{Kind: "PARTIAL_EXIT", At: c.OpenTime, Side: pos.side, Price: price, Quantity: qty, Fee: fee, Funding: decimal.Zero()})
						}
					}
				}
			}

			if hasExit {
				finish(c, exitPrice, exitReason, ambiguous)
			} else if policy != nil && detail != nil && detail.trailing != nil {
				t := detail.trailing
				if pos.side == "BUY" {
					if c.High.Cmp(detail.extremePrice) > 0 {
						detail.extremePrice = c.High
					}
					if !detail.trailingActive {
						if t.ActivationPrice.IsZero() || detail.extremePrice.Cmp(t.ActivationPrice) >= 0 {
							detail.trailingActive = true
						}
					}
					if detail.trailingActive {
						newSL := detail.extremePrice.Sub(t.Distance)
						if newSL.Cmp(pos.stopLoss) > 0 {
							pos.stopLoss = newSL
						}
					}
				} else {
					if c.Low.Cmp(detail.extremePrice) < 0 {
						detail.extremePrice = c.Low
					}
					if !detail.trailingActive {
						if t.ActivationPrice.IsZero() || detail.extremePrice.Cmp(t.ActivationPrice) <= 0 {
							detail.trailingActive = true
						}
					}
					if detail.trailingActive {
						newSL := detail.extremePrice.Add(t.Distance)
						if newSL.Cmp(pos.stopLoss) < 0 {
							pos.stopLoss = newSL
						}
					}
				}
			}
		}

		if pos == nil {
			blocked := policy != nil && peakEquity.IsPositive() && peakEquity.Sub(equity).Float64()/peakEquity.Float64() >= 0.05
			if blocked {
				entryBlocks++
			}
			if s, ok := byEntryBar[i]; ok && !blocked {
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

					entryEngine := *e
					if s.RiskRate != nil {
						entryEngine.config.RiskPerTradeRate = *s.RiskRate
					}
					opened := entryEngine.openPosition(s.Side, fillPrice, slDistance, equity, c.OpenTime, i)
					if opened != nil {
						opened.stopLoss = stopLoss
						opened.takeProfit = takeProfit
						pos = opened
						var partial *PartialTarget
						var trailing *TrailingTarget
						if policy != nil {
							if target, ok := policy.PartialTargets[s.DecisionBar]; ok {
								copy := target
								partial = &copy
							}
							if target, ok := policy.TrailingTargets[s.DecisionBar]; ok {
								copy := target
								trailing = &copy
							}
							equity = equity.Sub(opened.entryFee)
							executions = append(executions, ReplayExecution{Kind: "ENTRY", At: c.OpenTime, Side: pos.side, Price: fillPrice, Quantity: pos.quantity, Fee: opened.entryFee, Funding: decimal.Zero()})
						}
						detail = newReplayPosition(opened.quantity, opened.entryFee, partial, trailing, fillPrice)
						if policy != nil {
							detail.slippage = absoluteReplayDecimal(fillPrice.Sub(c.Open)).Mul(opened.quantity)
							if detail.trailing != nil {
								t := detail.trailing
								if pos.side == "BUY" {
									if c.High.Cmp(detail.extremePrice) > 0 {
										detail.extremePrice = c.High
									}
									if !detail.trailingActive {
										if t.ActivationPrice.IsZero() || detail.extremePrice.Cmp(t.ActivationPrice) >= 0 {
											detail.trailingActive = true
										}
									}
									if detail.trailingActive {
										newSL := detail.extremePrice.Sub(t.Distance)
										if newSL.Cmp(pos.stopLoss) > 0 {
											pos.stopLoss = newSL
										}
									}
								} else {
									if c.Low.Cmp(detail.extremePrice) < 0 {
										detail.extremePrice = c.Low
									}
									if !detail.trailingActive {
										if t.ActivationPrice.IsZero() || detail.extremePrice.Cmp(t.ActivationPrice) <= 0 {
											detail.trailingActive = true
										}
									}
									if detail.trailingActive {
										newSL := detail.extremePrice.Add(t.Distance)
										if newSL.Cmp(pos.stopLoss) < 0 {
											pos.stopLoss = newSL
										}
									}
								}
							}
						}
					}
				}
			}
		}

		if policy != nil && i == n-1 && pos != nil {
			finish(c, e.exitSlip(c.Close, pos.side), "END_OF_DATA", false)
		}
		markedEquity := equity
		if policy != nil && pos != nil {
			markedEquity = equity.Add(legProfit(pos.side, pos.quantity, pos.entryPrice, c.Close))
		}
		if markedEquity.Cmp(peakEquity) > 0 {
			peakEquity = markedEquity
		}
		dd := peakEquity.Sub(markedEquity)
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
				Equity:          markedEquity,
				DrawdownPercent: math.Round(ddPct*100) / 100,
			})
		}
	}

	report = e.buildReport(strategyID, trades, equitySeries, equity, peakEquity, maxDrawdown, maxDrawdownPct)
	if policy != nil {
		report.Metadata["executions"] = executions
		report.Metadata["funding"] = fundingTotal.String()
		report.Metadata["entry_blocks"] = entryBlocks
		report.Expectancy = report.AverageR
	}
	return report, nil
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
