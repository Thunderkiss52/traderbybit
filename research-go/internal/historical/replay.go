package historical

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"github.com/traderbybit/research-go/internal/backtest"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
	"github.com/traderbybit/research-go/internal/strategydsl"
	"os"
	"path/filepath"
	"time"
)

type Request struct {
	StrategyID  string                  `json:"strategy_id"`
	Definition  *strategydsl.Definition `json:"definition,omitempty"`
	Series      map[string]string       `json:"series"`
	SignalsFile string                  `json:"signals_file"`
	FundingFile string                  `json:"funding_file"`
	Output      string                  `json:"output"`
	From        time.Time               `json:"from"`
	To          time.Time               `json:"to"`
	Symbol      string                  `json:"symbol"`
	Capital     decimal.Decimal         `json:"capital"`
	Fee         decimal.Decimal         `json:"fee"`
	Slippage    decimal.Decimal         `json:"slippage"`
	Risk        decimal.Decimal         `json:"risk_rate"`
	MaxHolding  int                     `json:"max_holding_bars"`
}

func readRows[T any](ctx context.Context, path string, max int) ([]T, error) {
	file, e := os.Open(path)
	if e != nil {
		return nil, e
	}
	defer file.Close()
	scanner := bufio.NewScanner(file)
	scanner.Buffer(make([]byte, 65536), 1048576)
	rows := []T{}
	for scanner.Scan() {
		if e = ctx.Err(); e != nil {
			return nil, e
		}
		if len(rows) >= max {
			return nil, fmt.Errorf("historical replay row resource bound exceeded")
		}
		var row T
		if e = json.Unmarshal(scanner.Bytes(), &row); e != nil {
			return nil, e
		}
		rows = append(rows, row)
	}
	return rows, scanner.Err()
}
func writeRows[T any](path string, rows []T) error {
	file, e := os.Create(path)
	if e != nil {
		return e
	}
	defer file.Close()
	out := bufio.NewWriter(file)
	encoder := json.NewEncoder(out)
	for _, row := range rows {
		if e = encoder.Encode(row); e != nil {
			return e
		}
	}
	return out.Flush()
}
func Run(ctx context.Context, req Request) (map[string]interface{}, error) {
	if !req.Capital.IsPositive() || req.Fee.IsNegative() || req.Fee.Cmp(decimal.FromInt64(1)) >= 0 || req.Slippage.IsNegative() || req.Slippage.Cmp(decimal.FromInt64(1)) >= 0 || !req.Risk.IsPositive() || req.Risk.Cmp(decimal.MustFromString("0.005")) > 0 || req.MaxHolding < 1 || req.From.IsZero() || !req.From.Before(req.To) {
		return nil, fmt.Errorf("invalid explicit historical execution assumptions")
	}
	series := map[string][]datasets.Candle{}
	for tf, path := range req.Series {
		rows, e := readRows[datasets.Candle](ctx, path, 500000)
		if e != nil {
			return nil, e
		}
		seconds := map[string]int{"5": 300, "15": 900, "30": 1800, "60": 3600}[tf]
		if seconds == 0 {
			return nil, fmt.Errorf("unsupported replay timeframe")
		}
		for i, c := range rows {
			if c.Exchange != "binance" || c.Category != "linear" || c.Symbol != req.Symbol || c.Interval != tf || !c.IsClosed || c.OpenTime.Unix()%int64(seconds) != 0 || c.OpenTime.Add(time.Duration(seconds)*time.Second).After(req.To) || i > 0 && !c.OpenTime.Equal(rows[i-1].OpenTime.Add(time.Duration(seconds)*time.Second)) {
				return nil, fmt.Errorf("historical market identity, causality or coverage invalid")
			}
		}
		series[tf] = rows
	}
	funding, e := readRows[backtest.FundingEvent](ctx, req.FundingFile, 50000)
	if e != nil {
		return nil, e
	}
	executionFrame := "5"
	if req.Definition != nil {
		executionFrame = req.Definition.Timeframe
	}
	step := map[string]int64{"5": 300, "15": 900, "30": 1800, "60": 3600}[executionFrame]
	for _, event := range funding {
		if event.At.UnixNano()%(step*int64(time.Second)) != 0 {
			return nil, fmt.Errorf("funding settlement requires a finer execution clock; no timestamp rounding is permitted")
		}
	}
	var report *backtest.BacktestReport
	var signals []backtest.Signal
	if req.Definition != nil {
		result, err := strategydsl.Run(strategydsl.Request{Definition: *req.Definition, Exchange: "binance", Symbol: req.Symbol, From: req.From, AsOf: req.To, Series: series, Funding: funding, Capital: req.Capital, Fee: req.Fee, Slippage: req.Slippage, PublishedFundingEvents: true, IncludeFeatures: true, Context: ctx})
		if err != nil {
			return nil, err
		}
		report = result.Report
		signals = result.Signals
	} else {
		signals, e = readRows[backtest.Signal](ctx, req.SignalsFile, 100000)
		if e != nil {
			return nil, e
		}
		candles := series["5"]
		for _, signal := range signals {
			if signal.DecisionTime.Before(req.From) || !signal.DecisionTime.Before(req.To) || signal.DecisionBar < 0 || signal.DecisionBar >= len(candles) || signal.EntryBar != signal.DecisionBar+1 || signal.EntryBar >= len(candles) || !signal.DecisionTime.Equal(candles[signal.DecisionBar].OpenTime.Add(5*time.Minute)) {
				return nil, fmt.Errorf("invalid causal historical signal")
			}
		}
		cfg := backtest.DefaultConfig()
		cfg.StartingBalance = req.Capital
		cfg.TakerFeeRate = req.Fee
		cfg.SlippageRate = req.Slippage
		cfg.RiskPerTradeRate = req.Risk
		cfg.MaxHoldingBars = req.MaxHolding
		partials := map[int]backtest.PartialTarget{}
		for _, signal := range signals {
			if signal.Partial != nil {
				partials[signal.DecisionBar] = *signal.Partial
			}
		}
		report, e = backtest.NewEngine(cfg).RunSignalsWithPolicy(candles, signals, req.StrategyID, backtest.ReplayPolicy{Funding: funding, PartialTargets: partials})
		if e != nil {
			return nil, e
		}
	}
	if e = ctx.Err(); e != nil {
		return nil, e
	}
	if e = writeRows(filepath.Join(req.Output, "trades.ndjson"), report.Trades); e != nil {
		return nil, e
	}
	if e = writeRows(filepath.Join(req.Output, "equity.ndjson"), report.EquitySeries); e != nil {
		return nil, e
	}
	if e = writeRows(filepath.Join(req.Output, "signals.ndjson"), signals); e != nil {
		return nil, e
	}
	executions, _ := report.Metadata["executions"].([]backtest.ReplayExecution)
	if e = writeRows(filepath.Join(req.Output, "executions.ndjson"), executions); e != nil {
		return nil, e
	}
	delete(report.Metadata, "executions")
	report.Trades = nil
	report.EquitySeries = nil
	summary := map[string]interface{}{"report": report, "semantics": "VERSIONED_SIGNALS_GO_CLOSED_BAR_EXECUTION", "execution_frame": executionFrame, "funding_events": len(funding), "funding_timestamp_rounding": false, "money_precision_scale": decimal.Scale, "funding_rate_precision": "EXACT_SOURCE_RATIONAL_UNTIL_CASH_QUANTIZATION", "cash_quantization": "TRUNCATE_TOWARD_ZERO_TO_ENGINE_SCALE", "automatic_paper_promotion": false, "signal_count": len(signals)}
	file, e := os.Create(filepath.Join(req.Output, "result.json"))
	if e != nil {
		return nil, e
	}
	defer file.Close()
	if e = json.NewEncoder(file).Encode(summary); e != nil {
		return nil, e
	}
	return summary, nil
}
