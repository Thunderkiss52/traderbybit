package walkforward

import (
	"math/rand"
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/backtest"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

func makeTestCandles(count int) []datasets.Candle {
	candles := make([]datasets.Candle, count)
	baseTime := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	price := 50000.0
	rng := rand.New(rand.NewSource(42))

	for i := 0; i < count; i++ {
		change := (rng.Float64() - 0.495) * 100.0
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
			Volume:   decimal.FromFloat64(20.0),
			IsClosed: true,
		}
	}
	return candles
}

func TestWalkForwardRun(t *testing.T) {
	candles := makeTestCandles(900)
	cfg := DefaultWFOConfig()
	cfg.Folds = 3
	btCfg := backtest.DefaultConfig()

	res, err := Run(candles, cfg, btCfg)
	if err != nil {
		t.Fatalf("WFO run failed: %v", err)
	}

	if res.FoldsCount != 3 {
		t.Fatalf("expected 3 folds, got %d", res.FoldsCount)
	}
	if len(res.Folds) != 3 {
		t.Fatalf("expected 3 fold reports, got %d", len(res.Folds))
	}
}

func TestWalkForwardInsufficientData(t *testing.T) {
	candles := makeTestCandles(100) // Less than folds * 100
	cfg := DefaultWFOConfig()
	cfg.Folds = 3

	_, err := Run(candles, cfg, backtest.DefaultConfig())
	if err == nil {
		t.Fatalf("expected error for insufficient data in WFO")
	}
}
