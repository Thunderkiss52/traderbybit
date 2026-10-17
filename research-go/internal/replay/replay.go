package replay

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"time"

	"github.com/traderbybit/research-go/internal/algorithms"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

type ReplaySnapshot struct {
	DecisionTime        time.Time              `json:"decision_time"`
	StrategyID          string                 `json:"strategy_id"`
	Symbol              string                 `json:"symbol"`
	Timeframe           string                 `json:"timeframe"`
	ClosedPriceAtT      decimal.Decimal        `json:"closed_price_at_t"`
	IndicatorsAtT       map[string]interface{} `json:"indicators_at_t"`
	SignalAction        string                 `json:"signal_action"` // "BUY", "SELL", "SKIP"
	Reason              string                 `json:"reason"`
	SimulatedEntryPrice *decimal.Decimal       `json:"simulated_entry_price,omitempty"`
	SimulatedStopLoss   *decimal.Decimal       `json:"simulated_stop_loss,omitempty"`
	SimulatedTakeProfit *decimal.Decimal       `json:"simulated_take_profit,omitempty"`
	SimulatedOutcome    string                 `json:"simulated_outcome,omitempty"` // "TP_HIT", "SL_HIT", "TIMEOUT", "NONE"
	Ambiguous           bool                   `json:"ambiguous"`
	ReplayFingerprint   string                 `json:"replay_fingerprint"`
}

// ReconstructDecision inspects exact state at historical time T using only closed candles before T
func ReconstructDecision(candles []datasets.Candle, decisionTime time.Time, strategyID string) (*ReplaySnapshot, error) {
	cutoffIdx := -1
	for i, c := range candles {
		if c.OpenTime.Equal(decisionTime) || c.OpenTime.After(decisionTime) {
			cutoffIdx = i
			break
		}
	}

	if cutoffIdx < 50 {
		return nil, fmt.Errorf("insufficient historical warm-up candles before decision time %s", decisionTime.Format(time.RFC3339))
	}

	historicalCandles := candles[:cutoffIdx]
	lastCandle := historicalCandles[len(historicalCandles)-1]

	closes := make([]decimal.Decimal, len(historicalCandles))
	for i, c := range historicalCandles {
		closes[i] = c.Close
	}

	fastEMA := algorithms.CalculateEMA(closes, 20)
	slowEMA := algorithms.CalculateEMA(closes, 50)
	rsi := algorithms.CalculateRSI(closes, 14)
	atr := algorithms.CalculateATR(historicalCandles, 14)
	bb := algorithms.CalculateBollingerBands(closes, 20, 2.0)

	lastIdx := len(historicalCandles) - 1

	indicatorMap := make(map[string]interface{})
	if fastEMA[lastIdx] != nil {
		indicatorMap["ema_20"] = fastEMA[lastIdx].String()
	}
	if slowEMA[lastIdx] != nil {
		indicatorMap["ema_50"] = slowEMA[lastIdx].String()
	}
	if rsi[lastIdx] != nil {
		indicatorMap["rsi_14"] = rsi[lastIdx].String()
	}
	if atr[lastIdx] != nil {
		indicatorMap["atr_14"] = atr[lastIdx].String()
	}
	if bb.Upper[lastIdx] != nil && bb.Lower[lastIdx] != nil {
		indicatorMap["bb_upper"] = bb.Upper[lastIdx].String()
		indicatorMap["bb_lower"] = bb.Lower[lastIdx].String()
	}

	action := "SKIP"
	reason := "Conditions neutral"
	var entry, sl, tp *decimal.Decimal

	if fastEMA[lastIdx] != nil && slowEMA[lastIdx] != nil && rsi[lastIdx] != nil && atr[lastIdx] != nil {
		fastVal := fastEMA[lastIdx]
		slowVal := slowEMA[lastIdx]
		rsiVal := rsi[lastIdx].Float64()
		atrVal := *atr[lastIdx]

		if fastVal.Cmp(*slowVal) > 0 && rsiVal > 50 && rsiVal < 70 {
			action = "BUY"
			reason = "Trend bullish and RSI in momentum expansion zone (50-70)"
			e := lastCandle.Close
			entry = &e
			s := e.Sub(atrVal.Mul(decimal.FromFloat64(1.5)))
			sl = &s
			t := e.Add(atrVal.Mul(decimal.FromFloat64(3.0)))
			tp = &t
		} else if fastVal.Cmp(*slowVal) < 0 && rsiVal < 50 && rsiVal > 30 {
			action = "SELL"
			reason = "Trend bearish and RSI in momentum breakdown zone (30-50)"
			e := lastCandle.Close
			entry = &e
			s := e.Add(atrVal.Mul(decimal.FromFloat64(1.5)))
			sl = &s
			t := e.Sub(atrVal.Mul(decimal.FromFloat64(3.0)))
			tp = &t
		}
	}

	// Forward outcome evaluation across future candles (if available)
	simOutcome := "NONE"
	ambiguous := false
	if action != "SKIP" && entry != nil && sl != nil && tp != nil {
		futureCandles := candles[cutoffIdx:]
		for i, fc := range futureCandles {
			if i >= 48 { // 4 hours timeout
				simOutcome = "TIMEOUT"
				break
			}
			hitSL := false
			hitTP := false
			if action == "BUY" {
				hitSL = fc.Low.Cmp(*sl) <= 0
				hitTP = fc.High.Cmp(*tp) >= 0
			} else {
				hitSL = fc.High.Cmp(*sl) >= 0
				hitTP = fc.Low.Cmp(*tp) <= 0
			}

			if hitSL && hitTP {
				// Ambiguous same-candle execution: conservative resolution is SL_HIT
				simOutcome = "SL_HIT"
				ambiguous = true
				break
			} else if hitSL {
				simOutcome = "SL_HIT"
				break
			} else if hitTP {
				simOutcome = "TP_HIT"
				break
			}
		}
	}

	// Compute replay fingerprint
	h := sha256.New()
	h.Write([]byte(fmt.Sprintf("%s:%s:%s:%s:%s:%t",
		decisionTime.Format(time.RFC3339), strategyID, lastCandle.Close.String(), action, simOutcome, ambiguous)))
	fingerprint := hex.EncodeToString(h.Sum(nil))

	return &ReplaySnapshot{
		DecisionTime:        decisionTime,
		StrategyID:          strategyID,
		Symbol:              lastCandle.Symbol,
		Timeframe:           lastCandle.Interval,
		ClosedPriceAtT:      lastCandle.Close,
		IndicatorsAtT:       indicatorMap,
		SignalAction:        action,
		Reason:              reason,
		SimulatedEntryPrice: entry,
		SimulatedStopLoss:   sl,
		SimulatedTakeProfit: tp,
		SimulatedOutcome:    simOutcome,
		Ambiguous:           ambiguous,
		ReplayFingerprint:   fingerprint,
	}, nil
}
