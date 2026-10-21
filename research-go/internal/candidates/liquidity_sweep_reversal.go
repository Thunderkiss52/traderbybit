package candidates

import (
	"github.com/traderbybit/research-go/internal/decimal"
)

// LiquiditySweepConfig is the precommitted configuration of the false-break family.
//
// Support and resistance levels are rolling extremes of a fixed window that ends BEFORE the
// decision bar. No pivot is confirmed with future bars, therefore no hindsight swing point can
// enter a decision.
type LiquiditySweepConfig struct {
	MinBars        int
	ATRPeriod      int
	StopATRMult    float64
	TargetATRMult  float64
	LevelLookback  int
	MinLevelMargin decimal.Decimal
}

// DefaultLiquiditySweepConfig returns the precommitted default configuration.
func DefaultLiquiditySweepConfig() LiquiditySweepConfig {
	return LiquiditySweepConfig{
		MinBars:        60,
		ATRPeriod:      14,
		StopATRMult:    1.5,
		TargetATRMult:  3.0,
		LevelLookback:  20,
		MinLevelMargin: decimal.Zero(),
	}
}

// LiquiditySweepReversal evaluates false-break / level-recapture hypotheses.
type LiquiditySweepReversal struct {
	cfg LiquiditySweepConfig
}

// NewLiquiditySweepReversal builds the candidate with the given configuration.
func NewLiquiditySweepReversal(cfg LiquiditySweepConfig) *LiquiditySweepReversal {
	return &LiquiditySweepReversal{cfg: cfg}
}

// ID returns the research cohort identifier.
func (c *LiquiditySweepReversal) ID() string { return "liquidity_sweep_reversal" }

// Version returns the versioned rule set of the candidate.
func (c *LiquiditySweepReversal) Version() string { return "1.0.0" }

// priorLevels returns the highest high and the lowest low of the window that ends before index i.
func priorLevels(bars []Bar, i, lookback int) (support, resistance decimal.Decimal, ok bool) {
	start := i - lookback
	if start < 0 {
		return decimal.Zero(), decimal.Zero(), false
	}

	support = bars[start].Low
	resistance = bars[start].High
	for j := start + 1; j < i; j++ {
		if bars[j].Low.Cmp(support) < 0 {
			support = bars[j].Low
		}
		if bars[j].High.Cmp(resistance) > 0 {
			resistance = bars[j].High
		}
	}
	return support, resistance, true
}

// Generate produces the candidate signals.
func (c *LiquiditySweepReversal) Generate(d Dataset) ([]Signal, error) {
	if err := validateDataset(d, c.cfg.MinBars); err != nil {
		return nil, err
	}

	ind := computeIndicators(d.Bars, c.cfg.ATRPeriod, 20, 50, 14, 20, 2.0)
	signals := make([]Signal, 0, 16)

	for i := c.cfg.MinBars; i < len(d.Bars); i++ {
		support, resistance, ok := priorLevels(d.Bars, i, c.cfg.LevelLookback)
		if !ok {
			continue
		}

		side := ""
		reason := ""

		// Sweep below the prior-known support and recapture it on the close.
		if d.Bars[i].Low.Cmp(support) < 0 && d.Bars[i].Close.Cmp(support) > 0 {
			side, reason = "BUY", "PRIOR_SUPPORT_SWEEP_RECAPTURE"
		}

		// Sweep above the prior-known resistance and reject it on the close.
		if d.Bars[i].High.Cmp(resistance) > 0 && d.Bars[i].Close.Cmp(resistance) < 0 {
			side, reason = "SELL", "PRIOR_RESISTANCE_SWEEP_REJECTION"
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
			DecisionTime: d.Bars[i].CloseTime,
			DecisionBar:  i,
			EntryBar:     i + 1,
			StopLoss:     stop,
			TakeProfit:   target,
			Reason:       reason,
			Features: map[string]string{
				"prior_support":    support.String(),
				"prior_resistance": resistance.String(),
				"close":            d.Bars[i].Close.String(),
			},
		})
	}

	return signals, nil
}
