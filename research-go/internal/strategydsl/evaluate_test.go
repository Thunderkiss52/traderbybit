package strategydsl

import (
	"encoding/json"
	"reflect"
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

func fixture() Request {
	start := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	cs := []datasets.Candle{}
	for i := 0; i < 160; i++ {
		p := decimal.FromInt64(int64(100 + i%20))
		cs = append(cs, datasets.Candle{Exchange: "bybit", Category: "linear", Symbol: "BTCUSDT", Interval: "15", OpenTime: start.Add(time.Duration(i) * 15 * time.Minute), Open: p, Close: p, High: p.Add(decimal.FromInt64(2)), Low: p.Sub(decimal.FromInt64(2)), Volume: decimal.FromInt64(10), IsClosed: true})
	}
	return Request{Definition: Definition{SchemaVersion: 1, ID: "user_test", Version: 1, Symbols: []string{"BTCUSDT"}, Exchanges: []string{"bybit"}, Timeframe: "15", Indicators: map[string]Indicator{"fast": {Type: "EMA", Period: 3, Timeframe: "15"}, "slow": {Type: "EMA", Period: 8, Timeframe: "15"}}, Entry: map[string]Condition{"BUY": {Op: "crosses_above", Left: "fast", Right: json.RawMessage(`"slow"`)}}, StopATR: 1.5, TakeATR: 3, RiskPercent: 0.5, TimeoutBars: 10}, Exchange: "bybit", Symbol: "BTCUSDT", Series: map[string][]datasets.Candle{"15": cs}, From: start.Add(30 * 15 * time.Minute), AsOf: start.Add(160 * 15 * time.Minute), Capital: decimal.FromInt64(10000), Fee: decimal.MustFromString("0.00055"), Slippage: decimal.MustFromString("0.0002")}
}
func TestDeterminismAndCausality(t *testing.T) {
	req := fixture()
	a, err := Run(req)
	if err != nil {
		t.Fatal(err)
	}
	b, err := Run(req)
	if err != nil || !reflect.DeepEqual(a, b) {
		t.Fatal("nondeterministic", err)
	}
	if len(a.Signals) == 0 {
		t.Fatal("no signals")
	}
	req.Series["15"] = req.Series["15"][:100]
	req.AsOf = req.Series["15"][99].OpenTime.Add(15 * time.Minute)
	prefix, err := Run(req)
	if err != nil {
		t.Fatal(err)
	}
	for i, s := range prefix.Signals {
		if !reflect.DeepEqual(s, a.Signals[i]) {
			t.Fatal("future leakage")
		}
		if s.EntryBar <= s.DecisionBar {
			t.Fatal("same bar fill")
		}
	}
}
func TestRejectIntegrityFailures(t *testing.T) {
	for _, kind := range []string{"open", "exchange", "gap", "future", "warmup", "unknown"} {
		t.Run(kind, func(t *testing.T) {
			r := fixture()
			switch kind {
			case "open":
				r.Series["15"][50].IsClosed = false
			case "exchange":
				r.Series["15"][50].Exchange = "binance"
			case "gap":
				r.Series["15"] = append(r.Series["15"][:50], r.Series["15"][51:]...)
			case "future":
				r.AsOf = r.AsOf.Add(-time.Hour)
			case "warmup":
				r.From = r.Series["15"][0].OpenTime
			case "unknown":
				r.Definition.Indicators["fast"] = Indicator{Type: "EXEC", Period: 2, Timeframe: "15"}
			}
			if _, err := Run(r); err == nil {
				t.Fatal("unsafe input accepted")
			}
		})
	}
}
func TestMultiTimeframeUsesOnlyClosedBars(t *testing.T) {
	r := fixture()
	cs := []datasets.Candle{}
	for i := 0; i < 40; i++ {
		c := r.Series["15"][i*4]
		c.Interval = "60"
		cs = append(cs, c)
	}
	r.Series["60"] = cs
	r.Definition.Indicators["slow"] = Indicator{Type: "EMA", Period: 3, Timeframe: "60"}
	a, err := Run(r)
	if err != nil {
		t.Fatal(err)
	}
	r.Series["60"][39].Close = decimal.FromInt64(1000)
	r.Series["60"][39].High = decimal.FromInt64(1001)
	b, err := Run(r)
	if err != nil {
		t.Fatal(err)
	}
	for i, s := range a.Signals {
		if s.DecisionTime.Before(cs[39].OpenTime.Add(time.Hour)) && !reflect.DeepEqual(s, b.Signals[i]) {
			t.Fatal("unclosed HTF leakage")
		}
	}
}
