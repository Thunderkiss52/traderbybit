package backtest

import (
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
	"testing"
	"time"
)

func replayFixture() ([]datasets.Candle, []Signal, BacktestConfig) {
	cs := []datasets.Candle{}
	start := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	for i := 0; i < 12; i++ {
		cs = append(cs, datasets.Candle{Symbol: "BTCUSDT", OpenTime: start.Add(time.Duration(i) * 15 * time.Minute), Open: decimal.FromInt64(100), High: decimal.FromInt64(101), Low: decimal.FromInt64(99), Close: decimal.FromInt64(100)})
	}
	cfg := DefaultConfig()
	cfg.TakerFeeRate = decimal.MustFromString("0.001")
	cfg.SlippageRate = decimal.Zero()
	cfg.RiskPerTradeRate = decimal.MustFromString("0.005")
	cfg.MaxHoldingBars = 100
	return cs, []Signal{{Side: "BUY", DecisionBar: 0, EntryBar: 1, StopLoss: decimal.FromInt64(90), TakeProfit: decimal.FromInt64(120)}}, cfg
}
func TestPartialFundingAndFinalCashReconcile(t *testing.T) {
	cs, signals, cfg := replayFixture()
	cs[2].High = decimal.FromInt64(111)
	cs[3].High = decimal.FromInt64(121)
	report, err := NewEngine(cfg).RunSignalsWithPolicy(cs, signals, "test", ReplayPolicy{PartialTargets: map[int]PartialTarget{0: {Price: decimal.FromInt64(110), Fraction: decimal.MustFromString("0.5")}}, Funding: []FundingEvent{{At: cs[3].OpenTime, Rate: decimal.MustFromString("0.01"), Mark: decimal.FromInt64(100)}}})
	if err != nil {
		t.Fatal(err)
	}
	if len(report.Trades) != 1 {
		t.Fatal(report)
	}
	trade := report.Trades[0]
	// 5 units: 2.5 at +10, 2.5 at +20; fees .5+.275+.3; funding -2.5.
	if trade.GrossPnL.String() != "75.00000000" || trade.Fees.String() != "1.07500000" || trade.NetPnL.String() != "71.42500000" || report.NetPnL.Cmp(trade.NetPnL) != 0 {
		t.Fatalf("accounting mismatch %+v", trade)
	}
	executions := report.Metadata["executions"].([]ReplayExecution)
	if len(executions) != 4 {
		t.Fatal(executions)
	}
}
func TestStopPrecedesPartialAndRuleExitIsNextOpen(t *testing.T) {
	cs, signals, cfg := replayFixture()
	cs[2].High = decimal.FromInt64(111)
	cs[2].Low = decimal.FromInt64(89)
	report, err := NewEngine(cfg).RunSignalsWithPolicy(cs, signals, "test", ReplayPolicy{PartialTargets: map[int]PartialTarget{0: {Price: decimal.FromInt64(110), Fraction: decimal.MustFromString("0.5")}}})
	if err != nil {
		t.Fatal(err)
	}
	if report.Trades[0].ExitReason != "STOP_LOSS" || len(report.Metadata["executions"].([]ReplayExecution)) != 2 {
		t.Fatal("partial bypassed stop")
	}
	cs[2].Low = decimal.FromInt64(99)
	cs[2].Open = decimal.FromInt64(103)
	report, err = NewEngine(cfg).RunSignalsWithPolicy(cs, signals, "test", ReplayPolicy{ExitAtOpen: map[int]map[string]bool{2: {"BUY": true}}})
	if err != nil {
		t.Fatal(err)
	}
	if report.Trades[0].ExitReason != "RULE_EXIT" || report.Trades[0].ExitPrice.String() != "103.00000000" {
		t.Fatal(report.Trades)
	}
}
func TestLastBarEntriesCloseWithoutUnreportedExposure(t *testing.T) {
	cs, signals, cfg := replayFixture()
	signals[0].DecisionBar = 10
	signals[0].EntryBar = 11
	report, err := NewEngine(cfg).RunSignalsWithPolicy(cs, signals, "test", ReplayPolicy{})
	if err != nil {
		t.Fatal(err)
	}
	if len(report.Trades) != 1 || report.Trades[0].ExitReason != "END_OF_DATA" {
		t.Fatal(report)
	}
}

func TestExactFundingDoesNotTruncateSubEightDecimalRatesToZero(t *testing.T) {
	event := FundingEvent{Rate: decimal.Zero(), Mark: decimal.FromInt64(100000), RateExact: "0.000000000001", MarkExact: "100000.000000000001"}
	amount, err := exactFundingAmount(event, decimal.FromInt64(1))
	if err != nil {
		t.Fatal(err)
	}
	if amount.Cmp(decimal.MustFromString("0.00000010")) != 0 {
		t.Fatalf("source precision lost: %s", amount.String())
	}
}
