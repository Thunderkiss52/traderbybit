package candidates

import (
	"fmt"
)

// MultiTimeframeConfig is the precommitted configuration of the higher/lower timeframe family.
//
// The higher timeframe trend is taken from a higher timeframe bar that was FULLY CLOSED and KNOWN
// at the decision timestamp. The lookup is a search over (CloseTime, KnownAt), never an index
// assumption, so a higher timeframe candle can never be used before it closed.
type MultiTimeframeConfig struct {
	MinBars         int
	HTFMinBars      int
	HTFEMAFast      int
	HTFEMASlow      int
	ATRPeriod       int
	StopATRMult     float64
	TargetATRMult   float64
	EntryEMAFast    int
	EntryEMASlow    int
	BreakoutLookout int
	EntryMode       string
}

const (
	EntryModeEMACross  = "EMA_CROSS"
	EntryModeBreakout  = "BREAKOUT"
	EntryModeEitherOne = "EMA_CROSS_OR_BREAKOUT"
)

// DefaultMultiTimeframeConfig returns the precommitted default configuration.
func DefaultMultiTimeframeConfig() MultiTimeframeConfig {
	return MultiTimeframeConfig{
		MinBars:         60,
		HTFMinBars:      60,
		HTFEMAFast:      20,
		HTFEMASlow:      50,
		ATRPeriod:       14,
		StopATRMult:     1.5,
		TargetATRMult:   3.0,
		EntryEMAFast:    9,
		EntryEMASlow:    21,
		BreakoutLookout: 20,
		EntryMode:       EntryModeEMACross,
	}
}

// MultiTimeframeTrendFollowing evaluates higher timeframe trend with lower timeframe confirmation.
type MultiTimeframeTrendFollowing struct {
	cfg MultiTimeframeConfig
}

// NewMultiTimeframeTrendFollowing builds the candidate with the given configuration.
func NewMultiTimeframeTrendFollowing(cfg MultiTimeframeConfig) *MultiTimeframeTrendFollowing {
	return &MultiTimeframeTrendFollowing{cfg: cfg}
}

// ID returns the research cohort identifier.
func (c *MultiTimeframeTrendFollowing) ID() string { return "multi_timeframe_trend_following" }

// Version returns the versioned rule set of the candidate.
func (c *MultiTimeframeTrendFollowing) Version() string { return "1.0.0" }

// Generate produces the candidate signals.
func (c *MultiTimeframeTrendFollowing) Generate(d Dataset) ([]Signal, error) {
	if err := validateDataset(d, c.cfg.MinBars); err != nil {
		return nil, err
	}
	if len(d.HTFBars) == 0 {
		return nil, fmt.Errorf("candidate %s requires a higher timeframe series", c.ID())
	}

	ind := computeIndicators(d.Bars, c.cfg.ATRPeriod, c.cfg.EntryEMAFast, c.cfg.EntryEMASlow, 14, 20, 2.0)
	htf := computeIndicators(d.HTFBars, c.cfg.ATRPeriod, c.cfg.HTFEMAFast, c.cfg.HTFEMASlow, 14, 20, 2.0)

	signals := make([]Signal, 0, 16)

	for i := c.cfg.MinBars; i < len(d.Bars); i++ {
		decisionTime := d.Bars[i].CloseTime

		htfIdx, ok := lastKnownBarIndex(d.HTFBars, decisionTime)
		if !ok {
			continue
		}
		// The warm-up gate counts the higher timeframe bars that were KNOWN at the decision time.
		// It never depends on the position of the bar inside the array.
		if countKnownBars(d.HTFBars, decisionTime) < c.cfg.HTFMinBars {
			continue
		}
		htfBar := d.HTFBars[htfIdx]

		htfFast, okFast := emaAt(htf.emaFast, htfIdx)
		htfSlow, okSlow := emaAt(htf.emaSlow, htfIdx)
		if !okFast || !okSlow {
			continue
		}

		trend := ""
		switch {
		case htfFast.Cmp(htfSlow) > 0:
			trend = "UP"
		case htfFast.Cmp(htfSlow) < 0:
			trend = "DOWN"
		default:
			continue
		}

		side := ""
		reason := ""
		entryFast, okEntryFast := emaAt(ind.emaFast, i)
		entrySlow, okEntrySlow := emaAt(ind.emaSlow, i)
		prevFast, okPrevFast := emaAt(ind.emaFast, i-1)
		prevSlow, okPrevSlow := emaAt(ind.emaSlow, i-1)

		if c.cfg.EntryMode == EntryModeEMACross || c.cfg.EntryMode == EntryModeEitherOne {
			if okEntryFast && okEntrySlow && okPrevFast && okPrevSlow {
				crossUp := prevFast.Cmp(prevSlow) <= 0 && entryFast.Cmp(entrySlow) > 0
				crossDown := prevFast.Cmp(prevSlow) >= 0 && entryFast.Cmp(entrySlow) < 0
				if crossUp && trend == "UP" {
					side, reason = "BUY", "HTF_UPTREND_LTF_EMA_CROSS_UP"
				} else if crossDown && trend == "DOWN" {
					side, reason = "SELL", "HTF_DOWNTREND_LTF_EMA_CROSS_DOWN"
				}
			}
		}

		if side == "" && (c.cfg.EntryMode == EntryModeBreakout || c.cfg.EntryMode == EntryModeEitherOne) {
			start := i - c.cfg.BreakoutLookout
			if start >= 0 {
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
				if d.Bars[i].Close.Cmp(highest) > 0 && trend == "UP" {
					side, reason = "BUY", "HTF_UPTREND_LTF_BREAKOUT_UP"
				} else if d.Bars[i].Close.Cmp(lowest) < 0 && trend == "DOWN" {
					side, reason = "SELL", "HTF_DOWNTREND_LTF_BREAKOUT_DOWN"
				}
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
				"htf_close_time": htfBar.CloseTime.UTC().Format("2006-01-02T15:04:05Z"),
				"htf_trend":      trend,
				"htf_ema_fast":   htfFast.String(),
				"htf_ema_slow":   htfSlow.String(),
			},
		})
	}

	return signals, nil
}
