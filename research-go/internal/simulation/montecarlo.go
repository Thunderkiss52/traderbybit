package simulation

import (
	"fmt"
	"math"
	"math/rand"
	"sort"

	"github.com/traderbybit/research-go/internal/backtest"
	"github.com/traderbybit/research-go/internal/decimal"
)

type MonteCarloConfig struct {
	Iterations      int             `json:"iterations"`
	RandomSeed      int64           `json:"random_seed"`
	BlockSize       int             `json:"block_size"` // Block bootstrap size for autocorrelated trades
	StartingBalance decimal.Decimal `json:"starting_balance"`
	RuinThreshold   float64         `json:"ruin_threshold"` // e.g. 20.0% drawdown
}

func DefaultMonteCarloConfig() MonteCarloConfig {
	return MonteCarloConfig{
		Iterations:      500,
		RandomSeed:      12345,
		BlockSize:       5,
		StartingBalance: decimal.MustFromString("10000.00000000"),
		RuinThreshold:   20.0,
	}
}

type PercentileStats struct {
	P5  float64 `json:"p5"`
	P25 float64 `json:"p25"`
	P50 float64 `json:"p50"`
	P75 float64 `json:"p75"`
	P95 float64 `json:"p95"`
	P99 float64 `json:"p99"`
}

type MonteCarloResult struct {
	TotalTrades        int             `json:"total_trades"`
	Iterations         int             `json:"iterations"`
	RandomSeed         int64           `json:"random_seed"`
	StartingBalance    decimal.Decimal `json:"starting_balance"`
	DrawdownPercentiles PercentileStats `json:"drawdown_percentiles"`
	FinalEquityP50     decimal.Decimal `json:"final_equity_p50"`
	FinalEquityP5      decimal.Decimal `json:"final_equity_p5"`
	FinalEquityP95     decimal.Decimal `json:"final_equity_p95"`
	ProbabilityOfRuin  float64         `json:"probability_of_ruin"` // Fraction of paths hitting RuinThreshold
	ExpectancyRange    [2]float64      `json:"expectancy_range_90ci"`
}

// RunMonteCarlo executes deterministic block-bootstrap resampling on historical trades
func RunMonteCarlo(trades []backtest.Trade, cfg MonteCarloConfig) (res *MonteCarloResult, retErr error) {
	defer func() {
		if r := recover(); r != nil {
			retErr = fmt.Errorf("monte carlo simulation panic: %v", r)
		}
	}()

	nTrades := len(trades)
	if nTrades < 10 {
		return nil, fmt.Errorf("insufficient trades for Monte Carlo simulation: got %d, need >= 10", nTrades)
	}

	rng := rand.New(rand.NewSource(cfg.RandomSeed))
	blockSize := cfg.BlockSize
	if blockSize <= 0 || blockSize > nTrades {
		blockSize = 1
	}

	maxDrawdowns := make([]float64, cfg.Iterations)
	finalEquities := make([]float64, cfg.Iterations)
	ruinCount := 0

	tradePnLs := make([]float64, nTrades)
	for i, t := range trades {
		tradePnLs[i] = t.NetPnL.Float64()
	}

	for iter := 0; iter < cfg.Iterations; iter++ {
		currentEquity := cfg.StartingBalance.Float64()
		peakEquity := currentEquity
		maxDD := 0.0
		hitRuin := false

		tradesSampled := 0
		for tradesSampled < nTrades {
			// Pick random block start index
			start := rng.Intn(nTrades)
			for b := 0; b < blockSize && tradesSampled < nTrades; b++ {
				idx := (start + b) % nTrades
				currentEquity += tradePnLs[idx]

				if currentEquity > peakEquity {
					peakEquity = currentEquity
				}
				dd := 0.0
				if peakEquity > 0 {
					dd = ((peakEquity - currentEquity) / peakEquity) * 100.0
				}
				if dd > maxDD {
					maxDD = dd
				}
				if dd >= cfg.RuinThreshold {
					hitRuin = true
				}
				tradesSampled++
			}
		}

		maxDrawdowns[iter] = maxDD
		finalEquities[iter] = currentEquity
		if hitRuin {
			ruinCount++
		}
	}

	sort.Float64s(maxDrawdowns)
	sort.Float64s(finalEquities)

	getPercentile := func(arr []float64, p float64) float64 {
		idx := int(float64(len(arr)-1) * p)
		return math.Round(arr[idx]*100) / 100
	}

	ddStats := PercentileStats{
		P5:  getPercentile(maxDrawdowns, 0.05),
		P25: getPercentile(maxDrawdowns, 0.25),
		P50: getPercentile(maxDrawdowns, 0.50),
		P75: getPercentile(maxDrawdowns, 0.75),
		P95: getPercentile(maxDrawdowns, 0.95),
		P99: getPercentile(maxDrawdowns, 0.99),
	}

	probRuin := math.Round((float64(ruinCount)/float64(cfg.Iterations))*1000) / 1000

	return &MonteCarloResult{
		TotalTrades:        nTrades,
		Iterations:         cfg.Iterations,
		RandomSeed:         cfg.RandomSeed,
		StartingBalance:    cfg.StartingBalance,
		DrawdownPercentiles: ddStats,
		FinalEquityP5:      decimal.FromFloat64(finalEquities[int(float64(len(finalEquities)-1)*0.05)]),
		FinalEquityP50:     decimal.FromFloat64(finalEquities[int(float64(len(finalEquities)-1)*0.50)]),
		FinalEquityP95:     decimal.FromFloat64(finalEquities[int(float64(len(finalEquities)-1)*0.95)]),
		ProbabilityOfRuin:  probRuin,
		ExpectancyRange: [2]float64{
			getPercentile(finalEquities, 0.05),
			getPercentile(finalEquities, 0.95),
		},
	}, nil
}
