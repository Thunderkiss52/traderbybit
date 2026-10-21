// Command research-candidates evaluates the five VECTOR section 7 research candidate cohorts on a
// locally stored dataset and writes a machine readable evidence file.
//
// The command never opens an exchange order, never reads an exchange trade key and never touches
// the eight primary paper accounts. It only reads the local archive and writes a report file.
package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"time"

	"github.com/traderbybit/research-go/internal/auditdata"
	"github.com/traderbybit/research-go/internal/candidates"
)

type candidateResult struct {
	CandidateID   string                     `json:"candidate_id"`
	Version       string                     `json:"version"`
	CohortKind    string                     `json:"cohort_kind"`
	Status        string                     `json:"status"`
	Verification  string                     `json:"verification"`
	Executable    bool                       `json:"executable"`
	ExecutionNote string                     `json:"execution_note,omitempty"`
	SignalCount   int                        `json:"signal_count"`
	SignalCounts  map[string]int             `json:"signal_counts_per_split"`
	SplitMetrics  map[string]splitReport     `json:"split_metrics"`
	LookAhead     candidates.LookAheadChecks `json:"look_ahead_controls"`
	Limitations   []string                   `json:"limitations"`
}

type splitReport struct {
	Trades      int      `json:"trades"`
	Wins        int      `json:"wins"`
	Losses      int      `json:"losses"`
	WinRate     float64  `json:"win_rate"`
	NetPnL      string   `json:"net_pnl"`
	TotalFees   string   `json:"total_fees"`
	ProfitFact  *float64 `json:"profit_factor"`
	MaxDrawdown string   `json:"max_drawdown"`
	AverageR    float64  `json:"average_r"`
}

type evidence struct {
	GeneratedAtUTC string            `json:"generated_at_utc"`
	Mode           string            `json:"mode"`
	SourceManifest string            `json:"source_manifest"`
	SourceHashes   map[string]string `json:"source_sha256"`
	Dataset        datasetInfo       `json:"dataset"`
	Candidates     []candidateResult `json:"candidates"`
	CostModel      map[string]string `json:"cost_model"`
	Limitations    []string          `json:"limitations"`
	AccountTouched bool              `json:"primary_paper_accounts_touched"`
	OrderPathUsed  bool              `json:"exchange_order_path_used"`
}

type datasetInfo struct {
	Symbol            string          `json:"symbol"`
	PeerSymbol        string          `json:"peer_symbol"`
	EntryInterval     string          `json:"entry_interval"`
	DecisionInterval  string          `json:"decision_interval"`
	EntryBars         int             `json:"entry_bars"`
	HTFBars           int             `json:"htf_bars"`
	PeerBars          int             `json:"peer_bars"`
	FirstOpenUTC      time.Time       `json:"first_open_utc"`
	LastCloseUTC      time.Time       `json:"last_close_utc"`
	StaleAfterHours   float64         `json:"stale_after_hours"`
	Gaps              []auditdata.Gap `json:"gaps"`
	FundingEvents     int             `json:"funding_events"`
	FundingFirstUTC   time.Time       `json:"funding_first_utc"`
	FundingLastUTC    time.Time       `json:"funding_last_utc"`
	OpenInterestCount int             `json:"open_interest_events"`
	OpenInterestFirst time.Time       `json:"open_interest_first_utc"`
	OpenInterestLast  time.Time       `json:"open_interest_last_utc"`
}

func main() {
	dataDir := flag.String("data-dir", "audit_data", "directory of the local research archive")
	out := flag.String("out", "", "path of the evidence file that must be written")
	symbol := flag.String("symbol", "BTCUSDT", "symbol of the evaluated candidate cohort")
	peer := flag.String("peer", "ETHUSDT", "peer symbol of the lead/lag cohort")
	entryInterval := flag.String("entry-interval", "5", "entry interval in minutes")
	htfInterval := flag.String("htf-interval", "60", "decision (higher timeframe) interval in minutes")
	flag.Parse()

	if err := run(*dataDir, *out, *symbol, *peer, *entryInterval, *htfInterval); err != nil {
		fmt.Fprintf(os.Stderr, "research-candidates: %v\n", err)
		os.Exit(1)
	}
}

func run(dataDir, out, symbol, peer, entryInterval, htfInterval string) error {
	candlePath := filepath.Join(dataDir, "market_candles.csv")
	fundingPath := filepath.Join(dataDir, "market_funding_events.csv")
	oiPath := filepath.Join(dataDir, "market_open_interest_events.csv")
	manifestPath := filepath.Join(dataDir, "manifest.json")

	candles, err := auditdata.LoadCandles(candlePath)
	if err != nil {
		return fmt.Errorf("load candles: %w", err)
	}
	funding, err := auditdata.LoadFunding(fundingPath)
	if err != nil {
		return fmt.Errorf("load funding: %w", err)
	}
	oi, err := auditdata.LoadOpenInterest(oiPath)
	if err != nil {
		return fmt.Errorf("load open interest: %w", err)
	}

	dataset, quality, err := auditdata.BuildDataset(candles, funding, oi, auditdata.Options{
		Symbol:        symbol,
		PeerSymbol:    peer,
		EntryInterval: entryInterval,
		HTFInterval:   htfInterval,
		Now:           time.Now().UTC(),
	})
	if err != nil {
		return fmt.Errorf("build dataset: %w", err)
	}

	hashes := map[string]string{}
	for _, path := range []string{candlePath, fundingPath, oiPath, manifestPath} {
		hash, err := fileHash(path)
		if err != nil {
			return err
		}
		hashes[filepath.Base(path)] = hash
	}

	// The source hash binds the evaluation to the exact archive content that was read.
	combined := sha256.New()
	for _, name := range []string{"market_candles.csv", "market_funding_events.csv", "market_open_interest_events.csv", "manifest.json"} {
		_, _ = combined.Write([]byte(name))
		_, _ = combined.Write([]byte(hashes[name]))
	}
	dataset.SourceHash = hex.EncodeToString(combined.Sum(nil))

	cfg := candidates.DefaultEvaluationConfig()
	results := make([]candidateResult, 0, len(candidates.Registry()))

	for _, entry := range candidates.Registry() {
		c := entry.Build()

		signals, genErr := c.Generate(dataset)
		result := candidateResult{
			CandidateID:  entry.ID,
			Version:      entry.Version,
			CohortKind:   entry.Kind,
			SignalCounts: map[string]int{},
			SplitMetrics: map[string]splitReport{},
		}

		if genErr != nil {
			result.Status = candidates.StatusDataUnavailable
			result.Verification = candidates.StatusEngineUnverified
			result.Limitations = append(result.Limitations, "DATA_UNAVAILABLE: "+genErr.Error())
			results = append(results, result)
			continue
		}

		result.SignalCount = len(signals)

		ev, err := candidates.ExecuteResearchEvaluation(c, dataset, cfg)
		if err != nil {
			return fmt.Errorf("candidate %s: %w", entry.ID, err)
		}

		result.Status = ev.Status
		result.Verification = ev.Verification
		result.Executable = ev.Executable
		result.ExecutionNote = ev.ExecutionReason
		result.Limitations = ev.Limitations
		result.LookAhead = ev.LookAhead
		result.SignalCounts = ev.SignalCounts

		for name, report := range ev.SplitReports {
			if report == nil {
				continue
			}
			result.SplitMetrics[name] = splitReport{
				Trades:      report.TotalTrades,
				Wins:        report.Wins,
				Losses:      report.Losses,
				WinRate:     report.WinRate,
				NetPnL:      report.NetPnL.String(),
				TotalFees:   report.TotalFees.String(),
				ProfitFact:  report.ProfitFactor,
				MaxDrawdown: report.MaxDrawdown.String(),
				AverageR:    report.AverageR,
			}
		}

		results = append(results, result)
	}

	evidence := evidence{
		GeneratedAtUTC: time.Now().UTC().Format(time.RFC3339),
		Mode:           "RESEARCH_COHORT_EVALUATION",
		SourceManifest: manifestPath,
		SourceHashes:   hashes,
		Dataset: datasetInfo{
			Symbol:            dataset.Symbol,
			PeerSymbol:        dataset.PeerSymbol,
			EntryInterval:     dataset.EntryInterval,
			DecisionInterval:  dataset.DecisionInterval,
			EntryBars:         len(dataset.Bars),
			HTFBars:           len(dataset.HTFBars),
			PeerBars:          len(dataset.PeerBars),
			FirstOpenUTC:      quality.FirstOpen,
			LastCloseUTC:      quality.LastClose,
			StaleAfterHours:   quality.StaleAfterHours,
			Gaps:              quality.Gaps,
			FundingEvents:     quality.FundingEvents,
			FundingFirstUTC:   quality.FundingFirst,
			FundingLastUTC:    quality.FundingLast,
			OpenInterestCount: quality.OpenInterest,
			OpenInterestFirst: quality.OpenInterestF,
			OpenInterestLast:  quality.OpenInterestL,
		},
		Candidates: results,
		CostModel: map[string]string{
			"taker_fee":       cfg.Backtest.TakerFeeRate.String(),
			"slippage":        cfg.Backtest.SlippageRate.String(),
			"risk_per_trade":  cfg.Backtest.RiskPerTradeRate.String(),
			"fill_model":      "NEXT_CANDLE_OPEN",
			"ambiguity":       "CONSERVATIVE_STOP_LOSS_FIRST",
			"max_holding_bar": fmt.Sprintf("%d", cfg.Backtest.MaxHoldingBars),
		},
		Limitations: []string{
			"NO_PROSPECTIVE_PAPER_HISTORY: the archive is a historical export, not a forward paper observation stream.",
			"RESEARCH_COHORT_ONLY: no primary paper account was created, read or modified.",
			"NO_EXCHANGE_ORDER_PATH: the evaluation never opens an order and never uses a trade credential.",
			"BACKTEST_SPLIT_IS_NOT_EVIDENCE: a split result never establishes future performance.",
		},
		AccountTouched: false,
		OrderPathUsed:  false,
	}

	payload, err := json.MarshalIndent(evidence, "", "  ")
	if err != nil {
		return fmt.Errorf("encode evidence: %w", err)
	}

	if out == "" {
		fmt.Println(string(payload))
		return nil
	}

	if err := os.WriteFile(out, payload, 0o644); err != nil {
		return fmt.Errorf("write evidence: %w", err)
	}

	fmt.Printf("evidence written to %s (%d candidates)\n", out, len(results))
	return nil
}

func fileHash(path string) (string, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return "MISSING", nil
		}
		return "", err
	}
	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:]), nil
}
