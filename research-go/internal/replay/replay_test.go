package replay

import (
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

func TestReconstructDecision(t *testing.T) {
	candles := make([]datasets.Candle, 80)
	baseTime := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	for i := 0; i < 80; i++ {
		p := decimal.FromInt64(int64(50000 + i*10))
		candles[i] = datasets.Candle{
			Exchange: "bybit",
			Category: "linear",
			Symbol:   "BTCUSDT",
			Interval: "5",
			OpenTime: baseTime.Add(time.Duration(i*5) * time.Minute),
			Open:     p,
			High:     p.Add(decimal.FromInt64(5)),
			Low:      p.Sub(decimal.FromInt64(5)),
			Close:    p,
			Volume:   decimal.FromInt64(10),
			IsClosed: true,
		}
	}

	decisionTime := baseTime.Add(time.Duration(60*5) * time.Minute)
	snap, err := ReconstructDecision(candles, decisionTime, "trend_pullback")
	if err != nil {
		t.Fatalf("Replay failed: %v", err)
	}

	if snap.Symbol != "BTCUSDT" {
		t.Fatalf("expected symbol BTCUSDT, got %s", snap.Symbol)
	}
	if snap.ClosedPriceAtT.IsZero() {
		t.Fatalf("expected non-zero closed price at T")
	}
	if snap.ReplayFingerprint == "" {
		t.Fatalf("expected non-empty replay fingerprint")
	}
}

func TestReconstructDecisionAmbiguity(t *testing.T) {
	candles := make([]datasets.Candle, 70)
	baseTime := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	for i := 0; i < 70; i++ {
		p := decimal.FromInt64(int64(50000 + i*10))
		candles[i] = datasets.Candle{
			Exchange: "bybit",
			Category: "linear",
			Symbol:   "BTCUSDT",
			Interval: "5",
			OpenTime: baseTime.Add(time.Duration(i*5) * time.Minute),
			Open:     p,
			High:     p.Add(decimal.FromInt64(5)),
			Low:      p.Sub(decimal.FromInt64(5)),
			Close:    p,
			Volume:   decimal.FromInt64(10),
			IsClosed: true,
		}
	}

	// Make candle 61 touch BOTH extreme low and extreme high (ambiguous hit)
	candles[61].Low = decimal.FromInt64(40000)
	candles[61].High = decimal.FromInt64(60000)

	decisionTime := baseTime.Add(time.Duration(60*5) * time.Minute)
	snap, err := ReconstructDecision(candles, decisionTime, "trend_pullback")
	if err != nil {
		t.Fatalf("Replay failed: %v", err)
	}

	if snap.SignalAction == "BUY" {
		if !snap.Ambiguous {
			t.Fatalf("expected ambiguous flag to be true when both TP and SL are touched in the same candle")
		}
		if snap.SimulatedOutcome != "SL_HIT" {
			t.Fatalf("expected conservative resolution SL_HIT for ambiguous execution, got %s", snap.SimulatedOutcome)
		}
	}
}
