package candidates

import (
	"github.com/traderbybit/research-go/internal/decimal"
)

// VolatilityCompressionConfig is the precommitted configuration of the compression breakout family.
//
// The compression window, its ratio and the breakout window are fixed before evaluation. The
// breakout is confirmed on the close of the decision bar and the fill happens at the open of the
// next bar, so no intrabar sequence is assumed.
type VolatilityCompressionConfig struct {
	MinBars         int
	ATRPeriod       int
	CompressionBars int
	BaseBars        int
	CompressionRate float64
	BreakoutLookout int
	StopATRMult     float64
	TargetATRMult   float64
}

// DefaultVolatilityCompressionConfig returns the precommitted default configuration.
func DefaultVolatilityCompressionConfig() VolatilityCompressionConfig {
	return VolatilityCompressionConfig{
		MinBars:         60,
		ATRPeriod:       14,
		CompressionBars: 6,
		BaseBars:        20,
		CompressionRate: 0.8,
		BreakoutLookout: 20,
		StopATRMult:     1.5,
		TargetATRMult:   3.0,
	}
}

// VolatilityCompressionBreakout evaluates precommitted volatility compression breakouts.
type VolatilityCompressionBreakout struct {
	cfg VolatilityCompressionConfig
}

// NewVolatilityCompressionBreakout builds the candidate with the given configuration.
func NewVolatilityCompressionBreakout(cfg VolatilityCompressionConfig) *VolatilityCompressionBreakout {
	return &VolatilityCompressionBreakout{cfg: cfg}
}

// ID returns the research cohort identifier.
func (c *VolatilityCompressionBreakout) ID() string { return "volatility_compression_breakout" }

// Version returns the versioned rule set of the candidate.
func (c *VolatilityCompressionBreakout) Version() string { return "1.0.0" }

// Generate produces the candidate signals.
func (c *VolatilityCompressionBreakout) Generate(d Dataset) ([]Signal, error) {
	if err := validateDataset(d, c.cfg.MinBars); err != nil {
		return nil, err
	}

	ind := computeIndicators(d.Bars, c.cfg.ATRPeriod, 20, 50, 14, 20, 2.0)

	signals := make([]Signal, 0, 16)

	for i := c.cfg.MinBars; i < len(d.Bars); i++ {
		// The compression must be established BEFORE the breakout bar, otherwise a real
		// breakout would always fail its own compression test.
		//
		// Compression is the ratio between the mean true range of the most recent closed bars and
		// the mean true range of the baseline window that ends right before them.
		recent, okRecent := meanTrueRange(d.Bars, i-c.cfg.CompressionBars, i)
		base, okBase := meanTrueRange(d.Bars, i-c.cfg.CompressionBars-c.cfg.BaseBars, i-c.cfg.CompressionBars)
		if !okRecent || !okBase || !base.IsPositive() {
			continue
		}
		if recent.Cmp(base.Mul(decimal.FromFloat64(c.cfg.CompressionRate))) > 0 {
			continue
		}

		start := i - c.cfg.BreakoutLookout
		if start < 0 {
			continue
		}
		highest := d.Bars[start].High
		lowest := d.Bars[start].Low
		for j := start + 1; j < i; j++ {
			if d.Bars[j].High.Cmp(highest) > 0 {
				highest = d.Bars[j].High
			}
			if d.Bars[j].Low.Cmp(lowest) < 0 {
				lowest = d.Bars[j].Low
			}
		}

		side := ""
		reason := ""
		if d.Bars[i].Close.Cmp(highest) > 0 {
			side, reason = "BUY", "COMPRESSION_BREAKOUT_UP"
		} else if d.Bars[i].Close.Cmp(lowest) < 0 {
			side, reason = "SELL", "COMPRESSION_BREAKOUT_DOWN"
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
				"breakout_level_high": highest.String(),
				"breakout_level_low":  lowest.String(),
				"close":               d.Bars[i].Close.String(),
			},
		})
	}

	return signals, nil
}
