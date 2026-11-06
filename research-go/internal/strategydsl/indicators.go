package strategydsl

import (
	"github.com/traderbybit/research-go/internal/algorithms"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
	"math"
)

func adx(cs []datasets.Candle, period int) []*decimal.Decimal {
	out := make([]*decimal.Decimal, len(cs))
	if len(cs) <= period*2 {
		return out
	}
	tr, plus, minus := 0.0, 0.0, 0.0
	dx := []float64{}
	average := 0.0
	for i := 1; i < len(cs); i++ {
		up := cs[i].High.Sub(cs[i-1].High).Float64()
		down := cs[i-1].Low.Sub(cs[i].Low).Float64()
		p, m := 0.0, 0.0
		if up > down && up > 0 {
			p = up
		}
		if down > up && down > 0 {
			m = down
		}
		r := math.Max(cs[i].High.Sub(cs[i].Low).Float64(), math.Max(math.Abs(cs[i].High.Sub(cs[i-1].Close).Float64()), math.Abs(cs[i].Low.Sub(cs[i-1].Close).Float64())))
		if i <= period {
			tr += r
			plus += p
			minus += m
		} else {
			tr = tr - tr/float64(period) + r
			plus = plus - plus/float64(period) + p
			minus = minus - minus/float64(period) + m
		}
		if i < period {
			continue
		}
		x := 0.0
		if plus+minus > 0 {
			x = 100 * math.Abs(plus-minus) / (plus + minus)
		}
		if len(dx) < period {
			dx = append(dx, x)
			if len(dx) < period {
				continue
			}
			for _, v := range dx {
				average += v
			}
			average /= float64(period)
		} else {
			average = (average*float64(period-1) + x) / float64(period)
		}
		value := decimal.FromFloat64(average)
		out[i] = &value
	}
	return out
}
func roc(closes []decimal.Decimal, period int) []*decimal.Decimal {
	out := make([]*decimal.Decimal, len(closes))
	if len(closes) <= period || period <= 0 {
		return out
	}
	hundred := decimal.FromInt64(100)
	for i := period; i < len(closes); i++ {
		prev := closes[i-period]
		if prev.IsZero() {
			continue
		}
		diff := closes[i].Sub(prev)
		val, err := diff.Mul(hundred).Div(prev)
		if err == nil {
			out[i] = &val
		}
	}
	return out
}

func zscore(closes []decimal.Decimal, period int) []*decimal.Decimal {
	out := make([]*decimal.Decimal, len(closes))
	if len(closes) < period || period <= 0 {
		return out
	}
	smas := algorithms.CalculateSMA(closes, period)
	for i := period - 1; i < len(closes); i++ {
		mid := smas[i]
		if mid == nil {
			continue
		}
		midFloat := mid.Float64()
		var sumSq float64
		for j := i - period + 1; j <= i; j++ {
			diff := closes[j].Float64() - midFloat
			sumSq += diff * diff
		}
		stdDev := math.Sqrt(sumSq / float64(period))
		if stdDev == 0 {
			zero := decimal.Zero()
			out[i] = &zero
		} else {
			z := (closes[i].Float64() - midFloat) / stdDev
			val := decimal.FromFloat64(z)
			out[i] = &val
		}
	}
	return out
}

func extendedValues(cs []datasets.Candle, ind Indicator, closes []decimal.Decimal) []*decimal.Decimal {
	switch ind.Type {
	case "ADX":
		return adx(cs, ind.Period)
	case "ROC":
		return roc(closes, ind.Period)
	case "ZSCORE":
		return zscore(closes, ind.Period)
	case "MACD_SIGNAL", "MACD_HISTOGRAM":
		macd := values(cs, Indicator{Type: "MACD", Period: ind.Period, SlowPeriod: ind.SlowPeriod, Timeframe: ind.Timeframe})
		start := 0
		for start < len(macd) && macd[start] == nil {
			start++
		}
		raw := []decimal.Decimal{}
		for _, v := range macd[start:] {
			raw = append(raw, *v)
		}
		period := ind.SignalPeriod
		if period == 0 {
			period = 9
		}
		signal := algorithms.CalculateEMA(raw, period)
		out := make([]*decimal.Decimal, len(cs))
		for i, v := range signal {
			if v != nil {
				value := *v
				if ind.Type == "MACD_HISTOGRAM" {
					value = macd[start+i].Sub(*v)
				}
				out[start+i] = &value
			}
		}
		return out
	}
	out := make([]*decimal.Decimal, len(cs))
	for i, c := range cs {
		v := c.Close
		switch ind.Type {
		case "OPEN":
			v = c.Open
		case "HIGH":
			v = c.High
		case "LOW":
			v = c.Low
		case "VOLUME":
			v = c.Volume
		case "TURNOVER":
			v = c.Turnover
		case "BODY":
			v = c.Close.Sub(c.Open)
		case "RANGE":
			v = c.High.Sub(c.Low)
		}
		out[i] = &v
	}
	return out
}
