package algorithms

import (
	"math/big"
	"math/rand"
	"testing"

	"github.com/traderbybit/research-go/internal/decimal"
)

// referenceRSIRational reproduces the previous exact rational RSI implementation.
//
// It is kept as an independent oracle for the integer implementation: both must produce the same
// decimal for every bar of every fixture, otherwise the optimisation changed the strategy
// semantics.
func referenceRSIRational(closes []decimal.Decimal, period int) []*decimal.Decimal {
	count := len(closes)
	result := make([]*decimal.Decimal, count)
	if count <= period || period <= 0 {
		return result
	}

	const internalScale = 24

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
	avgGain := decimal.RatQuantize(new(big.Rat).Quo(gainSum, periodRat), internalScale)
	avgLoss := decimal.RatQuantize(new(big.Rat).Quo(lossSum, periodRat), internalScale)

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
		avgGain = decimal.RatQuantize(new(big.Rat).Quo(numGain, periodRat), internalScale)

		numLoss := new(big.Rat).Add(new(big.Rat).Mul(avgLoss, periodMinusOne), currentLoss)
		avgLoss = decimal.RatQuantize(new(big.Rat).Quo(numLoss, periodRat), internalScale)

		val := calcRSI(avgGain, avgLoss)
		result[i] = &val
	}

	return result
}

// TestCalculateRSIIntegerParityWithRationalReference proves that the integer implementation and
// the exact rational reference produce bit identical decimals on deterministic random series.
func TestCalculateRSIIntegerParityWithRationalReference(t *testing.T) {
	rng := rand.New(rand.NewSource(20261021))

	for _, period := range []int{2, 7, 14} {
		for _, count := range []int{period + 1, period + 37, 250} {
			closes := make([]decimal.Decimal, count)
			price := 100.0
			for i := 0; i < count; i++ {
				price += (rng.Float64() - 0.5) * 2.5
				if price < 1 {
					price = 1
				}
				closes[i] = decimal.FromFloat64(price)
			}

			got := CalculateRSI(closes, period)
			want := referenceRSIRational(closes, period)

			for i := range want {
				if want[i] == nil {
					if got[i] != nil {
						t.Fatalf("period=%d count=%d index=%d: expected nil, got %s", period, count, i, got[i].String())
					}
					continue
				}
				if got[i] == nil {
					t.Fatalf("period=%d count=%d index=%d: expected %s, got nil", period, count, i, want[i].String())
				}
				if got[i].String() != want[i].String() {
					t.Fatalf("period=%d count=%d index=%d: integer RSI %s != rational RSI %s",
						period, count, i, got[i].String(), want[i].String())
				}
			}
		}
	}
}

// TestCalculateRSIDeterministicFlatSeries locks the degenerate behaviour of a flat price series.
func TestCalculateRSIDeterministicFlatSeries(t *testing.T) {
	count := 60
	closes := make([]decimal.Decimal, count)
	for i := range closes {
		closes[i] = decimal.FromFloat64(42.5)
	}

	rsi := CalculateRSI(closes, 14)
	if rsi[13] != nil {
		t.Fatalf("expected nil before the period is complete, got %s", rsi[13].String())
	}
	for i := 14; i < count; i++ {
		if rsi[i] == nil {
			t.Fatalf("expected RSI at index %d", i)
		}
		if rsi[i].String() != "50.00000000" {
			t.Fatalf("expected RSI 50 on a flat series at index %d, got %s", i, rsi[i].String())
		}
	}
}
