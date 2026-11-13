package jobrunner

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"net/url"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/lib/pq"
	"github.com/traderbybit/research-go/internal/contracts"
	"github.com/traderbybit/research-go/internal/signalintel"
)

// These tests use an explicitly selected disposable database and a private schema.
// They never create application financial tables or share a schema with other tests.
func signalMarkoutTestDB(t *testing.T) *sql.DB {
	t.Helper()
	raw := os.Getenv("SIGNAL_MARKOUT_TEST_DSN")
	if raw == "" {
		t.Skip("SIGNAL_MARKOUT_TEST_DSN must select an isolated PostgreSQL test database")
	}
	dsn, err := url.Parse(raw)
	if err != nil || (dsn.Scheme != "postgres" && dsn.Scheme != "postgresql") {
		t.Fatal("SIGNAL_MARKOUT_TEST_DSN must be a PostgreSQL URL")
	}
	admin, err := sql.Open("postgres", raw)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = admin.Close() })
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	var database string
	if err := admin.QueryRowContext(ctx, "SELECT current_database()").Scan(&database); err != nil {
		t.Fatal(err)
	}
	if !strings.HasSuffix(database, "_test") {
		t.Fatal("refusing to create test fixtures outside a database ending in _test")
	}
	schema := fmt.Sprintf("signal_markout_%d", time.Now().UnixNano())
	if _, err := admin.ExecContext(ctx, "CREATE SCHEMA "+pq.QuoteIdentifier(schema)); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		if _, err := admin.Exec("DROP SCHEMA " + pq.QuoteIdentifier(schema) + " CASCADE"); err != nil {
			t.Error(err)
		}
	})
	query := dsn.Query()
	query.Set("search_path", schema)
	dsn.RawQuery = query.Encode()
	db, err := sql.Open("postgres", dsn.String())
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = db.Close() })
	_, err = db.ExecContext(ctx, `
CREATE TABLE research_workers (worker_id text PRIMARY KEY, generation_token bigint NOT NULL);
CREATE TABLE exchanges (id text PRIMARY KEY, lifecycle_status text NOT NULL DEFAULT 'OFFLINE_DATA_SOURCE');
INSERT INTO exchanges (id) VALUES ('bybit');
CREATE TABLE research_jobs (
 id bigserial PRIMARY KEY, job_id text UNIQUE NOT NULL, schema_version integer,
 idempotency_key text, kind text, algorithm_version text, code_revision text,
 strategy_id text, strategy_version text, configuration_hash text, model_version text,
 dataset_id text, dataset_sha256 text, as_of_utc timestamptz, training_cutoff_utc timestamptz,
 research_mode text, random_seed bigint, limits jsonb, payload jsonb, created_by text,
 requested_at_utc timestamptz, fencing_token bigint NOT NULL DEFAULT 1,
 status text, lease_owner text, lease_expires_at timestamptz, heartbeat_at timestamptz,
 started_at timestamptz, completed_at timestamptz, claimed_at timestamptz,
 result_manifest_sha256 text, result_payload jsonb, runtime_stats jsonb,
 progress integer, progress_message text, sanitized_error text,
 retry_count integer NOT NULL DEFAULT 0, max_retries integer NOT NULL DEFAULT 0
);`)
	if err != nil {
		t.Fatal(err)
	}
	return db
}

func signalMarkoutJobFixture() (*contracts.JobRequest, signalintel.Request) {
	start := int64(1704067200000)
	asOf := time.UnixMilli(start + signalintel.MinuteMS).Add(123456 * time.Microsecond)
	packet := signalintel.Request{Operation: "evaluate", Evaluation: &signalintel.EvaluationRequest{
		Signal: signalintel.Signal{ID: "signal-1", Exchange: "bybit", Category: "linear", Symbol: "BTCUSDT",
			StrategyID: "baseline", StrategyVersion: "1.0.0", SourceTimeframeMinutes: 15,
			DecisionMS: start, AvailableMS: start, FeatureAvailableMS: start, Direction: "BUY", ReferencePrice: "100", Regime: "UNCERTAIN"},
		AsOfMS: asOf.UnixMilli(), HorizonsMinutes: []int{1}, Notional: "5", HorizonAnchor: "AVAILABILITY",
		Instrument: signalintel.Instrument{Exchange: "bybit", Category: "linear", Symbol: "BTCUSDT", AvailableMS: start,
			TickSize: "0.01", QuantityStep: "0.001", MinQuantity: "0.001", MaxQuantity: "1000", MinNotional: "2"},
		Costs: signalintel.Costs{SourceID: "test-cost-v1", TakerRate: "0.0005", SlippageRate: "0.001", FundingComplete: true,
			FundingScheduleID: "test-eight-hours", FundingIntervalMinutes: 480, FundingCoverageFromMS: start,
			FundingCoverageToMS: start + signalintel.MinuteMS},
		Provenance: signalintel.Provenance{DatasetID: "dataset-a", DatasetHash: strings.Repeat("a", 64),
			ConfigurationHash: strings.Repeat("b", 64), CodeRevision: strings.Repeat("c", 40)},
		Candles: []signalintel.Candle{{Exchange: "bybit", Category: "linear", Symbol: "BTCUSDT", OpenMS: start,
			CloseMS: start + signalintel.MinuteMS, Closed: true, Open: "100", High: "102", Low: "99", Close: "101"}},
	}}
	req := &contracts.JobRequest{SchemaVersion: 1, JobID: "signal-markout-job", Kind: contracts.KindSignalMarkout,
		IdempotencyKey: "signal-markout-key", AlgorithmVersion: signalintel.LabelPolicy, CodeRevision: strings.Repeat("c", 40),
		StrategyID: "baseline", StrategyVersion: "1.0.0", ConfigurationHash: strings.Repeat("b", 64),
		DatasetID: "dataset-a", DatasetSHA256: strings.Repeat("a", 64), AsOfUTC: asOf.UTC().Format(time.RFC3339Nano),
		TrainingCutoffUTC: "2023-12-31T23:59:00.654321Z", ResearchMode: "HYPOTHETICAL", CreatedBy: "quant-qa",
		Limits:  contracts.JobLimits{MaxRows: 1, MaxDurationS: 5, MaxMemoryMiB: 64},
		Payload: map[string]interface{}{"exchange": "bybit", "signal_intelligence": packet}}
	return req, packet
}

func claimSignalMarkoutFixture(t *testing.T, db *sql.DB) (*Runner, *contracts.JobRequest, int64) {
	t.Helper()
	req, _ := signalMarkoutJobFixture()
	return claimSignalMarkoutRequest(t, db, req)
}

func claimSignalMarkoutRequest(t *testing.T, db *sql.DB, req *contracts.JobRequest) (*Runner, *contracts.JobRequest, int64) {
	t.Helper()
	payload, err := json.Marshal(req.Payload)
	if err != nil {
		t.Fatal(err)
	}
	limits, err := json.Marshal(req.Limits)
	if err != nil {
		t.Fatal(err)
	}
	_, err = db.Exec(`INSERT INTO research_workers VALUES ('signal-markout-worker', 1)`)
	if err != nil {
		t.Fatal(err)
	}
	_, err = db.Exec(`INSERT INTO research_jobs
(job_id,schema_version,idempotency_key,kind,algorithm_version,code_revision,strategy_id,strategy_version,
 configuration_hash,dataset_id,dataset_sha256,as_of_utc,training_cutoff_utc,research_mode,random_seed,
 limits,payload,created_by,requested_at_utc,status)
VALUES ($1,1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,'HYPOTHETICAL',0,$13,$14,'quant-qa',clock_timestamp(),'QUEUED')`,
		req.JobID, req.IdempotencyKey, req.Kind, req.AlgorithmVersion, req.CodeRevision, req.StrategyID, req.StrategyVersion,
		req.ConfigurationHash, req.DatasetID, req.DatasetSHA256, req.AsOfUTC, req.TrainingCutoffUTC, limits, payload)
	if err != nil {
		t.Fatal(err)
	}
	runner := NewRunner(db, "signal-markout-worker")
	runner.generationToken = 1
	claimed, token, err := runner.ClaimNextJob(context.Background(), time.Minute)
	if err != nil || claimed == nil {
		t.Fatal("claim did not return seeded markout job", err)
	}
	if claimed.AsOfUTC != req.AsOfUTC || claimed.TrainingCutoffUTC != req.TrainingCutoffUTC {
		t.Fatal("claim lost fractional immutable cutoffs", claimed.AsOfUTC, claimed.TrainingCutoffUTC)
	}
	return runner, claimed, token
}

func TestSignalMarkoutPostgresClaimAndComplete(t *testing.T) {
	db := signalMarkoutTestDB(t)
	runner, req, token := claimSignalMarkoutFixture(t, db)
	if err := runner.ExecuteJob(context.Background(), req, token); err != nil {
		t.Fatal(err)
	}
	var status, manifest string
	var progress, rows int
	var payload, stats []byte
	if err := db.QueryRow(`SELECT status,progress,result_manifest_sha256,result_payload,runtime_stats
FROM research_jobs WHERE job_id=$1`, req.JobID).Scan(&status, &progress, &manifest, &payload, &stats); err != nil {
		t.Fatal(err)
	}
	var metrics map[string]interface{}
	if err := json.Unmarshal(payload, &metrics); err != nil {
		t.Fatal(err)
	}
	expectedManifest, err := contracts.ComputeManifestHash(metrics)
	if err != nil || manifest != expectedManifest || status != contracts.StatusSucceeded || progress != 100 {
		t.Fatal("completed job or result manifest is invalid", status, progress, manifest, expectedManifest, err)
	}
	text, ok := metrics["signal_intelligence_json"].(string)
	if !ok || metrics["research_only"] != true {
		t.Fatal("missing serialized research-only report", metrics)
	}
	reportHash, err := contracts.ComputeManifestHash(json.RawMessage(text))
	if err != nil || reportHash != metrics["signal_intelligence_sha256"] {
		t.Fatal("report digest does not cover exact serialized report", err)
	}
	var report signalintel.Response
	if err := json.Unmarshal([]byte(text), &report); err != nil {
		t.Fatal(err)
	}
	if report.Evaluation == nil || len(report.Evaluation.Outcomes) != 1 || !report.Evaluation.ResearchOnly ||
		report.Evaluation.EntryAuthority || report.Evaluation.Outcomes[0].Status != "MATURED" ||
		report.Evaluation.Outcomes[0].NetPnL == nil {
		t.Fatal("worker did not persist genuine mature research evidence", report)
	}
	var runtime contracts.RuntimeStats
	if err := json.Unmarshal(stats, &runtime); err != nil {
		t.Fatal(err)
	}
	rows = runtime.RowsProcessed
	if rows != 1 {
		t.Fatal("worker reported an incorrect processed row count", rows)
	}
}

func TestSignalMarkoutPostgresRejectsTamperedImmutableInputs(t *testing.T) {
	cases := map[string]func(*contracts.JobRequest, *signalintel.Request){
		"exchange":         func(_ *contracts.JobRequest, p *signalintel.Request) { p.Evaluation.Signal.Exchange = "binance" },
		"strategy":         func(_ *contracts.JobRequest, p *signalintel.Request) { p.Evaluation.Signal.StrategyID = "momentum" },
		"strategy_version": func(_ *contracts.JobRequest, p *signalintel.Request) { p.Evaluation.Signal.StrategyVersion = "2.0.0" },
		"configuration": func(_ *contracts.JobRequest, p *signalintel.Request) {
			p.Evaluation.Provenance.ConfigurationHash = strings.Repeat("d", 64)
		},
		"revision": func(_ *contracts.JobRequest, p *signalintel.Request) {
			p.Evaluation.Provenance.CodeRevision = strings.Repeat("d", 40)
		},
		"dataset_id": func(_ *contracts.JobRequest, p *signalintel.Request) { p.Evaluation.Provenance.DatasetID = "dataset-b" },
		"dataset_hash": func(_ *contracts.JobRequest, p *signalintel.Request) {
			p.Evaluation.Provenance.DatasetHash = strings.Repeat("d", 64)
		},
		"inner_cutoff":       func(_ *contracts.JobRequest, p *signalintel.Request) { p.Evaluation.AsOfMS++ },
		"outer_cutoff":       func(r *contracts.JobRequest, _ *signalintel.Request) { r.AsOfUTC = "invalid" },
		"missing_row_limit":  func(r *contracts.JobRequest, _ *signalintel.Request) { r.Limits.MaxRows = 0 },
		"paper_forward_mode": func(r *contracts.JobRequest, _ *signalintel.Request) { r.ResearchMode = "PAPER_FORWARD" },
		"live_mode":          func(r *contracts.JobRequest, _ *signalintel.Request) { r.ResearchMode = "LIVE" },
		"row_limit": func(_ *contracts.JobRequest, p *signalintel.Request) {
			p.Evaluation.Candles = append(p.Evaluation.Candles, p.Evaluation.Candles[0])
		},
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			db := signalMarkoutTestDB(t)
			runner, req, token := claimSignalMarkoutFixture(t, db)
			_, packet := signalMarkoutJobFixture()
			mutate(req, &packet)
			req.Payload["signal_intelligence"] = packet
			if err := runner.ExecuteJob(context.Background(), req, token); err == nil {
				t.Fatal("tampered immutable input was accepted")
			}
			var status string
			var result []byte
			if err := db.QueryRow("SELECT status,result_payload FROM research_jobs WHERE job_id=$1", req.JobID).Scan(&status, &result); err != nil {
				t.Fatal(err)
			}
			if status != contracts.StatusFailed || result != nil {
				t.Fatal("tampered job persisted successful research results", status, string(result))
			}
		})
	}
}

func TestSignalMarkoutPostgresAcceptsSupportedLowercaseKind(t *testing.T) {
	db := signalMarkoutTestDB(t)
	runner, req, token := claimSignalMarkoutFixture(t, db)
	req.Kind = strings.ToLower(contracts.KindSignalMarkout)
	if !IsSupportedKind(req.Kind) {
		t.Fatal("lowercase kind is no longer advertised as supported")
	}
	if err := runner.ExecuteJob(context.Background(), req, token); err != nil {
		t.Fatal("supported lowercase markout fell through to ordinary candle loading", err)
	}
	var status string
	if err := db.QueryRow("SELECT status FROM research_jobs WHERE job_id=$1", req.JobID).Scan(&status); err != nil {
		t.Fatal(err)
	}
	if status != contracts.StatusSucceeded {
		t.Fatal("supported lowercase markout did not complete", status)
	}
}

func signalMarkoutCohortFixture(t *testing.T) (*contracts.JobRequest, signalintel.Request) {
	t.Helper()
	req, evaluation := signalMarkoutJobFixture()
	result, err := signalintel.Evaluate(context.Background(), *evaluation.Evaluation)
	if err != nil || len(result.Outcomes) != 1 || result.Outcomes[0].Status != "MATURED" {
		t.Fatal("cohort requires genuine matured observation", result, err)
	}
	outcome := result.Outcomes[0]
	provenance := evaluation.Evaluation.Provenance
	observations := []signalintel.Observation{{ID: outcome.SignalID, InputHash: outcome.InputHash, Scope: outcome.Scope,
		Status: outcome.Status, AvailableMS: outcome.EntryMS, EndMS: outcome.EndMS, NetReturn: *outcome.NetReturn}}
	provenance.DatasetID = "dataset-cohort-a"
	provenance.DatasetHash, err = signalintel.CohortDatasetHash(observations)
	if err != nil {
		t.Fatal(err)
	}
	packet := signalintel.Request{Operation: "classify", Cohort: &signalintel.CohortRequest{Scope: outcome.Scope,
		AsOfMS: evaluation.Evaluation.AsOfMS, Policy: signalintel.DefaultQualitySettings(),
		Observations: observations, Provenance: &provenance}}
	req.DatasetID, req.DatasetSHA256 = provenance.DatasetID, provenance.DatasetHash
	req.Payload["signal_intelligence"] = packet
	return req, packet
}

func TestSignalMarkoutPostgresCohortClaimAndComplete(t *testing.T) {
	db := signalMarkoutTestDB(t)
	req, _ := signalMarkoutCohortFixture(t)
	runner, claimed, token := claimSignalMarkoutRequest(t, db, req)
	if err := runner.ExecuteJob(context.Background(), claimed, token); err != nil {
		t.Fatal(err)
	}
	var status, manifest string
	var payload []byte
	if err := db.QueryRow(`SELECT status,result_manifest_sha256,result_payload FROM research_jobs WHERE job_id=$1`, req.JobID).
		Scan(&status, &manifest, &payload); err != nil {
		t.Fatal(err)
	}
	var metrics map[string]interface{}
	if err := json.Unmarshal(payload, &metrics); err != nil {
		t.Fatal(err)
	}
	expectedManifest, err := contracts.ComputeManifestHash(metrics)
	if err != nil || manifest != expectedManifest || status != contracts.StatusSucceeded {
		t.Fatal("completed cohort or result manifest is invalid", status, manifest, expectedManifest, err)
	}
	var report signalintel.Response
	if err := json.Unmarshal([]byte(metrics["signal_intelligence_json"].(string)), &report); err != nil {
		t.Fatal(err)
	}
	if report.Cohort == nil || report.Cohort.Classification != "INSUFFICIENT_DATA" || report.Cohort.TotalObservations != 1 ||
		!report.Cohort.ResearchOnly || report.Cohort.EntryAuthority {
		t.Fatal("worker did not persist bounded research-only cohort", report)
	}
}

func TestSignalMarkoutPostgresCohortRejectsTamperedProvenance(t *testing.T) {
	cases := map[string]func(*signalintel.CohortRequest){
		"missing_provenance": func(p *signalintel.CohortRequest) { p.Provenance = nil },
		"dataset_id":         func(p *signalintel.CohortRequest) { p.Provenance.DatasetID = "dataset-other" },
		"dataset_hash":       func(p *signalintel.CohortRequest) { p.Provenance.DatasetHash = strings.Repeat("d", 64) },
		"configuration":      func(p *signalintel.CohortRequest) { p.Provenance.ConfigurationHash = strings.Repeat("d", 64) },
		"revision":           func(p *signalintel.CohortRequest) { p.Provenance.CodeRevision = strings.Repeat("d", 40) },
		"observation":        func(p *signalintel.CohortRequest) { p.Observations[0].NetReturn = "0.25" },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			db := signalMarkoutTestDB(t)
			req, packet := signalMarkoutCohortFixture(t)
			runner, claimed, token := claimSignalMarkoutRequest(t, db, req)
			mutate(packet.Cohort)
			claimed.Payload["signal_intelligence"] = packet
			if err := runner.ExecuteJob(context.Background(), claimed, token); err == nil {
				t.Fatal("tampered cohort dataset was accepted")
			}
			var status string
			var result []byte
			if err := db.QueryRow("SELECT status,result_payload FROM research_jobs WHERE job_id=$1", req.JobID).Scan(&status, &result); err != nil {
				t.Fatal(err)
			}
			if status != contracts.StatusFailed || result != nil {
				t.Fatal("tampered cohort persisted successful research results", status, string(result))
			}
		})
	}
}

func TestSignalMarkoutPostgresUnregisteredSourceRejectedBeforeReads(t *testing.T) {
	for _, kind := range []string{contracts.KindBacktest, contracts.KindSignalMarkout} {
		t.Run(kind, func(t *testing.T) {
			db := signalMarkoutTestDB(t)
			req, packet := signalMarkoutJobFixture()
			req.Kind = kind
			req.Payload["exchange"] = "unsupported_exchange"
			packet.Evaluation.Signal.Exchange = "unsupported_exchange"
			packet.Evaluation.Instrument.Exchange = "unsupported_exchange"
			packet.Evaluation.Candles[0].Exchange = "unsupported_exchange"
			req.Payload["signal_intelligence"] = packet
			runner, claimed, token := claimSignalMarkoutRequest(t, db, req)
			err := runner.ExecuteJob(context.Background(), claimed, token)
			// No market_candles table exists in the private fixture; the registry
			// error must precede even an attempted source read or computation.
			if err == nil || !strings.Contains(err.Error(), "missing or unsupported") {
				t.Fatal("unregistered exchange reached source reads or computation", err)
			}
			var status string
			var result []byte
			if err := db.QueryRow("SELECT status,result_payload FROM research_jobs WHERE job_id=$1", req.JobID).Scan(&status, &result); err != nil {
				t.Fatal(err)
			}
			if status != contracts.StatusFailed || result != nil {
				t.Fatal("unregistered exchange persisted successful research results", status, string(result))
			}
		})
	}
}

func TestSignalMarkoutPostgresRegisteredOfflineSourceSupported(t *testing.T) {
	db := signalMarkoutTestDB(t)
	if _, err := db.Exec("INSERT INTO exchanges (id) VALUES ('custom_offline_source')"); err != nil {
		t.Fatal(err)
	}
	req, packet := signalMarkoutJobFixture()
	req.Payload["exchange"] = "custom_offline_source"
	packet.Evaluation.Signal.Exchange = "custom_offline_source"
	packet.Evaluation.Instrument.Exchange = "custom_offline_source"
	packet.Evaluation.Candles[0].Exchange = "custom_offline_source"
	req.Payload["signal_intelligence"] = packet
	runner, claimed, token := claimSignalMarkoutRequest(t, db, req)
	if err := runner.ExecuteJob(context.Background(), claimed, token); err != nil {
		t.Fatal("registered offline source was blocked by a hardcoded exchange or PAPER gate", err)
	}
	var status string
	if err := db.QueryRow("SELECT status FROM research_jobs WHERE job_id=$1", req.JobID).Scan(&status); err != nil {
		t.Fatal(err)
	}
	if status != contracts.StatusSucceeded {
		t.Fatal("registered offline source did not complete", status)
	}
}
