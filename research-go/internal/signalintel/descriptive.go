package signalintel

import (
	"math/big"
	"sort"
)

const descriptiveVersion = "SIGNAL_NET_RETURN_DESCRIPTIVE_V2"

func unavailableDescriptiveMetrics(reason string) DescriptiveMetrics {
	return DescriptiveMetrics{
		Version: descriptiveVersion, Status: reason,
		MetricUnit: "NET_RETURN_ON_EXECUTED_NOTIONAL", Population: "NONOVERLAPPING_MATURED_OBSERVATIONS_AFTER_EMBARGO",
		Quantiles: ReturnQuantiles{Method: "LINEAR_INTERPOLATION_TYPE_7"}, Distribution: []ReturnBin{},
		UnavailableReasons: map[string]string{
			"mean_winner": reason, "mean_loser": reason, "net_expectancy": reason,
			"net_return_per_signal": reason, "profit_factor": reason, "quantiles": reason, "distribution": reason,
			"precision":                  "PREDICTION_TARGET_AND_BINARY_DECISIONS_NOT_PROVIDED",
			"false_positive_rate":        "LABELED_NEGATIVE_PREDICTIONS_NOT_PROVIDED",
			"directional_accuracy":       "PRICE_DIRECTION_OUTCOMES_NOT_PROVIDED",
			"mean_mfe_ratio":             "MFE_NOT_PROVIDED",
			"mean_mae_ratio":             "MAE_NOT_PROVIDED",
			"brier_score":                "DECISION_TIME_TARGET_PROBABILITIES_NOT_PROVIDED",
			"log_loss":                   "DECISION_TIME_TARGET_PROBABILITIES_NOT_PROVIDED",
			"expected_calibration_error": "DECISION_TIME_TARGET_PROBABILITIES_NOT_PROVIDED",
		},
	}
}

func excursionsValid(observation Observation) bool {
	for _, value := range []*string{observation.MFERatio, observation.MAERatio} {
		if value != nil {
			if _, ok := number(*value, false); !ok {
				return false
			}
		}
	}
	return true
}

// Optional outcomes share the same accepted population; each metric reports its
// actual coverage so missing evidence never becomes an observed false or zero.
func addObservationMetrics(result *DescriptiveMetrics, observations []Observation) {
	correct := 0
	mfe, mae := new(big.Rat), new(big.Rat)
	for _, observation := range observations {
		if observation.DirectionalCorrect != nil {
			result.DirectionalObservedCount++
			if *observation.DirectionalCorrect {
				correct++
			}
		}
		if observation.MFERatio != nil {
			value, _ := number(*observation.MFERatio, false)
			mfe.Add(mfe, value)
			result.MFEObservedCount++
		}
		if observation.MAERatio != nil {
			value, _ := number(*observation.MAERatio, false)
			mae.Add(mae, value)
			result.MAEObservedCount++
		}
	}
	if result.DirectionalObservedCount > 0 {
		accuracy := float64(correct) / float64(result.DirectionalObservedCount)
		result.DirectionalAccuracy = &accuracy
		delete(result.UnavailableReasons, "directional_accuracy")
	}
	if result.MFEObservedCount > 0 {
		result.MeanMFERatio = decimalResult(div(mfe, big.NewRat(int64(result.MFEObservedCount), 1)))
		delete(result.UnavailableReasons, "mean_mfe_ratio")
	}
	if result.MAEObservedCount > 0 {
		result.MeanMAERatio = decimalResult(div(mae, big.NewRat(int64(result.MAEObservedCount), 1)))
		delete(result.UnavailableReasons, "mean_mae_ratio")
	}
}

// The caller supplies only completely validated, purged observations. This
// bounded descriptive population does not establish statistical independence.
func describeNetReturns(values []*big.Rat) DescriptiveMetrics {
	result := unavailableDescriptiveMetrics("NO_OBSERVATIONS")
	if len(values) == 0 {
		return result
	}
	result.Status = "OBSERVED"
	result.ObservedCount = len(values)
	for _, metric := range []string{"net_expectancy", "net_return_per_signal", "quantiles", "distribution"} {
		delete(result.UnavailableReasons, metric)
	}
	bound := func(value string) *string { return &value }
	result.Distribution = []ReturnBin{
		{UpperBound: bound("-0.01")},
		{LowerBound: bound("-0.01"), UpperBound: bound("0"), IncludeLower: true},
		{LowerBound: bound("0"), UpperBound: bound("0"), IncludeLower: true, IncludeUpper: true},
		{LowerBound: bound("0"), UpperBound: bound("0.01"), IncludeUpper: true},
		{LowerBound: bound("0.01")},
	}
	sum, winners, losers := new(big.Rat), new(big.Rat), new(big.Rat)
	minusOnePercent, onePercent := big.NewRat(-1, 100), big.NewRat(1, 100)
	for _, value := range values {
		sum.Add(sum, value)
		switch value.Sign() {
		case 1:
			result.Winners++
			winners.Add(winners, value)
		case -1:
			result.Losers++
			losers.Add(losers, value)
		default:
			result.ZeroReturns++
		}
		bin := 4
		switch {
		case value.Cmp(minusOnePercent) < 0:
			bin = 0
		case value.Sign() < 0:
			bin = 1
		case value.Sign() == 0:
			bin = 2
		case value.Cmp(onePercent) <= 0:
			bin = 3
		}
		result.Distribution[bin].Count++
	}
	result.NetExpectancy = decimalResult(div(sum, big.NewRat(int64(len(values)), 1)))
	result.NetReturnPerSignal = decimalResult(div(sum, big.NewRat(int64(len(values)), 1)))
	result.UnavailableReasons["mean_winner"] = "NO_POSITIVE_RETURNS"
	result.UnavailableReasons["mean_loser"] = "NO_NEGATIVE_RETURNS"
	// Normalized returns do not determine aggregate currency PnL when executed
	// notionals differ. Never present their ratio as a PnL profit factor.
	result.UnavailableReasons["profit_factor"] = "OBSERVATION_PNL_AMOUNTS_NOT_PROVIDED"
	if result.Winners > 0 {
		result.MeanWinner = decimalResult(div(winners, big.NewRat(int64(result.Winners), 1)))
		delete(result.UnavailableReasons, "mean_winner")
	}
	if result.Losers > 0 {
		result.MeanLoser = decimalResult(div(losers, big.NewRat(int64(result.Losers), 1)))
		delete(result.UnavailableReasons, "mean_loser")
	}
	ordered := append([]*big.Rat(nil), values...)
	sort.Slice(ordered, func(i, j int) bool { return ordered[i].Cmp(ordered[j]) < 0 })
	result.Quantiles = ReturnQuantiles{Method: "LINEAR_INTERPOLATION_TYPE_7",
		Min: decimalResult(ordered[0]), P05: returnQuantile(ordered, 5), P25: returnQuantile(ordered, 25),
		P50: returnQuantile(ordered, 50), P75: returnQuantile(ordered, 75), P95: returnQuantile(ordered, 95),
		Max: decimalResult(ordered[len(ordered)-1])}
	return result
}

func returnQuantile(ordered []*big.Rat, percentile int) *string {
	position := (len(ordered) - 1) * percentile
	index, remainder := position/100, position%100
	if remainder == 0 {
		return decimalResult(ordered[index])
	}
	return decimalResult(add(ordered[index], mul(sub(ordered[index+1], ordered[index]), big.NewRat(int64(remainder), 100))))
}
