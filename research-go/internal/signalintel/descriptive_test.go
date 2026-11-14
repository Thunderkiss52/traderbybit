package signalintel

import (
	"context"
	"encoding/json"
	"math/big"
	"reflect"
	"testing"
)

func assertProfitFactorUnavailable(t *testing.T, result CohortResponse) {
	t.Helper()
	if result.ProfitFactor != nil || result.DescriptiveMetrics.ProfitFactor != nil ||
		result.DescriptiveMetrics.UnavailableReasons["profit_factor"] != "OBSERVATION_PNL_AMOUNTS_NOT_PROVIDED" {
		t.Fatalf("return-only observations published a PnL profit factor: %+v", result)
	}
}

func TestCohortNeverConfusesReturnRatioWithPnLProfitFactor(t *testing.T) {
	input := cohortFixture("0.009")
	for i := len(input.Observations) / 2; i < len(input.Observations); i++ {
		input.Observations[i].NetReturn = "-0.009"
	}
	result, err := Classify(context.Background(), input)
	if err != nil || result.Classification != "NEUTRAL" || result.WinRate == nil || *result.WinRate != 0.5 {
		t.Fatal(result, err)
	}
	// Equal return magnitudes imply a return ratio of 1, but winning and losing
	// $100 and $5 notionals imply a PnL ratio of 20. Neither notional is in the
	// observation contract, so the PnL ratio cannot be inferred.
	assertProfitFactorUnavailable(t, result)

	input.Policy.Version = "SIGNAL_QUALITY_HOEFFDING_V1"
	legacy, err := Classify(context.Background(), input)
	if err != nil || legacy.Classification != "INVALID_DATA" || legacy.Reason != "INVALID_COHORT_OR_POLICY" || legacy.ProfitFactor != nil {
		t.Fatal("old policy packet accepted under changed classifier behavior", legacy, err)
	}
}

func TestCohortDescriptiveMetricsDoNotRequireClassificationSampleSize(t *testing.T) {
	input := cohortFixture("0")
	input.Observations = input.Observations[:3]
	input.Observations[0].NetReturn = "0.009"
	input.Observations[1].NetReturn = "-0.003"
	result, err := Classify(context.Background(), input)
	if err != nil || result.Classification != "INSUFFICIENT_DATA" || result.MeanNetReturn != nil || result.WinRate != nil ||
		result.LowerBound != nil || result.UpperBound != nil || result.EntryAuthority || !result.ResearchOnly {
		t.Fatal("descriptions changed conditional classification", result, err)
	}
	metrics := result.DescriptiveMetrics
	if metrics.Status != "OBSERVED" || metrics.ObservedCount != 3 || metrics.Winners != 1 || metrics.Losers != 1 || metrics.ZeroReturns != 1 {
		t.Fatal(metrics)
	}
	assertReturnMetric(t, metrics.MeanWinner, "0.009000000000")
	assertReturnMetric(t, metrics.MeanLoser, "-0.003000000000")
	assertReturnMetric(t, metrics.NetExpectancy, "0.002000000000")
	assertProfitFactorUnavailable(t, result)
}

func TestCohortEmptyDescriptionHasUnavailableMetrics(t *testing.T) {
	input := cohortFixture("0")
	input.Observations = nil
	result, err := Classify(context.Background(), input)
	metrics := result.DescriptiveMetrics
	if err != nil || result.Classification != "INSUFFICIENT_DATA" || metrics.Status != "NO_OBSERVATIONS" || metrics.ObservedCount != 0 ||
		metrics.NetExpectancy != nil || metrics.NetReturnPerSignal != nil || metrics.ProfitFactor != nil || metrics.Quantiles.P50 != nil || len(metrics.Distribution) != 0 {
		t.Fatal(result, err)
	}
	for _, name := range []string{"mean_winner", "mean_loser", "net_expectancy", "net_return_per_signal", "profit_factor", "quantiles", "distribution"} {
		if metrics.UnavailableReasons[name] != "NO_OBSERVATIONS" {
			t.Fatal("missing empty-population reason", name, metrics)
		}
	}
}

func TestCohortDescriptiveMetricsUseOnlyPurgedAcceptedObservations(t *testing.T) {
	input := cohortFixture("0.009")
	truth, falsity := true, false
	small, large := "0.001", "9"
	for i := range input.Observations {
		input.Observations[i].DirectionalCorrect = &truth
		input.Observations[i].MFERatio = &small
		input.Observations[i].MAERatio = &small
	}
	before, err := Classify(context.Background(), input)
	if err != nil {
		t.Fatal(err)
	}
	overlap := input.Observations[0]
	overlap.ID, overlap.NetReturn = "zz-overlap", "-0.009"
	overlap.DirectionalCorrect, overlap.MFERatio, overlap.MAERatio = &falsity, &large, &large
	input.Observations = append(input.Observations, overlap)
	after, err := Classify(context.Background(), input)
	if err != nil || after.PurgedObservations != 1 || !reflect.DeepEqual(before.DescriptiveMetrics, after.DescriptiveMetrics) {
		t.Fatal("purged loss changed the descriptive sample", after, err)
	}
	assertProfitFactorUnavailable(t, after)
}

func TestOptionalObservationMetricsReportSeparateCoverage(t *testing.T) {
	input := cohortFixture("0.009")
	input.Observations = input.Observations[:3]
	truth, falsity := true, false
	zero, one, two := "0", "0.01", "0.02"
	input.Observations[0].DirectionalCorrect, input.Observations[0].MFERatio, input.Observations[0].MAERatio = &truth, &two, &zero
	input.Observations[1].DirectionalCorrect, input.Observations[1].MAERatio = &falsity, &one
	input.Observations[2].MFERatio = &one
	result, err := Classify(context.Background(), input)
	metrics := result.DescriptiveMetrics
	if err != nil || result.Classification != "INSUFFICIENT_DATA" || metrics.DirectionalObservedCount != 2 || metrics.MFEObservedCount != 2 || metrics.MAEObservedCount != 2 ||
		metrics.DirectionalAccuracy == nil || *metrics.DirectionalAccuracy != 0.5 {
		t.Fatal("optional missing fields became zeros or false labels", result, err)
	}
	assertReturnMetric(t, metrics.MeanMFERatio, "0.015000000000")
	assertReturnMetric(t, metrics.MeanMAERatio, "0.005000000000")
	for _, name := range []string{"directional_accuracy", "mean_mfe_ratio", "mean_mae_ratio"} {
		if _, exists := metrics.UnavailableReasons[name]; exists {
			t.Fatal("available metric retains an unavailable reason", name, metrics)
		}
	}
	// All three positive net returns do not establish all three price directions.
	if metrics.Winners != 3 || metrics.DirectionalObservedCount == metrics.ObservedCount {
		t.Fatal(metrics)
	}
}

func TestInvalidOptionalExcursionsRejectCohortWithoutPartialMetrics(t *testing.T) {
	for _, field := range []string{"mfe_ratio", "mae_ratio"} {
		for _, malformed := range []string{"-0.001", "NaN", "Inf", "", "1e-3"} {
			t.Run(field+"/"+malformed, func(t *testing.T) {
				input := cohortFixture("0.009")
				value := malformed
				if field == "mfe_ratio" {
					input.Observations[29].MFERatio = &value
				} else {
					input.Observations[29].MAERatio = &value
				}
				result, err := Classify(context.Background(), input)
				if err != nil || result.Classification != "INVALID_DATA" || result.DescriptiveMetrics.Status != "INVALID_DATA" ||
					result.DescriptiveMetrics.ObservedCount != 0 || result.DescriptiveMetrics.MeanMFERatio != nil || result.DescriptiveMetrics.MeanMAERatio != nil {
					t.Fatal("malformed excursion published evidence", result, err)
				}
			})
		}
	}
}

func TestOptionalObservationFieldsPreserveAbsentWireContractAndBindPresentEvidence(t *testing.T) {
	input := cohortFixture("0.009")
	input.Observations = input.Observations[:1]
	before, err := Classify(context.Background(), input)
	if err != nil {
		t.Fatal(err)
	}
	encoded, err := json.Marshal(input.Observations[0])
	if err != nil {
		t.Fatal(err)
	}
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(encoded, &fields); err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"directional_correct", "mfe_ratio", "mae_ratio"} {
		if _, present := fields[name]; present {
			t.Fatal("absent optional field changed the legacy wire contract", name)
		}
	}
	falseValue, zero := false, "0"
	input.Observations[0].DirectionalCorrect, input.Observations[0].MFERatio = &falseValue, &zero
	after, err := Classify(context.Background(), input)
	if err != nil || after.InputHash == before.InputHash || after.PolicyHash != before.PolicyHash ||
		after.DescriptiveMetrics.DirectionalObservedCount != 1 || after.DescriptiveMetrics.DirectionalAccuracy == nil || *after.DescriptiveMetrics.DirectionalAccuracy != 0 ||
		after.DescriptiveMetrics.MFEObservedCount != 1 || after.DescriptiveMetrics.MAEObservedCount != 0 || after.DescriptiveMetrics.MeanMAERatio != nil {
		t.Fatal("optional zero/false evidence was lost or did not bind the request", after, err)
	}
	assertReturnMetric(t, after.DescriptiveMetrics.MeanMFERatio, "0.000000000000")
}

func TestCohortInvalidDescriptionNeverPublishesAPartialSample(t *testing.T) {
	for name, change := range map[string]func(*CohortRequest){
		"duplicate_id":     func(input *CohortRequest) { input.Observations = append(input.Observations, input.Observations[0]) },
		"foreign_scope":    func(input *CohortRequest) { input.Observations[29].Scope.Exchange = "binance" },
		"malformed_return": func(input *CohortRequest) { input.Observations[29].NetReturn = "NaN" },
		"future_outcome":   func(input *CohortRequest) { input.AsOfMS = input.Observations[29].EndMS - 1 },
		"immature_outcome": func(input *CohortRequest) { input.Observations[29].Status = "PENDING" },
		"out_of_bounds":    func(input *CohortRequest) { input.Observations[29].NetReturn = "0.02" },
		"invalid_baseline": func(input *CohortRequest) { input.Baseline = &Baseline{} },
	} {
		t.Run(name, func(t *testing.T) {
			input := cohortFixture("0.009")
			change(&input)
			result, err := Classify(context.Background(), input)
			metrics := result.DescriptiveMetrics
			if err != nil || result.Classification != "INVALID_DATA" || metrics.Status != "INVALID_DATA" || metrics.ObservedCount != 0 ||
				metrics.NetExpectancy != nil || metrics.Quantiles.Min != nil || len(metrics.Distribution) != 0 {
				t.Fatal("invalid cohort published descriptive evidence", result, err)
			}
		})
	}
}

func TestCohortDescriptionUsesNetOutcomeAfterCosts(t *testing.T) {
	evaluation := evaluationFixture()
	evaluation.HorizonsMinutes = []int{1}
	evaluation.Candles = evaluation.Candles[:1]
	evaluation.Candles[0].Close = "100"
	outcomes, err := Evaluate(context.Background(), evaluation)
	if err != nil {
		t.Fatal(err)
	}
	outcome := outcomes.Outcomes[0]
	if outcome.NetReturn == nil || outcome.Status != "MATURED" {
		t.Fatal(outcome)
	}
	input := cohortFixture("0")
	input.Observations = input.Observations[:1]
	input.Observations[0].NetReturn = *outcome.NetReturn
	result, err := Classify(context.Background(), input)
	metrics := result.DescriptiveMetrics
	if err != nil || result.Classification != "INSUFFICIENT_DATA" || metrics.ObservedCount != 1 || metrics.Winners != 0 || metrics.Losers != 1 || metrics.ZeroReturns != 0 {
		t.Fatal("flat price after costs was described as a winner", result, err)
	}
	assertReturnMetric(t, metrics.NetExpectancy, *outcome.NetReturn)
	assertReturnMetric(t, metrics.MeanLoser, *outcome.NetReturn)
	assertReturnMetric(t, metrics.Quantiles.P50, *outcome.NetReturn)
	assertProfitFactorUnavailable(t, result)
	if metrics.MeanWinner != nil || metrics.UnavailableReasons["mean_winner"] != "NO_POSITIVE_RETURNS" {
		t.Fatal(metrics)
	}
}

func TestCohortDescriptiveExpectancyIsNotHitRate(t *testing.T) {
	input := cohortFixture("0.009")
	input.Observations = input.Observations[:2]
	input.Observations[1].NetReturn = "-0.001"
	smallLoss, _ := Classify(context.Background(), input)
	input.Observations[1].NetReturn = "-0.009"
	largeLoss, _ := Classify(context.Background(), input)
	if smallLoss.DescriptiveMetrics.Winners != largeLoss.DescriptiveMetrics.Winners ||
		smallLoss.DescriptiveMetrics.Losers != largeLoss.DescriptiveMetrics.Losers {
		t.Fatal("negative control changed the hit rate")
	}
	assertReturnMetric(t, smallLoss.DescriptiveMetrics.NetExpectancy, "0.004000000000")
	assertReturnMetric(t, largeLoss.DescriptiveMetrics.NetExpectancy, "0.000000000000")
	assertProfitFactorUnavailable(t, smallLoss)
	assertProfitFactorUnavailable(t, largeLoss)
}

func TestCohortDescriptionDoesNotInventPredictionOrCalibrationData(t *testing.T) {
	result, err := Classify(context.Background(), cohortFixture("0.009"))
	if err != nil {
		t.Fatal(err)
	}
	encoded, err := json.Marshal(result)
	if err != nil {
		t.Fatal(err)
	}
	var response map[string]json.RawMessage
	if err := json.Unmarshal(encoded, &response); err != nil {
		t.Fatal(err)
	}
	var metrics map[string]json.RawMessage
	if err := json.Unmarshal(response["descriptive_metrics"], &metrics); err != nil {
		t.Fatal("missing additive descriptive object", err)
	}
	for _, name := range []string{"precision", "false_positive_rate", "directional_accuracy", "brier_score", "log_loss", "expected_calibration_error", "mean_mfe_ratio", "mean_mae_ratio"} {
		if string(metrics[name]) != "null" || result.DescriptiveMetrics.UnavailableReasons[name] == "" {
			t.Fatal("a net return became an unavailable metric", name, string(metrics[name]))
		}
	}
	if result.EntryAuthority || !result.ResearchOnly || result.DescriptiveMetrics.UnavailableReasons["profit_factor"] != "OBSERVATION_PNL_AMOUNTS_NOT_PROVIDED" {
		t.Fatal("description granted authority or invented profit factor", result)
	}
}

func TestCohortDescriptionHonorsEmbargoAndIsReproducible(t *testing.T) {
	input := cohortFixture("0.009")
	input.Observations = input.Observations[:3]
	start := input.Observations[0].AvailableMS
	for i := range input.Observations {
		input.Observations[i].AvailableMS = start + int64(i)*MinuteMS
		input.Observations[i].EndMS = start + int64(i+1)*MinuteMS
	}
	input.Observations[1].NetReturn = "-0.009"
	input.Observations[2].NetReturn = "0.003"
	input.Policy.EmbargoMS = MinuteMS
	before, err := Classify(context.Background(), input)
	if err != nil || before.PurgedObservations != 1 || before.DescriptiveMetrics.ObservedCount != 2 || before.DescriptiveMetrics.Losers != 0 {
		t.Fatal(before, err)
	}
	assertReturnMetric(t, before.DescriptiveMetrics.NetExpectancy, "0.006000000000")
	input.Observations[0], input.Observations[2] = input.Observations[2], input.Observations[0]
	after, err := Classify(context.Background(), input)
	if err != nil || !reflect.DeepEqual(before.DescriptiveMetrics, after.DescriptiveMetrics) {
		t.Fatal("shuffling changed a chronological descriptive cohort", after, err)
	}
	a, _ := json.Marshal(before.DescriptiveMetrics)
	b, _ := json.Marshal(after.DescriptiveMetrics)
	if string(a) != string(b) {
		t.Fatal("descriptive JSON is not deterministic")
	}
}

func TestCohortDescriptionDistinguishesObservedZeroFromUnavailable(t *testing.T) {
	input := cohortFixture("0")
	input.Observations = input.Observations[:1]
	result, err := Classify(context.Background(), input)
	metrics := result.DescriptiveMetrics
	if err != nil || metrics.Status != "OBSERVED" || metrics.ZeroReturns != 1 || metrics.Winners != 0 || metrics.Losers != 0 ||
		metrics.MeanWinner != nil || metrics.MeanLoser != nil || metrics.ProfitFactor != nil {
		t.Fatal(result, err)
	}
	assertReturnMetric(t, metrics.NetExpectancy, "0.000000000000")
	assertReturnMetric(t, metrics.NetReturnPerSignal, "0.000000000000")
	assertReturnMetric(t, metrics.Quantiles.Min, "0.000000000000")
	assertReturnMetric(t, metrics.Quantiles.Max, "0.000000000000")
	if metrics.Distribution[2].Count != 1 || metrics.UnavailableReasons["profit_factor"] != "OBSERVATION_PNL_AMOUNTS_NOT_PROVIDED" {
		t.Fatal(metrics)
	}
}

func TestDescriptiveNetReturnsHaveExactUnitsMeansAndDistribution(t *testing.T) {
	values := []*big.Rat{big.NewRat(2, 100), big.NewRat(-1, 100), big.NewRat(0, 1), big.NewRat(-2, 100), big.NewRat(1, 100)}
	result := describeNetReturns(values)
	if result.Status != "OBSERVED" || result.MetricUnit != "NET_RETURN_ON_EXECUTED_NOTIONAL" || result.ObservedCount != 5 ||
		result.Winners != 2 || result.Losers != 2 || result.ZeroReturns != 1 {
		t.Fatal(result)
	}
	assertReturnMetric(t, result.MeanWinner, "0.015000000000")
	assertReturnMetric(t, result.MeanLoser, "-0.015000000000")
	assertReturnMetric(t, result.NetExpectancy, "0.000000000000")
	assertReturnMetric(t, result.NetReturnPerSignal, "0.000000000000")
	if result.ProfitFactor != nil || result.UnavailableReasons["profit_factor"] != "OBSERVATION_PNL_AMOUNTS_NOT_PROVIDED" {
		t.Fatal("return-only observations cannot establish PnL profit factor", result)
	}
	assertReturnMetric(t, result.Quantiles.P05, "-0.018000000000")
	assertReturnMetric(t, result.Quantiles.P25, "-0.010000000000")
	assertReturnMetric(t, result.Quantiles.P50, "0.000000000000")
	assertReturnMetric(t, result.Quantiles.P75, "0.010000000000")
	assertReturnMetric(t, result.Quantiles.P95, "0.018000000000")
	for _, bin := range result.Distribution {
		if bin.Count != 1 {
			t.Fatal("boundary return was missed or counted twice", result.Distribution)
		}
	}
	if values[0].Cmp(big.NewRat(2, 100)) != 0 || values[1].Cmp(big.NewRat(-1, 100)) != 0 {
		t.Fatal("description changed input values or order")
	}
	reversed := []*big.Rat{values[4], values[3], values[2], values[1], values[0]}
	if !reflect.DeepEqual(result, describeNetReturns(reversed)) {
		t.Fatal("descriptive arithmetic depended on input order")
	}
}

func assertReturnMetric(t *testing.T, value *string, want string) {
	t.Helper()
	if value == nil || *value != want {
		t.Fatalf("return metric %v, want %s", value, want)
	}
}
