package candidates

import (
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/decimal"
)

// researchDataset builds a deterministic dataset that contains every input a candidate may read.
func researchDataset(t *testing.T) Dataset {
	t.Helper()

	start := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	interval := 5 * time.Minute

	bars := make([]Bar, 0, 300)
	for i := 0; i < 300; i++ {
		openTime := start.Add(time.Duration(i) * interval)
		// A deterministic wave with a slow trend: the price is a pure function of the bar index.
		base := 100.0 + float64(i)*0.02
		osc := 1.5 * sin(float64(i)/9.0)
		close := base + osc
		high := close + 0.4
		low := close - 0.4

		bars = append(bars, Bar{
			Symbol:    "BTCUSDT",
			Interval:  "5",
			OpenTime:  openTime,
			CloseTime: openTime.Add(interval),
			KnownAt:   openTime.Add(interval),
			Open:      decimal.FromFloat64(base + osc - 0.1),
			High:      decimal.FromFloat64(high),
			Low:       decimal.FromFloat64(low),
			Close:     decimal.FromFloat64(close),
			Volume:    decimal.FromFloat64(100 + float64(i%7)),
		})
	}

	// The higher timeframe series starts 120 hours BEFORE the entry series: a warm-up history is
	// required, and the warm-up must be data driven, not position driven.
	htf := make([]Bar, 0, 160)
	for i := 0; i < 160; i++ {
		openTime := start.Add(-120 * time.Hour).Add(time.Duration(i) * time.Hour)
		base := 100.0 + float64(i)*0.25 - 30
		htf = append(htf, Bar{
			Symbol:    "BTCUSDT",
			Interval:  "60",
			OpenTime:  openTime,
			CloseTime: openTime.Add(time.Hour),
			KnownAt:   openTime.Add(time.Hour),
			Open:      decimal.FromFloat64(base),
			High:      decimal.FromFloat64(base + 1.2),
			Low:       decimal.FromFloat64(base - 1.2),
			Close:     decimal.FromFloat64(base + 0.4),
			Volume:    decimal.FromFloat64(1000),
		})
	}

	peer := make([]Bar, 0, 300)
	for i := 0; i < 300; i++ {
		openTime := start.Add(time.Duration(i) * interval)
		base := 50.0 + float64(i)*0.01
		peer = append(peer, Bar{
			Symbol:    "ETHUSDT",
			Interval:  "5",
			OpenTime:  openTime,
			CloseTime: openTime.Add(interval),
			KnownAt:   openTime.Add(interval),
			Open:      decimal.FromFloat64(base),
			High:      decimal.FromFloat64(base + 0.3),
			Low:       decimal.FromFloat64(base - 0.3),
			Close:     decimal.FromFloat64(base + 0.1*sin(float64(i)/13.0)),
			Volume:    decimal.FromFloat64(2000),
		})
	}

	funding := make([]FundingEvent, 0, 16)
	for i := 0; i < 16; i++ {
		at := start.Add(time.Duration(i) * 8 * time.Hour)
		funding = append(funding, FundingEvent{
			Symbol:    "BTCUSDT",
			EventTime: at,
			KnownAt:   at,
			Rate:      decimal.FromFloat64(0.0002 * cos(float64(i))),
		})
	}

	oi := make([]OpenInterestEvent, 0, 300)
	for i := 0; i < 300; i++ {
		at := start.Add(time.Duration(i) * interval)
		oi = append(oi, OpenInterestEvent{
			Symbol:       "BTCUSDT",
			EventTime:    at,
			KnownAt:      at,
			OpenInterest: decimal.FromFloat64(1000 + float64(i%23)*7),
		})
	}

	return Dataset{
		Symbol:           "BTCUSDT",
		EntryInterval:    "5",
		DecisionInterval: "60",
		PeerSymbol:       "ETHUSDT",
		Bars:             bars,
		HTFBars:          htf,
		PeerBars:         peer,
		Funding:          funding,
		OpenInterest:     oi,
		SourceHash:       "synthetic-dataset-v1",
	}
}

// cos is a small deterministic cosine helper, the test data must not depend on a random source.
func cos(x float64) float64 {
	return sin(x + 1.5707963267948966)
}

// sin is a small deterministic sine helper, the test data must not depend on a random source.
func sin(x float64) float64 {
	// Taylor free implementation with a fixed period: it only has to be deterministic.
	const twoPi = 6.283185307179586
	phase := x
	phase = phase - float64(int(phase/twoPi))*twoPi
	// Bhaskara approximation, deterministic and smooth enough for the test data.
	return 16 * phase * (3.141592653589793 - phase) / (5*3.141592653589793 - 4*phase*(3.141592653589793-phase))
}

// volatilityDataset builds a dataset that contains a deterministic volatility compression
// followed by a breakout, so the compression candidate is exercised with real signals.
func volatilityDataset(t *testing.T) Dataset {
	t.Helper()

	d := researchDataset(t)
	start := d.Bars[0].OpenTime
	interval := 5 * time.Minute

	bars := make([]Bar, 0, 200)
	for i := 0; i < 200; i++ {
		openTime := start.Add(time.Duration(i) * interval)
		high := 100.5
		low := 99.5
		close := 100.0

		switch {
		case i < 130:
			// Wide range.
		case i < 150:
			// Narrow range: the volatility contracts.
			high = 100.05
			low = 99.95
		case i == 150:
			// The breakout bar: the close leaves the previous window.
			high = 100.2
			low = 100.0
			close = 100.2
		default:
			high = 100.25
			low = 100.15
			close = 100.2
		}

		bars = append(bars, Bar{
			Symbol:    "BTCUSDT",
			Interval:  "5",
			OpenTime:  openTime,
			CloseTime: openTime.Add(interval),
			KnownAt:   openTime.Add(interval),
			Open:      decimal.FromFloat64(100),
			High:      decimal.FromFloat64(high),
			Low:       decimal.FromFloat64(low),
			Close:     decimal.FromFloat64(close),
			Volume:    decimal.FromFloat64(100),
		})
	}

	d.Bars = bars
	return d
}

// datasetFor returns the dataset that must be used to exercise a candidate.
func datasetFor(t *testing.T, id string) Dataset {
	t.Helper()

	if id == "volatility_compression_breakout" {
		return volatilityDataset(t)
	}
	return researchDataset(t)
}

func TestEveryCandidatePassesTheLookAheadControls(t *testing.T) {
	cfg := DefaultEvaluationConfig()

	for _, entry := range Registry() {
		c := entry.Build()
		d := datasetFor(t, entry.ID)

		checks, err := RunLookAheadControls(c, d, cfg)
		if err != nil {
			t.Fatalf("candidate %s: %v", entry.ID, err)
		}
		if len(checks.Failures) > 0 {
			t.Fatalf("candidate %s failed the look-ahead controls: %v", entry.ID, checks.Failures)
		}
		if checks.CausalChecksRun == 0 || checks.CausalChecksPassed != checks.CausalChecksRun {
			t.Fatalf("candidate %s: %d of %d causal checks passed", entry.ID, checks.CausalChecksPassed, checks.CausalChecksRun)
		}
		if !checks.NegativeControlRan || !checks.NegativeControlOK {
			t.Fatalf("candidate %s: the negative control did not detect the future only feature", entry.ID)
		}
		if checks.PreviousBarChecks == 0 || checks.PreviousBarPassed != checks.PreviousBarChecks {
			t.Fatalf("candidate %s: %d of %d previous bar checks passed", entry.ID, checks.PreviousBarPassed, checks.PreviousBarChecks)
		}

		// The controls are only meaningful when the candidate actually produces decisions.
		signals, err := c.Generate(d)
		if err != nil {
			t.Fatalf("candidate %s: %v", entry.ID, err)
		}
		if len(signals) == 0 {
			t.Fatalf("candidate %s produced no signal: the look-ahead controls would be vacuous", entry.ID)
		}
	}
}

func TestTheCausalTestDetectsAFutureOnlyFeature(t *testing.T) {
	d := researchDataset(t)
	cutoff := d.Bars[len(d.Bars)/2].CloseTime

	leaky := &LeakyCandidate{IDValue: "negative_control_future_only"}
	if err := CausalInvarianceCheck(leaky, d, cutoff); err == nil {
		t.Fatal("the causal test must FAIL for a candidate that reads the next bar")
	}
	if err := NegativeControlDetectsLeakage(leaky, d, cutoff); err != nil {
		t.Fatalf("the negative control must be detected: %v", err)
	}
}

func TestHigherTimeframeBarsAreUsedOnlyAfterTheyClosed(t *testing.T) {
	d := researchDataset(t)
	c := NewMultiTimeframeTrendFollowing(DefaultMultiTimeframeConfig())

	base, err := c.Generate(d)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	// Append a higher timeframe bar that closes in the future of every decision and carries a
	// completely different price. A correct candidate must not be able to see it.
	futureHTF := d.HTFBars[len(d.HTFBars)-1]
	futureHTF.CloseTime = futureHTF.CloseTime.Add(24 * time.Hour)
	futureHTF.KnownAt = futureHTF.CloseTime
	futureHTF.Open = decimal.FromFloat64(9999)
	futureHTF.High = decimal.FromFloat64(10000)
	futureHTF.Low = decimal.FromFloat64(9998)
	futureHTF.Close = decimal.FromFloat64(9999)

	withFuture := d
	withFuture.HTFBars = append(append([]Bar{}, d.HTFBars...), futureHTF)

	mutated, err := c.Generate(withFuture)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if len(base) != len(mutated) {
		t.Fatalf("a future higher timeframe bar changed the signal count: %d -> %d", len(base), len(mutated))
	}
	for i := range base {
		if SignalKey(base[i]) != SignalKey(mutated[i]) {
			t.Fatalf("a future higher timeframe bar changed the signal at bar %d", base[i].DecisionBar)
		}
		if base[i].Features["htf_close_time"] == futureHTF.CloseTime.UTC().Format("2006-01-02T15:04:05Z") {
			t.Fatal("the future higher timeframe bar was used")
		}
	}
}

func TestPreviousBarCutoffDetectsALookingAheadCandidate(t *testing.T) {
	d := researchDataset(t)
	leaky := &LeakyCandidate{IDValue: "negative_control_future_only"}

	if err := PreviousBarCutoffCheck(leaky, d, 100); err == nil {
		t.Fatal("the previous bar cutoff check must FAIL for a candidate that reads the next bar")
	}
}
