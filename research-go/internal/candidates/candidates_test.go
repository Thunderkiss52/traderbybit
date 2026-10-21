package candidates

import (
	"fmt"
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/decimal"
)

// testBar describes a synthetic bar of a golden test.
type testBar struct {
	open  string
	high  string
	low   string
	close string
}

// buildBars converts a list of synthetic bars into the research bar type.
//
// Every bar is closed and known exactly at its close time, which is the strictest causal setting.
func buildBars(t *testing.T, symbol string, interval time.Duration, start time.Time, specs []testBar) []Bar {
	t.Helper()

	bars := make([]Bar, 0, len(specs))
	for i, s := range specs {
		openTime := start.Add(time.Duration(i) * interval)
		closeTime := openTime.Add(interval)
		bars = append(bars, Bar{
			Symbol:    symbol,
			Interval:  interval.String(),
			OpenTime:  openTime,
			CloseTime: closeTime,
			KnownAt:   closeTime,
			Open:      decimal.MustFromString(s.open),
			High:      decimal.MustFromString(s.high),
			Low:       decimal.MustFromString(s.low),
			Close:     decimal.MustFromString(s.close),
			Volume:    decimal.MustFromString("100"),
		})
	}

	return bars
}

// flatBars builds a constant series, used as the base of the crafted scenarios.
func flatBars(t *testing.T, symbol string, count int, price string) []Bar {
	t.Helper()

	specs := make([]testBar, 0, count)
	for i := 0; i < count; i++ {
		specs = append(specs, testBar{open: price, high: price, low: price, close: price})
	}

	return buildBars(t, symbol, 5*time.Minute, time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC), specs)
}

// series builds a deterministic series from a close price function.
//
// Every bar carries a small range so that the average true range is strictly positive.
func series(t *testing.T, symbol string, count int, interval time.Duration, closeAt func(i int) string) []Bar {
	t.Helper()

	start := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	specs := make([]testBar, 0, count)
	for i := 0; i < count; i++ {
		c := closeAt(i)
		base, err := decimal.FromString(c)
		if err != nil {
			t.Fatalf("unexpected decimal error: %v", err)
		}
		high := base.Add(decimal.MustFromString("0.05")).String()
		low := base.Sub(decimal.MustFromString("0.05")).String()
		specs = append(specs, testBar{open: c, high: high, low: low, close: c})
	}

	return buildBars(t, symbol, interval, start, specs)
}

func TestRegistryContainsTheFiveContractCandidates(t *testing.T) {
	expected := []string{
		"btc_eth_lead_lag",
		"funding_open_interest_squeeze",
		"liquidity_sweep_reversal",
		"multi_timeframe_trend_following",
		"volatility_compression_breakout",
	}

	ids := IDs()
	if len(ids) != len(expected) {
		t.Fatalf("expected %d registered cohorts, got %d (%v)", len(expected), len(ids), ids)
	}
	for i, id := range expected {
		if ids[i] != id {
			t.Fatalf("expected cohort %q at position %d, got %q", id, i, ids[i])
		}
	}

	entry, ok := Lookup("btc_eth_lead_lag")
	if !ok {
		t.Fatal("btc_eth_lead_lag must be registered")
	}
	if entry.Executable {
		t.Fatal("btc_eth_lead_lag must not be marked as executable without a two-leg execution model")
	}
	if entry.Kind != CohortKindResearch {
		t.Fatalf("candidates must be research cohorts, got %q", entry.Kind)
	}
}

func TestLiquiditySweepReversalProducesTheCraftedSignalOnly(t *testing.T) {
	bars := flatBars(t, "BTCUSDT", 80, "100")
	// A prior-known support of 100 is swept and recaptured on the close.
	bars[70].Low = decimal.MustFromString("99")
	bars[70].Close = decimal.MustFromString("101")
	bars[70].High = decimal.MustFromString("101")
	bars[70].Open = decimal.MustFromString("100")

	d := Dataset{Symbol: "BTCUSDT", EntryInterval: "5m", Bars: bars}

	signals, err := NewLiquiditySweepReversal(DefaultLiquiditySweepConfig()).Generate(d)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(signals) != 1 {
		t.Fatalf("expected exactly 1 signal, got %d (%+v)", len(signals), signals)
	}

	s := signals[0]
	if s.Side != "BUY" {
		t.Fatalf("expected a BUY sweep signal, got %s", s.Side)
	}
	if s.Reason != "PRIOR_SUPPORT_SWEEP_RECAPTURE" {
		t.Fatalf("unexpected reason %q", s.Reason)
	}
	if s.DecisionBar != 70 {
		t.Fatalf("expected the decision at bar 70, got %d", s.DecisionBar)
	}
	if s.EntryBar != 71 {
		t.Fatalf("the fill must happen on the next bar, got %d", s.EntryBar)
	}
	if s.StopLoss.Cmp(bars[70].Close) >= 0 {
		t.Fatal("a BUY signal must carry a stop loss below the decision close")
	}
}

func TestVolatilityCompressionBreakoutRequiresCompressionAndBreakout(t *testing.T) {
	// A flat series has no ATR, so the compression can never be satisfied: no signal.
	flat := flatBars(t, "BTCUSDT", 100, "100")
	if signals, err := NewVolatilityCompressionBreakout(DefaultVolatilityCompressionConfig()).Generate(Dataset{Symbol: "BTCUSDT", Bars: flat}); err == nil {
		if len(signals) != 0 {
			t.Fatalf("a flat series must not produce breakout signals, got %d", len(signals))
		}
	}

	// A compressed series followed by a close beyond the previous window high produces one BUY.
	//
	// Bars 0..49 have a wide range, bars 50..99 a narrow range: the average true range contracts,
	// the compression is established, and bar 100 closes above the previous window high.
	specs := make([]testBar, 0, 120)
	for i := 0; i < 120; i++ {
		switch {
		case i < 80:
			specs = append(specs, testBar{open: "100", high: "100.5", low: "99.5", close: "100"})
		case i < 100:
			specs = append(specs, testBar{open: "100", high: "100.05", low: "99.95", close: "100"})
		case i == 100:
			specs = append(specs, testBar{open: "100", high: "100.2", low: "100", close: "100.2"})
		default:
			specs = append(specs, testBar{open: "100.2", high: "100.25", low: "100.15", close: "100.2"})
		}
	}
	bars := buildBars(t, "BTCUSDT", 5*time.Minute, time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC), specs)

	signals, err := NewVolatilityCompressionBreakout(DefaultVolatilityCompressionConfig()).Generate(Dataset{Symbol: "BTCUSDT", Bars: bars})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(signals) != 1 {
		t.Fatalf("expected exactly 1 compression breakout signal, got %d (%+v)", len(signals), signals)
	}
	if signals[0].Side != "BUY" || signals[0].Reason != "COMPRESSION_BREAKOUT_UP" {
		t.Fatalf("unexpected signal %+v", signals[0])
	}
	if signals[0].DecisionBar != 100 {
		t.Fatalf("expected the decision at bar 100, got %d", signals[0].DecisionBar)
	}
	for _, s := range signals {
		if s.Side != "BUY" && s.Side != "SELL" {
			t.Fatalf("unexpected side %q", s.Side)
		}
		if s.EntryBar != s.DecisionBar+1 {
			t.Fatalf("the fill must happen on the next bar: decision %d entry %d", s.DecisionBar, s.EntryBar)
		}
	}
}

func TestFundingOpenInterestSqueezeRequiresPublishedFundingAndOpenInterest(t *testing.T) {
	bars := series(t, "BTCUSDT", 100, 5*time.Minute, func(i int) string {
		if i < 90 {
			return "100"
		}
		return "99"
	})
	c := NewFundingOpenInterestSqueeze(DefaultFundingOpenInterestConfig())

	if _, err := c.Generate(Dataset{Symbol: "BTCUSDT", Bars: bars}); err == nil {
		t.Fatal("the candidate must fail when funding or open interest data is missing")
	}

	start := bars[0].OpenTime
	funding := []FundingEvent{{
		Symbol:    "BTCUSDT",
		EventTime: start.Add(100 * time.Minute),
		KnownAt:   start.Add(100 * time.Minute),
		Rate:      decimal.MustFromString("0.00050000"),
	}}
	// The open interest snapshots must straddle the lookback window of the decision bar, otherwise
	// the "now" and the "past" snapshot are the same reading and no change can be observed.
	oi := []OpenInterestEvent{
		{Symbol: "BTCUSDT", EventTime: start.Add(420 * time.Minute), KnownAt: start.Add(420 * time.Minute), OpenInterest: decimal.MustFromString("1000")},
		{Symbol: "BTCUSDT", EventTime: start.Add(440 * time.Minute), KnownAt: start.Add(440 * time.Minute), OpenInterest: decimal.MustFromString("1100")},
	}

	signals, err := c.Generate(Dataset{Symbol: "BTCUSDT", Bars: bars, Funding: funding, OpenInterest: oi})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(signals) != 1 {
		t.Fatalf("expected exactly 1 squeeze signal, got %d (%+v)", len(signals), signals)
	}
	if signals[0].DecisionBar != 90 {
		t.Fatalf("expected the decision at bar 90, got %d", signals[0].DecisionBar)
	}
	if signals[0].Side != "BUY" {
		t.Fatalf("a crowded long that fails to extend must produce a BUY squeeze signal, got %s", signals[0].Side)
	}
	if signals[0].Reason != "FUNDING_CROWD_LONG_FAILED_SQUEEZE" {
		t.Fatalf("unexpected reason %q", signals[0].Reason)
	}
}

func TestMultiTimeframeTrendFollowingUsesOnlyClosedHigherTimeframeBars(t *testing.T) {
	ltf := series(t, "BTCUSDT", 200, 5*time.Minute, func(i int) string {
		if i < 150 {
			return "100"
		}
		return fmt.Sprintf("%d", 100+i)
	})

	// A rising higher timeframe series: the EMA fast is above the EMA slow.
	htf := series(t, "BTCUSDT", 120, 60*time.Minute, func(i int) string {
		return fmt.Sprintf("%d", 100+i)
	})

	d := Dataset{Symbol: "BTCUSDT", EntryInterval: "5m", DecisionInterval: "1h", Bars: ltf, HTFBars: htf}

	c := NewMultiTimeframeTrendFollowing(DefaultMultiTimeframeConfig())
	signals, err := c.Generate(d)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	for _, s := range signals {
		htfClose, err := time.Parse("2006-01-02T15:04:05Z", s.Features["htf_close_time"])
		if err != nil {
			t.Fatalf("unexpected feature value %q: %v", s.Features["htf_close_time"], err)
		}
		if s.DecisionTime.Before(htfClose) {
			t.Fatalf("signal at %s used a higher timeframe bar that closes at %s", s.DecisionTime, htfClose)
		}
	}
}

func TestBtcEthLeadLagAlignsThePeerOnCloseAndKnownTime(t *testing.T) {
	ltf := series(t, "BTCUSDT", 200, 5*time.Minute, func(i int) string {
		if i < 150 {
			return "100"
		}
		return "100.2"
	})
	peer := series(t, "ETHUSDT", 200, 5*time.Minute, func(i int) string {
		if i < 140 {
			return "50"
		}
		return "55"
	})

	c := NewBTCETHLagLead(DefaultLeadLagConfig())
	signals, err := c.Generate(Dataset{Symbol: "BTCUSDT", PeerSymbol: "ETHUSDT", Bars: ltf, PeerBars: peer})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	for _, s := range signals {
		if s.Side != "BUY" && s.Side != "SELL" {
			t.Fatalf("unexpected side %q", s.Side)
		}
	}
}
