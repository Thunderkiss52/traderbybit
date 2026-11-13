package signalintel

import (
	"context"
	"fmt"
	"math"
	"reflect"
	"strings"
	"testing"
)

func evaluationFixture() EvaluationRequest {
	start := int64(1704067200000)
	r := EvaluationRequest{Signal: Signal{ID: "signal-1", Exchange: "bybit", Category: "linear", Symbol: "BTCUSDT", StrategyID: "baseline", StrategyVersion: "1.0.0", SourceTimeframeMinutes: 15, DecisionMS: start, AvailableMS: start, FeatureAvailableMS: start, Direction: "BUY", ReferencePrice: "100", Regime: "UNCERTAIN"},
		AsOfMS: start + 60*MinuteMS, HorizonsMinutes: []int{1, 3, 5, 15, 30, 60}, Notional: "5",
		Instrument: Instrument{Exchange: "bybit", Category: "linear", Symbol: "BTCUSDT", AvailableMS: start, TickSize: "0.01", QuantityStep: "0.001", MinQuantity: "0.001", MaxQuantity: "1000", MinNotional: "2"},
		Costs:      Costs{SourceID: "test-cost-v1", TakerRate: "0.0005", SlippageRate: "0.001", FundingComplete: true, FundingScheduleID: "test-eight-hours", FundingIntervalMinutes: 480, FundingCoverageFromMS: start, FundingCoverageToMS: start + 60*MinuteMS},
		Provenance: Provenance{DatasetID: "isolated-test", DatasetHash: strings.Repeat("a", 64), ConfigurationHash: strings.Repeat("b", 64), CodeRevision: strings.Repeat("c", 40)}}
	for i := 0; i < 60; i++ {
		r.Candles = append(r.Candles, Candle{Exchange: "bybit", Category: "linear", Symbol: "BTCUSDT", OpenMS: start + int64(i)*MinuteMS, CloseMS: start + int64(i+1)*MinuteMS, Closed: true, Open: "100", High: "162", Low: "99", Close: fmt.Sprint(101 + i)})
	}
	return r
}

func TestEverySourceTimeframeAndHorizonPreserveDistinctAttribution(t *testing.T) {
	for _, source := range []int{1, 3, 5, 15, 30, 60} {
		r := evaluationFixture()
		r.Signal.SourceTimeframeMinutes = source
		result, err := Evaluate(context.Background(), r)
		if err != nil {
			t.Fatal(err)
		}
		if len(result.Outcomes) != 6 || !result.ResearchOnly || result.EntryAuthority {
			t.Fatal(result)
		}
		for i, o := range result.Outcomes {
			if o.Status != "MATURED" || o.Scope.SourceTimeframeMinutes != source || o.Scope.HorizonMinutes != r.HorizonsMinutes[i] || o.Source != "HYPOTHETICAL" || o.ExecutionModel != "HORIZON_MARKOUT" {
				t.Fatalf("%+v", o)
			}
			n, _ := positive(*o.ExecutedNotional)
			limit, _ := positive("5")
			if n.Cmp(limit) > 0 {
				t.Fatal("exposure was increased")
			}
			gross, _ := number(*o.GrossPnL, true)
			fees, _ := number(*o.Fees, false)
			funding, _ := number(*o.Funding, true)
			net, _ := number(*o.NetPnL, true)
			if add(sub(gross, fees), funding).Cmp(net) != 0 {
				t.Fatal("persisted accounting identity failed")
			}
		}
		if *result.Outcomes[0].NetPnL == *result.Outcomes[5].NetPnL {
			t.Fatal("horizons were conflated")
		}
	}
}

func TestMissingFutureForeignOrInvalidEvidenceCannotProduceMoney(t *testing.T) {
	cases := map[string]func(*EvaluationRequest){
		"future_feature":         func(r *EvaluationRequest) { r.Signal.FeatureAvailableMS++ },
		"future_signal":          func(r *EvaluationRequest) { r.Signal.AvailableMS = r.AsOfMS + MinuteMS },
		"future_instrument":      func(r *EvaluationRequest) { r.Instrument.AvailableMS++ },
		"foreign_instrument":     func(r *EvaluationRequest) { r.Instrument.Exchange = "binance" },
		"foreign_candle":         func(r *EvaluationRequest) { r.Candles[0].Exchange = "binance" },
		"open_candle":            func(r *EvaluationRequest) { r.Candles[0].Closed = false },
		"future_candle":          func(r *EvaluationRequest) { r.AsOfMS = r.Signal.AvailableMS },
		"missing_fee":            func(r *EvaluationRequest) { r.Costs.TakerRate = "" },
		"funding_unknown":        func(r *EvaluationRequest) { r.Costs.FundingComplete = false },
		"dataset_missing":        func(r *EvaluationRequest) { r.Provenance.DatasetHash = "" },
		"bad_ohlc":               func(r *EvaluationRequest) { r.Candles[0].Low = "200" },
		"duplicate_candle":       func(r *EvaluationRequest) { r.Candles[1] = r.Candles[0] },
		"subminute_availability": func(r *EvaluationRequest) { r.Signal.AvailableMS++ },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			r := evaluationFixture()
			mutate(&r)
			rep, err := Evaluate(context.Background(), r)
			if err != nil {
				t.Fatal(err)
			}
			for _, o := range rep.Outcomes {
				if o.Status != "INVALID_DATA" || o.NetPnL != nil || o.Fees != nil || o.Quantity != nil {
					t.Fatalf("invalid evidence earned money: %+v", o)
				}
			}
		})
	}
}

func TestInteriorGapAndPendingMaturityRemainExplicit(t *testing.T) {
	r := evaluationFixture()
	r.Candles = append(r.Candles[:10], r.Candles[11:]...)
	result, err := Evaluate(context.Background(), r)
	if err != nil {
		t.Fatal(err)
	}
	if result.Outcomes[0].Status != "MATURED" || result.Outcomes[5].Reason != "MISSING_CANDLE" || result.Outcomes[5].NetPnL != nil {
		t.Fatal(result)
	}
	r = evaluationFixture()
	r.HorizonsMinutes = []int{60}
	r.AsOfMS = r.Signal.AvailableMS + 30*MinuteMS
	r.Candles = r.Candles[:30]
	r.Costs.FundingCoverageToMS = r.AsOfMS
	result, err = Evaluate(context.Background(), r)
	if err != nil || result.Outcomes[0].Status != "PENDING" || result.Outcomes[0].NetPnL != nil {
		t.Fatal(result, err)
	}
}

func TestFiveUSDTMinimumConstraintNeverIncreasesExposure(t *testing.T) {
	r := evaluationFixture()
	r.Instrument.MinNotional = "10"
	result, err := Evaluate(context.Background(), r)
	if err != nil {
		t.Fatal(err)
	}
	for _, o := range result.Outcomes {
		if o.Status != "UNFILLABLE" || o.Reason != "BELOW_MINIMUM_NOTIONAL" || o.NetPnL != nil {
			t.Fatal(o)
		}
	}
}

func TestFlatPriceLosesAfterCostsAndDoesNotDoubleSubtractSlippage(t *testing.T) {
	r := evaluationFixture()
	r.HorizonsMinutes = []int{1}
	r.Candles = r.Candles[:1]
	r.Candles[0].Close = "100"
	result, err := Evaluate(context.Background(), r)
	if err != nil {
		t.Fatal(err)
	}
	o := result.Outcomes[0]
	net, _ := number(*o.NetPnL, true)
	if net.Sign() >= 0 || *o.NetProfitable {
		t.Fatal("fees/slippage were ignored")
	}
	entry, _ := positive(*o.EntryPrice)
	exit, _ := positive(*o.ExitPrice)
	q, _ := positive(*o.Quantity)
	fees, _ := number(*o.Fees, false)
	want := sub(mul(q, sub(exit, entry)), fees)
	want, _ = number(want.FloatString(12), true)
	if want.Cmp(net) != 0 {
		t.Fatal("slippage was deducted twice")
	}
}

func TestFundingHasSideCorrectSignAndMissingSettlementFailsClosed(t *testing.T) {
	for _, side := range []string{"BUY", "SELL"} {
		r := evaluationFixture()
		old := r.Signal.AvailableMS
		start := old + 479*MinuteMS
		r.Signal.DecisionMS = start
		r.Signal.AvailableMS = start
		r.Signal.FeatureAvailableMS = start
		r.Instrument.AvailableMS = start
		r.Signal.Direction = side
		r.AsOfMS = start + MinuteMS
		r.HorizonsMinutes = []int{1}
		r.Candles = r.Candles[:1]
		r.Candles[0].OpenMS = start
		r.Candles[0].CloseMS = start + MinuteMS
		r.Costs.FundingCoverageFromMS = start
		r.Costs.FundingCoverageToMS = r.AsOfMS
		r.Costs.FundingEvents = []FundingEvent{{Exchange: "bybit", Category: "linear", Symbol: "BTCUSDT", AtMS: r.AsOfMS, Rate: "0.01", MarkPrice: "100"}}
		result, err := Evaluate(context.Background(), r)
		if err != nil {
			t.Fatal(err)
		}
		o := result.Outcomes[0]
		if o.Status != "MATURED" {
			t.Fatal(o)
		}
		funding, _ := number(*o.Funding, true)
		if (side == "BUY" && funding.Sign() >= 0) || (side == "SELL" && funding.Sign() <= 0) {
			t.Fatal("wrong funding sign")
		}
		r.Costs.FundingEvents = nil
		result, err = Evaluate(context.Background(), r)
		if err != nil || result.Outcomes[0].Reason != "MISSING_FUNDING_EVENT" || result.Outcomes[0].NetPnL != nil {
			t.Fatal(result, err)
		}
	}
}

func TestEvaluationReproducibilityProvenanceAndCancellation(t *testing.T) {
	r := evaluationFixture()
	a, err := Evaluate(context.Background(), r)
	if err != nil {
		t.Fatal(err)
	}
	b, _ := Evaluate(context.Background(), r)
	if !reflect.DeepEqual(a, b) {
		t.Fatal("nondeterministic outcomes")
	}
	r.Provenance.ConfigurationHash = strings.Repeat("d", 64)
	b, _ = Evaluate(context.Background(), r)
	if a.Outcomes[0].InputHash == b.Outcomes[0].InputHash {
		t.Fatal("changed configuration reused evidence")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, err = Evaluate(ctx, r); err != context.Canceled {
		t.Fatal(err)
	}
	r.Candles = make([]Candle, MaxCandles+1)
	if _, err = Evaluate(context.Background(), r); err == nil {
		t.Fatal("unbounded input accepted")
	}
}

func cohortFixture(value string) CohortRequest {
	scope := newOutcome(evaluationFixture(), 1, "").Scope
	p := DefaultQualitySettings()
	p.MinIndependentSamples = 10
	p.MinDistinctDays = 10
	p.ReturnLowerBound = "-0.01"
	p.ReturnUpperBound = "0.01"
	r := CohortRequest{Scope: scope, Policy: p, AsOfMS: 1704067200000 + 100*1440*MinuteMS}
	for i := 0; i < 30; i++ {
		start := int64(1704067200000) + int64(i)*1440*MinuteMS
		r.Observations = append(r.Observations, Observation{ID: fmt.Sprintf("outcome-%d", i), InputHash: fmt.Sprintf("%064x", i+1), Scope: scope, Status: "MATURED", AvailableMS: start, EndMS: start + MinuteMS, NetReturn: value})
	}
	return r
}

func TestCohortSevenClassificationsAndPurging(t *testing.T) {
	for value, want := range map[string]string{"0.009": "PROFITABLE_CONFIRMED", "-0.009": "UNPROFITABLE_CONFIRMED", "0": "NEUTRAL", "0.0001": "PROMISING"} {
		r := cohortFixture(value)
		rep, err := Classify(context.Background(), r)
		if err != nil || rep.Classification != want || rep.EntryAuthority || !rep.ResearchOnly {
			t.Fatalf("%s: %+v %v", want, rep, err)
		}
	}
	r := cohortFixture("-0.009")
	r.Observations = r.Observations[:2]
	rep, _ := Classify(context.Background(), r)
	if rep.Classification != "INSUFFICIENT_DATA" || rep.MeanNetReturn != nil {
		t.Fatal(rep)
	}
	r = cohortFixture("0.009")
	r.Observations[1].Scope.Exchange = "binance"
	rep, _ = Classify(context.Background(), r)
	if rep.Classification != "INVALID_DATA" {
		t.Fatal(rep)
	}
	r = cohortFixture("0.009")
	duplicate := r.Observations[0]
	duplicate.ID = "overlap"
	r.Observations = append(r.Observations, duplicate)
	rep, _ = Classify(context.Background(), r)
	if rep.PurgedObservations != 1 || rep.IndependentSamples != 30 {
		t.Fatal(rep)
	}
	r = cohortFixture("-0.009")
	r.Baseline = &Baseline{Scope: r.Scope, PolicyVersion: r.Policy.Version, PolicyHash: hash(r.Policy), InputHash: strings.Repeat("e", 64), Classification: "PROFITABLE_CONFIRMED", IndependentSamples: 30, DistinctDays: 30, EndMS: r.Observations[0].AvailableMS - MinuteMS, LowerBound: .005}
	rep, _ = Classify(context.Background(), r)
	if rep.Classification != "DRIFT_DETECTED" {
		t.Fatal(rep)
	}
}

func TestCohortLeakageBoundsMultiplicityAndCancellation(t *testing.T) {
	r := cohortFixture("0.009")
	a, _ := Classify(context.Background(), r)
	b, _ := Classify(context.Background(), r)
	if !reflect.DeepEqual(a, b) {
		t.Fatal("classification not reproducible")
	}
	r.Policy.MultipleTests = 10000
	b, _ = Classify(context.Background(), r)
	if *b.LowerBound >= *a.LowerBound {
		t.Fatal("multiple testing did not increase uncertainty")
	}
	r = cohortFixture("0.009")
	r.AsOfMS = r.Observations[0].AvailableMS
	b, _ = Classify(context.Background(), r)
	if b.Classification != "INVALID_DATA" {
		t.Fatal("future labels accepted")
	}
	r = cohortFixture("0.02")
	b, _ = Classify(context.Background(), r)
	if b.Classification != "INVALID_DATA" {
		t.Fatal("unregistered out-of-bound returns accepted")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, err := Classify(ctx, r); err != context.Canceled {
		t.Fatal(err)
	}
}

func TestExecutionAssumptionsAndHorizonAnchorSeparateCohorts(t *testing.T) {
	original := evaluationFixture()
	base := newOutcome(original, 1, "").Scope
	if base.HorizonAnchor != "AVAILABILITY" || !hashPattern.MatchString(base.ExecutionHash) {
		t.Fatal("default execution identity is missing", base)
	}
	cases := map[string]func(*EvaluationRequest){
		"notional":         func(r *EvaluationRequest) { r.Notional = "6" },
		"tick":             func(r *EvaluationRequest) { r.Instrument.TickSize = "0.1" },
		"quantity_step":    func(r *EvaluationRequest) { r.Instrument.QuantityStep = "0.01" },
		"minimum_quantity": func(r *EvaluationRequest) { r.Instrument.MinQuantity = "0.01" },
		"maximum_quantity": func(r *EvaluationRequest) { r.Instrument.MaxQuantity = "2000" },
		"minimum_notional": func(r *EvaluationRequest) { r.Instrument.MinNotional = "3" },
		"cost_source":      func(r *EvaluationRequest) { r.Costs.SourceID = "other-costs" },
		"fee":              func(r *EvaluationRequest) { r.Costs.TakerRate = "0.0006" },
		"slippage":         func(r *EvaluationRequest) { r.Costs.SlippageRate = "0.002" },
		"funding_schedule": func(r *EvaluationRequest) { r.Costs.FundingScheduleID = "other-schedule" },
		"funding_interval": func(r *EvaluationRequest) { r.Costs.FundingIntervalMinutes = 240 },
		"funding_offset":   func(r *EvaluationRequest) { r.Costs.FundingOffsetMinutes = 1 },
		"horizon_anchor":   func(r *EvaluationRequest) { r.HorizonAnchor = "NEXT_MINUTE_OPEN" },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			r := evaluationFixture()
			mutate(&r)
			scope := newOutcome(r, 1, "").Scope
			if scope == base || scope.ExecutionHash == base.ExecutionHash {
				t.Fatal("changed execution assumptions reused cohort identity", scope)
			}
			cohort := cohortFixture("0.009")
			cohort.Observations[0].Scope = scope
			result, err := Classify(context.Background(), cohort)
			if err != nil || result.Classification != "INVALID_DATA" || result.Reason != "INVALID_OR_UNMATURED_OBSERVATION" {
				t.Fatal("mixed execution assumptions were classified", result, err)
			}
		})
	}
	// Equivalent decimal notation and per-observation changes preserve cohort identity.
	r := evaluationFixture()
	r.Notional = ""
	r.HorizonAnchor = "AVAILABILITY"
	r.Instrument.TickSize = "0.0100"
	r.Signal.ID = "signal-2"
	r.Signal.DecisionMS += MinuteMS
	r.Signal.AvailableMS += MinuteMS
	r.Instrument.AvailableMS += MinuteMS
	r.AsOfMS += MinuteMS
	r.Candles[0].Close = "102"
	r.Costs.FundingCoverageToMS += MinuteMS
	r.Costs.FundingEvents = []FundingEvent{{AtMS: r.AsOfMS, Rate: "0.01"}}
	r.Provenance.DatasetHash = strings.Repeat("f", 64)
	if got := newOutcome(r, 1, "").Scope; got != base {
		t.Fatal("observation-specific data split equivalent execution assumptions", got, base)
	}
}

func TestCohortRequiresExecutionIdentity(t *testing.T) {
	for _, field := range []string{"execution_hash", "horizon_anchor"} {
		r := cohortFixture("0.009")
		if field == "execution_hash" {
			r.Scope.ExecutionHash = ""
		} else {
			r.Scope.HorizonAnchor = ""
		}
		result, err := Classify(context.Background(), r)
		if err != nil || result.Classification != "INVALID_DATA" || result.Reason != "INVALID_COHORT_OR_POLICY" {
			t.Fatal(field, result, err)
		}
	}
}

func TestNextMinuteAnchorPreservesSourceTimeframeAndUsesCausalMinuteOpen(t *testing.T) {
	for _, delay := range []int64{0, 1, MinuteMS - 1} {
		r := evaluationFixture()
		r.HorizonAnchor = "NEXT_MINUTE_OPEN"
		r.HorizonsMinutes = []int{1}
		r.Signal.AvailableMS += delay
		result, err := Evaluate(context.Background(), r)
		if err != nil {
			t.Fatal(err)
		}
		out := result.Outcomes[0]
		wantEntry := r.Signal.DecisionMS
		if delay > 0 {
			wantEntry += MinuteMS
		}
		if out.Status != "MATURED" || out.EntryMS != wantEntry || out.EndMS != wantEntry+MinuteMS ||
			out.EntryMS < r.Signal.AvailableMS || out.Scope.SourceTimeframeMinutes != 15 ||
			out.Scope.HorizonMinutes != 1 || out.Scope.HorizonAnchor != r.HorizonAnchor {
			t.Fatal("minute resolution changed signal attribution or used an earlier open", out)
		}
	}
}

func TestBaselineRequiresIdenticalFullQualityPolicy(t *testing.T) {
	cases := map[string]func(*QualitySettings){
		"minimum_samples": func(p *QualitySettings) { p.MinIndependentSamples++ },
		"minimum_days":    func(p *QualitySettings) { p.MinDistinctDays++ },
		"alpha":           func(p *QualitySettings) { p.Alpha = .01 },
		"multiplicity":    func(p *QualitySettings) { p.MultipleTests = 10 },
		"embargo":         func(p *QualitySettings) { p.EmbargoMS = MinuteMS },
		"lower_bound":     func(p *QualitySettings) { p.ReturnLowerBound = "-0.02" },
		"upper_bound":     func(p *QualitySettings) { p.ReturnUpperBound = "0.02" },
		"neutral_band":    func(p *QualitySettings) { p.NeutralBand = "0.000002" },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			r := cohortFixture("-0.009")
			r.Baseline = &Baseline{Scope: r.Scope, PolicyVersion: r.Policy.Version, PolicyHash: hash(r.Policy),
				InputHash: strings.Repeat("e", 64), Classification: "PROFITABLE_CONFIRMED", IndependentSamples: 30,
				DistinctDays: 30, EndMS: r.Observations[0].AvailableMS - MinuteMS, LowerBound: .005}
			mutate(&r.Policy)
			result, err := Classify(context.Background(), r)
			if err != nil || result.Classification != "INVALID_DATA" || result.Reason != "INVALID_OR_OVERLAPPING_BASELINE" {
				t.Fatal("baseline from another statistical policy was accepted", result, err)
			}
			if result.PolicyHash != hash(r.Policy) || result.PolicyHash == r.Baseline.PolicyHash {
				t.Fatal("quality policy identity did not capture changed settings", result.PolicyHash)
			}
		})
	}
	r := cohortFixture("-0.009")
	r.Baseline = &Baseline{Scope: r.Scope, PolicyVersion: r.Policy.Version, InputHash: strings.Repeat("e", 64),
		Classification: "PROFITABLE_CONFIRMED", IndependentSamples: 30, DistinctDays: 30,
		EndMS: r.Observations[0].AvailableMS - MinuteMS, LowerBound: .005}
	result, err := Classify(context.Background(), r)
	if err != nil || result.Classification != "INVALID_DATA" || result.Reason != "INVALID_OR_OVERLAPPING_BASELINE" {
		t.Fatal("baseline without policy identity was accepted", result, err)
	}
}

func TestBaselineEnforcesEmbargoBeforeCurrentCohort(t *testing.T) {
	for _, gap := range []int64{MinuteMS - 1, MinuteMS, MinuteMS + 1} {
		r := cohortFixture("-0.009")
		r.Policy.EmbargoMS = MinuteMS
		r.Baseline = &Baseline{Scope: r.Scope, PolicyVersion: r.Policy.Version, PolicyHash: hash(r.Policy),
			InputHash: strings.Repeat("e", 64), Classification: "PROFITABLE_CONFIRMED", IndependentSamples: 30,
			DistinctDays: 30, EndMS: r.Observations[0].AvailableMS - gap, LowerBound: .005}
		result, err := Classify(context.Background(), r)
		if err != nil {
			t.Fatal(err)
		}
		if gap < r.Policy.EmbargoMS {
			if result.Classification != "INVALID_DATA" || result.Reason != "INVALID_OR_OVERLAPPING_BASELINE" {
				t.Fatal("baseline inside embargo was accepted", result)
			}
		} else if result.Classification != "DRIFT_DETECTED" {
			t.Fatal("nonoverlapping baseline at or beyond embargo was rejected", result)
		}
	}
}

func TestUnsafeSignalTimestampsFailBeforeOutcomeDerivation(t *testing.T) {
	for _, anchor := range []string{"AVAILABILITY", "NEXT_MINUTE_OPEN"} {
		for _, timestamp := range []int64{math.MinInt64, -1, 0, MaxTimestampMS, MaxTimestampMS + 1, math.MaxInt64} {
			r := evaluationFixture()
			r.HorizonAnchor = anchor
			r.Signal.AvailableMS = timestamp
			result, err := Evaluate(context.Background(), r)
			if err == nil || err.Error() != "UNSUPPORTED_SIGNAL_TIMESTAMP" || len(result.Outcomes) != 0 {
				t.Fatal("unsafe timestamp produced derived outcomes", timestamp, anchor, result, err)
			}
			out := newOutcome(r, 1, "")
			if out.EntryMS != 0 || out.EndMS != 0 {
				t.Fatal("direct derivation wrapped or fabricated unsupported timestamps", out)
			}
		}
	}
	// Ceil-to-minute plus a horizon must fit, even when availability itself fits.
	r := evaluationFixture()
	r.HorizonAnchor = "NEXT_MINUTE_OPEN"
	r.HorizonsMinutes = []int{1}
	r.Signal.AvailableMS = MaxTimestampMS - MinuteMS + 1
	if _, err := Evaluate(context.Background(), r); err == nil {
		t.Fatal("minute alignment exceeded the supported timestamp window")
	}
	for _, anchor := range []string{"AVAILABILITY", "NEXT_MINUTE_OPEN"} {
		entry, end, ok := horizonTimes(MaxTimestampMS-MinuteMS, 1, anchor)
		if !ok || entry != MaxTimestampMS-MinuteMS || end != MaxTimestampMS {
			t.Fatal("last representable minute was rejected", anchor, entry, end, ok)
		}
	}
}

func TestOverflowedCandleCloseCannotMasqueradeAsClosedHistoricalData(t *testing.T) {
	r := evaluationFixture()
	candle := r.Candles[len(r.Candles)-1]
	candle.OpenMS = int64(math.MaxInt64) - int64(math.MaxInt64)%MinuteMS
	candle.CloseMS = candle.OpenMS + MinuteMS // Reproduces a wrapped external timestamp.
	r.Candles = append(r.Candles, candle)
	result, err := Evaluate(context.Background(), r)
	if err != nil {
		t.Fatal(err)
	}
	for _, out := range result.Outcomes {
		if out.Status != "INVALID_DATA" || out.Reason != "OPEN_OR_FUTURE_CANDLE" || out.NetPnL != nil {
			t.Fatal("overflowed future candle produced mature evidence", out)
		}
	}
}

func TestCohortConfidenceRemainsConditionalAfterPurging(t *testing.T) {
	for _, value := range []string{"0.009", "-0.009", "0"} {
		r := cohortFixture(value)
		result, err := Classify(context.Background(), r)
		if err != nil {
			t.Fatal(err)
		}
		a := result.StatisticalAssumptions
		if a.ConfidenceInterpretation != "CONDITIONAL_ON_DECLARED_ASSUMPTIONS" ||
			a.SampleCountMeaning != "NONOVERLAPPING_OBSERVATIONS_AFTER_EMBARGO" ||
			a.IndependenceVerified || a.PurgingEstablishesIndependence || a.PreregistrationVerified ||
			!result.ResearchOnly || result.EntryAuthority {
			t.Fatal("purging implied verified statistical assumptions or trading authority", result)
		}
		want := []string{"INDEPENDENT_OBSERVATIONS", "PREREGISTERED_RETURN_BOUNDS", "PREDECLARED_TEST_FAMILY", "FIXED_SAMPLE_WITHOUT_OPTIONAL_STOPPING", "REPRESENTATIVE_TARGET_POPULATION"}
		if !reflect.DeepEqual(a.Required, want) {
			t.Fatal("confidence assumptions omitted", a.Required)
		}
	}
}
