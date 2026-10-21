// Package candidates implements the five VECTOR section 7 research candidate hypothesis families.
//
// The candidates are RESEARCH COHORTS only. They never create, attach to or modify the eight
// primary paper accounts, they never place exchange orders and they never read exchange trade
// credentials. Every decision is derived from closed bars and from events that were already
// published (known) at the decision timestamp.
package candidates

import (
	"fmt"
	"time"

	"github.com/traderbybit/research-go/internal/algorithms"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

// Bar is a closed market bar with explicit close and knowledge timestamps.
//
// CloseTime is the moment the bar finished. KnownAt is the moment the bar became observable to
// the research pipeline. For archived candles they are equal; for a live stream KnownAt may be
// later, and every candidate must respect KnownAt.
type Bar struct {
	Symbol    string          `json:"symbol"`
	Interval  string          `json:"interval"`
	OpenTime  time.Time       `json:"open_time"`
	CloseTime time.Time       `json:"close_time"`
	KnownAt   time.Time       `json:"known_at"`
	Open      decimal.Decimal `json:"open"`
	High      decimal.Decimal `json:"high"`
	Low       decimal.Decimal `json:"low"`
	Close     decimal.Decimal `json:"close"`
	Volume    decimal.Decimal `json:"volume"`
}

// FundingEvent is a published funding rate event.
//
// Sign convention (Bybit USDT-margined linear): a POSITIVE rate means the long side pays the short
// side, a NEGATIVE rate means the short side pays the long side.
type FundingEvent struct {
	Symbol    string          `json:"symbol"`
	EventTime time.Time       `json:"event_time"`
	KnownAt   time.Time       `json:"known_at"`
	Rate      decimal.Decimal `json:"rate"`
}

// OpenInterestEvent is a published open interest snapshot.
type OpenInterestEvent struct {
	Symbol       string          `json:"symbol"`
	EventTime    time.Time       `json:"event_time"`
	KnownAt      time.Time       `json:"known_at"`
	OpenInterest decimal.Decimal `json:"open_interest"`
}

// Dataset carries every input a candidate is allowed to read.
type Dataset struct {
	Symbol           string              `json:"symbol"`
	EntryInterval    string              `json:"entry_interval"`
	DecisionInterval string              `json:"decision_interval"`
	Bars             []Bar               `json:"bars"`
	HTFBars          []Bar               `json:"htf_bars"`
	PeerBars         []Bar               `json:"peer_bars"`
	Funding          []FundingEvent      `json:"funding"`
	OpenInterest     []OpenInterestEvent `json:"open_interest"`
	PeerSymbol       string              `json:"peer_symbol"`
	SourceHash       string              `json:"source_hash"`
}

// Signal is a research decision of a candidate.
type Signal struct {
	CandidateID  string            `json:"candidate_id"`
	Side         string            `json:"side"`
	DecisionTime time.Time         `json:"decision_time"`
	DecisionBar  int               `json:"decision_bar"`
	EntryBar     int               `json:"entry_bar"`
	StopLoss     decimal.Decimal   `json:"stop_loss"`
	TakeProfit   decimal.Decimal   `json:"take_profit"`
	Reason       string            `json:"reason"`
	Features     map[string]string `json:"features,omitempty"`
}

// Candidate is a research-only hypothesis family.
type Candidate interface {
	ID() string
	Version() string
	Generate(d Dataset) ([]Signal, error)
}

// toCandles converts the research bar type into the shared engine candle type.
func toCandles(bars []Bar) []datasets.Candle {
	out := make([]datasets.Candle, 0, len(bars))
	for _, b := range bars {
		out = append(out, datasets.Candle{
			Exchange: "bybit",
			Category: "linear",
			Symbol:   b.Symbol,
			Interval: b.Interval,
			OpenTime: b.OpenTime,
			Open:     b.Open,
			High:     b.High,
			Low:      b.Low,
			Close:    b.Close,
			Volume:   b.Volume,
			Turnover: decimal.Zero(),
			IsClosed: true,
		})
	}
	return out
}

func closesOf(bars []Bar) []decimal.Decimal {
	out := make([]decimal.Decimal, 0, len(bars))
	for _, b := range bars {
		out = append(out, b.Close)
	}
	return out
}

// lastKnownBar returns the most recent bar that was fully closed AND known at decisionTime.
//
// This is the single lookup used by every candidate that may read a higher timeframe series. It is
// deliberately a search over (CloseTime, KnownAt) instead of an index arithmetic assumption.
func lastKnownBar(bars []Bar, decisionTime time.Time) (Bar, bool) {
	for i := len(bars) - 1; i >= 0; i-- {
		b := bars[i]
		if b.CloseTime.After(decisionTime) || b.KnownAt.After(decisionTime) {
			continue
		}
		return b, true
	}
	return Bar{}, false
}

// lastKnownBarIndex returns the index of the most recent bar that was fully closed AND known at
// decisionTime.
func lastKnownBarIndex(bars []Bar, decisionTime time.Time) (int, bool) {
	for i := len(bars) - 1; i >= 0; i-- {
		b := bars[i]
		if b.CloseTime.After(decisionTime) || b.KnownAt.After(decisionTime) {
			continue
		}
		return i, true
	}
	return -1, false
}

// countKnownBars returns the number of bars that were fully closed AND known at decisionTime.
//
// The count is data driven and therefore independent of the position of the bar inside the array.
// A warm-up gate must use this count, never the array index, otherwise the same decision would be
// treated differently depending on how much history the array happens to carry.
func countKnownBars(bars []Bar, decisionTime time.Time) int {
	count := 0
	for _, b := range bars {
		if b.CloseTime.After(decisionTime) || b.KnownAt.After(decisionTime) {
			continue
		}
		count++
	}
	return count
}

// lastKnownFunding returns the most recent funding event published at decisionTime.
func lastKnownFunding(events []FundingEvent, decisionTime time.Time) (FundingEvent, bool) {
	for i := len(events) - 1; i >= 0; i-- {
		e := events[i]
		if e.EventTime.After(decisionTime) || e.KnownAt.After(decisionTime) {
			continue
		}
		return e, true
	}
	return FundingEvent{}, false
}

// lastKnownOpenInterest returns the most recent open interest snapshot published at decisionTime.
func lastKnownOpenInterest(events []OpenInterestEvent, decisionTime time.Time) (OpenInterestEvent, bool) {
	for i := len(events) - 1; i >= 0; i-- {
		e := events[i]
		if e.EventTime.After(decisionTime) || e.KnownAt.After(decisionTime) {
			continue
		}
		return e, true
	}
	return OpenInterestEvent{}, false
}

// openInterestAt returns the open interest snapshot closest to (and not after) decisionTime.
func openInterestAt(events []OpenInterestEvent, decisionTime time.Time) (OpenInterestEvent, bool) {
	return lastKnownOpenInterest(events, decisionTime)
}

// absDecimal returns the absolute value of a decimal.
func absDecimal(d decimal.Decimal) decimal.Decimal {
	if d.IsNegative() {
		return d.Neg()
	}
	return d
}

// stopAndTarget derives the stop loss and take profit from the ATR of the decision bar.
//
// The distance is precommitted in the candidate configuration, never fitted on the evaluated data.
func stopAndTarget(side string, entryPrice, atr decimal.Decimal, stopATRMult, targetATRMult float64) (decimal.Decimal, decimal.Decimal) {
	stopDistance := atr.Mul(decimal.FromFloat64(stopATRMult))
	targetDistance := atr.Mul(decimal.FromFloat64(targetATRMult))

	if side == "BUY" {
		return entryPrice.Sub(stopDistance), entryPrice.Add(targetDistance)
	}
	return entryPrice.Add(stopDistance), entryPrice.Sub(targetDistance)
}

// trueRangeAt returns the true range of a bar, using the same definition as the ATR algorithm.
func trueRangeAt(bars []Bar, i int) decimal.Decimal {
	if i < 0 || i >= len(bars) {
		return decimal.Zero()
	}

	tr := bars[i].High.Sub(bars[i].Low)
	if i == 0 {
		return tr
	}

	prevClose := bars[i-1].Close
	highPrev := bars[i].High.Sub(prevClose)
	if highPrev.IsNegative() {
		highPrev = highPrev.Neg()
	}
	lowPrev := bars[i].Low.Sub(prevClose)
	if lowPrev.IsNegative() {
		lowPrev = lowPrev.Neg()
	}

	if highPrev.Cmp(tr) > 0 {
		tr = highPrev
	}
	if lowPrev.Cmp(tr) > 0 {
		tr = lowPrev
	}

	return tr
}

// meanTrueRange returns the arithmetic mean true range of the half open bar range [start, end).
//
// The range is always evaluated on bars that are already closed and known, therefore the measure
// cannot depend on future data.
func meanTrueRange(bars []Bar, start, end int) (decimal.Decimal, bool) {
	if start < 0 || end > len(bars) || end <= start {
		return decimal.Zero(), false
	}

	sum := decimal.Zero()
	for i := start; i < end; i++ {
		sum = sum.Add(trueRangeAt(bars, i))
	}

	mean, err := sum.Div(decimal.FromInt64(int64(end - start)))
	if err != nil {
		return decimal.Zero(), false
	}

	return mean, true
}

// atrAt returns the ATR at the given index of the precomputed ATR series.
func atrAt(atr []*decimal.Decimal, idx int) (decimal.Decimal, bool) {
	if idx < 0 || idx >= len(atr) || atr[idx] == nil {
		return decimal.Zero(), false
	}
	return *atr[idx], true
}

// emaAt returns the EMA at the given index of a precomputed EMA series.
func emaAt(ema []*decimal.Decimal, idx int) (decimal.Decimal, bool) {
	if idx < 0 || idx >= len(ema) || ema[idx] == nil {
		return decimal.Zero(), false
	}
	return *ema[idx], true
}

// rsiAt returns the RSI at the given index of a precomputed RSI series.
func rsiAt(rsi []*decimal.Decimal, idx int) (decimal.Decimal, bool) {
	if idx < 0 || idx >= len(rsi) || rsi[idx] == nil {
		return decimal.Zero(), false
	}
	return *rsi[idx], true
}

// indicatorSet bundles the precomputed causal indicator series of one bar list.
type indicatorSet struct {
	candles []datasets.Candle
	closes  []decimal.Decimal
	atr     []*decimal.Decimal
	emaFast []*decimal.Decimal
	emaSlow []*decimal.Decimal
	rsi     []*decimal.Decimal
	bb      algorithms.BollingerBandsResult
}

func computeIndicators(bars []Bar, atrPeriod, fastPeriod, slowPeriod, rsiPeriod, bbPeriod int, bbMultiplier float64) indicatorSet {
	candles := toCandles(bars)
	closes := closesOf(bars)
	return indicatorSet{
		candles: candles,
		closes:  closes,
		atr:     algorithms.CalculateATR(candles, atrPeriod),
		emaFast: algorithms.CalculateEMA(closes, fastPeriod),
		emaSlow: algorithms.CalculateEMA(closes, slowPeriod),
		rsi:     algorithms.CalculateRSI(closes, rsiPeriod),
		bb:      algorithms.CalculateBollingerBands(closes, bbPeriod, bbMultiplier),
	}
}

// validateDataset performs the structural checks shared by every candidate.
func validateDataset(d Dataset, minBars int) error {
	if len(d.Bars) < minBars {
		return fmt.Errorf("dataset %s: got %d entry bars, need >= %d", d.Symbol, len(d.Bars), minBars)
	}
	for i := 1; i < len(d.Bars); i++ {
		if !d.Bars[i].OpenTime.After(d.Bars[i-1].OpenTime) {
			return fmt.Errorf("dataset %s: entry bars are not strictly ascending at index %d", d.Symbol, i)
		}
		if d.Bars[i].CloseTime.After(d.Bars[i].KnownAt) {
			return fmt.Errorf("dataset %s: bar %d cannot be known before it closed", d.Symbol, i)
		}
	}
	return nil
}
