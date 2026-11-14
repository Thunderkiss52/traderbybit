package indicators

import (
	"fmt"

	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

// divSafe divides a by b, returning Zero on division-by-zero instead of an error.
// Used internally so callers do not need to handle the error at every call site.
func divSafe(a, b decimal.Decimal) decimal.Decimal {
	v, err := a.Div(b)
	if err != nil {
		return decimal.Zero()
	}
	return v
}

// EMA computes the exponential moving average for a slice of closed candles.
// period must be >= 1. The result slice has the same length; for the first
// (period-1) entries the value is nil because there is insufficient history.
func EMA(candles []datasets.Candle, period int) ([]*decimal.Decimal, error) {
	if period <= 0 {
		return nil, fmt.Errorf("period must be positive")
	}
	n := len(candles)
	result := make([]*decimal.Decimal, n)
	if n == 0 {
		return result, nil
	}
	// multiplier = 2 / (period + 1)
	mult := divSafe(decimal.FromFloat64(2.0), decimal.FromFloat64(float64(period+1)))
	var prev *decimal.Decimal
	for i, c := range candles {
		closeVal := c.Close
		if i == 0 {
			prev = &closeVal
			result[i] = prev
			continue
		}
		// EMA = (Close - PrevEMA) * multiplier + PrevEMA
		diff := closeVal.Sub(*prev)
		ema := diff.Mul(mult).Add(*prev)
		e := ema
		result[i] = &e
		prev = &e
	}
	// Nil out first (period-1) entries — insufficient history.
	for i := 0; i < period-1 && i < n; i++ {
		result[i] = nil
	}
	return result, nil
}

// SMA computes the simple moving average of close prices.
func SMA(candles []datasets.Candle, period int) ([]*decimal.Decimal, error) {
	if period <= 0 {
		return nil, fmt.Errorf("period must be positive")
	}
	n := len(candles)
	result := make([]*decimal.Decimal, n)
	if n == 0 {
		return result, nil
	}
	periodD := decimal.FromInt64(int64(period))
	var sum decimal.Decimal
	for i := 0; i < n; i++ {
		sum = sum.Add(candles[i].Close)
		if i >= period-1 {
			avg := divSafe(sum, periodD)
			result[i] = &avg
			// slide the window: subtract the value falling out.
			sum = sum.Sub(candles[i-period+1].Close)
		} else {
			result[i] = nil
		}
	}
	return result, nil
}

// RSI computes the Relative Strength Index (Wilder smoothing).
func RSI(candles []datasets.Candle, period int) ([]*decimal.Decimal, error) {
	if period <= 0 {
		return nil, fmt.Errorf("period must be positive")
	}
	n := len(candles)
	result := make([]*decimal.Decimal, n)
	if n == 0 {
		return result, nil
	}

	zero := decimal.Zero()
	periodD := decimal.FromInt64(int64(period))
	hundred := decimal.FromFloat64(100.0)
	one := decimal.FromFloat64(1.0)

	var sumGain, sumLoss decimal.Decimal
	for i := 1; i <= period && i < n; i++ {
		change := candles[i].Close.Sub(candles[i-1].Close)
		if change.Cmp(zero) > 0 {
			sumGain = sumGain.Add(change)
		} else {
			sumLoss = sumLoss.Add(change.Neg())
		}
	}

	avgGain := divSafe(sumGain, periodD)
	avgLoss := divSafe(sumLoss, periodD)

	// First RSI value at index = period.
	if period < n {
		rsi := rsiValue(avgGain, avgLoss, hundred, one)
		result[period] = &rsi
	}

	// Subsequent values — Wilder's smoothing.
	multPrev := divSafe(decimal.FromFloat64(float64(period-1)), periodD)
	for i := period + 1; i < n; i++ {
		change := candles[i].Close.Sub(candles[i-1].Close)
		var gain, loss decimal.Decimal
		if change.Cmp(zero) > 0 {
			gain = change
		} else {
			loss = change.Neg()
		}
		avgGain = avgGain.Mul(multPrev).Add(divSafe(gain, periodD))
		avgLoss = avgLoss.Mul(multPrev).Add(divSafe(loss, periodD))

		rsi := rsiValue(avgGain, avgLoss, hundred, one)
		result[i] = &rsi
	}

	// nil out indices with insufficient history.
	for i := 0; i < period && i < n; i++ {
		result[i] = nil
	}
	return result, nil
}

// rsiValue computes 100 - 100/(1+RS). When avgLoss is zero the RSI is 100.
func rsiValue(avgGain, avgLoss, hundred, one decimal.Decimal) decimal.Decimal {
	if avgLoss.IsZero() {
		return hundred
	}
	rs := divSafe(avgGain, avgLoss)
	denom := one.Add(rs)
	rsi := hundred.Sub(divSafe(hundred, denom))
	return rsi
}
