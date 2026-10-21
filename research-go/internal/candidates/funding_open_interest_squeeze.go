package candidates

import (
	"fmt"
	"time"

	"github.com/traderbybit/research-go/internal/decimal"
)

// FundingOpenInterestConfig is the precommitted configuration of the crowding hypothesis.
//
// Hypothesis family: a crowded side (extreme funding + rising open interest) that fails to extend
// the price move is assumed to squeeze. The reversal variant is the precommitted default; the
// continuation variant is available for an explicitly registered challenger cohort and is never
// selected after inspecting held-out results.
type FundingOpenInterestConfig struct {
	MinBars                 int
	ATRPeriod               int
	StopATRMult             float64
	TargetATRMult           float64
	FundingRateThreshold    decimal.Decimal
	OpenInterestLookbackBar int
	PriceMomentumLookback   int
	PriceMomentumThreshold  decimal.Decimal
	OpenInterestMinChange   decimal.Decimal
	Mode                    string
}

const (
	FundingModeReversal     = "CROWDING_REVERSAL"
	FundingModeContinuation = "CROWDING_CONTINUATION"
)

// DefaultFundingOpenInterestConfig returns the precommitted default configuration.
func DefaultFundingOpenInterestConfig() FundingOpenInterestConfig {
	return FundingOpenInterestConfig{
		MinBars:                 60,
		ATRPeriod:               14,
		StopATRMult:             1.5,
		TargetATRMult:           3.0,
		FundingRateThreshold:    decimal.MustFromString("0.00010000"),
		OpenInterestLookbackBar: 4,
		PriceMomentumLookback:   4,
		PriceMomentumThreshold:  decimal.MustFromString("0.00100000"),
		OpenInterestMinChange:   decimal.MustFromString("0.00000000"),
		Mode:                    FundingModeReversal,
	}
}

// FundingOpenInterestSqueeze evaluates funding / open interest crowding hypotheses.
type FundingOpenInterestSqueeze struct {
	cfg FundingOpenInterestConfig
}

// NewFundingOpenInterestSqueeze builds the candidate with the given configuration.
func NewFundingOpenInterestSqueeze(cfg FundingOpenInterestConfig) *FundingOpenInterestSqueeze {
	return &FundingOpenInterestSqueeze{cfg: cfg}
}

// ID returns the research cohort identifier.
func (c *FundingOpenInterestSqueeze) ID() string { return "funding_open_interest_squeeze" }

// Version returns the versioned rule set of the candidate.
func (c *FundingOpenInterestSqueeze) Version() string { return "1.0.0" }

// Generate produces the candidate signals. Every input is restricted to values that were already
// published at the decision timestamp.
func (c *FundingOpenInterestSqueeze) Generate(d Dataset) ([]Signal, error) {
	if err := validateDataset(d, c.cfg.MinBars); err != nil {
		return nil, err
	}
	if len(d.Funding) == 0 || len(d.OpenInterest) == 0 {
		return nil, fmt.Errorf("candidate %s requires published funding and open interest events", c.ID())
	}

	ind := computeIndicators(d.Bars, c.cfg.ATRPeriod, 20, 50, 14, 20, 2.0)
	barDuration := d.Bars[1].OpenTime.Sub(d.Bars[0].OpenTime)
	if barDuration <= 0 {
		return nil, fmt.Errorf("candidate %s: cannot derive the bar duration", c.ID())
	}

	signals := make([]Signal, 0, 16)

	for i := c.cfg.MinBars; i < len(d.Bars); i++ {
		decisionTime := d.Bars[i].CloseTime

		funding, ok := lastKnownFunding(d.Funding, decisionTime)
		if !ok {
			continue
		}

		oiNow, ok := openInterestAt(d.OpenInterest, decisionTime)
		if !ok {
			continue
		}
		oiPast, ok := openInterestAt(d.OpenInterest, decisionTime.Add(-time.Duration(c.cfg.OpenInterestLookbackBar)*barDuration))
		if !ok || oiPast.OpenInterest.IsZero() {
			continue
		}

		fundingAbs := absDecimal(funding.Rate)
		if fundingAbs.Cmp(c.cfg.FundingRateThreshold) < 0 {
			continue
		}

		oiChange := oiNow.OpenInterest.Sub(oiPast.OpenInterest)
		if oiChange.Cmp(c.cfg.OpenInterestMinChange) <= 0 {
			continue
		}

		priceMove := d.Bars[i].Close.Sub(d.Bars[i-c.cfg.PriceMomentumLookback].Close)
		priceMoveAbs := absDecimal(priceMove)
		if priceMoveAbs.Cmp(c.cfg.PriceMomentumThreshold) < 0 {
			continue
		}

		side := ""
		reason := ""

		switch c.cfg.Mode {
		case FundingModeContinuation:
			// Continuation: the crowded side keeps extending the move.
			if funding.Rate.IsPositive() && priceMove.IsPositive() {
				side, reason = "BUY", "FUNDING_CROWD_LONG_CONTINUATION"
			} else if funding.Rate.IsNegative() && priceMove.IsNegative() {
				side, reason = "SELL", "FUNDING_CROWD_SHORT_CONTINUATION"
			}
		default:
			// Reversal: the crowded side fails to extend the move.
			if funding.Rate.IsPositive() && priceMove.IsNegative() {
				side, reason = "BUY", "FUNDING_CROWD_LONG_FAILED_SQUEEZE"
			} else if funding.Rate.IsNegative() && priceMove.IsPositive() {
				side, reason = "SELL", "FUNDING_CROWD_SHORT_FAILED_SQUEEZE"
			}
		}

		if side == "" {
			continue
		}

		atrValue, ok := atrAt(ind.atr, i)
		if !ok || !atrValue.IsPositive() {
			continue
		}

		stop, target := stopAndTarget(side, d.Bars[i].Close, atrValue, c.cfg.StopATRMult, c.cfg.TargetATRMult)

		signals = append(signals, Signal{
			CandidateID:  c.ID(),
			Side:         side,
			DecisionTime: decisionTime,
			DecisionBar:  i,
			EntryBar:     i + 1,
			StopLoss:     stop,
			TakeProfit:   target,
			Reason:       reason,
			Features: map[string]string{
				"funding_rate":      funding.Rate.String(),
				"funding_event_at":  funding.EventTime.UTC().Format(time.RFC3339),
				"open_interest":     oiNow.OpenInterest.String(),
				"open_interest_chg": oiChange.String(),
				"price_move":        priceMove.String(),
			},
		})
	}

	return signals, nil
}
