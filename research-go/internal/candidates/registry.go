package candidates

import (
	"fmt"
	"sort"
)

// RegistryEntry describes one registered research candidate cohort.
type RegistryEntry struct {
	ID           string
	Version      string
	Family       string
	Kind         string
	Precommitted string
	Executable   bool
	Build        func() Candidate
}

// Registry returns the five research candidate cohorts of the execution contract.
//
// The cohorts are research-only. They are NOT primary paper accounts and they never modify the
// eight operational paper accounts.
func Registry() []RegistryEntry {
	return []RegistryEntry{
		{
			ID:           "funding_open_interest_squeeze",
			Version:      "1.0.0",
			Family:       "FUNDING_AND_OPEN_INTEREST_CROWDING",
			Kind:         CohortKindResearch,
			Precommitted: "CROWDING_REVERSAL: extreme funding with rising open interest and a failing price move implies a squeeze of the crowded side.",
			Executable:   true,
			Build:        func() Candidate { return NewFundingOpenInterestSqueeze(DefaultFundingOpenInterestConfig()) },
		},
		{
			ID:           "liquidity_sweep_reversal",
			Version:      "1.0.0",
			Family:       "LIQUIDITY_SWEEP_AND_LEVEL_RECAPTURE",
			Kind:         CohortKindResearch,
			Precommitted: "A sweep beyond a prior-known level that is recaptured on the close is treated as a failed break of that level.",
			Executable:   true,
			Build:        func() Candidate { return NewLiquiditySweepReversal(DefaultLiquiditySweepConfig()) },
		},
		{
			ID:           "volatility_compression_breakout",
			Version:      "1.0.0",
			Family:       "VOLATILITY_COMPRESSION_BREAKOUT",
			Kind:         CohortKindResearch,
			Precommitted: "A precommitted volatility compression followed by a close beyond the previous breakout window is treated as a breakout.",
			Executable:   true,
			Build:        func() Candidate { return NewVolatilityCompressionBreakout(DefaultVolatilityCompressionConfig()) },
		},
		{
			ID:           "btc_eth_lead_lag",
			Version:      "1.0.0",
			Family:       "CROSS_SYMBOL_LEAD_LAG",
			Kind:         CohortKindResearch,
			Precommitted: "A peer move that the local symbol has not yet followed is treated as a catch-up hypothesis. It is never labelled arbitrage.",
			Executable:   false,
			Build:        func() Candidate { return NewBTCETHLagLead(DefaultLeadLagConfig()) },
		},
		{
			ID:           "multi_timeframe_trend_following",
			Version:      "1.0.0",
			Family:       "HIGHER_TIMEFRAME_TREND_LOWER_TIMEFRAME_ENTRY",
			Kind:         CohortKindResearch,
			Precommitted: "The higher timeframe trend (EMA fast/slow of fully closed higher timeframe candles) must agree with the lower timeframe entry confirmation.",
			Executable:   true,
			Build:        func() Candidate { return NewMultiTimeframeTrendFollowing(DefaultMultiTimeframeConfig()) },
		},
	}
}

// Lookup returns the registry entry of a candidate id.
func Lookup(id string) (RegistryEntry, bool) {
	for _, e := range Registry() {
		if e.ID == id {
			return e, true
		}
	}
	return RegistryEntry{}, false
}

// IDs returns the sorted identifiers of all registered cohorts.
func IDs() []string {
	entries := Registry()
	ids := make([]string, 0, len(entries))
	for _, e := range entries {
		ids = append(ids, e.ID)
	}
	sort.Strings(ids)
	return ids
}

// Describe returns a human readable summary of the registry.
func Describe() string {
	out := ""
	for _, e := range Registry() {
		out += fmt.Sprintf("%s v%s [%s] executable=%t\n  hypothesis: %s\n", e.ID, e.Version, e.Kind, e.Executable, e.Precommitted)
	}
	return out
}
