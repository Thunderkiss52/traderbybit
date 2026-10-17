package algorithms

import (
	"math"
	"math/big"

	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

// CalculateSMA computes Simple Moving Average
func CalculateSMA(values []decimal.Decimal, period int) []*decimal.Decimal {
	count := len(values)
	result := make([]*decimal.Decimal, count)
	if count < period || period <= 0 {
		return result
	}

	sum := decimal.Zero()
	for i := 0; i < period; i++ {
		sum = sum.Add(values[i])
	}
	avg, _ := sum.Div(decimal.FromInt64(int64(period)))
	firstAvg := avg
	result[period-1] = &firstAvg

	for i := period; i < count; i++ {
		sum = sum.Add(values[i]).Sub(values[i-period])
		avg, _ = sum.Div(decimal.FromInt64(int64(period)))
		val := avg
		result[i] = &val
	}

	return result
}

// CalculateEMA computes Exponential Moving Average matching PHP implementation
func CalculateEMA(values []decimal.Decimal, period int) []*decimal.Decimal {
	count := len(values)
	result := make([]*decimal.Decimal, count)
	if count < period || period <= 0 {
		return result
	}

	// Seed with SMA of first period elements
	sum := decimal.Zero()
	for i := 0; i < period; i++ {
		sum = sum.Add(values[i])
	}
	ema, _ := sum.Div(decimal.FromInt64(int64(period)))
	seed := ema
	result[period-1] = &seed

	alpha, _ := decimal.FromInt64(2).Div(decimal.FromInt64(int64(period + 1)))

	for i := period; i < count; i++ {
		diff := values[i].Sub(ema)
		step := diff.Mul(alpha)
		ema = ema.Add(step)
		val := ema
		result[i] = &val
	}

	return result
}

// CalculateRSI computes Wilder's RSI matching PHP implementation
func CalculateRSI(closes []decimal.Decimal, period int) []*decimal.Decimal {
	count := len(closes)
	result := make([]*decimal.Decimal, count)
	if count <= period || period <= 0 {
		return result
	}

	gainSum := new(big.Rat)
	lossSum := new(big.Rat)

	for i := 1; i <= period; i++ {
		diff := closes[i].Sub(closes[i-1])
		if diff.IsPositive() {
			gainSum.Add(gainSum, diff.ToRat())
		} else if diff.IsNegative() {
			lossSum.Add(lossSum, diff.Neg().ToRat())
		}
	}

	periodRat := new(big.Rat).SetInt64(int64(period))
	avgGain := new(big.Rat).Quo(gainSum, periodRat)
	avgLoss := new(big.Rat).Quo(lossSum, periodRat)

	calcRSI := func(ag, al *big.Rat) decimal.Decimal {
		if ag.Sign() == 0 && al.Sign() == 0 {
			return decimal.FromInt64(50)
		}
		if al.Sign() == 0 {
			return decimal.FromInt64(100)
		}
		rs := new(big.Rat).Quo(ag, al)
		onePlusRs := new(big.Rat).Add(new(big.Rat).SetInt64(1), rs)
		hundredDiv := new(big.Rat).Quo(new(big.Rat).SetInt64(100), onePlusRs)
		rsiVal := new(big.Rat).Sub(new(big.Rat).SetInt64(100), hundredDiv)
		return decimal.FromRat(rsiVal)
	}

	firstRsi := calcRSI(avgGain, avgLoss)
	result[period] = &firstRsi

	periodMinusOne := new(big.Rat).SetInt64(int64(period - 1))

	for i := period + 1; i < count; i++ {
		diff := closes[i].Sub(closes[i-1])
		currentGain := new(big.Rat)
		currentLoss := new(big.Rat)
		if diff.IsPositive() {
			currentGain = diff.ToRat()
		} else if diff.IsNegative() {
			currentLoss = diff.Neg().ToRat()
		}

		numGain := new(big.Rat).Add(new(big.Rat).Mul(avgGain, periodMinusOne), currentGain)
		avgGain = new(big.Rat).Quo(numGain, periodRat)

		numLoss := new(big.Rat).Add(new(big.Rat).Mul(avgLoss, periodMinusOne), currentLoss)
		avgLoss = new(big.Rat).Quo(numLoss, periodRat)

		val := calcRSI(avgGain, avgLoss)
		result[i] = &val
	}

	return result
}

// CalculateATR computes Average True Range matching PHP implementation
func CalculateATR(candles []datasets.Candle, period int) []*decimal.Decimal {
	count := len(candles)
	result := make([]*decimal.Decimal, count)
	if count < period || period <= 0 {
		return result
	}

	trueRanges := make([]decimal.Decimal, count)
	for i, c := range candles {
		highLow := c.High.Sub(c.Low)
		if i == 0 {
			trueRanges[i] = highLow
			continue
		}
		prevClose := candles[i-1].Close
		highPrev := c.High.Sub(prevClose)
		if highPrev.IsNegative() {
			highPrev = highPrev.Neg()
		}
		lowPrev := c.Low.Sub(prevClose)
		if lowPrev.IsNegative() {
			lowPrev = lowPrev.Neg()
		}

		tr := highLow
		if highPrev.Cmp(tr) > 0 {
			tr = highPrev
		}
		if lowPrev.Cmp(tr) > 0 {
			tr = lowPrev
		}
		trueRanges[i] = tr
	}

	trSum := decimal.Zero()
	for i := 0; i < period; i++ {
		trSum = trSum.Add(trueRanges[i])
	}
	atr, _ := trSum.Div(decimal.FromInt64(int64(period)))
	firstAtr := atr
	result[period-1] = &firstAtr

	periodDec := decimal.FromInt64(int64(period))
	periodMinusOne := decimal.FromInt64(int64(period - 1))

	for i := period; i < count; i++ {
		atr, _ = atr.Mul(periodMinusOne).Add(trueRanges[i]).Div(periodDec)
		val := atr
		result[i] = &val
	}

	return result
}

// BollingerBandsResult contains upper, middle, and lower bands
type BollingerBandsResult struct {
	Middle []*decimal.Decimal
	Upper  []*decimal.Decimal
	Lower  []*decimal.Decimal
}

// CalculateBollingerBands computes BB using SMA and population/sample standard deviation
func CalculateBollingerBands(closes []decimal.Decimal, period int, multiplier float64) BollingerBandsResult {
	count := len(closes)
	res := BollingerBandsResult{
		Middle: CalculateSMA(closes, period),
		Upper:  make([]*decimal.Decimal, count),
		Lower:  make([]*decimal.Decimal, count),
	}

	if count < period || period <= 0 {
		return res
	}

	multDec := decimal.FromFloat64(multiplier)

	for i := period - 1; i < count; i++ {
		mid := res.Middle[i]
		if mid == nil {
			continue
		}

		var sumSquaredDiff float64
		midFloat := mid.Float64()
		for j := i - period + 1; j <= i; j++ {
			diff := closes[j].Float64() - midFloat
			sumSquaredDiff += diff * diff
		}
		stdDev := math.Sqrt(sumSquaredDiff / float64(period))
		bandWidth := multDec.Mul(decimal.FromFloat64(stdDev))

		upper := mid.Add(bandWidth)
		lower := mid.Sub(bandWidth)

		res.Upper[i] = &upper
		res.Lower[i] = &lower
	}

	return res
}

// DonchianResult contains highest high and lowest low
type DonchianResult struct {
	Upper []*decimal.Decimal
	Lower []*decimal.Decimal
	Mid   []*decimal.Decimal
}

// CalculateDonchianChannels computes Donchian breakout channels
func CalculateDonchianChannels(candles []datasets.Candle, period int) DonchianResult {
	count := len(candles)
	res := DonchianResult{
		Upper: make([]*decimal.Decimal, count),
		Lower: make([]*decimal.Decimal, count),
		Mid:   make([]*decimal.Decimal, count),
	}
	if count < period || period <= 0 {
		return res
	}

	for i := period - 1; i < count; i++ {
		highest := candles[i-period+1].High
		lowest := candles[i-period+1].Low

		for j := i - period + 2; j <= i; j++ {
			if candles[j].High.Cmp(highest) > 0 {
				highest = candles[j].High
			}
			if candles[j].Low.Cmp(lowest) < 0 {
				lowest = candles[j].Low
			}
		}

		mid, _ := highest.Add(lowest).Div(decimal.FromInt64(2))
		h := highest
		l := lowest
		m := mid
		res.Upper[i] = &h
		res.Lower[i] = &l
		res.Mid[i] = &m
	}

	return res
}
