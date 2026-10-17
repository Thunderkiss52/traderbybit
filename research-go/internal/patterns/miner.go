package patterns

import (
	"fmt"
	"math"
	"sort"

	"github.com/traderbybit/research-go/internal/algorithms"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

type PatternConfig struct {
	MinSupport    float64 `json:"min_support"`     // e.g. 0.05 (5%)
	MinConfidence float64 `json:"min_confidence"`  // e.g. 0.55 (55%)
	MinSampleSize int     `json:"min_sample_size"` // e.g. 30
	HorizonBars   int     `json:"horizon_bars"`    // e.g. 12 (1 hour on 5m)
	MaxFDR        float64 `json:"max_fdr"`         // e.g. 0.10 (10% false discovery rate)
}

func DefaultPatternConfig() PatternConfig {
	return PatternConfig{
		MinSupport:    0.04,
		MinConfidence: 0.55,
		MinSampleSize: 30,
		HorizonBars:   12,
		MaxFDR:        0.10,
	}
}

type MinedPattern struct {
	Conditions     []string `json:"conditions"`
	Direction      string   `json:"direction"` // "BUY" or "SELL"
	SampleSize     int      `json:"sample_size"`
	SuccessCount   int      `json:"success_count"`
	Support        float64  `json:"support"`
	Confidence     float64  `json:"confidence"`
	BaselineRate   float64  `json:"baseline_rate"`
	Lift           float64  `json:"lift"`
	PValue         float64  `json:"p_value"`
	FDRPassed      bool     `json:"fdr_passed"`
	NetExpectancyR float64  `json:"net_expectancy_r"`
}

type Observation struct {
	Features []string
	BullWin  bool
	BearWin  bool
}

// MinePatterns discovers frequent decision-time conditions predictive of forward returns
func MinePatterns(candles []datasets.Candle, cfg PatternConfig) (patterns []MinedPattern, retErr error) {
	defer func() {
		if r := recover(); r != nil {
			retErr = fmt.Errorf("pattern mining engine panic: %v", r)
		}
	}()

	n := len(candles)
	if n < 100 {
		return nil, fmt.Errorf("insufficient candles for pattern mining: got %d, need >= 100", n)
	}

	closes := make([]decimal.Decimal, n)
	volumes := make([]decimal.Decimal, n)
	for i, c := range candles {
		closes[i] = c.Close
		volumes[i] = c.Volume
	}

	fastEMA := algorithms.CalculateEMA(closes, 20)
	slowEMA := algorithms.CalculateEMA(closes, 50)
	rsi := algorithms.CalculateRSI(closes, 14)
	volSMA := algorithms.CalculateSMA(volumes, 20)
	bb := algorithms.CalculateBollingerBands(closes, 20, 2.0)

	totalObs := 0
	observations := make([]Observation, 0, n)
	bullWinsTotal := 0
	bearWinsTotal := 0

	// Horizon guard: don't evaluate the last HorizonBars candles (causality protection)
	for i := 50; i < n-cfg.HorizonBars; i++ {
		c := candles[i]
		futureClose := candles[i+cfg.HorizonBars].Close

		priceChange := futureClose.Sub(c.Close)
		bullWin := priceChange.IsPositive()
		bearWin := priceChange.IsNegative()

		features := make([]string, 0, 8)

		// 1. Trend feature
		if fastEMA[i] != nil && slowEMA[i] != nil {
			if fastEMA[i].Cmp(*slowEMA[i]) > 0 {
				features = append(features, "trend_bullish")
			} else {
				features = append(features, "trend_bearish")
			}
		}

		// 2. RSI Zone
		if rsi[i] != nil {
			rv := rsi[i].Float64()
			if rv < 35 {
				features = append(features, "rsi_oversold")
			} else if rv > 65 {
				features = append(features, "rsi_overbought")
			} else {
				features = append(features, "rsi_neutral")
			}
		}

		// 3. Volume spike
		if volSMA[i] != nil && volSMA[i].IsPositive() {
			volRatio := c.Volume.Float64() / volSMA[i].Float64()
			if volRatio > 1.5 {
				features = append(features, "volume_spike")
			}
		}

		// 4. Bollinger Band breakout
		if bb.Upper[i] != nil && bb.Lower[i] != nil {
			if c.Close.Cmp(*bb.Upper[i]) > 0 {
				features = append(features, "bb_breakout_high")
			} else if c.Close.Cmp(*bb.Lower[i]) < 0 {
				features = append(features, "bb_breakout_low")
			}
		}

		if bullWin {
			bullWinsTotal++
		}
		if bearWin {
			bearWinsTotal++
		}
		totalObs++

		observations = append(observations, Observation{
			Features: features,
			BullWin:  bullWin,
			BearWin:  bearWin,
		})
	}

	if totalObs < cfg.MinSampleSize {
		return []MinedPattern{}, nil
	}

	baseBullRate := float64(bullWinsTotal) / float64(totalObs)
	baseBearRate := float64(bearWinsTotal) / float64(totalObs)

	// Collect candidate itemsets of size 1 and 2
	type itemStat struct {
		featureCombo []string
		count        int
		bullWins     int
		bearWins     int
	}

	statsMap := make(map[string]*itemStat)

	for _, obs := range observations {
		m := len(obs.Features)
		for j := 0; j < m; j++ {
			f1 := obs.Features[j]
			if _, ok := statsMap[f1]; !ok {
				statsMap[f1] = &itemStat{featureCombo: []string{f1}}
			}
			statsMap[f1].count++
			if obs.BullWin {
				statsMap[f1].bullWins++
			}
			if obs.BearWin {
				statsMap[f1].bearWins++
			}

			// Pairwise
			for k := j + 1; k < m; k++ {
				f2 := obs.Features[k]
				key := f1 + "+" + f2
				if _, ok := statsMap[key]; !ok {
					statsMap[key] = &itemStat{featureCombo: []string{f1, f2}}
				}
				statsMap[key].count++
				if obs.BullWin {
					statsMap[key].bullWins++
				}
				if obs.BearWin {
					statsMap[key].bearWins++
				}
			}
		}
	}

	candidates := make([]MinedPattern, 0, len(statsMap)*2)

	for _, st := range statsMap {
		if st.count < cfg.MinSampleSize {
			continue
		}
		support := float64(st.count) / float64(totalObs)
		if support < cfg.MinSupport {
			continue
		}

		// Evaluate Bullish
		bullConf := float64(st.bullWins) / float64(st.count)
		if bullConf >= cfg.MinConfidence && bullConf > baseBullRate {
			lift := bullConf / baseBullRate
			// Binomial normal approximation p-value
			se := math.Sqrt(baseBullRate * (1 - baseBullRate) / float64(st.count))
			z := (bullConf - baseBullRate) / se
			pValue := 0.5 * math.Erfc(z/math.Sqrt2)

			candidates = append(candidates, MinedPattern{
				Conditions:     st.featureCombo,
				Direction:      "BUY",
				SampleSize:     st.count,
				SuccessCount:   st.bullWins,
				Support:        math.Round(support*1000) / 1000,
				Confidence:     math.Round(bullConf*1000) / 1000,
				BaselineRate:   math.Round(baseBullRate*1000) / 1000,
				Lift:           math.Round(lift*1000) / 1000,
				PValue:         pValue,
				NetExpectancyR: math.Round((bullConf*2.0-(1.0-bullConf)*1.0)*1000) / 1000,
			})
		}

		// Evaluate Bearish
		bearConf := float64(st.bearWins) / float64(st.count)
		if bearConf >= cfg.MinConfidence && bearConf > baseBearRate {
			lift := bearConf / baseBearRate
			se := math.Sqrt(baseBearRate * (1 - baseBearRate) / float64(st.count))
			z := (bearConf - baseBearRate) / se
			pValue := 0.5 * math.Erfc(z/math.Sqrt2)

			candidates = append(candidates, MinedPattern{
				Conditions:     st.featureCombo,
				Direction:      "SELL",
				SampleSize:     st.count,
				SuccessCount:   st.bearWins,
				Support:        math.Round(support*1000) / 1000,
				Confidence:     math.Round(bearConf*1000) / 1000,
				BaselineRate:   math.Round(baseBearRate*1000) / 1000,
				Lift:           math.Round(lift*1000) / 1000,
				PValue:         pValue,
				NetExpectancyR: math.Round((bearConf*2.0-(1.0-bearConf)*1.0)*1000) / 1000,
			})
		}
	}

	// Apply Benjamini-Hochberg False Discovery Rate (FDR) control
	sort.Slice(candidates, func(i, j int) bool {
		return candidates[i].PValue < candidates[j].PValue
	})

	m := len(candidates)
	maxK := -1
	for i := m - 1; i >= 0; i-- {
		rank := float64(i + 1)
		bhThreshold := (rank / float64(m)) * cfg.MaxFDR
		if candidates[i].PValue <= bhThreshold {
			maxK = i
			break
		}
	}

	for i := range candidates {
		candidates[i].FDRPassed = (maxK >= 0 && i <= maxK)
	}

	return candidates, nil
}
