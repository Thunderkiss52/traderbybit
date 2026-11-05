package strategydsl

import (
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
	"strings"
)

// HistoryWindow is versioned strategy semantics, not a missing-data fallback.
func HistoryWindow(d Definition) int {
	window := 220
	for name, ind := range d.Indicators {
		period, slow, signal := ind.Period, ind.SlowPeriod, ind.SignalPeriod
		if signal == 0 {
			signal = 9
		}
		for path, p := range d.Parameters {
			prefix := "indicators." + name + "."
			if strings.HasPrefix(path, prefix) {
				switch strings.TrimPrefix(path, prefix) {
				case "period":
					if int(p.Max) > period {
						period = int(p.Max)
					}
				case "slow_period":
					if int(p.Max) > slow {
						slow = int(p.Max)
					}
				case "signal_period":
					if int(p.Max) > signal {
						signal = int(p.Max)
					}
				}
			}
		}
		need := period * 2
		if slow > need {
			need = slow
		}
		need += signal + 2
		if need > window {
			window = need
		}
	}
	return window
}
func boundedValue(cs []datasets.Candle, ind Indicator, at, window int) *decimal.Decimal {
	if at < window-1 {
		return nil
	}
	result := values(cs[at-window+1:at+1], ind)
	return result[len(result)-1]
}
