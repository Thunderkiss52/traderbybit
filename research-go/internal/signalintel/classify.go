package signalintel

import (
	"context"
	"encoding/json"
	"fmt"
	"math"
	"math/big"
	"sort"
)

// CohortDatasetHash covers the exact supplied observation order, with object
// keys sorted recursively to match the cross-language dataset identity contract.
func CohortDatasetHash(observations []Observation) (string, error) {
	encoded, err := json.Marshal(observations)
	if err != nil {
		return "", err
	}
	var canonical interface{}
	if err := json.Unmarshal(encoded, &canonical); err != nil {
		return "", err
	}
	return hash(canonical), nil
}

// DefaultQualitySettings provides conservative research defaults, never entry authority.
func DefaultQualitySettings() QualitySettings {
	return QualitySettings{Version: QualityPolicy, MinIndependentSamples: 100, MinDistinctDays: 20,
		Alpha: .05, MultipleTests: 1, EmbargoMS: 0, ReturnLowerBound: "-1", ReturnUpperBound: "1", NeutralBand: "0.000001"}
}

func Classify(ctx context.Context, input CohortRequest) (CohortResponse, error) {
	result := CohortResponse{Scope: input.Scope, PolicyVersion: input.Policy.Version, PolicyHash: hash(input.Policy), InputHash: hash(input),
		DescriptiveMetrics: unavailableDescriptiveMetrics("INVALID_DATA"),
		Classification:     "INVALID_DATA", ResearchOnly: true, EntryAuthority: false,
		UncertaintyMethod: "BOUNDED_HOEFFDING_FAMILYWISE", MetricUnit: "NET_RETURN_ON_EXECUTED_NOTIONAL",
		StatisticalAssumptions: StatisticalAssumptions{
			ConfidenceInterpretation: "CONDITIONAL_ON_DECLARED_ASSUMPTIONS",
			Required:                 []string{"INDEPENDENT_OBSERVATIONS", "PREREGISTERED_RETURN_BOUNDS", "PREDECLARED_TEST_FAMILY", "FIXED_SAMPLE_WITHOUT_OPTIONAL_STOPPING", "REPRESENTATIVE_TARGET_POPULATION"},
			SampleCountMeaning:       "NONOVERLAPPING_OBSERVATIONS_AFTER_EMBARGO",
		}}
	if err := ctx.Err(); err != nil {
		return result, err
	}
	p := input.Policy
	low, okL := number(p.ReturnLowerBound, true)
	high, okH := number(p.ReturnUpperBound, true)
	neutral, okN := number(p.NeutralBand, false)
	if !scopeValid(input.Scope) || input.AsOfMS <= 0 || input.AsOfMS > MaxTimestampMS || p.Version != QualityPolicy || !okL || !okH || !okN ||
		low.Cmp(high) >= 0 || p.MinIndependentSamples < 2 || p.MinIndependentSamples > MaxObservations ||
		p.MinDistinctDays < 1 || p.MinDistinctDays > MaxObservations || p.EmbargoMS < 0 || p.EmbargoMS > 30*1440*MinuteMS ||
		math.IsNaN(p.Alpha) || math.IsInf(p.Alpha, 0) || p.Alpha <= 0 || p.Alpha > .25 || p.MultipleTests < 1 || p.MultipleTests > 1000000 ||
		len(input.Observations) > MaxObservations {
		result.Reason = "INVALID_COHORT_OR_POLICY"
		return result, nil
	}
	result.AdjustedAlpha = p.Alpha / float64(p.MultipleTests)
	observations := append([]Observation(nil), input.Observations...)
	sort.Slice(observations, func(i, j int) bool {
		if observations[i].AvailableMS == observations[j].AvailableMS {
			return observations[i].ID < observations[j].ID
		}
		return observations[i].AvailableMS < observations[j].AvailableMS
	})
	seen := map[string]bool{}
	days := map[int64]bool{}
	lastEnd := int64(0)
	var accepted []Observation
	var acceptedReturns []*big.Rat
	var mean, m2 float64
	wins := 0
	for _, o := range observations {
		if err := ctx.Err(); err != nil {
			return result, err
		}
		net, ok := number(o.NetReturn, true)
		if !identifierPattern.MatchString(o.ID) || !hashPattern.MatchString(o.InputHash) || seen[o.ID] || o.Scope != input.Scope || !excursionsValid(o) ||
			o.Status != "MATURED" || !ok || o.AvailableMS <= 0 || o.AvailableMS > MaxTimestampMS-int64(input.Scope.HorizonMinutes)*MinuteMS || o.EndMS > input.AsOfMS ||
			o.EndMS != o.AvailableMS+int64(input.Scope.HorizonMinutes)*MinuteMS || net.Cmp(low) < 0 || net.Cmp(high) > 0 {
			result.Reason = "INVALID_OR_UNMATURED_OBSERVATION"
			return result, nil
		}
		seen[o.ID] = true
		result.TotalObservations++
		if len(accepted) > 0 && o.AvailableMS < lastEnd+p.EmbargoMS {
			result.PurgedObservations++
			continue
		}
		value, _ := net.Float64()
		if math.IsNaN(value) || math.IsInf(value, 0) {
			result.Reason = "NONFINITE_RETURN"
			return result, nil
		}
		accepted = append(accepted, o)
		acceptedReturns = append(acceptedReturns, net)
		lastEnd = o.EndMS
		days[o.AvailableMS/(1440*MinuteMS)] = true
		n := float64(len(accepted))
		delta := value - mean
		mean += delta / n
		m2 += delta * (value - mean)
		if value > 0 {
			wins++
		}
	}
	result.IndependentSamples = len(accepted)
	result.DistinctDays = len(days)
	result.DescriptiveMetrics = describeNetReturns(acceptedReturns)
	addObservationMetrics(&result.DescriptiveMetrics, accepted)
	if len(accepted) < p.MinIndependentSamples || len(days) < p.MinDistinctDays {
		result.Classification = "INSUFFICIENT_DATA"
		result.Reason = "INDEPENDENT_SAMPLE_OR_DAY_MINIMUM_NOT_MET"
		return result, nil
	}
	lo, _ := low.Float64()
	hi, _ := high.Float64()
	band, _ := neutral.Float64()
	radius := (hi - lo) * math.Sqrt(math.Log(2/result.AdjustedAlpha)/(2*float64(len(accepted))))
	lcb, ucb := math.Max(lo, mean-radius), math.Min(hi, mean+radius)
	se := math.Sqrt(math.Max(0, m2) / (float64(len(accepted)-1) * float64(len(accepted))))
	winRate := float64(wins) / float64(len(accepted))
	result.MeanNetReturn = &mean
	result.LowerBound = &lcb
	result.UpperBound = &ucb
	result.StandardError = &se
	result.WinRate = &winRate
	result.Classification = "NEUTRAL"
	result.Reason = "CONFIDENCE_INTERVAL_INCLUDES_ZERO"
	if lcb > band {
		result.Classification = "PROFITABLE_CONFIRMED"
		result.Reason = "POSITIVE_BOUNDED_NET_RETURN_INTERVAL"
	} else if ucb < -band {
		result.Classification = "UNPROFITABLE_CONFIRMED"
		result.Reason = "NEGATIVE_BOUNDED_NET_RETURN_INTERVAL"
	} else if mean > band {
		result.Classification = "PROMISING"
		result.Reason = "POSITIVE_MEAN_WITH_UNRESOLVED_UNCERTAINTY"
	}
	if input.Baseline != nil {
		b := input.Baseline
		if b.Scope != input.Scope || b.PolicyVersion != p.Version || b.PolicyHash != result.PolicyHash || !hashPattern.MatchString(b.InputHash) || b.EndMS <= 0 || b.EndMS >= accepted[0].AvailableMS ||
			accepted[0].AvailableMS < b.EndMS+p.EmbargoMS ||
			b.IndependentSamples < p.MinIndependentSamples || b.DistinctDays < p.MinDistinctDays || math.IsNaN(b.LowerBound) || math.IsInf(b.LowerBound, 0) || b.LowerBound < lo || b.LowerBound > hi {
			result.Classification = "INVALID_DATA"
			result.Reason = "INVALID_OR_OVERLAPPING_BASELINE"
			result.DescriptiveMetrics = unavailableDescriptiveMetrics("INVALID_DATA")
			return result, nil
		}
		if b.Classification == "PROFITABLE_CONFIRMED" && b.LowerBound > band && ucb < b.LowerBound {
			result.Classification = "DRIFT_DETECTED"
			result.Reason = "CURRENT_INTERVAL_BELOW_INDEPENDENT_PRIOR_LOWER_BOUND"
		}
	}
	return result, nil
}

func Run(ctx context.Context, input Request) (Response, error) {
	result := Response{SchemaVersion: 1}
	switch input.Operation {
	case "evaluate":
		if input.Evaluation == nil || input.Cohort != nil {
			return result, fmt.Errorf("EVALUATION_REQUEST_REQUIRED")
		}
		evaluation, err := Evaluate(ctx, *input.Evaluation)
		result.Evaluation = &evaluation
		return result, err
	case "classify":
		if input.Cohort == nil || input.Evaluation != nil {
			return result, fmt.Errorf("COHORT_REQUEST_REQUIRED")
		}
		cohort, err := Classify(ctx, *input.Cohort)
		result.Cohort = &cohort
		return result, err
	default:
		return result, fmt.Errorf("UNSUPPORTED_SIGNAL_INTELLIGENCE_OPERATION")
	}
}
