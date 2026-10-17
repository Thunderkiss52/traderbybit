package backtest

import (
	"fmt"
	"math"
	"time"

	"github.com/traderbybit/research-go/internal/algorithms"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

type BacktestConfig struct {
	StartingBalance       decimal.Decimal `json:"starting_balance"`
	TakerFeeRate          decimal.Decimal `json:"taker_fee_rate"` // e.g. 0.00055 (0.055%)
	SlippageRate          decimal.Decimal `json:"slippage_rate"`  // e.g. 0.0002 (0.02%)
	RiskPerTradeRate      decimal.Decimal `json:"risk_per_trade_rate"` // e.g. 0.01 (1%)
	MaxHoldingBars        int             `json:"max_holding_bars"`
	ConservativeAmbiguity bool            `json:"conservative_ambiguity"` // true: SL takes precedence if both hit
}

func DefaultConfig() BacktestConfig {
	return BacktestConfig{
		StartingBalance:       decimal.MustFromString("10000.00000000"),
		TakerFeeRate:          decimal.MustFromString("0.00055000"),
		SlippageRate:          decimal.MustFromString("0.00020000"),
		RiskPerTradeRate:      decimal.MustFromString("0.01000000"),
		MaxHoldingBars:        48, // 48 * 5m = 4 hours
		ConservativeAmbiguity: true,
	}
}

type Trade struct {
	ID         int             `json:"id"`
	Symbol     string          `json:"symbol"`
	Side       string          `json:"side"` // "BUY" or "SELL"
	EntryTime  time.Time       `json:"entry_time"`
	ExitTime   time.Time       `json:"exit_time"`
	EntryPrice decimal.Decimal `json:"entry_price"`
	ExitPrice  decimal.Decimal `json:"exit_price"`
	Quantity   decimal.Decimal `json:"quantity"`
	StopLoss   decimal.Decimal `json:"stop_loss"`
	TakeProfit decimal.Decimal `json:"take_profit"`
	GrossPnL   decimal.Decimal `json:"gross_pnl"`
	Fees       decimal.Decimal `json:"fees"`
	NetPnL     decimal.Decimal `json:"net_pnl"`
	RMultiple  float64         `json:"r_multiple"`
	ExitReason string          `json:"exit_reason"` // "TAKE_PROFIT", "STOP_LOSS", "TIMEOUT"
	Ambiguous  bool            `json:"ambiguous"`
}

type EquityPoint struct {
	Time            time.Time       `json:"time"`
	Equity          decimal.Decimal `json:"equity"`
	DrawdownPercent float64         `json:"drawdown_percent"`
}

type BacktestReport struct {
	StrategyID         string                 `json:"strategy_id"`
	TotalTrades        int                    `json:"total_trades"`
	Wins               int                    `json:"wins"`
	Losses             int                    `json:"losses"`
	WinRate            float64                `json:"win_rate"`
	GrossProfit        decimal.Decimal        `json:"gross_profit"`
	GrossLoss          decimal.Decimal        `json:"gross_loss"`
	TotalFees          decimal.Decimal        `json:"total_fees"`
	NetPnL             decimal.Decimal        `json:"net_pnl"`
	ProfitFactor       *float64               `json:"profit_factor"` // nil if no losses
	MaxDrawdown        decimal.Decimal        `json:"max_drawdown"`
	MaxDrawdownPercent float64                `json:"max_drawdown_percent"`
	AverageR           float64                `json:"average_r"`
	Expectancy         float64                `json:"expectancy"`
	Trades             []Trade                `json:"trades"`
	EquitySeries       []EquityPoint          `json:"equity_series"`
	Metadata           map[string]interface{} `json:"metadata,omitempty"`
}

type Engine struct {
	config BacktestConfig
}

func NewEngine(cfg BacktestConfig) *Engine {
	return &Engine{config: cfg}
}

// Run executes deterministic historical backtest over closed candles
func (e *Engine) Run(candles []datasets.Candle, strategyID string, params map[string]interface{}) (report *BacktestReport, retErr error) {
	// Recover from unexpected panics safely
	defer func() {
		if r := recover(); r != nil {
			retErr = fmt.Errorf("backtest simulation engine panic: %v", r)
		}
	}()

	n := len(candles)
	if n < 50 {
		return nil, fmt.Errorf("insufficient candles for backtest warmup: got %d, need >= 50", n)
	}

	closes := make([]decimal.Decimal, n)
	for i, c := range candles {
		closes[i] = c.Close
	}

	// Calculate common indicators
	fastEMA := algorithms.CalculateEMA(closes, 20)
	slowEMA := algorithms.CalculateEMA(closes, 50)
	rsi := algorithms.CalculateRSI(closes, 14)
	atr := algorithms.CalculateATR(candles, 14)
	donchian := algorithms.CalculateDonchianChannels(candles, 20)
	bb := algorithms.CalculateBollingerBands(closes, 20, 2.0)

	equity := e.config.StartingBalance
	peakEquity := equity
	maxDrawdown := decimal.Zero()
	maxDrawdownPct := 0.0

	trades := make([]Trade, 0, 100)
	equitySeries := make([]EquityPoint, 0, n)

	type activePosition struct {
		side       string
		entryPrice decimal.Decimal
		quantity   decimal.Decimal
		stopLoss   decimal.Decimal
		takeProfit decimal.Decimal
		entryTime  time.Time
		entryBar   int
		entryFee   decimal.Decimal
		riskAmount decimal.Decimal
	}

	var pos *activePosition
	tradeCounter := 0

	// Warmup window of 50 bars
	for i := 50; i < n; i++ {
		c := candles[i]

		// 1. Check open position exit on current candle
		if pos != nil {
			barsHeld := i - pos.entryBar
			exitPrice := decimal.Zero()
			exitReason := ""
			ambiguous := false

			if pos.side == "BUY" {
				hitSL := c.Low.Cmp(pos.stopLoss) <= 0
				hitTP := c.High.Cmp(pos.takeProfit) >= 0

				if hitSL && hitTP {
					ambiguous = true
					if e.config.ConservativeAmbiguity {
						exitPrice = pos.stopLoss.Sub(pos.stopLoss.Mul(e.config.SlippageRate))
						exitReason = "STOP_LOSS"
					} else {
						exitPrice = pos.takeProfit.Sub(pos.takeProfit.Mul(e.config.SlippageRate))
						exitReason = "TAKE_PROFIT"
					}
				} else if hitSL {
					exitPrice = pos.stopLoss.Sub(pos.stopLoss.Mul(e.config.SlippageRate))
					exitReason = "STOP_LOSS"
				} else if hitTP {
					exitPrice = pos.takeProfit.Sub(pos.takeProfit.Mul(e.config.SlippageRate))
					exitReason = "TAKE_PROFIT"
				} else if barsHeld >= e.config.MaxHoldingBars {
					exitPrice = c.Close
					exitReason = "TIMEOUT"
				}
			} else { // SELL (Short)
				hitSL := c.High.Cmp(pos.stopLoss) >= 0
				hitTP := c.Low.Cmp(pos.takeProfit) <= 0

				if hitSL && hitTP {
					ambiguous = true
					if e.config.ConservativeAmbiguity {
						exitPrice = pos.stopLoss.Add(pos.stopLoss.Mul(e.config.SlippageRate))
						exitReason = "STOP_LOSS"
					} else {
						exitPrice = pos.takeProfit.Add(pos.takeProfit.Mul(e.config.SlippageRate))
						exitReason = "TAKE_PROFIT"
					}
				} else if hitSL {
					exitPrice = pos.stopLoss.Add(pos.stopLoss.Mul(e.config.SlippageRate))
					exitReason = "STOP_LOSS"
				} else if hitTP {
					exitPrice = pos.takeProfit.Add(pos.takeProfit.Mul(e.config.SlippageRate))
					exitReason = "TAKE_PROFIT"
				} else if barsHeld >= e.config.MaxHoldingBars {
					exitPrice = c.Close
					exitReason = "TIMEOUT"
				}
			}

			if exitReason != "" {
				tradeCounter++
				// Calculate PnL
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

		// 2. Evaluate strategy entry if flat
		if pos == nil && i > 0 {
			prevIdx := i - 1
			signalSide := ""

			switch strategyID {
			case "donchian_atr_breakout":
				if donchian.Upper[prevIdx] != nil && c.Close.Cmp(*donchian.Upper[prevIdx]) > 0 {
					signalSide = "BUY"
				} else if donchian.Lower[prevIdx] != nil && c.Close.Cmp(*donchian.Lower[prevIdx]) < 0 {
					signalSide = "SELL"
				}
			case "bollinger_mean_reversion":
				if prevIdx > 0 && bb.Lower[prevIdx] != nil && bb.Upper[prevIdx] != nil && bb.Lower[prevIdx-1] != nil && bb.Upper[prevIdx-1] != nil && rsi[prevIdx] != nil {
					rsiVal := rsi[prevIdx].Float64()
					// Re-entry inside lower band from below with oversold RSI
					if closes[prevIdx-1].Cmp(*bb.Lower[prevIdx-1]) < 0 && closes[prevIdx].Cmp(*bb.Lower[prevIdx]) > 0 && rsiVal < 45 {
						signalSide = "BUY"
					// Re-entry inside upper band from above with overbought RSI
					} else if closes[prevIdx-1].Cmp(*bb.Upper[prevIdx-1]) > 0 && closes[prevIdx].Cmp(*bb.Upper[prevIdx]) < 0 && rsiVal > 55 {
						signalSide = "SELL"
					}
				}
			case "support_resistance_bounce":
				if donchian.Lower[prevIdx] != nil && donchian.Upper[prevIdx] != nil && rsi[prevIdx] != nil {
					rsiVal := rsi[prevIdx].Float64()
					if candles[prevIdx].Low.Cmp(*donchian.Lower[prevIdx]) <= 0 && c.Close.Cmp(candles[prevIdx].Low) > 0 && rsiVal < 40 {
						signalSide = "BUY"
					} else if candles[prevIdx].High.Cmp(*donchian.Upper[prevIdx]) >= 0 && c.Close.Cmp(candles[prevIdx].High) < 0 && rsiVal > 60 {
						signalSide = "SELL"
					}
				}
			case "support_resistance_breakout":
				if donchian.Upper[prevIdx] != nil && donchian.Lower[prevIdx] != nil {
					if c.Close.Cmp(*donchian.Upper[prevIdx]) > 0 {
						signalSide = "BUY"
					} else if c.Close.Cmp(*donchian.Lower[prevIdx]) < 0 {
						signalSide = "SELL"
					}
				}
			case "regime_switching":
				if fastEMA[prevIdx] != nil && slowEMA[prevIdx] != nil && rsi[prevIdx] != nil && bb.Lower[prevIdx] != nil && bb.Upper[prevIdx] != nil {
					diff := math.Abs(fastEMA[prevIdx].Float64() - slowEMA[prevIdx].Float64())
					ratio := diff / math.Max(1.0, slowEMA[prevIdx].Float64())
					rsiVal := rsi[prevIdx].Float64()
					if ratio > 0.002 {
						// Trending regime
						if fastEMA[prevIdx].Cmp(*slowEMA[prevIdx]) > 0 && rsiVal > 50 && rsiVal < 70 {
							signalSide = "BUY"
						} else if fastEMA[prevIdx].Cmp(*slowEMA[prevIdx]) < 0 && rsiVal < 50 && rsiVal > 30 {
							signalSide = "SELL"
						}
					} else {
						// Ranging regime (Bollinger mean reversion)
						if closes[prevIdx].Cmp(*bb.Lower[prevIdx]) < 0 && rsiVal < 40 {
							signalSide = "BUY"
						} else if closes[prevIdx].Cmp(*bb.Upper[prevIdx]) > 0 && rsiVal > 60 {
							signalSide = "SELL"
						}
					}
				}
			case "trend_pullback", "momentum":
				if fastEMA[prevIdx] != nil && slowEMA[prevIdx] != nil && rsi[prevIdx] != nil {
					emaCrossUp := fastEMA[prevIdx].Cmp(*slowEMA[prevIdx]) > 0
					emaCrossDown := fastEMA[prevIdx].Cmp(*slowEMA[prevIdx]) < 0
					rsiVal := rsi[prevIdx].Float64()

					if emaCrossUp && rsiVal > 50 && rsiVal < 70 {
						signalSide = "BUY"
					} else if emaCrossDown && rsiVal < 50 && rsiVal > 30 {
						signalSide = "SELL"
					}
				}
			default: // Baseline / generic moving average crossover
				if fastEMA[prevIdx] != nil && slowEMA[prevIdx] != nil && fastEMA[prevIdx-1] != nil && slowEMA[prevIdx-1] != nil {
					prevFast := fastEMA[prevIdx-1]
					prevSlow := slowEMA[prevIdx-1]
					currFast := fastEMA[prevIdx]
					currSlow := slowEMA[prevIdx]

					if prevFast.Cmp(*prevSlow) <= 0 && currFast.Cmp(*currSlow) > 0 {
						signalSide = "BUY"
					} else if prevFast.Cmp(*prevSlow) >= 0 && currFast.Cmp(*currSlow) < 0 {
						signalSide = "SELL"
					}
				}
			}

			if signalSide != "" && atr[prevIdx] != nil && atr[prevIdx].IsPositive() {
				currentATR := *atr[prevIdx]
				entryPrice := c.Close

				// ATR based SL and TP (SL = 1.5 * ATR, TP = 3.0 * ATR => 2:1 R/R)
				slDistance := currentATR.Mul(decimal.FromFloat64(1.5))
				tpDistance := currentATR.Mul(decimal.FromFloat64(3.0))

				stopLoss := decimal.Zero()
				takeProfit := decimal.Zero()

				if signalSide == "BUY" {
					entryPrice = entryPrice.Add(entryPrice.Mul(e.config.SlippageRate))
					stopLoss = entryPrice.Sub(slDistance)
					takeProfit = entryPrice.Add(tpDistance)
				} else {
					entryPrice = entryPrice.Sub(entryPrice.Mul(e.config.SlippageRate))
					stopLoss = entryPrice.Add(slDistance)
					takeProfit = entryPrice.Sub(tpDistance)
				}

				// Position sizing based on risk per trade
				riskAmount := equity.Mul(e.config.RiskPerTradeRate)
				qty, err := riskAmount.Div(slDistance)
				if err == nil && qty.IsPositive() {
					entryFee := qty.Mul(entryPrice).Mul(e.config.TakerFeeRate)
					pos = &activePosition{
						side:       signalSide,
						entryPrice: entryPrice,
						quantity:   qty,
						stopLoss:   stopLoss,
						takeProfit: takeProfit,
						entryTime:  c.OpenTime,
						entryBar:   i,
						entryFee:   entryFee,
						riskAmount: riskAmount,
					}
				}
			}
		}

		// Update drawdown & equity series
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

	// Compute aggregate metrics
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

	netPnL := equity.Sub(e.config.StartingBalance)

	return &BacktestReport{
		StrategyID:         strategyID,
		TotalTrades:        totalTrades,
		Wins:               wins,
		Losses:             losses,
		WinRate:            math.Round(winRate*10000) / 10000,
		GrossProfit:        grossProfit,
		GrossLoss:          grossLoss,
		TotalFees:          totalFees,
		NetPnL:             netPnL,
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
	}, nil
}
