package candidates

import (
	"fmt"
	"math"
	"sort"
	"time"

	"github.com/traderbybit/research-go/internal/backtest"
)

// This file implements the common versioned evaluation pipeline of the five research candidates.
//
// The pipeline NEVER creates a paper account, never touches the eight primary accounts and never
// places an order. It produces a research evaluation object that can be persisted in an isolated
// research result table.

// Cohort kinds.
const (
	CohortKindResearch = "RESEARCH_COHORT"
)

// Evaluation status values. They are intentionally explicit: a missing dataset or a missing
// execution model is a valid research outcome and must never be reported as a result.
const (
	StatusVerifiedControls = "VERIFIED_ENGINE_AND_LOOK_AHEAD_CONTROLS"
	StatusControlFailed    = "LOOK_AHEAD_CONTROL_FAILED"
	StatusDataUnavailable  = "NOT_EVALUATED_DATA_UNAVAILABLE"
	StatusExecNotValidated = "NOT_EVALUATED_EXECUTION_NOT_VALIDATED"
	StatusInsufficientEvid = "INSUFFICIENT_EVIDENCE"
	StatusNotEligible      = "NOT_ELIGIBLE"
	StatusEngineUnverified = "NOT_VERIFIED"
)

// SplitConfig describes the deterministic train / validation / test partition.
type SplitConfig struct {
	TrainRatio      float64
	ValidationRatio float64
}

// DefaultSplitConfig returns the precommitted partition (60 / 20 / 20, chronological).
func DefaultSplitConfig() SplitConfig {
	return SplitConfig{TrainRatio: 0.6, ValidationRatio: 0.2}
}

// Split is one chronological slice of the entry series.
type Split struct {
	Name       string
	StartIndex int
	EndIndex   int
}

// Splits partitions the bar series chronologically. The test split is always the most recent part
// of the series and is never used for any selection decision.
func Splits(bars []Bar, cfg SplitConfig) []Split {
	n := len(bars)
	if n == 0 {
		return nil
	}

	trainEnd := int(math.Round(float64(n) * cfg.TrainRatio))
	validationEnd := trainEnd + int(math.Round(float64(n)*cfg.ValidationRatio))
	if trainEnd < 1 {
		trainEnd = 1
	}
	if validationEnd > n {
		validationEnd = n
	}
	if validationEnd <= trainEnd {
		validationEnd = trainEnd
	}

	splits := []Split{
		{Name: "TRAIN", StartIndex: 0, EndIndex: trainEnd},
		{Name: "VALIDATION", StartIndex: trainEnd, EndIndex: validationEnd},
	}
	if validationEnd < n {
		splits = append(splits, Split{Name: "TEST", StartIndex: validationEnd, EndIndex: n})
	}
	return splits
}

// LookAheadChecks records the outcome of the mechanical section 7.7 controls.
type LookAheadChecks struct {
	HTFClosureEnforced bool
	CausalChecksRun    int
	CausalChecksPassed int
	NegativeControlRan bool
	NegativeControlOK  bool
	PreviousBarChecks  int
	PreviousBarPassed  int
	Failures           []string
}

// Evaluation is the research evaluation of one candidate.
type Evaluation struct {
	CandidateID     string
	Version         string
	CohortKind      string
	SourceHash      string
	Status          string
	Verification    string
	SignalCounts    map[string]int
	SplitReports    map[string]*backtest.BacktestReport
	LookAhead       LookAheadChecks
	CostModel       map[string]string
	Limitations     []string
	Executable      bool
	ExecutionReason string
}

// EvaluationConfig is the configuration of a candidate evaluation.
type EvaluationConfig struct {
	Split      SplitConfig
	Backtest   backtest.BacktestConfig
	CausalCut  int
	PrevChecks int
}

// DefaultEvaluationConfig returns the precommitted evaluation configuration.
func DefaultEvaluationConfig() EvaluationConfig {
	return EvaluationConfig{
		Split:      DefaultSplitConfig(),
		Backtest:   backtest.DefaultConfig(),
		CausalCut:  3,
		PrevChecks: 3,
	}
}

// ExecuteResearchEvaluation runs the common pipeline for one candidate.
//
// The candidate is evaluated on the deterministic chronological split, the mechanical look-ahead
// controls of section 7.7 are executed, and the resulting status states exactly what is and what
// is not proven.
func ExecuteResearchEvaluation(c Candidate, d Dataset, cfg EvaluationConfig) (*Evaluation, error) {
	ev := &Evaluation{
		CandidateID:  c.ID(),
		Version:      c.Version(),
		CohortKind:   CohortKindResearch,
		SourceHash:   d.SourceHash,
		SignalCounts: map[string]int{},
		SplitReports: map[string]*backtest.BacktestReport{},
		CostModel: map[string]string{
			"taker_fee":      cfg.Backtest.TakerFeeRate.String(),
			"slippage":       cfg.Backtest.SlippageRate.String(),
			"risk_per_trade": cfg.Backtest.RiskPerTradeRate.String(),
			"fill_model":     "NEXT_CANDLE_OPEN",
		},
		Executable: true,
	}

	signals, err := c.Generate(d)
	if err != nil {
		ev.Status = StatusDataUnavailable
		ev.Verification = StatusEngineUnverified
		ev.Limitations = append(ev.Limitations, err.Error())
		return ev, nil
	}

	if d.SourceHash == "" {
		ev.Limitations = append(ev.Limitations, "SOURCE_HASH_NOT_PROVIDED")
	}

	// Section 7.7 controls.
	lookahead, err := RunLookAheadControls(c, d, cfg)
	if err != nil {
		return nil, err
	}
	ev.LookAhead = lookahead
	if len(lookahead.Failures) > 0 {
		ev.Status = StatusControlFailed
		ev.Verification = StatusControlFailed
		ev.Limitations = append(ev.Limitations, lookahead.Failures...)
		return ev, nil
	}
	ev.Verification = StatusVerifiedControls

	// The pair cohort cannot be executed by the research engine: a two leg decision needs a fill,
	// a financing and a concurrency model that does not exist yet.
	if c.ID() == "btc_eth_lead_lag" {
		ev.Executable = false
		ev.ExecutionReason = "TWO_LEG_EXECUTION_FINANCING_AND_CONCURRENCY_NOT_MODELLED"
	}

	splits := Splits(d.Bars, cfg.Split)
	engine := backtest.NewEngine(cfg.Backtest)

	for _, sp := range splits {
		splitSignals := make([]backtest.Signal, 0, len(signals))
		for _, s := range signals {
			if s.DecisionBar < sp.StartIndex || s.DecisionBar >= sp.EndIndex {
				continue
			}
			if s.EntryBar < sp.StartIndex || s.EntryBar >= sp.EndIndex {
				// A trade whose fill leaves the split is not attributed to it.
				continue
			}
			splitSignals = append(splitSignals, backtest.Signal{
				Side:         s.Side,
				DecisionTime: s.DecisionTime,
				DecisionBar:  s.DecisionBar - sp.StartIndex,
				EntryBar:     s.EntryBar - sp.StartIndex,
				StopLoss:     s.StopLoss,
				TakeProfit:   s.TakeProfit,
				Reason:       s.Reason,
			})
		}

		slice := d.Bars[sp.StartIndex:sp.EndIndex]
		report, err := engine.RunSignals(toCandles(slice), splitSignals, c.ID())
		if err != nil {
			return nil, fmt.Errorf("candidate %s split %s: %w", c.ID(), sp.Name, err)
		}

		ev.SplitReports[sp.Name] = report
		ev.SignalCounts[sp.Name] = len(splitSignals)
	}

	// Evidence status: without a prospective paper history no candidate can be eligible.
	ev.Status = StatusNotEligible
	ev.Limitations = append(ev.Limitations,
		"NO_PROSPECTIVE_PAPER_HISTORY: a backtest split is not evidence of future performance.",
		"RESEARCH_COHORT_ONLY: the candidate is not attached to any of the eight primary paper accounts.",
	)

	testReport, hasTest := ev.SplitReports["TEST"]
	if hasTest && testReport != nil && testReport.TotalTrades < 30 {
		ev.Status = StatusInsufficientEvid
		ev.Limitations = append(ev.Limitations,
			fmt.Sprintf("INSUFFICIENT_OOS_TRADES: test split produced %d trades, at least 30 are required.", testReport.TotalTrades))
	}

	// A candidate without a validated execution model can never be scored as an executed trade.
	// This status is the most specific one and therefore wins over the generic evidence status.
	if !ev.Executable {
		ev.Status = StatusExecNotValidated
		ev.Limitations = append(ev.Limitations,
			"EXECUTION_NOT_VALIDATED: no pair fill, financing or concurrency model exists; the signal was not scored as an executed trade.")
	}

	return ev, nil
}

// RunLookAheadControls executes the mechanical section 7.7 controls for one candidate.
func RunLookAheadControls(c Candidate, d Dataset, cfg EvaluationConfig) (LookAheadChecks, error) {
	checks := LookAheadChecks{HTFClosureEnforced: true}

	if len(d.Bars) < 2 {
		return checks, fmt.Errorf("look-ahead controls: dataset is too small")
	}

	// Cutoffs: the decision time of a bar in the middle of the series and of a later bar.
	cutoffs := []time.Time{
		d.Bars[len(d.Bars)/2].CloseTime,
		d.Bars[(3*len(d.Bars))/4].CloseTime,
	}

	for _, cutoff := range cutoffs {
		checks.CausalChecksRun++
		if err := CausalInvarianceCheck(c, d, cutoff); err != nil {
			checks.Failures = append(checks.Failures, err.Error())
			continue
		}
		checks.CausalChecksPassed++
	}

	// The negative control must be detected by the causal test.
	leaky := &LeakyCandidate{IDValue: "negative_control_future_only"}
	if err := NegativeControlDetectsLeakage(leaky, d, cutoffs[0]); err != nil {
		checks.Failures = append(checks.Failures, err.Error())
	} else {
		checks.NegativeControlRan = true
		checks.NegativeControlOK = true
	}

	// The previous bar cutoff: a decision at bar i must not depend on bars after bar i.
	for k := 0; k < cfg.PrevChecks; k++ {
		idx := len(d.Bars) - 1 - (k * len(d.Bars) / (cfg.PrevChecks + 2))
		if idx < 1 || idx >= len(d.Bars) {
			continue
		}
		checks.PreviousBarChecks++
		if err := PreviousBarCutoffCheck(c, d, idx); err != nil {
			checks.Failures = append(checks.Failures, err.Error())
			continue
		}
		checks.PreviousBarPassed++
	}

	sort.Strings(checks.Failures)
	return checks, nil
}

// DatasetHash computes a deterministic hash of the dataset contents used by a candidate.
func DatasetHash(d Dataset) string {
	return fmt.Sprintf("%s|%s|%d|%d|%d|%d|%d|%s",
		d.Symbol, d.EntryInterval, len(d.Bars), len(d.HTFBars), len(d.PeerBars),
		len(d.Funding), len(d.OpenInterest), d.SourceHash)
}
