package candidates

import (
	"fmt"
	"time"

	"github.com/traderbybit/research-go/internal/decimal"
)

// This file implements the mechanical look-ahead controls required by the execution contract
// section 7.7 for EVERY candidate:
//
//  1. Higher timeframe candle closure: a higher timeframe bar may only be used when it was fully
//     closed and known at the decision timestamp.
//  2. Causal indicator test: mutating every candle/event whose known_at is after the decision
//     timestamp must not change any earlier feature value or signal.
//  3. Negative control: a deliberately future-only feature must make the causal test FAIL. A
//     negative control that passes invalidates the test itself.

// HTFClosureOK reports whether a higher timeframe bar may be used at the given decision time.
func HTFClosureOK(decisionTime time.Time, bar Bar) bool {
	return !bar.CloseTime.After(decisionTime) && !bar.KnownAt.After(decisionTime)
}

// MutationFactor scales the prices of every mutated bar.
const MutationFactor = 1.5

// MutateFuture returns a copy of the bars where every bar that became known AFTER the cutoff has
// its prices scaled. Bars that were already known at the cutoff are untouched.
//
// The mutation is applied to the entry bars, the higher timeframe bars and the peer bars.
func MutateFuture(d Dataset, cutoff time.Time) Dataset {
	out := d
	out.Bars = mutateBars(d.Bars, cutoff)
	out.HTFBars = mutateBars(d.HTFBars, cutoff)
	out.PeerBars = mutateBars(d.PeerBars, cutoff)

	out.Funding = make([]FundingEvent, len(d.Funding))
	copy(out.Funding, d.Funding)
	for i := range out.Funding {
		if out.Funding[i].KnownAt.After(cutoff) {
			out.Funding[i].Rate = out.Funding[i].Rate.Mul(decimal.FromFloat64(MutationFactor))
		}
	}

	out.OpenInterest = make([]OpenInterestEvent, len(d.OpenInterest))
	copy(out.OpenInterest, d.OpenInterest)
	for i := range out.OpenInterest {
		if out.OpenInterest[i].KnownAt.After(cutoff) {
			out.OpenInterest[i].OpenInterest = out.OpenInterest[i].OpenInterest.Mul(decimal.FromFloat64(MutationFactor))
		}
	}

	return out
}

func mutateBars(bars []Bar, cutoff time.Time) []Bar {
	out := make([]Bar, len(bars))
	copy(out, bars)
	for i := range out {
		if out[i].KnownAt.After(cutoff) {
			out[i].Open = out[i].Open.Mul(decimal.FromFloat64(MutationFactor))
			out[i].High = out[i].High.Mul(decimal.FromFloat64(MutationFactor))
			out[i].Low = out[i].Low.Mul(decimal.FromFloat64(MutationFactor))
			out[i].Close = out[i].Close.Mul(decimal.FromFloat64(MutationFactor))
			out[i].Volume = out[i].Volume.Mul(decimal.FromFloat64(MutationFactor))
		}
	}
	return out
}

// SignalKey is the comparable identity of a signal.
//
// It contains the side, the decision and entry bars, the reason and the derived risk prices. A
// decision that depends on data that was not known at the decision time changes its key when that
// data is mutated.
func SignalKey(s Signal) string {
	return fmt.Sprintf("%s|%s|%d|%d|%s|%s|%s", s.CandidateID, s.Side, s.DecisionBar, s.EntryBar, s.Reason, s.StopLoss.String(), s.TakeProfit.String())
}

// CausalInvarianceCheck runs the candidate on the original dataset and on a dataset whose future
// (known_at > cutoff) values were mutated, and verifies that every signal decided at or before the
// cutoff is unchanged.
//
// The negative control of this test is implemented by NegativeControlDetectsLeakage.
func CausalInvarianceCheck(c Candidate, d Dataset, cutoff time.Time) error {
	base, err := c.Generate(d)
	if err != nil {
		return fmt.Errorf("causal check: baseline generation failed: %w", err)
	}

	mutated, err := c.Generate(MutateFuture(d, cutoff))
	if err != nil {
		return fmt.Errorf("causal check: mutated generation failed: %w", err)
	}

	baseByBar := map[int]Signal{}
	for _, s := range base {
		if s.DecisionTime.After(cutoff) {
			continue
		}
		baseByBar[s.DecisionBar] = s
	}
	mutatedByBar := map[int]Signal{}
	for _, s := range mutated {
		if s.DecisionTime.After(cutoff) {
			continue
		}
		mutatedByBar[s.DecisionBar] = s
	}

	if len(baseByBar) != len(mutatedByBar) {
		return fmt.Errorf("causal check failed: signal count before the cutoff changed (%d -> %d)", len(baseByBar), len(mutatedByBar))
	}

	for bar, s := range baseByBar {
		m, ok := mutatedByBar[bar]
		if !ok {
			return fmt.Errorf("causal check failed: signal at bar %d disappeared after the future mutation", bar)
		}
		if SignalKey(s) != SignalKey(m) {
			return fmt.Errorf("causal check failed: signal at bar %d changed after the future mutation (%s -> %s)", bar, SignalKey(s), SignalKey(m))
		}
	}

	return nil
}

// PreviousBarCutoffCheck verifies that a decision taken at bar i does not depend on any bar after
// bar i: regenerating the signals from a dataset truncated at bar i must reproduce the same signal
// at bar i.
func PreviousBarCutoffCheck(c Candidate, d Dataset, i int) error {
	if i < 0 || i >= len(d.Bars) {
		return fmt.Errorf("previous bar cutoff check: bar index %d is out of range", i)
	}

	full, err := c.Generate(d)
	if err != nil {
		return fmt.Errorf("previous bar cutoff check: full generation failed: %w", err)
	}

	truncated := d
	truncated.Bars = d.Bars[:i+1]
	truncated.HTFBars = truncateBars(d.HTFBars, d.Bars[i].CloseTime)
	truncated.PeerBars = truncateBars(d.PeerBars, d.Bars[i].CloseTime)
	truncated.Funding = truncateFunding(d.Funding, d.Bars[i].CloseTime)
	truncated.OpenInterest = truncateOpenInterest(d.OpenInterest, d.Bars[i].CloseTime)

	partial, err := c.Generate(truncated)
	if err != nil {
		// The truncated dataset may not contain the inputs the candidate needs yet (for example a
		// funding event that was never published at that time). Such a candidate cannot decide and
		// therefore produces no signal. This is not a look-ahead violation, but the full run must
		// not contain a decision at that bar either.
		partial = nil
	}

	var fullSignal, partialSignal *Signal
	for idx := range full {
		if full[idx].DecisionBar == i {
			fullSignal = &full[idx]
			break
		}
	}
	for idx := range partial {
		if partial[idx].DecisionBar == i {
			partialSignal = &partial[idx]
			break
		}
	}

	switch {
	case fullSignal == nil && partialSignal == nil:
		return nil
	case fullSignal == nil || partialSignal == nil:
		return fmt.Errorf("previous bar cutoff check failed: signal at bar %d exists in only one of the two runs", i)
	case SignalKey(*fullSignal) != SignalKey(*partialSignal):
		return fmt.Errorf("previous bar cutoff check failed: signal at bar %d depends on later data", i)
	}

	return nil
}

func truncateBars(bars []Bar, cutoff time.Time) []Bar {
	out := make([]Bar, 0, len(bars))
	for _, b := range bars {
		if b.KnownAt.After(cutoff) {
			continue
		}
		out = append(out, b)
	}
	return out
}

func truncateFunding(events []FundingEvent, cutoff time.Time) []FundingEvent {
	out := make([]FundingEvent, 0, len(events))
	for _, e := range events {
		if e.KnownAt.After(cutoff) {
			continue
		}
		out = append(out, e)
	}
	return out
}

func truncateOpenInterest(events []OpenInterestEvent, cutoff time.Time) []OpenInterestEvent {
	out := make([]OpenInterestEvent, 0, len(events))
	for _, e := range events {
		if e.KnownAt.After(cutoff) {
			continue
		}
		out = append(out, e)
	}
	return out
}

// LeakyCandidate is a deliberately broken candidate that reads the NEXT bar. It exists only as the
// negative control of the causal test: the causal test MUST detect it.
type LeakyCandidate struct {
	IDValue string
}

// ID returns the identifier of the leaky control.
func (l *LeakyCandidate) ID() string { return l.IDValue }

// Version returns the version of the leaky control.
func (l *LeakyCandidate) Version() string { return "0.0.0-control" }

// Generate reads the next bar close, which is not known at the decision time.
//
// The leak is expressed through the risk prices, which is the realistic form of a look-ahead bug:
// a stop or a target that was derived from a candle that had not closed yet.
func (l *LeakyCandidate) Generate(d Dataset) ([]Signal, error) {
	signals := make([]Signal, 0, 4)
	for i := 1; i+1 < len(d.Bars); i++ {
		side := "BUY"
		if d.Bars[i+1].Close.Cmp(d.Bars[i].Close) < 0 {
			side = "SELL"
		}
		futureClose := d.Bars[i+1].Close
		signals = append(signals, Signal{
			CandidateID:  l.IDValue,
			Side:         side,
			DecisionTime: d.Bars[i].CloseTime,
			DecisionBar:  i,
			EntryBar:     i + 1,
			StopLoss:     futureClose.Mul(decimal.FromFloat64(0.99)),
			TakeProfit:   futureClose.Mul(decimal.FromFloat64(1.01)),
			Reason:       "NEGATIVE_CONTROL_FUTURE_ONLY_FEATURE",
		})
	}
	return signals, nil
}

// NegativeControlDetectsLeakage verifies that the causal test FAILS for a deliberately leaky
// candidate. If this check passes, the causal test is invalid and the candidate must not be
// reported as look-ahead safe.
func NegativeControlDetectsLeakage(c Candidate, d Dataset, cutoff time.Time) error {
	err := CausalInvarianceCheck(c, d, cutoff)
	if err == nil {
		return fmt.Errorf("negative control failed: the causal test did NOT detect a future-only feature in %s", c.ID())
	}
	return nil
}
