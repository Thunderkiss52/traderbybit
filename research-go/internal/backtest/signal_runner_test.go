package backtest

import (
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

// signalTestCandles builds a deterministic series of candles and applies the given mutation.
//
// The mutation is used to push the price into the take profit or the stop loss zone of a trade
// that was opened earlier, so the trade can actually close.
func signalTestCandles(t *testing.T, n int, mutate func(i int, c *datasets.Candle)) []datasets.Candle {
	t.Helper()

	start := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	candles := make([]datasets.Candle, 0, n)
	for i := 0; i < n; i++ {
		openTime := start.Add(time.Duration(i) * 5 * time.Minute)
		candle := datasets.Candle{
			Exchange: "bybit",
			Category: "linear",
			Symbol:   "BTCUSDT",
			Interval: "5",
			OpenTime: openTime,
			Open:     decimal.MustFromString("100"),
			High:     decimal.MustFromString("100.5"),
			Low:      decimal.MustFromString("99.5"),
			Close:    decimal.MustFromString("100"),
			Volume:   decimal.MustFromString("10"),
			Turnover: decimal.Zero(),
			IsClosed: true,
		}
		if mutate != nil {
			mutate(i, &candle)
		}
		candles = append(candles, candle)
	}

	return candles
}

func TestRunSignalsFillsAtTheOpenOfTheEntryBarWithSlippage(t *testing.T) {
	candles := signalTestCandles(t, 20, func(i int, c *datasets.Candle) {
		if i == 5 {
			c.High = decimal.MustFromString("120")
			c.Close = decimal.MustFromString("118")
		}
	})

	cfg := DefaultConfig()
	engine := NewEngine(cfg)

	signals := []Signal{{
		Side:         "BUY",
		DecisionTime: candles[2].OpenTime.Add(5 * time.Minute),
		DecisionBar:  2,
		EntryBar:     3,
		StopLoss:     decimal.MustFromString("95"),
		TakeProfit:   decimal.MustFromString("110"),
		Reason:       "TEST_ENTRY",
	}}

	report, err := engine.RunSignals(candles, signals, "test_signal_runner")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if report.TotalTrades != 1 {
		t.Fatalf("expected exactly 1 trade, got %d", report.TotalTrades)
	}

	trade := report.Trades[0]
	if !trade.EntryTime.Equal(candles[3].OpenTime) {
		t.Fatalf("the fill must happen at the open of the entry bar, got %s", trade.EntryTime)
	}

	expectedFill := candles[3].Open.Add(candles[3].Open.Mul(cfg.SlippageRate))
	if trade.EntryPrice.Cmp(expectedFill) != 0 {
		t.Fatalf("expected the buy fill %s (open + slippage), got %s", expectedFill.String(), trade.EntryPrice.String())
	}

	if !trade.Fees.IsPositive() {
		t.Fatal("the taker fee must be charged")
	}
	if trade.Side != "BUY" {
		t.Fatalf("unexpected side %q", trade.Side)
	}
	if trade.ExitReason != "TAKE_PROFIT" {
		t.Fatalf("unexpected exit reason %q", trade.ExitReason)
	}
}

func TestRunSignalsDropsLookAheadAndOutOfRangeSignals(t *testing.T) {
	candles := signalTestCandles(t, 20, nil)
	engine := NewEngine(DefaultConfig())

	signals := []Signal{
		{
			// The fill bar is not strictly after the decision bar: look-ahead, must be dropped.
			Side:        "BUY",
			DecisionBar: 4,
			EntryBar:    4,
			StopLoss:    decimal.MustFromString("95"),
			TakeProfit:  decimal.MustFromString("110"),
			Reason:      "TEST_LOOKAHEAD",
		},
		{
			// The fill bar is before the decision bar: look-ahead, must be dropped.
			Side:        "BUY",
			DecisionBar: 4,
			EntryBar:    2,
			StopLoss:    decimal.MustFromString("95"),
			TakeProfit:  decimal.MustFromString("110"),
			Reason:      "TEST_BACKWARD",
		},
		{
			// The fill bar is outside the series: must be dropped.
			Side:        "BUY",
			DecisionBar: 4,
			EntryBar:    99,
			StopLoss:    decimal.MustFromString("95"),
			TakeProfit:  decimal.MustFromString("110"),
			Reason:      "TEST_OUT_OF_RANGE",
		},
		{
			// Unknown side: must be dropped.
			Side:        "HOLD",
			DecisionBar: 4,
			EntryBar:    5,
			StopLoss:    decimal.MustFromString("95"),
			TakeProfit:  decimal.MustFromString("110"),
			Reason:      "TEST_INVALID_SIDE",
		},
	}

	report, err := engine.RunSignals(candles, signals, "test_signal_runner_invalid")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if report.TotalTrades != 0 {
		t.Fatalf("expected no trade, got %d", report.TotalTrades)
	}
}

func TestRunSignalsUsesTheSameCostModelAsTheStrategyEngine(t *testing.T) {
	candles := signalTestCandles(t, 20, func(i int, c *datasets.Candle) {
		if i == 5 {
			c.Low = decimal.MustFromString("80")
			c.Close = decimal.MustFromString("85")
		}
	})

	cfg := DefaultConfig()
	engine := NewEngine(cfg)

	signals := []Signal{{
		Side:        "SELL",
		DecisionBar: 2,
		EntryBar:    3,
		StopLoss:    decimal.MustFromString("105"),
		TakeProfit:  decimal.MustFromString("90"),
		Reason:      "TEST_SHORT",
	}}

	report, err := engine.RunSignals(candles, signals, "test_signal_runner_short")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if report.TotalTrades != 1 {
		t.Fatalf("expected exactly 1 trade, got %d", report.TotalTrades)
	}

	trade := report.Trades[0]
	expectedFill := candles[3].Open.Sub(candles[3].Open.Mul(cfg.SlippageRate))
	if trade.EntryPrice.Cmp(expectedFill) != 0 {
		t.Fatalf("expected the sell fill %s (open - slippage), got %s", expectedFill.String(), trade.EntryPrice.String())
	}

	// The risk budget is a fixed share of the starting balance, the stop distance is measured from
	// the filled price to the stop loss. The same rule is used by the strategy engine.
	stopDistance := expectedFill.Sub(trade.StopLoss)
	if stopDistance.IsNegative() {
		stopDistance = stopDistance.Neg()
	}
	expectedQty, err := cfg.StartingBalance.Mul(cfg.RiskPerTradeRate).Div(stopDistance)
	if err != nil {
		t.Fatalf("unexpected decimal error: %v", err)
	}
	if trade.Quantity.Cmp(expectedQty) != 0 {
		t.Fatalf("expected the risk sized quantity %s, got %s", expectedQty.String(), trade.Quantity.String())
	}
}
