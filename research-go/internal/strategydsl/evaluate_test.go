package strategydsl

import (
	"encoding/json"
	"reflect"
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/backtest"
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

func TestClosedSignalModeMatchesResearchDecision(t *testing.T) {
	r := fixture()
	historical, err := Run(r)
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range historical.Signals {
		prefix := fixture()
		prefix.Series["15"] = prefix.Series["15"][:want.DecisionBar+1]
		prefix.AsOf = want.DecisionTime
		prefix.Mode = "signal"
		got, err := Run(prefix)
		if err != nil {
			t.Fatal(err)
		}
		if len(got.Signals) == 0 || !reflect.DeepEqual(got.Signals[len(got.Signals)-1], want) {
			t.Fatal("PAPER/research decision mismatch")
		}
	}
}
func TestFundingCoverageAndAdvancedIndicators(t *testing.T) {
	r := fixture()
	r.Definition.Funding = &FundingConfig{Enabled: true, IntervalHours: 8}
	if _, err := Run(r); err == nil {
		t.Fatal("missing funding accepted")
	}
	step := int64(8 * 3600)
	for at := (r.From.Unix()/step + 1) * step; at < r.AsOf.Unix(); at += step {
		r.Funding = append(r.Funding, backtest.FundingEvent{At: time.Unix(at, 0).UTC(), Rate: decimal.MustFromString("0.0001"), Mark: decimal.FromInt64(100)})
	}
	for _, kind := range []string{"ADX", "MACD_SIGNAL", "MACD_HISTOGRAM", "OPEN", "HIGH", "LOW", "BODY", "RANGE", "VOLUME", "TURNOVER"} {
		r.Definition.Indicators["context"] = Indicator{Type: kind, Period: 3, Timeframe: "15"}
		if _, err := Run(r); err != nil {
			t.Fatalf("%s: %v", kind, err)
		}
	}
	r.Definition.Exit = map[string]Condition{"BUY": {Op: "gt", Left: "fast", Right: json.RawMessage("0")}}
	result, err := Run(r)
	if err != nil {
		t.Fatal(err)
	}
	if result.PaperEligible {
		t.Fatal("unverified custom-exit parity allowed")
	}
}
func TestADXWilderReference(t *testing.T) {
	r := fixture()
	for i := range r.Series["15"] {
		p := decimal.FromInt64(int64(100 + i))
		r.Series["15"][i].High = p.Add(decimal.FromInt64(2))
		r.Series["15"][i].Low = p.Sub(decimal.FromInt64(2))
		r.Series["15"][i].Close = p
	}
	v := adx(r.Series["15"], 14)
	if v[26] != nil || v[27] == nil || v[27].String() != "100.00000000" {
		t.Fatal("ADX warmup/reference mismatch")
	}
}

func TestV2DecisionsRemainIdenticalAcrossMovingPaperWindows(t *testing.T) {
	r := fixture()
	r.Definition.SchemaVersion = 2
	original := r.Series["15"]
	r.Series["15"] = nil
	for cycle := 0; cycle < 4; cycle++ {
		for _, c := range original {
			c.OpenTime = c.OpenTime.Add(time.Duration(cycle*len(original)) * 15 * time.Minute)
			r.Series["15"] = append(r.Series["15"], c)
		}
	}
	r.From = r.Series["15"][221].OpenTime
	r.AsOf = r.Series["15"][len(r.Series["15"])-1].OpenTime.Add(15 * time.Minute)
	a, err := Run(r)
	if err != nil {
		t.Fatal(err)
	}
	if len(a.Signals) == 0 {
		t.Fatal("no signals")
	}
	for _, want := range a.Signals {
		p := r
		p.Mode = "signal"
		first := want.DecisionBar - 221
		if first < 0 {
			first = 0
		}
		p.Series = map[string][]datasets.Candle{"15": r.Series["15"][first : want.DecisionBar+1]}
		p.AsOf = want.DecisionTime
		p.From = want.DecisionTime
		b, err := Run(p)
		if err != nil {
			t.Fatal(err)
		}
		got := b.Signals[len(b.Signals)-1]
		if !got.DecisionTime.Equal(want.DecisionTime) || got.Side != want.Side || got.StopLoss.Cmp(want.StopLoss) != 0 || got.TakeProfit.Cmp(want.TakeProfit) != 0 {
			t.Fatal("moving-window PAPER parity failed")
		}
	}
}
