package golden

import (
	"testing"

	"github.com/traderbybit/research-go/internal/algorithms"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

// TestGoldenIndicatorParity verifies exact numerical agreement with PHP Indicators
func TestGoldenIndicatorParity(t *testing.T) {
	// Golden SMA Fixture matching PHP EmaCalculatorTest and SmaCalculatorTest
	smaInputs := []decimal.Decimal{
		decimal.MustFromString("10.00000000"),
		decimal.MustFromString("20.00000000"),
		decimal.MustFromString("30.00000000"),
		decimal.MustFromString("40.00000000"),
		decimal.MustFromString("50.00000000"),
	}

	expectedSMA := []string{
		"", "", "20.00000000", "30.00000000", "40.00000000",
	}

	smaRes := algorithms.CalculateSMA(smaInputs, 3)
	for i, exp := range expectedSMA {
		if exp == "" {
			if smaRes[i] != nil {
				t.Fatalf("index %d: expected nil, got %v", i, smaRes[i])
			}
		} else {
			if smaRes[i] == nil || smaRes[i].String() != exp {
				t.Fatalf("index %d: expected %s, got %v", i, exp, smaRes[i])
			}
		}
	}

	// Golden EMA Fixture
	emaInputs := []decimal.Decimal{
		decimal.MustFromString("10.00000000"),
		decimal.MustFromString("11.00000000"),
		decimal.MustFromString("12.00000000"),
		decimal.MustFromString("13.00000000"),
		decimal.MustFromString("14.00000000"),
	}

	expectedEMA := []string{
		"", "", "11.00000000", "12.00000000", "13.00000000",
	}

	emaRes := algorithms.CalculateEMA(emaInputs, 3)
	for i, exp := range expectedEMA {
		if exp == "" {
			if emaRes[i] != nil {
				t.Fatalf("index %d: expected nil, got %v", i, emaRes[i])
			}
		} else {
			if emaRes[i] == nil || emaRes[i].String() != exp {
				t.Fatalf("index %d: expected %s, got %v", i, exp, emaRes[i])
			}
		}
	}

	// Golden Wilder's RSI Fixture matching PHP MovingMomentumIndicatorsTest
	rsiInputs := []decimal.Decimal{
		decimal.MustFromString("44.34"), decimal.MustFromString("44.09"), decimal.MustFromString("44.15"),
		decimal.MustFromString("43.61"), decimal.MustFromString("44.33"), decimal.MustFromString("44.83"),
		decimal.MustFromString("45.10"), decimal.MustFromString("45.42"), decimal.MustFromString("45.84"),
		decimal.MustFromString("46.08"), decimal.MustFromString("45.89"), decimal.MustFromString("46.03"),
		decimal.MustFromString("45.61"), decimal.MustFromString("46.28"), decimal.MustFromString("46.28"),
	}
	rsiRes := algorithms.CalculateRSI(rsiInputs, 14)
	if rsiRes[14] == nil || rsiRes[14].String() != "70.46413502" {
		t.Fatalf("RSI parity mismatch: expected 70.46413502, got %v", rsiRes[14])
	}

	// Golden ATR Fixture matching PHP VolatilityTrendIndicatorsTest
	type candleSpec struct {
		h, l, c string
	}
	atrSpecs := []candleSpec{
		{"10", "8", "9"},
		{"11", "9", "10"},
		{"13", "10", "12"},
		{"14", "12", "13"},
		{"15", "11", "12"},
	}
	atrCandles := make([]datasets.Candle, len(atrSpecs))
	for i, s := range atrSpecs {
		atrCandles[i] = datasets.Candle{
			High:  decimal.MustFromString(s.h),
			Low:   decimal.MustFromString(s.l),
			Close: decimal.MustFromString(s.c),
		}
	}
	expectedATR := []string{"", "", "2.33333333", "2.22222222", "2.81481481"}
	atrRes := algorithms.CalculateATR(atrCandles, 3)
	for i, exp := range expectedATR {
		if exp == "" {
			if atrRes[i] != nil {
				t.Fatalf("ATR index %d: expected nil, got %v", i, atrRes[i])
			}
		} else {
			if atrRes[i] == nil || atrRes[i].String() != exp {
				t.Fatalf("ATR index %d: expected %s, got %v", i, exp, atrRes[i])
			}
		}
	}

	// Golden Bollinger Bands Fixture matching PHP VolatilityTrendIndicatorsTest
	bbInputs := []decimal.Decimal{
		decimal.MustFromString("1"),
		decimal.MustFromString("2"),
		decimal.MustFromString("3"),
	}
	bbRes := algorithms.CalculateBollingerBands(bbInputs, 3, 2.0)
	if bbRes.Middle[2] == nil || bbRes.Middle[2].String() != "2.00000000" {
		t.Fatalf("BB Middle parity mismatch: expected 2.00000000, got %v", bbRes.Middle[2])
	}
	if bbRes.Upper[2] == nil || bbRes.Upper[2].String() != "3.63299316" {
		t.Fatalf("BB Upper parity mismatch: expected 3.63299316, got %v", bbRes.Upper[2])
	}
	if bbRes.Lower[2] == nil || bbRes.Lower[2].String() != "0.36700684" {
		t.Fatalf("BB Lower parity mismatch: expected 0.36700684, got %v", bbRes.Lower[2])
	}
}
