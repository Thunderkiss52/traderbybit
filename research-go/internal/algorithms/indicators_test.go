package algorithms

import (
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

func TestCalculateSMA(t *testing.T) {
	vals := []decimal.Decimal{
		decimal.MustFromString("10.00000000"),
		decimal.MustFromString("20.00000000"),
		decimal.MustFromString("30.00000000"),
		decimal.MustFromString("40.00000000"),
		decimal.MustFromString("50.00000000"),
	}

	sma3 := CalculateSMA(vals, 3)
	if sma3[0] != nil || sma3[1] != nil {
		t.Fatalf("expected nil for warmup bars")
	}
	if sma3[2] == nil || sma3[2].String() != "20.00000000" {
		t.Fatalf("expected 20.00000000 at index 2, got %v", sma3[2])
	}
	if sma3[3] == nil || sma3[3].String() != "30.00000000" {
		t.Fatalf("expected 30.00000000 at index 3, got %v", sma3[3])
	}
	if sma3[4] == nil || sma3[4].String() != "40.00000000" {
		t.Fatalf("expected 40.00000000 at index 4, got %v", sma3[4])
	}
}

func TestCalculateEMA(t *testing.T) {
	vals := []decimal.Decimal{
		decimal.MustFromString("10.00000000"),
		decimal.MustFromString("11.00000000"),
		decimal.MustFromString("12.00000000"),
		decimal.MustFromString("13.00000000"),
		decimal.MustFromString("14.00000000"),
	}

	ema3 := CalculateEMA(vals, 3)
	if ema3[0] != nil || ema3[1] != nil {
		t.Fatalf("expected nil for warmup bars")
	}
	// Seed at index 2 is average(10, 11, 12) = 11.0
	if ema3[2] == nil || ema3[2].String() != "11.00000000" {
		t.Fatalf("expected 11.00000000 at index 2, got %v", ema3[2])
	}

	// alpha = 2 / (3 + 1) = 0.5
	// index 3: 11.0 + (13 - 11) * 0.5 = 12.0
	if ema3[3] == nil || ema3[3].String() != "12.00000000" {
		t.Fatalf("expected 12.00000000 at index 3, got %v", ema3[3])
	}
}

func TestCalculateRSI(t *testing.T) {
	closes := make([]decimal.Decimal, 20)
	for i := 0; i < 20; i++ {
		// Monotonically increasing closes
		closes[i] = decimal.FromInt64(int64(100 + i*5))
	}

	rsi := CalculateRSI(closes, 14)
	if rsi[13] != nil {
		t.Fatalf("expected warmup nil before period")
	}
	// Pure uptrend: RSI should be 100
	if rsi[14] == nil || rsi[14].String() != "100.00000000" {
		t.Fatalf("expected RSI 100 on pure uptrend, got %v", rsi[14])
	}
}

func TestCalculateATR(t *testing.T) {
	candles := make([]datasets.Candle, 20)
	baseTime := time.Now()
	for i := 0; i < 20; i++ {
		candles[i] = datasets.Candle{
			OpenTime: baseTime.Add(time.Duration(i*5) * time.Minute),
			Open:     decimal.FromInt64(100),
			High:     decimal.FromInt64(110),
			Low:      decimal.FromInt64(90),
			Close:    decimal.FromInt64(100),
		}
	}

	atr := CalculateATR(candles, 14)
	if atr[12] != nil {
		t.Fatalf("expected nil before period")
	}
	// Range is constant (110 - 90 = 20)
	if atr[13] == nil || atr[13].String() != "20.00000000" {
		t.Fatalf("expected ATR 20, got %v", atr[13])
	}
}

func TestCalculateBollingerBands(t *testing.T) {
	closes := make([]decimal.Decimal, 25)
	for i := 0; i < 25; i++ {
		closes[i] = decimal.FromInt64(100)
	}

	bb := CalculateBollingerBands(closes, 20, 2.0)
	// Zero standard deviation means upper == lower == middle
	if bb.Middle[19] == nil || bb.Middle[19].String() != "100.00000000" {
		t.Fatalf("expected middle band 100, got %v", bb.Middle[19])
	}
	if bb.Upper[19] == nil || bb.Upper[19].String() != "100.00000000" {
		t.Fatalf("expected upper band 100 with 0 stdDev, got %v", bb.Upper[19])
	}
}
