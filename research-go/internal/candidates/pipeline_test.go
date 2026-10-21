package candidates

import (
	"strings"
	"testing"
)

func TestSplitsAreChronologicalAndKeepTheTestSplitLast(t *testing.T) {
	bars := make([]Bar, 0, 100)
	d := researchDataset(t)
	bars = d.Bars[:100]

	splits := Splits(bars, DefaultSplitConfig())
	if len(splits) != 3 {
		t.Fatalf("expected 3 splits, got %d", len(splits))
	}

	if splits[0].Name != "TRAIN" || splits[1].Name != "VALIDATION" || splits[2].Name != "TEST" {
		t.Fatalf("unexpected split order: %+v", splits)
	}
	if splits[0].StartIndex != 0 {
		t.Fatalf("the train split must start at 0, got %d", splits[0].StartIndex)
	}
	if splits[2].EndIndex != len(bars) {
		t.Fatalf("the test split must end at the last bar, got %d", splits[2].EndIndex)
	}

	for i := 1; i < len(splits); i++ {
		if splits[i].StartIndex != splits[i-1].EndIndex {
			t.Fatalf("the splits must be contiguous: %+v", splits)
		}
	}
}

func TestEvaluationOfACandidateStaysResearchOnlyAndNotEligible(t *testing.T) {
	d := researchDataset(t)
	cfg := DefaultEvaluationConfig()

	entry, ok := Lookup("funding_open_interest_squeeze")
	if !ok {
		t.Fatal("the funding candidate must be registered")
	}

	ev, err := ExecuteResearchEvaluation(entry.Build(), d, cfg)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if ev.CohortKind != CohortKindResearch {
		t.Fatalf("a candidate evaluation must stay a research cohort, got %q", ev.CohortKind)
	}
	if ev.Verification != StatusVerifiedControls {
		t.Fatalf("the mechanical controls must be verified, got %q", ev.Verification)
	}
	if ev.Status != StatusNotEligible && ev.Status != StatusInsufficientEvid {
		t.Fatalf("a backtest split must never be reported as eligible evidence, got %q", ev.Status)
	}
	if !hasLimitation(ev, "NO_PROSPECTIVE_PAPER_HISTORY") {
		t.Fatalf("the missing prospective paper history must be disclosed: %v", ev.Limitations)
	}
	if !hasLimitation(ev, "RESEARCH_COHORT_ONLY") {
		t.Fatalf("the research cohort restriction must be disclosed: %v", ev.Limitations)
	}
	if ev.SplitReports["TEST"] == nil {
		t.Fatal("the test split report is missing")
	}
	if ev.SignalCounts["TEST"] == 0 {
		t.Fatal("the test split must contain signals")
	}
}

func TestEvaluationReportsDataUnavailableWhenTheInputIsMissing(t *testing.T) {
	d := researchDataset(t)
	d.Funding = nil
	d.OpenInterest = nil

	entry, _ := Lookup("funding_open_interest_squeeze")
	ev, err := ExecuteResearchEvaluation(entry.Build(), d, DefaultEvaluationConfig())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if ev.Status != StatusDataUnavailable {
		t.Fatalf("expected %q, got %q", StatusDataUnavailable, ev.Status)
	}
	if ev.Verification != StatusEngineUnverified {
		t.Fatalf("expected %q, got %q", StatusEngineUnverified, ev.Verification)
	}
}

func TestPairCandidateIsNeverReportedAsExecuted(t *testing.T) {
	d := researchDataset(t)

	entry, _ := Lookup("btc_eth_lead_lag")
	ev, err := ExecuteResearchEvaluation(entry.Build(), d, DefaultEvaluationConfig())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if ev.Executable {
		t.Fatal("the pair candidate must not be reported as executable")
	}
	if ev.Status != StatusExecNotValidated {
		t.Fatalf("expected %q, got %q", StatusExecNotValidated, ev.Status)
	}
	if ev.ExecutionReason == "" {
		t.Fatal("the reason for the missing execution model must be disclosed")
	}
	if !hasLimitation(ev, "EXECUTION_NOT_VALIDATED") {
		t.Fatalf("the missing execution model must be disclosed: %v", ev.Limitations)
	}
}

func TestEvaluationNeverTouchesAPrimaryPaperAccount(t *testing.T) {
	d := researchDataset(t)

	for _, entry := range Registry() {
		ev, err := ExecuteResearchEvaluation(entry.Build(), d, DefaultEvaluationConfig())
		if err != nil {
			t.Fatalf("candidate %s: %v", entry.ID, err)
		}
		if ev.CohortKind != CohortKindResearch {
			t.Fatalf("candidate %s: a candidate must stay a research cohort, got %q", entry.ID, ev.CohortKind)
		}
		if strings.Contains(ev.CandidateID, "account") {
			t.Fatalf("candidate %s: the evaluation must not reference a paper account", entry.ID)
		}
	}
}

func hasLimitation(ev *Evaluation, needle string) bool {
	for _, l := range ev.Limitations {
		if strings.Contains(l, needle) {
			return true
		}
	}
	return false
}
