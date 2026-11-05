package historical

import (
	"context"
	"encoding/json"
	"github.com/traderbybit/research-go/internal/backtest"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func request(t *testing.T) Request {
	t.Helper()
	dir := t.TempDir()
	at := time.Date(2024, 1, 1, 0, 0, 0, 0, time.UTC)
	candles := []datasets.Candle{}
	for i := 0; i < 30; i++ {
		candles = append(candles, datasets.Candle{Exchange: "binance", Category: "linear", Symbol: "BTCUSDT", Interval: "5", OpenTime: at.Add(time.Duration(i) * 5 * time.Minute), Open: decimal.FromInt64(100), High: decimal.FromInt64(101), Low: decimal.FromInt64(99), Close: decimal.FromInt64(100), Volume: decimal.FromInt64(10), Turnover: decimal.FromInt64(1000), IsClosed: true})
	}
	c := filepath.Join(dir, "candles")
	writeRows(c, candles)
	s := filepath.Join(dir, "signals")
	writeRows(s, []backtest.Signal{{Side: "BUY", DecisionTime: at.Add(5 * time.Minute), DecisionBar: 0, EntryBar: 1, StopLoss: decimal.FromInt64(90), TakeProfit: decimal.FromInt64(120)}})
	f := filepath.Join(dir, "funding")
	writeRows(f, []backtest.FundingEvent{{At: at.Add(50 * time.Minute), Rate: decimal.MustFromString("-0.00000012"), Mark: decimal.FromInt64(100), RateExact: "-0.000000123456", MarkExact: "100.000000000001"}})
	return Request{StrategyID: "fixture", Series: map[string]string{"5": c}, SignalsFile: s, FundingFile: f, Output: dir, From: at, To: at.Add(150 * time.Minute), Symbol: "BTCUSDT", Capital: decimal.FromInt64(10000), Fee: decimal.MustFromString("0.0005"), Slippage: decimal.MustFromString("0.0002"), Risk: decimal.MustFromString("0.005"), MaxHolding: 48}
}
func TestDeterministicReplayAndExactFundingCash(t *testing.T) {
	r := request(t)
	a, e := Run(context.Background(), r)
	if e != nil {
		t.Fatal(e)
	}
	bytesA, _ := json.Marshal(a)
	a, e = Run(context.Background(), r)
	if e != nil {
		t.Fatal(e)
	}
	bytesB, _ := json.Marshal(a)
	if string(bytesA) != string(bytesB) {
		t.Fatal("not deterministic")
	}
	raw, e := os.ReadFile(filepath.Join(r.Output, "trades.ndjson"))
	if e != nil || len(raw) == 0 {
		t.Fatal(e)
	}
	var trade backtest.Trade
	if e = json.Unmarshal(raw, &trade); e != nil {
		t.Fatal(e)
	}
	if trade.Funding == nil || !trade.Funding.IsPositive() || trade.Slippage == nil || !trade.Slippage.IsPositive() {
		t.Fatalf("missing funding/slippage evidence: %s", raw)
	}
}
func TestNoTimestampRoundingAndCancellation(t *testing.T) {
	r := request(t)
	events, _ := readRows[backtest.FundingEvent](context.Background(), r.FundingFile, 100)
	events[0].At = events[0].At.Add(time.Millisecond)
	writeRows(r.FundingFile, events)
	if _, e := Run(context.Background(), r); e == nil {
		t.Fatal("rounded unsupported funding timestamp")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, e := Run(ctx, r); e == nil {
		t.Fatal("ignored cancellation")
	}
}
func TestForeignOrFutureCandlesRejected(t *testing.T) {
	r := request(t)
	rows, _ := readRows[datasets.Candle](context.Background(), r.Series["5"], 100)
	rows[0].Exchange = "bybit"
	writeRows(r.Series["5"], rows)
	if _, e := Run(context.Background(), r); e == nil {
		t.Fatal("cross-exchange contamination")
	}
	rows[0].Exchange = "binance"
	rows[len(rows)-1].OpenTime = r.To
	writeRows(r.Series["5"], rows)
	if _, e := Run(context.Background(), r); e == nil {
		t.Fatal("future candle accepted")
	}
}
