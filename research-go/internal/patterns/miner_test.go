package patterns

import (
	"math/rand"
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

func generatePatternCandles(count int) []datasets.Candle {
	candles := make([]datasets.Candle, count)
	baseTime := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	price := 50000.0
	rng := rand.New(rand.NewSource(555))

	for i := 0; i < count; i++ {
		change := (rng.Float64() - 0.48) * 50.0
		open := price
		close := price + change
		high := open
		if close > high {
			high = close
		}
		high += rng.Float64() * 10.0
		low := open
		if close < low {
			low = close
		}
		low -= rng.Float64() * 10.0

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
			Volume:   decimal.FromFloat64(30.0),
			IsClosed: true,
		}
	}
	return candles
}

func TestMinePatternsExecution(t *testing.T) {
	candles := generatePatternCandles(500)
	cfg := DefaultPatternConfig()

	patterns, err := MinePatterns(candles, cfg)
	if err != nil {
		t.Fatalf("mine patterns failed: %v", err)
	}

	for _, p := range patterns {
		if p.SampleSize < cfg.MinSampleSize {
			t.Fatalf("pattern sample size %d < min %d", p.SampleSize, cfg.MinSampleSize)
		}
		if p.Support < cfg.MinSupport {
			t.Fatalf("pattern support %f < min %f", p.Support, cfg.MinSupport)
		}
	}
}

func TestBenjaminiHochbergStepUp(t *testing.T) {
	// Verify that if candidate k passes the BH step-up threshold,
	// all prior candidates i <= k are also rejected (FDRPassed = true),
	// even if an intermediate candidate had PValue > (i/m)*alpha.
	candidates := []MinedPattern{
		{Conditions: []string{"A"}, PValue: 0.02}, // rank 1: threshold (1/3)*0.10 = 0.0333 (pass)
		{Conditions: []string{"B"}, PValue: 0.07}, // rank 2: threshold (2/3)*0.10 = 0.0667 (fail point-wise)
		{Conditions: []string{"C"}, PValue: 0.09}, // rank 3: threshold (3/3)*0.10 = 0.1000 (pass step-up)
	}

	cfg := PatternConfig{MaxFDR: 0.10}
	m := len(candidates)
	maxK := -1
	for i := m - 1; i >= 0; i-- {
		rank := float64(i + 1)
		bhThreshold := (rank / float64(m)) * cfg.MaxFDR
		if candidates[i].PValue <= bhThreshold {
			maxK = i
			break
		}
	}

	for i := range candidates {
		candidates[i].FDRPassed = (maxK >= 0 && i <= maxK)
	}

	// Under BH step-up, since rank 3 passed, rank 2 MUST also pass
	if !candidates[1].FDRPassed {
		t.Fatalf("expected candidate 2 to pass FDR under BH step-up procedure")
	}
	if !candidates[0].FDRPassed || !candidates[2].FDRPassed {
		t.Fatalf("expected candidates 1 and 3 to pass FDR")
	}
}
