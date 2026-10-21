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

	const internalScale = 24

	// The averages are kept as exact integers in the internal scale, so every step is an integer
	// operation. The previous implementation used exact rationals, whose normalisation ran a GCD
	// per bar and dominated the CPU profile of the research engine.
	//
	// scaleBridge converts a scale-8 price difference into the scale-24 average representation.
	scaleBridge := new(big.Int).Exp(big.NewInt(10), big.NewInt(int64(internalScale-decimal.Scale)), nil)
	scaleHundred := new(big.Int).Exp(big.NewInt(10), big.NewInt(int64(decimal.Scale)), nil)

	// Seed with simple average over the first `period` movements (Wilder's smoothing),
	// matching the PHP Decimal internal scale (24) and HalfEven rounding.
	gainSum := new(big.Int)
	lossSum := new(big.Int)

	for i := 1; i <= period; i++ {
		diff := closes[i].Sub(closes[i-1])
		if diff.IsPositive() {
			gainSum.Add(gainSum, new(big.Int).Mul(diff.ScaledInt(), scaleBridge))
		} else if diff.IsNegative() {
			lossSum.Add(lossSum, new(big.Int).Mul(diff.Neg().ScaledInt(), scaleBridge))
		}
	}

	periodBig := big.NewInt(int64(period))
	avgGain := roundHalfEvenDiv(gainSum, periodBig)
	avgLoss := roundHalfEvenDiv(lossSum, periodBig)

	calcRSI := func(ag, al *big.Int) decimal.Decimal {
		if ag.Sign() == 0 && al.Sign() == 0 {
			return decimal.FromInt64(50)
		}
		if al.Sign() == 0 {
			return decimal.FromInt64(100)
		}
		// RSI = 100 * ag / (ag + al). The rational is exact, so only the final rounding to the
		// decimal scale is left, with the same half away from zero rule as the rational path.
		den := new(big.Int).Add(ag, al)
		num := new(big.Int).Mul(ag, big.NewInt(100))
		num.Mul(num, scaleHundred)
		return decimal.FromScaledInt(roundHalfAwayDiv(num, den))
	}

	firstRsi := calcRSI(avgGain, avgLoss)
	result[period] = &firstRsi

	periodMinusOne := big.NewInt(int64(period - 1))

	for i := period + 1; i < count; i++ {
		diff := closes[i].Sub(closes[i-1])
		currentGain := new(big.Int)
		currentLoss := new(big.Int)
		if diff.IsPositive() {
			currentGain.Mul(diff.ScaledInt(), scaleBridge)
		} else if diff.IsNegative() {
			currentLoss.Mul(diff.Neg().ScaledInt(), scaleBridge)
		}

		numGain := new(big.Int).Mul(avgGain, periodMinusOne)
		numGain.Add(numGain, currentGain)
		avgGain = roundHalfEvenDiv(numGain, periodBig)

		numLoss := new(big.Int).Mul(avgLoss, periodMinusOne)
		numLoss.Add(numLoss, currentLoss)
		avgLoss = roundHalfEvenDiv(numLoss, periodBig)

		val := calcRSI(avgGain, avgLoss)
		result[i] = &val
	}

	return result
}

// roundHalfEvenDiv divides x by the positive divisor d and rounds the result to the nearest
// integer with halves rounded to even, mirroring the rational quantisation of the PHP decimal
// implementation.
func roundHalfEvenDiv(x *big.Int, d *big.Int) *big.Int {
	q := new(big.Int)
	rem := new(big.Int)
	q.QuoRem(x, d, rem)
	if rem.Sign() == 0 {
		return q
	}

	twiceRem := new(big.Int).Abs(rem)
	twiceRem.Lsh(twiceRem, 1)
	cmp := twiceRem.Cmp(d)
	roundUp := false
	if cmp > 0 {
		roundUp = true
	} else if cmp == 0 && q.Bit(0) == 1 {
		roundUp = true
	}
	if roundUp {
		if x.Sign() < 0 {
			q.Sub(q, big.NewInt(1))
		} else {
			q.Add(q, big.NewInt(1))
		}
	}
	return q
}

// roundHalfAwayDiv divides the positive numerator by the positive denominator and rounds the
// result to the nearest integer with halves rounded away from zero, mirroring the rounding of the
// rational to string conversion of the big integer package.
func roundHalfAwayDiv(num *big.Int, den *big.Int) *big.Int {
	q := new(big.Int)
	rem := new(big.Int)
	q.QuoRem(num, den, rem)
	if rem.Sign() == 0 {
		return q
	}
	twiceRem := new(big.Int).Lsh(rem, 1)
	if twiceRem.Cmp(den) >= 0 {
		q.Add(q, big.NewInt(1))
	}
	return q
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
