package simulation

import (
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/backtest"
	"github.com/traderbybit/research-go/internal/decimal"
)

func TestMonteCarloSimulation(t *testing.T) {
	trades := make([]backtest.Trade, 50)
	now := time.Now()
	for i := 0; i < 50; i++ {
		pnlStr := "150.00000000"
		if i%2 == 1 {
			pnlStr = "-100.00000000"
		}
		trades[i] = backtest.Trade{
			ID:        i + 1,
			Symbol:    "BTCUSDT",
			EntryTime: now.Add(time.Duration(i) * time.Hour),
			NetPnL:    decimal.MustFromString(pnlStr),
		}
	}

	cfg := DefaultMonteCarloConfig()
	cfg.Iterations = 200
	cfg.RandomSeed = 42

	res, err := RunMonteCarlo(trades, cfg)
	if err != nil {
		t.Fatalf("Monte Carlo run failed: %v", err)
	}

	if res.Iterations != 200 {
		t.Fatalf("expected 200 iterations, got %d", res.Iterations)
	}
	if res.TotalTrades != 50 {
		t.Fatalf("expected 50 trades, got %d", res.TotalTrades)
	}
	if res.DrawdownPercentiles.P50 <= 0 {
		t.Fatalf("expected positive median drawdown, got %f", res.DrawdownPercentiles.P50)
	}
}

func TestMonteCarloInsufficientTrades(t *testing.T) {
	trades := make([]backtest.Trade, 5) // < 10 trades
	_, err := RunMonteCarlo(trades, DefaultMonteCarloConfig())
	if err == nil {
		t.Fatalf("expected error for insufficient trades (<10)")
	}
}
