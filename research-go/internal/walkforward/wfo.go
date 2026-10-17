package walkforward

import (
	"fmt"
	"math"

	"github.com/traderbybit/research-go/internal/backtest"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

type WFOConfig struct {
	Folds      int     `json:"folds"`
	TrainRatio float64 `json:"train_ratio"` // e.g. 0.70 train, 0.30 test per fold
	PurgeBars  int     `json:"purge_bars"`  // Embargo between train and test
	StrategyID string  `json:"strategy_id"`
}

func DefaultWFOConfig() WFOConfig {
	return WFOConfig{
		Folds:      3,
		TrainRatio: 0.70,
		PurgeBars:  20,
		StrategyID: "trend_pullback",
	}
}

type FoldReport struct {
	FoldIndex       int             `json:"fold_index"`
	TrainCandles    int             `json:"train_candles"`
	TestCandles     int             `json:"test_candles"`
	TrainNetPnL     decimal.Decimal `json:"train_net_pnl"`
	TestNetPnL      decimal.Decimal `json:"test_net_pnl"`
	TrainWinRate    float64         `json:"train_win_rate"`
	TestWinRate     float64         `json:"test_win_rate"`
	TrainTrades     int             `json:"train_trades"`
	TestTrades      int             `json:"test_trades"`
	FoldEfficiency  float64         `json:"fold_efficiency"`
}

type WFOResult struct {
	StrategyID            string          `json:"strategy_id"`
	FoldsCount            int             `json:"folds_count"`
	TotalInSampleTrades   int             `json:"total_in_sample_trades"`
	TotalOutSampleTrades  int             `json:"total_out_sample_trades"`
	InSampleNetPnL        decimal.Decimal `json:"in_sample_net_pnl"`
	OutSampleNetPnL       decimal.Decimal `json:"out_sample_net_pnl"`
	InSampleWinRate       float64         `json:"in_sample_win_rate"`
	OutSampleWinRate      float64         `json:"out_sample_win_rate"`
	WalkForwardEfficiency float64         `json:"walk_forward_efficiency"`
	DegradationPercent    float64         `json:"degradation_percent"`
	SufficientData        bool            `json:"sufficient_data"`
	OverfitDetected       bool            `json:"overfit_detected"`
	Folds                 []FoldReport    `json:"folds"`
}

// Run executes chronological Walk-Forward Optimization with strict embargo purging
func Run(candles []datasets.Candle, cfg WFOConfig, btCfg backtest.BacktestConfig) (res *WFOResult, retErr error) {
	defer func() {
		if r := recover(); r != nil {
			retErr = fmt.Errorf("walk forward optimization panic: %v", r)
		}
	}()

	totalCandles := len(candles)
	minCandlesPerFold := 200
	if totalCandles < cfg.Folds*minCandlesPerFold {
		return nil, fmt.Errorf("insufficient candles for %d folds: need at least %d, got %d",
			cfg.Folds, cfg.Folds*minCandlesPerFold, totalCandles)
	}

	foldSize := totalCandles / cfg.Folds
	engine := backtest.NewEngine(btCfg)

	foldReports := make([]FoldReport, 0, cfg.Folds)
	totalInSampleNet := decimal.Zero()
	totalOutSampleNet := decimal.Zero()
	totalInSampleTrades := 0
	totalOutSampleTrades := 0
	totalInSampleWins := 0
	totalOutSampleWins := 0

	for f := 0; f < cfg.Folds; f++ {
		startIdx := f * foldSize
		endIdx := (f + 1) * foldSize
		if f == cfg.Folds-1 {
			endIdx = totalCandles
		}

		foldCandles := candles[startIdx:endIdx]
		nFold := len(foldCandles)
		trainLen := int(float64(nFold) * cfg.TrainRatio)
		testStart := trainLen + cfg.PurgeBars

		if testStart >= nFold {
			testStart = trainLen
		}

		trainCandles := foldCandles[:trainLen]
		warmupBars := 50
		warmupStart := testStart - warmupBars
		if warmupStart < 0 {
			warmupStart = 0
		}
		testSlice := foldCandles[warmupStart:]

		trainReport, err := engine.Run(trainCandles, cfg.StrategyID, nil)
		if err != nil {
			continue
		}

		rawTestReport, err := engine.Run(testSlice, cfg.StrategyID, nil)
		if err != nil {
			continue
		}

		// Filter test trades to only those entered on or after testStart
		testCutoffTime := foldCandles[testStart].OpenTime
		var testTrades []backtest.Trade
		testWins := 0
		testNetPnL := decimal.Zero()
		for _, tr := range rawTestReport.Trades {
			if tr.EntryTime.Equal(testCutoffTime) || tr.EntryTime.After(testCutoffTime) {
				testTrades = append(testTrades, tr)
				testNetPnL = testNetPnL.Add(tr.NetPnL)
				if tr.NetPnL.IsPositive() {
					testWins++
				}
			}
		}

		testWinRate := 0.0
		if len(testTrades) > 0 {
			testWinRate = float64(testWins) / float64(len(testTrades))
		}

		foldEff := 0.0
		if trainReport.NetPnL.IsPositive() {
			foldEff = testNetPnL.Float64() / trainReport.NetPnL.Float64()
		}

		foldReports = append(foldReports, FoldReport{
			FoldIndex:      f + 1,
			TrainCandles:   len(trainCandles),
			TestCandles:    nFold - testStart,
			TrainNetPnL:    trainReport.NetPnL,
			TestNetPnL:     testNetPnL,
			TrainWinRate:   trainReport.WinRate,
			TestWinRate:    math.Round(testWinRate*10000) / 10000,
			TrainTrades:    trainReport.TotalTrades,
			TestTrades:     len(testTrades),
			FoldEfficiency: math.Round(foldEff*1000) / 1000,
		})

		totalInSampleNet = totalInSampleNet.Add(trainReport.NetPnL)
		totalOutSampleNet = totalOutSampleNet.Add(testNetPnL)
		totalInSampleTrades += trainReport.TotalTrades
		totalOutSampleTrades += len(testTrades)
		totalInSampleWins += trainReport.Wins
		totalOutSampleWins += testWins
	}

	if len(foldReports) == 0 {
		return nil, fmt.Errorf("all %d walk forward folds failed: insufficient trades or warmup bars", cfg.Folds)
	}

	inWinRate := 0.0
	if totalInSampleTrades > 0 {
		inWinRate = float64(totalInSampleWins) / float64(totalInSampleTrades)
	}

	outWinRate := 0.0
	if totalOutSampleTrades > 0 {
		outWinRate = float64(totalOutSampleWins) / float64(totalOutSampleTrades)
	}

	wfe := 0.0
	if totalInSampleNet.IsPositive() {
		wfe = totalOutSampleNet.Float64() / totalInSampleNet.Float64()
	}

	degradation := 0.0
	if inWinRate > 0 {
		degradation = math.Max(0, (inWinRate-outWinRate)/inWinRate*100)
	}

	sufficient := totalOutSampleTrades >= 20
	overfit := (wfe < 0.4 || degradation > 40.0) && sufficient

	return &WFOResult{
		StrategyID:            cfg.StrategyID,
		FoldsCount:            len(foldReports),
		TotalInSampleTrades:   totalInSampleTrades,
		TotalOutSampleTrades:  totalOutSampleTrades,
		InSampleNetPnL:        totalInSampleNet,
		OutSampleNetPnL:       totalOutSampleNet,
		InSampleWinRate:       math.Round(inWinRate*10000) / 10000,
		OutSampleWinRate:      math.Round(outWinRate*10000) / 10000,
		WalkForwardEfficiency: math.Round(wfe*1000) / 1000,
		DegradationPercent:    math.Round(degradation*100) / 100,
		SufficientData:        sufficient,
		OverfitDetected:       overfit,
		Folds:                 foldReports,
	}, nil
}
