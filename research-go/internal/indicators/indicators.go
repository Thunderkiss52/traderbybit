package indicators

import (
    "fmt"
    "math"
    "time"

    "github.com/traderbybit/research-go/internal/datasets"
    "github.com/traderbybit/research-go/internal/decimal"
)

// EMA computes the exponential moving average for a slice of closed candles.
// period must be >= 1. The result slice has the same length; for the first (period-1)
// entries the EMA is set to nil because there is insufficient history.
func EMA(candles []datasets.Candle, period int) ([]*decimal.Decimal, error) {
    if period <= 0 {
        return nil, fmt.Errorf("period must be positive")
    }
    n := len(candles)
    result := make([]*decimal.Decimal, n)
    if n == 0 {
        return result, nil
    }
    // multiplier = 2/(period+1)
    mult := decimal.FromFloat64(2.0 / float64(period+1))
    var prev *decimal.Decimal
    for i, c := range candles {
        closeVal := c.Close
        if i == 0 {
            // seed EMA with first close value.
            prev = &closeVal
            result[i] = prev
            continue
        }
        // EMA = (Close - PrevEMA) * multiplier + PrevEMA
        diff := closeVal.Sub(*prev)
        ema := diff.Mul(mult).Add(*prev)
        // store a copy to avoid referencing loop variable
        e := ema
        result[i] = &e
        prev = &e
    }
    // Zero out first (period-1) entries as insufficient history.
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
    var sum decimal.Decimal
    for i := 0; i < n; i++ {
        sum = sum.Add(candles[i].Close)
        if i >= period-1 {
            avg := sum.Div(decimal.FromInt64(int64(period)))
            result[i] = &avg
            // subtract the value that slides out of the window.
            sum = sum.Sub(candles[i-period+1].Close)
        } else {
            result[i] = nil
        }
    }
    return result, nil
}

// RSI computes the Relative Strength Index using the standard 14‑period default.
func RSI(candles []datasets.Candle, period int) ([]*decimal.Decimal, error) {
    if period <= 0 {
        return nil, fmt.Errorf("period must be positive")
    }
    n := len(candles)
    result := make([]*decimal.Decimal, n)
    if n == 0 {
        return result, nil
    }
    var avgGain, avgLoss decimal.Decimal
    // first average gain/loss over period
    for i := 1; i <= period && i < n; i++ {
        change := candles[i].Close.Sub(candles[i-1].Close)
        if change.Cmp(decimal.Zero) > 0 {
            avgGain = avgGain.Add(change)
        } else {
            avgLoss = avgLoss.Add(change.Neg())
        }
    }
    if period > 0 {
        avgGain = avgGain.Div(decimal.FromInt64(int64(period)))
        avgLoss = avgLoss.Div(decimal.FromInt64(int64(period)))
    }
    // compute first RSI value at index period
    if period < n && avgLoss.IsPositive() {
        rs := avgGain.Div(avgLoss)
        rsi := decimal.FromFloat64(100.0).Sub(decimal.FromFloat64(100.0).Div(decimal.FromFloat64(1.0).Add(rs)))
        result[period] = &rsi
    }
    // subsequent values use Wilder's smoothing
    for i := period + 1; i < n; i++ {
        change := candles[i].Close.Sub(candles[i-1].Close)
        var gain, loss decimal.Decimal
        if change.Cmp(decimal.Zero) > 0 {
            gain = change
        } else {
            loss = change.Neg()
        }
        // smoothing factors
        multPrev := decimal.FromFloat64(float64(period-1) / float64(period))
        avgGain = avgGain.Mul(multPrev).Add(gain.Div(decimal.FromInt64(int64(period))))
        avgLoss = avgLoss.Mul(multPrev).Add(loss.Div(decimal.FromInt64(int64(period))))
        if avgLoss.IsPositive() {
            rs := avgGain.Div(avgLoss)
            rsi := decimal.FromFloat64(100.0).Sub(decimal.FromFloat64(100.0).Div(decimal.FromFloat64(1.0).Add(rs)))
            result[i] = &rsi
        } else {
            // avoid division by zero; RSI set to 100
            rsi := decimal.FromFloat64(100.0)
            result[i] = &rsi
        }
    }
    // prior indices have insufficient data
    for i := 0; i < period && i < n; i++ {
        result[i] = nil
    }
    return result, nil
}

// Additional indicators (MACD, ATR, Bollinger Bands, ADX, VWAP) would follow the same pattern.
// For brevity they are omitted here but can be added later with the same precision guarantees.
