package backtest

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"math/rand"
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

func makeSyntheticCandles(count int) []datasets.Candle {
	candles := make([]datasets.Candle, count)
	baseTime := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	price := 50000.0
	rng := rand.New(rand.NewSource(101))

	for i := 0; i < count; i++ {
		change := (rng.Float64() - 0.49) * 100.0
		open := price
		close := price + change
		high := open
		if close > high {
			high = close
		}
		high += rng.Float64() * 20.0
		low := open
		if close < low {
			low = close
		}
		low -= rng.Float64() * 20.0

		price = close
		candles[i] = datasets.Candle{
			Exchange: "bybit",
			Category: "linear",
			Symbol:   "BTCUSDT",
			Interval: "5",
			OpenTime: baseTime.Add(time.Duration(i*5) * time.Minute),
			Open:     decimal.FromFloat64(open),
			High:     decimal.FromFloat64(high),
			Low:      decimal.FromFloat64(low),
			Close:    decimal.FromFloat64(close),
			Volume:   decimal.FromFloat64(25.0),
			IsClosed: true,
		}
	}
	return candles
}

func TestBacktestEngineDeterministicRun(t *testing.T) {
	candles := makeSyntheticCandles(300)
	engine := NewEngine(DefaultConfig())

	report1, err := engine.Run(candles, "trend_pullback", nil)
	if err != nil {
		t.Fatalf("run 1 failed: %v", err)
	}

	report2, err := engine.Run(candles, "trend_pullback", nil)
	if err != nil {
		t.Fatalf("run 2 failed: %v", err)
	}

	// Determinism check: identical results
	if report1.TotalTrades != report2.TotalTrades {
		t.Fatalf("non-deterministic trades count: %d vs %d", report1.TotalTrades, report2.TotalTrades)
	}
	if report1.NetPnL.String() != report2.NetPnL.String() {
		t.Fatalf("non-deterministic NetPnL: %s vs %s", report1.NetPnL.String(), report2.NetPnL.String())
	}
	if report1.MaxDrawdownPercent != report2.MaxDrawdownPercent {
		t.Fatalf("non-deterministic MaxDrawdownPercent: %f vs %f", report1.MaxDrawdownPercent, report2.MaxDrawdownPercent)
	}
}

func TestBacktestInsufficientWarmup(t *testing.T) {
	candles := makeSyntheticCandles(30)
	engine := NewEngine(DefaultConfig())

	_, err := engine.Run(candles, "trend_pullback", nil)
	if err == nil {
		t.Fatalf("expected error for insufficient warmup candles (<50)")
	}
}

// TestBacktestGoldenParityHash locks the full trade-event output hash. RSI
// rational-growth fix must preserve exact trade events, prices and PnL.
func TestBacktestGoldenParityHash(t *testing.T) {
	candles := makeSyntheticCandles(300)
	engine := NewEngine(DefaultConfig())
	report, err := engine.Run(candles, "trend_pullback", nil)
	if err != nil {
		t.Fatalf("run failed: %v", err)
	}
	if report.NetPnL.String() != "-3224.17089853" {
		t.Fatalf("NetPnL parity mismatch: got %s", report.NetPnL.String())
	}
	if report.TotalTrades != 21 {
		t.Fatalf("trades parity mismatch: got %d", report.TotalTrades)
	}
	h := sha256.New()
	for _, tr := range report.Trades {
		fmt.Fprintf(h, "%d|%s|%s|%s|%s|%s|%s|%s|%s\n", tr.ID, tr.Side, tr.EntryTime.UTC().Format("2006-01-02T15:04:05Z"), tr.ExitTime.UTC().Format("2006-01-02T15:04:05Z"), tr.EntryPrice.String(), tr.ExitPrice.String(), tr.Quantity.String(), tr.NetPnL.String(), tr.ExitReason)
	}
	got := hex.EncodeToString(h.Sum(nil))
	want := "670795e1e0b269515d6ea0e9fdf0af098fb9cee9a12b3a58b54009f72645f83f"
	if got != want {
		t.Fatalf("golden trade-event hash mismatch: got %s want %s", got, want)
	}
}
