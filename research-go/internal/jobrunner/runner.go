package jobrunner

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"math"
	"strings"
	"time"

	"github.com/traderbybit/research-go/internal/algorithms"
	"github.com/traderbybit/research-go/internal/backtest"
	"github.com/traderbybit/research-go/internal/contracts"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
	"github.com/traderbybit/research-go/internal/patterns"
	"github.com/traderbybit/research-go/internal/replay"
	"github.com/traderbybit/research-go/internal/simulation"
	"github.com/traderbybit/research-go/internal/telemetry"
	"github.com/traderbybit/research-go/internal/walkforward"
)

var ErrStaleGeneration = errors.New("stale worker generation token")

type Runner struct {
	db              *sql.DB
	workerID        string
	generationToken int64
}

func NewRunner(db *sql.DB, workerID string) *Runner {
	return &Runner{
		db:              db,
		workerID:        workerID,
		generationToken: 0,
	}
}

// GenerationToken returns the registered generation fencing token.
func (r *Runner) GenerationToken() int64 {
	return r.generationToken
}

// ClaimNextJob claims an eligible job using FOR UPDATE SKIP LOCKED
func (r *Runner) ClaimNextJob(ctx context.Context, leaseDuration time.Duration) (*contracts.JobRequest, int64, error) {
	if r.generationToken <= 0 {
		return nil, 0, errors.New("cannot claim job without a valid positive worker generation token")
	}

	tx, err := r.db.BeginTx(ctx, nil)
	if err != nil {
		return nil, 0, fmt.Errorf("failed to begin tx: %w", err)
	}
	defer tx.Rollback()

	if err := r.verifyWorkerGenerationTx(ctx, tx); err != nil {
		return nil, 0, err
	}

	query := `
		SELECT id, job_id, schema_version, idempotency_key, kind, algorithm_version, code_revision,
		       COALESCE(strategy_id, ''), COALESCE(strategy_version, ''), COALESCE(configuration_hash, ''),
		       COALESCE(model_version, ''), COALESCE(dataset_id::text, ''), COALESCE(dataset_sha256, ''),
		       as_of_utc, training_cutoff_utc, research_mode, random_seed,
		       limits, payload, created_by, requested_at_utc, fencing_token
		FROM research_jobs
		WHERE status = 'QUEUED'
		ORDER BY requested_at_utc ASC
		FOR UPDATE SKIP LOCKED
		LIMIT 1
	`

	var (
		id           int64
		jobID        string
		schemaVer    int
		idempKey     string
		kind         string
		algoVer      string
		codeRev      string
		strategyID   string
		stratVer     string
		configHash   string
		modelVer     string
		datasetID    string
		datasetHash  string
		asOfUTC      sql.NullTime
		cutoffUTC    sql.NullTime
		researchMode string
		seed         int64
		limitsRaw    []byte
		payloadRaw   []byte
		createdBy    string
		reqAt        time.Time
		fencingToken int64
	)

	err = tx.QueryRowContext(ctx, query).Scan(
		&id, &jobID, &schemaVer, &idempKey, &kind, &algoVer, &codeRev,
		&strategyID, &stratVer, &configHash, &modelVer, &datasetID, &datasetHash,
		&asOfUTC, &cutoffUTC, &researchMode, &seed, &limitsRaw, &payloadRaw,
		&createdBy, &reqAt, &fencingToken,
	)

	if err == sql.ErrNoRows {
		return nil, 0, nil
	}
	if err != nil {
		return nil, 0, fmt.Errorf("failed to query next job: %w", err)
	}

	newFencingToken := fencingToken + 1
	now := time.Now().UTC()
	leaseExpires := now.Add(leaseDuration)

	updateQuery := `
		UPDATE research_jobs
		SET status = 'CLAIMED', lease_owner = $1, lease_expires_at = $2, claimed_at = $3, fencing_token = $4
		WHERE id = $5
	`
	_, err = tx.ExecContext(ctx, updateQuery, r.workerID, leaseExpires, now, newFencingToken, id)
	if err != nil {
		return nil, 0, fmt.Errorf("failed to claim job: %w", err)
	}

	if err := tx.Commit(); err != nil {
		return nil, 0, fmt.Errorf("failed to commit claim: %w", err)
	}

	var limits contracts.JobLimits
	if len(limitsRaw) > 0 {
		_ = json.Unmarshal(limitsRaw, &limits)
	}
	var payload map[string]interface{}
	if len(payloadRaw) > 0 {
		_ = json.Unmarshal(payloadRaw, &payload)
	}

	req := &contracts.JobRequest{
		SchemaVersion:     schemaVer,
		JobID:             jobID,
		IdempotencyKey:    idempKey,
		Kind:              kind,
		AlgorithmVersion:  algoVer,
		CodeRevision:      codeRev,
		StrategyID:        strategyID,
		StrategyVersion:   stratVer,
		ConfigurationHash: configHash,
		ModelVersion:      modelVer,
		DatasetID:         datasetID,
		DatasetSHA256:     datasetHash,
		ResearchMode:      researchMode,
		RandomSeed:        seed,
		Limits:            limits,
		Payload:           payload,
		CreatedBy:         createdBy,
		RequestedAtUTC:    reqAt.Format(time.RFC3339),
	}

	return req, newFencingToken, nil
}

// verifyWorkerGenerationTx acquires a FOR SHARE lock on the worker's record
// to ensure the worker generation has not been replaced/superseded by another instance.
func (r *Runner) verifyWorkerGenerationTx(ctx context.Context, tx *sql.Tx) error {
	if r.generationToken <= 0 {
		return errors.New("worker generation token is required and must be strictly positive")
	}

	var currentGen int64
	query := `
		SELECT generation_token
		FROM research_workers
		WHERE worker_id = $1
		FOR SHARE
	`
	err := tx.QueryRowContext(ctx, query, r.workerID).Scan(&currentGen)
	if err == sql.ErrNoRows {
		return fmt.Errorf("worker %s not found in research_workers: %w", r.workerID, ErrStaleGeneration)
	}
	if err != nil {
		return fmt.Errorf("failed to verify worker generation lock: %w", err)
	}
	if currentGen != r.generationToken {
		return fmt.Errorf("current worker generation %d does not match active generation %d: %w", currentGen, r.generationToken, ErrStaleGeneration)
	}
	return nil
}

// StartJob transitions CLAIMED to RUNNING under transactional generation fencing
func (r *Runner) StartJob(ctx context.Context, jobID string, fencingToken int64) error {
	if r.generationToken <= 0 {
		return errors.New("worker generation token is required and must be strictly positive")
	}

	tx, err := r.db.BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("failed to begin tx for start job: %w", err)
	}
	defer tx.Rollback()

	if err := r.verifyWorkerGenerationTx(ctx, tx); err != nil {
		return err
	}

	now := time.Now().UTC()
	query := `
		UPDATE research_jobs
		SET status = 'RUNNING', started_at = $1, heartbeat_at = $1
		WHERE job_id = $2 AND lease_owner = $3 AND fencing_token = $4 AND status = 'CLAIMED'
	`
	res, err := tx.ExecContext(ctx, query, now, jobID, r.workerID, fencingToken)
	if err != nil {
		return err
	}
	rows, _ := res.RowsAffected()
	if rows == 0 {
		return fmt.Errorf("job %s could not be started (lease or fencing token mismatch)", jobID)
	}
	return tx.Commit()
}

// Heartbeat refreshes the lease under transactional generation fencing
func (r *Runner) Heartbeat(ctx context.Context, jobID string, fencingToken int64, progress int, msg string, ext time.Duration) error {
	if r.generationToken <= 0 {
		return errors.New("worker generation token is required and must be strictly positive")
	}

	tx, err := r.db.BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("failed to begin tx for heartbeat: %w", err)
	}
	defer tx.Rollback()

	if err := r.verifyWorkerGenerationTx(ctx, tx); err != nil {
		return err
	}

	now := time.Now().UTC()
	expires := now.Add(ext)
	query := `
		UPDATE research_jobs
		SET heartbeat_at = $1, lease_expires_at = $2, progress = $3, progress_message = $4
		WHERE job_id = $5 AND lease_owner = $6 AND fencing_token = $7 AND status IN ('CLAIMED', 'RUNNING')
	`
	res, err := tx.ExecContext(ctx, query, now, expires, progress, msg, jobID, r.workerID, fencingToken)
	if err != nil {
		return err
	}
	rows, _ := res.RowsAffected()
	if rows == 0 {
		return fmt.Errorf("job %s heartbeat failed (lease or fencing token mismatch)", jobID)
	}
	return tx.Commit()
}

// CompleteJob commits results and marks job SUCCEEDED under transactional generation fencing
func (r *Runner) CompleteJob(ctx context.Context, jobID string, fencingToken int64, result contracts.JobResult) error {
	if r.generationToken <= 0 {
		return errors.New("worker generation token is required and must be strictly positive")
	}

	resBytes, err := json.Marshal(result.Metrics)
	if err != nil {
		return err
	}
	statsBytes, err := json.Marshal(result.RuntimeStats)
	if err != nil {
		return err
	}

	manifestHash, err := contracts.ComputeManifestHash(result.Metrics)
	if err != nil {
		manifestHash = ""
	}

	tx, err := r.db.BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("failed to begin tx for complete job: %w", err)
	}
	defer tx.Rollback()

	if err := r.verifyWorkerGenerationTx(ctx, tx); err != nil {
		return err
	}

	now := time.Now().UTC()
	query := `
		UPDATE research_jobs
		SET status = 'SUCCEEDED', progress = 100, progress_message = 'Completed',
		    result_manifest_sha256 = $1, result_payload = $2, runtime_stats = $3,
		    completed_at = $4, lease_owner = NULL, lease_expires_at = NULL
		WHERE job_id = $5 AND lease_owner = $6 AND fencing_token = $7 AND status IN ('CLAIMED', 'RUNNING')
	`
	res, err := tx.ExecContext(ctx, query, manifestHash, resBytes, statsBytes, now, jobID, r.workerID, fencingToken)
	if err != nil {
		return err
	}
	rows, _ := res.RowsAffected()
	if rows == 0 {
		return fmt.Errorf("job %s completion failed (fencing token expired or lost lease)", jobID)
	}
	return tx.Commit()
}

// FailJob records failure or re-queues under transactional generation fencing
func (r *Runner) FailJob(ctx context.Context, jobID string, fencingToken int64, failErr error) error {
	if r.generationToken <= 0 {
		return errors.New("worker generation token is required and must be strictly positive")
	}

	sanitized := failErr.Error()
	if len(sanitized) > 2000 {
		sanitized = sanitized[:2000]
	}

	tx, err := r.db.BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("failed to begin tx for fail job: %w", err)
	}
	defer tx.Rollback()

	if err := r.verifyWorkerGenerationTx(ctx, tx); err != nil {
		return err
	}

	now := time.Now().UTC()
	query := `
		UPDATE research_jobs
		SET retry_count = retry_count + 1,
		    sanitized_error = $1,
		    status = CASE WHEN retry_count + 1 < max_retries THEN 'QUEUED' ELSE 'FAILED' END,
		    completed_at = CASE WHEN retry_count + 1 >= max_retries THEN $2 ELSE NULL END,
		    lease_owner = NULL, lease_expires_at = NULL
		WHERE job_id = $3 AND lease_owner = $4 AND fencing_token = $5 AND status IN ('CLAIMED', 'RUNNING')
	`
	res, err := tx.ExecContext(ctx, query, sanitized, now, jobID, r.workerID, fencingToken)
	if err != nil {
		return err
	}
	rows, _ := res.RowsAffected()
	if rows == 0 {
		return fmt.Errorf("job %s failure recording failed (lease or fencing token mismatch)", jobID)
	}
	return tx.Commit()
}

// IsSupportedKind checks whether the job kind is implemented and supported by the worker
func IsSupportedKind(kind string) bool {
	switch strings.ToUpper(kind) {
	case contracts.KindBacktest,
		contracts.KindWalkForward,
		contracts.KindPatternMine,
		contracts.KindMonteCarlo,
		contracts.KindReplay,
		contracts.KindSkipOutcomes,
		contracts.KindFeatureBuild:
		return true
	default:
		return false
	}
}

// ExecuteJob runs a single job and handles heartbeat, telemetry, execution, and completion
func (r *Runner) ExecuteJob(ctx context.Context, req *contracts.JobRequest, fencingToken int64) (retErr error) {
	log.Printf("[jobrunner] Starting job %s kind=%s strategy=%s", req.JobID, req.Kind, req.StrategyID)

	if !IsSupportedKind(req.Kind) {
		unsupportedErr := fmt.Errorf("unsupported or unimplemented research job kind: %s", req.Kind)
		_ = r.FailJob(ctx, req.JobID, fencingToken, unsupportedErr)
		log.Printf("[jobrunner] Rejected job %s with unsupported kind %s: %v", req.JobID, req.Kind, unsupportedErr)
		return unsupportedErr
	}

	if err := r.StartJob(ctx, req.JobID, fencingToken); err != nil {
		return fmt.Errorf("failed to start job: %w", err)
	}

	// Spawn heartbeat goroutine with cancellation
	hbCtx, cancelHB := context.WithCancel(ctx)
	defer cancelHB()

	go func() {
		ticker := time.NewTicker(5 * time.Second)
		defer ticker.Stop()
		progress := 5
		_ = r.Heartbeat(hbCtx, req.JobID, fencingToken, progress, "Processing", 60*time.Second)
		for {
			select {
			case <-hbCtx.Done():
				return
			case <-ticker.C:
				if progress < 95 {
					progress += 5
				}
				_ = r.Heartbeat(hbCtx, req.JobID, fencingToken, progress, "Processing", 60*time.Second)
			}
		}
	}()

	tracker := telemetry.Start()
	rowsProcessed := 0

	defer func() {
		if rec := recover(); rec != nil {
			panicErr := fmt.Errorf("recovered from panic in job %s: %v", req.JobID, rec)
			log.Printf("[jobrunner] Panic during job %s: %v", req.JobID, rec)
			_ = r.FailJob(ctx, req.JobID, fencingToken, panicErr)
			retErr = panicErr
		}
	}()

	// Extract parameters
	symbol := "BTCUSDT"
	interval := "5"
	category := "linear"
	if s, ok := req.Payload["symbol"].(string); ok && s != "" {
		symbol = s
	}
	if iv, ok := req.Payload["interval"].(string); ok && iv != "" {
		interval = iv
	}
	if cat, ok := req.Payload["category"].(string); ok && cat != "" {
		category = cat
	}

	fromTime := time.Now().Add(-30 * 24 * time.Hour)
	toTime := time.Now()
	if fromStr, ok := req.Payload["from"].(string); ok && fromStr != "" {
		if t, err := time.Parse(time.RFC3339, fromStr); err == nil {
			fromTime = t
		}
	}
	if toStr, ok := req.Payload["to"].(string); ok && toStr != "" {
		if t, err := time.Parse(time.RFC3339, toStr); err == nil {
			toTime = t
		}
	}

	maxRows := 50000
	if req.Limits.MaxRows > 0 {
		maxRows = req.Limits.MaxRows
	}

	candles, err := datasets.LoadCandlesFromDB(r.db, "bybit", category, symbol, interval, fromTime, toTime, maxRows)
	if err != nil {
		_ = r.FailJob(ctx, req.JobID, fencingToken, err)
		return fmt.Errorf("failed to load candles: %w", err)
	}
	rowsProcessed = len(candles)

	metrics := make(map[string]interface{})

	switch strings.ToUpper(req.Kind) {
	case contracts.KindBacktest:
		engine := backtest.NewEngine(backtest.DefaultConfig())
		report, err := engine.Run(candles, req.StrategyID, req.Payload)
		if err != nil {
			_ = r.FailJob(ctx, req.JobID, fencingToken, err)
			return err
		}
		metrics["strategy_id"] = report.StrategyID
		metrics["total_trades"] = report.TotalTrades
		metrics["wins"] = report.Wins
		metrics["losses"] = report.Losses
		metrics["win_rate"] = report.WinRate
		metrics["net_pnl"] = report.NetPnL.String()
		metrics["gross_profit"] = report.GrossProfit.String()
		metrics["gross_loss"] = report.GrossLoss.String()
		metrics["total_fees"] = report.TotalFees.String()
		metrics["profit_factor"] = report.ProfitFactor
		metrics["max_drawdown"] = report.MaxDrawdown.String()
		metrics["max_drawdown_percent"] = report.MaxDrawdownPercent
		metrics["average_r"] = report.AverageR
		metrics["expectancy"] = report.Expectancy
		metrics["trades_count"] = len(report.Trades)

	case contracts.KindWalkForward:
		wfoCfg := walkforward.DefaultWFOConfig()
		wfoCfg.StrategyID = req.StrategyID
		res, err := walkforward.Run(candles, wfoCfg, backtest.DefaultConfig())
		if err != nil {
			_ = r.FailJob(ctx, req.JobID, fencingToken, err)
			return err
		}
		metrics["strategy_id"] = res.StrategyID
		metrics["folds_count"] = res.FoldsCount
		metrics["in_sample_net_pnl"] = res.InSampleNetPnL.String()
		metrics["out_sample_net_pnl"] = res.OutSampleNetPnL.String()
		metrics["in_sample_win_rate"] = res.InSampleWinRate
		metrics["out_sample_win_rate"] = res.OutSampleWinRate
		metrics["walk_forward_efficiency"] = res.WalkForwardEfficiency
		metrics["degradation_percent"] = res.DegradationPercent
		metrics["sufficient_data"] = res.SufficientData
		metrics["overfit_detected"] = res.OverfitDetected
		metrics["folds"] = res.Folds

	case contracts.KindPatternMine:
		patCfg := patterns.DefaultPatternConfig()
		res, err := patterns.MinePatterns(candles, patCfg)
		if err != nil {
			_ = r.FailJob(ctx, req.JobID, fencingToken, err)
			return err
		}
		metrics["patterns_mined"] = len(res)
		metrics["patterns"] = res

	case contracts.KindMonteCarlo:
		engine := backtest.NewEngine(backtest.DefaultConfig())
		btReport, err := engine.Run(candles, req.StrategyID, req.Payload)
		if err != nil {
			_ = r.FailJob(ctx, req.JobID, fencingToken, err)
			return err
		}
		mcCfg := simulation.DefaultMonteCarloConfig()
		mcCfg.RandomSeed = req.RandomSeed
		mcRes, err := simulation.RunMonteCarlo(btReport.Trades, mcCfg)
		if err != nil {
			_ = r.FailJob(ctx, req.JobID, fencingToken, err)
			return err
		}
		metrics["iterations"] = mcRes.Iterations
		metrics["drawdown_percentiles"] = mcRes.DrawdownPercentiles
		metrics["final_equity_p50"] = mcRes.FinalEquityP50.String()
		metrics["probability_of_ruin"] = mcRes.ProbabilityOfRuin

	case contracts.KindReplay:
		asOf := time.Now().Add(-1 * time.Hour)
		if req.AsOfUTC != "" {
			if t, err := time.Parse(time.RFC3339, req.AsOfUTC); err == nil {
				asOf = t
			}
		}
		rep, err := replay.ReconstructDecision(candles, asOf, req.StrategyID)
		if err != nil {
			_ = r.FailJob(ctx, req.JobID, fencingToken, err)
			return err
		}
		metrics["decision_time"] = rep.DecisionTime.Format(time.RFC3339)
		metrics["strategy_id"] = rep.StrategyID
		metrics["signal_action"] = rep.SignalAction
		metrics["reason"] = rep.Reason
		metrics["simulated_outcome"] = rep.SimulatedOutcome
		metrics["ambiguous"] = rep.Ambiguous
		metrics["fingerprint"] = rep.ReplayFingerprint

	case contracts.KindSkipOutcomes:
		horizon := 16 // 16 bars (4 hours on 15m)
		totalSkips := 0
		tpHits := 0
		slHits := 0
		timeouts := 0
		ambiguousCount := 0

		if len(candles) >= 60 {
			closes := make([]decimal.Decimal, len(candles))
			for i, c := range candles {
				closes[i] = c.Close
			}
			fastEMA := algorithms.CalculateEMA(closes, 20)
			slowEMA := algorithms.CalculateEMA(closes, 50)
			rsi := algorithms.CalculateRSI(closes, 14)
			atr := algorithms.CalculateATR(candles, 14)

			for i := 50; i < len(candles)-horizon; i++ {
				if fastEMA[i] == nil || slowEMA[i] == nil || rsi[i] == nil || atr[i] == nil {
					continue
				}
				rv := rsi[i].Float64()
				isSkippedBull := fastEMA[i].Cmp(*slowEMA[i]) > 0 && rv >= 70
				isSkippedBear := fastEMA[i].Cmp(*slowEMA[i]) < 0 && rv <= 30

				if !isSkippedBull && !isSkippedBear {
					continue
				}

				totalSkips++
				entry := candles[i].Close
				atrDist := atr[i].Mul(decimal.FromFloat64(1.5))
				tpDist := atr[i].Mul(decimal.FromFloat64(3.0))

				hitTP := false
				hitSL := false

				for h := 1; h <= horizon; h++ {
					fc := candles[i+h]
					if isSkippedBull {
						slPrice := entry.Sub(atrDist)
						tpPrice := entry.Add(tpDist)
						if fc.Low.Cmp(slPrice) <= 0 {
							hitSL = true
						}
						if fc.High.Cmp(tpPrice) >= 0 {
							hitTP = true
						}
					} else {
						slPrice := entry.Add(atrDist)
						tpPrice := entry.Sub(tpDist)
						if fc.High.Cmp(slPrice) >= 0 {
							hitSL = true
						}
						if fc.Low.Cmp(tpPrice) <= 0 {
							hitTP = true
						}
					}
					if hitTP || hitSL {
						break
					}
				}

				if hitTP && hitSL {
					ambiguousCount++
					slHits++
				} else if hitTP {
					tpHits++
				} else if hitSL {
					slHits++
				} else {
					timeouts++
				}
			}
		}

		metrics["total_skips_evaluated"] = totalSkips
		metrics["tp_hits"] = tpHits
		metrics["sl_hits"] = slHits
		metrics["timeouts"] = timeouts
		metrics["ambiguous"] = ambiguousCount
		metrics["restrictive_skips"] = tpHits
		metrics["protective_skips"] = slHits
		if totalSkips > 0 {
			metrics["missed_win_ratio"] = math.Round(float64(tpHits)/float64(totalSkips)*10000) / 10000
			metrics["avoided_loss_ratio"] = math.Round(float64(slHits)/float64(totalSkips)*10000) / 10000
		} else {
			metrics["missed_win_ratio"] = 0.0
			metrics["avoided_loss_ratio"] = 0.0
		}

	case contracts.KindFeatureBuild:
		closes := make([]decimal.Decimal, len(candles))
		for i, c := range candles {
			closes[i] = c.Close
		}
		fastEMA := algorithms.CalculateEMA(closes, 20)
		slowEMA := algorithms.CalculateEMA(closes, 50)
		rsi := algorithms.CalculateRSI(closes, 14)
		atr := algorithms.CalculateATR(candles, 14)
		bb := algorithms.CalculateBollingerBands(closes, 20, 2.0)
		donchian := algorithms.CalculateDonchianChannels(candles, 20)

		datasetHash := datasets.ComputeDatasetHash(candles)
		validRows := 0
		for i := 50; i < len(candles); i++ {
			if fastEMA[i] != nil && slowEMA[i] != nil && rsi[i] != nil && atr[i] != nil && bb.Upper[i] != nil && donchian.Upper[i] != nil {
				validRows++
			}
		}

		metrics["dataset_sha256"] = datasetHash
		metrics["total_candles"] = len(candles)
		metrics["materialized_rows"] = validRows
		metrics["features"] = []string{"ema_20", "ema_50", "rsi_14", "atr_14", "bb_upper", "bb_lower", "donchian_upper", "donchian_lower"}
		if len(candles) > 0 {
			metrics["start_time"] = candles[0].OpenTime.Format(time.RFC3339)
			metrics["end_time"] = candles[len(candles)-1].OpenTime.Format(time.RFC3339)
		}

	default:
		unsupportedErr := fmt.Errorf("unsupported or unimplemented research job kind: %s", req.Kind)
		if fjErr := r.FailJob(ctx, req.JobID, fencingToken, unsupportedErr); fjErr != nil {
			log.Printf("[jobrunner] FailJob returned error: %v", fjErr)
		}
		log.Printf("[jobrunner] Rejected job %s with unsupported kind %s: %v", req.JobID, req.Kind, unsupportedErr)
		return unsupportedErr
	}

	runtimeStats := tracker.Stop(rowsProcessed)

	result := contracts.JobResult{
		JobID:        req.JobID,
		Kind:         req.Kind,
		Status:       contracts.StatusSucceeded,
		Metrics:      metrics,
		RuntimeStats: runtimeStats,
		CompletedAt:  contracts.FormatNowUTC(),
	}

	if err := r.CompleteJob(ctx, req.JobID, fencingToken, result); err != nil {
		log.Printf("[jobrunner] Failed to complete job %s: %v", req.JobID, err)
		return err
	}

	log.Printf("[jobrunner] Succeeded job %s kind=%s duration=%dms rows=%d",
		req.JobID, req.Kind, runtimeStats.DurationMS, runtimeStats.RowsProcessed)

	return nil
}

// RegisterWorker registers or updates the worker node in research_workers table.
// Returns the registered generation fencing token.
func (r *Runner) RegisterWorker(ctx context.Context, version string, algorithms []string, hostname string, pid int) error {
	algoJSON, err := json.Marshal(algorithms)
	if err != nil {
		algoJSON = []byte("[]")
	}
	now := time.Now().UTC()

	query := `
		INSERT INTO research_workers (
			worker_id, engine, version, status, hostname, pid, supported_algorithms,
			heartbeat_at, registered_at, created_at, updated_at, generation_token
		) VALUES (
			$1, 'go', $2, 'ready', $3, $4, $5, $6, $6, $6, $6, 1
		)
		ON CONFLICT (worker_id) DO UPDATE SET
			generation_token = research_workers.generation_token + 1,
			engine = 'go',
			version = EXCLUDED.version,
			status = 'ready',
			hostname = EXCLUDED.hostname,
			pid = EXCLUDED.pid,
			supported_algorithms = EXCLUDED.supported_algorithms,
			heartbeat_at = EXCLUDED.heartbeat_at,
			updated_at = EXCLUDED.updated_at
		RETURNING generation_token;
	`
	var genToken int64
	err = r.db.QueryRowContext(ctx, query, r.workerID, version, hostname, pid, string(algoJSON), now).Scan(&genToken)
	if err != nil {
		return fmt.Errorf("failed to register worker %s: %w", r.workerID, err)
	}
	r.generationToken = genToken
	return nil
}

// SendHeartbeat updates heartbeat timestamp and status for the worker under generation fencing.
func (r *Runner) SendHeartbeat(ctx context.Context, status string) error {
	now := time.Now().UTC()
	query := `
		UPDATE research_workers
		SET heartbeat_at = $1, status = $2, updated_at = $1
		WHERE worker_id = $3 AND generation_token = $4
	`
	res, err := r.db.ExecContext(ctx, query, now, status, r.workerID, r.generationToken)
	if err != nil {
		return fmt.Errorf("failed to send heartbeat for worker %s: %w", r.workerID, err)
	}
	rows, err := res.RowsAffected()
	if err != nil {
		return fmt.Errorf("failed to inspect affected rows on heartbeat: %w", err)
	}
	if rows == 0 {
		return ErrStaleGeneration
	}
	return nil
}

// RecordJobCompletion records job completion metrics and sets status back to ready under generation fencing.
func (r *Runner) RecordJobCompletion(ctx context.Context, jobID string, success bool) error {
	now := time.Now().UTC()
	var query string
	if success {
		query = `
			UPDATE research_workers
			SET status = 'ready',
			    heartbeat_at = $1,
			    last_job_id = $2,
			    last_job_finished_at = $1,
			    total_jobs_completed = total_jobs_completed + 1,
			    updated_at = $1
			WHERE worker_id = $3 AND generation_token = $4
		`
	} else {
		query = `
			UPDATE research_workers
			SET status = 'ready',
			    heartbeat_at = $1,
			    last_job_id = $2,
			    last_job_finished_at = $1,
			    total_jobs_failed = total_jobs_failed + 1,
			    updated_at = $1
			WHERE worker_id = $3 AND generation_token = $4
		`
	}
	res, err := r.db.ExecContext(ctx, query, now, jobID, r.workerID, r.generationToken)
	if err != nil {
		return fmt.Errorf("failed to record job completion for worker %s: %w", r.workerID, err)
	}
	rows, err := res.RowsAffected()
	if err != nil {
		return fmt.Errorf("failed to inspect affected rows on job completion: %w", err)
	}
	if rows == 0 {
		return ErrStaleGeneration
	}
	return nil
}

// UnregisterWorker sets worker status to stopped on graceful shutdown under generation fencing.
func (r *Runner) UnregisterWorker(ctx context.Context) error {
	now := time.Now().UTC()
	query := `
		UPDATE research_workers
		SET status = 'stopped', heartbeat_at = $1, updated_at = $1
		WHERE worker_id = $2 AND generation_token = $3
	`
	res, err := r.db.ExecContext(ctx, query, now, r.workerID, r.generationToken)
	if err != nil {
		return fmt.Errorf("failed to unregister worker %s: %w", r.workerID, err)
	}
	rows, err := res.RowsAffected()
	if err != nil {
		return fmt.Errorf("failed to inspect affected rows on unregister: %w", err)
	}
	if rows == 0 {
		return ErrStaleGeneration
	}
	return nil
}
