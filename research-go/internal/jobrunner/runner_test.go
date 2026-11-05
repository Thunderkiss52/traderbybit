package jobrunner

import (
	"context"
	"database/sql"
	"database/sql/driver"
	"errors"
	"io"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/contracts"
)

func TestIsSupportedKind(t *testing.T) {
	supported := []string{
		contracts.KindBacktest,
		contracts.KindWalkForward,
		contracts.KindPatternMine,
		contracts.KindMonteCarlo,
		contracts.KindReplay,
		contracts.KindSkipOutcomes,
		contracts.KindFeatureBuild,
	}

	for _, k := range supported {
		if !IsSupportedKind(k) {
			t.Fatalf("expected kind %s to be supported", k)
		}
	}

	unsupported := []string{
		contracts.KindModelTrain,
		"model_train",
		"UNKNOWN_KIND",
		"",
		"RANDOM_ACTION",
	}

	for _, k := range unsupported {
		if IsSupportedKind(k) {
			t.Fatalf("expected kind %s to be unsupported", k)
		}
	}
}

// Mock database driver to test ExecuteJob failure with unsupported kind
type mockDriver struct {
	mu                   sync.Mutex
	failedJobID          string
	failedReason         string
	lastCandleExchange   string
	executedSQL          []string
	simulateExpiredLease bool
	overrideGenToken     int64
	queryErr             error
}

func (m *mockDriver) reset() {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.failedJobID = ""
	m.failedReason = ""
	m.lastCandleExchange = ""
	m.executedSQL = nil
	m.simulateExpiredLease = false
	m.overrideGenToken = 0
	m.queryErr = nil
}

func (m *mockDriver) Open(name string) (driver.Conn, error) {
	return &mockConn{driver: m}, nil
}

type mockConn struct {
	driver *mockDriver
}

func (c *mockConn) Prepare(query string) (driver.Stmt, error) {
	return &mockStmt{query: query, conn: c}, nil
}

func (c *mockConn) Close() error { return nil }
func (c *mockConn) Begin() (driver.Tx, error) {
	return &mockTx{}, nil
}

type mockTx struct{}

func (t *mockTx) Commit() error   { return nil }
func (t *mockTx) Rollback() error { return nil }

type mockStmt struct {
	query string
	conn  *mockConn
}

func (s *mockStmt) Close() error { return nil }
func (s *mockStmt) NumInput() int {
	return -1
}

func (s *mockStmt) Exec(args []driver.Value) (driver.Result, error) {
	s.conn.driver.mu.Lock()
	s.conn.driver.executedSQL = append(s.conn.driver.executedSQL, s.query)
	expired := s.conn.driver.simulateExpiredLease
	s.conn.driver.mu.Unlock()
	if expired && strings.Contains(s.query, "UPDATE research_jobs") && strings.Contains(s.query, "lease_expires_at > clock_timestamp()") {
		return driver.RowsAffected(0), nil
	}
	if strings.Contains(s.query, "sanitized_error = $1") {
		s.conn.driver.mu.Lock()
		if len(args) >= 3 {
			s.conn.driver.failedReason = args[0].(string)
			s.conn.driver.failedJobID = args[2].(string)
		}
		s.conn.driver.mu.Unlock()
	}
	return driver.RowsAffected(1), nil
}

func (s *mockStmt) Query(args []driver.Value) (driver.Rows, error) {
	if strings.Contains(s.query, "to_regclass('historical_download_entries')") {
		return &policyRows{}, nil
	}
	s.conn.driver.mu.Lock()
	qErr := s.conn.driver.queryErr
	genToken := s.conn.driver.overrideGenToken
	if strings.Contains(s.query, "FROM market_candles") && len(args) > 0 {
		if exchange, ok := args[0].(string); ok {
			s.conn.driver.lastCandleExchange = exchange
		}
	}
	s.conn.driver.mu.Unlock()

	if qErr != nil {
		return nil, qErr
	}
	if strings.Contains(s.query, "generation_token") {
		if genToken == 0 {
			genToken = 1
		}
		return &mockRows{singleVal: genToken}, nil
	}
	// For LoadCandlesFromDB
	return &mockRows{}, nil
}

type mockRows struct {
	read      bool
	singleVal any
}

func (r *mockRows) Columns() []string {
	if r.singleVal != nil {
		return []string{"generation_token"}
	}
	return []string{"id", "open_time", "open", "high", "low", "close", "volume", "turnover"}
}

func (r *mockRows) Close() error { return nil }
func (r *mockRows) Next(dest []driver.Value) error {
	if r.singleVal != nil {
		if r.read {
			return io.EOF
		}
		r.read = true
		dest[0] = r.singleVal
		return nil
	}
	return io.EOF
}

var (
	mockDrv     = &mockDriver{}
	registerDrv sync.Once
)

func initMockDB() *sql.DB {
	registerDrv.Do(func() {
		sql.Register("mock_jobrunner", mockDrv)
	})
	db, err := sql.Open("mock_jobrunner", "test")
	if err != nil {
		panic(err)
	}
	return db
}

func TestExecuteJob_RejectsUnsupportedKind(t *testing.T) {
	db := initMockDB()
	runner := NewRunner(db, "test-worker-1")
	runner.generationToken = 1

	req := &contracts.JobRequest{
		JobID:   "job-model-train-123",
		Kind:    contracts.KindModelTrain,
		Payload: map[string]interface{}{"symbol": "BTCUSDT"},
		Limits:  contracts.JobLimits{MaxRows: 100},
	}

	err := runner.ExecuteJob(context.Background(), req, 1)
	if err == nil {
		t.Fatalf("expected error executing unsupported kind %s, got nil", req.Kind)
	}

	if !strings.Contains(err.Error(), "unsupported or unimplemented") {
		t.Fatalf("expected unsupported error message, got: %v", err)
	}

	mockDrv.mu.Lock()
	defer mockDrv.mu.Unlock()

	if mockDrv.failedJobID != "job-model-train-123" {
		t.Fatalf("expected failed job ID %s, got %s", "job-model-train-123", mockDrv.failedJobID)
	}
	if !strings.Contains(mockDrv.failedReason, "unsupported") {
		t.Fatalf("expected failed reason to mention unsupported, got: %s", mockDrv.failedReason)
	}
}

func TestWorkerLifecycleMethods(t *testing.T) {
	db := initMockDB()
	runner := NewRunner(db, "test-worker-alpha")
	ctx := context.Background()

	// 1. Test RegisterWorker
	err := runner.RegisterWorker(ctx, "1.0.0", []string{"backtest", "walk_forward"}, "test-host", 12345)
	if err != nil {
		t.Fatalf("RegisterWorker failed: %v", err)
	}

	// 2. Test SendHeartbeat
	err = runner.SendHeartbeat(ctx, "busy")
	if err != nil {
		t.Fatalf("SendHeartbeat failed: %v", err)
	}

	// 3. Test RecordJobCompletion (success)
	err = runner.RecordJobCompletion(ctx, "test-job-123", true)
	if err != nil {
		t.Fatalf("RecordJobCompletion (success) failed: %v", err)
	}

	// 4. Test RecordJobCompletion (failure)
	err = runner.RecordJobCompletion(ctx, "test-job-456", false)
	if err != nil {
		t.Fatalf("RecordJobCompletion (failure) failed: %v", err)
	}

	// 5. Test UnregisterWorker
	err = runner.UnregisterWorker(ctx)
	if err != nil {
		t.Fatalf("UnregisterWorker failed: %v", err)
	}
}

func TestVerifyWorkerGeneration_UninitializedRejectsMutations(t *testing.T) {
	db := initMockDB()
	runner := NewRunner(db, "test-worker-zero")
	ctx := context.Background()

	// 1. ClaimNextJob
	_, _, err := runner.ClaimNextJob(ctx, 60*time.Second)
	if err == nil || !strings.Contains(err.Error(), "cannot claim job without a valid positive worker generation token") {
		t.Fatalf("expected uninitialized generation token error from ClaimNextJob, got: %v", err)
	}

	// 2. StartJob
	err = runner.StartJob(ctx, "job-uninit-1", 1)
	if err == nil || !strings.Contains(err.Error(), "worker generation token is required and must be strictly positive") {
		t.Fatalf("expected uninitialized generation token error from StartJob, got: %v", err)
	}

	// 3. CompleteJob
	err = runner.CompleteJob(ctx, "job-uninit-1", 1, contracts.JobResult{Status: "SUCCEEDED", ManifestSHA256: "manifest"})
	if err == nil || !strings.Contains(err.Error(), "worker generation token is required and must be strictly positive") {
		t.Fatalf("expected uninitialized generation token error from CompleteJob, got: %v", err)
	}

	// 4. FailJob
	err = runner.FailJob(ctx, "job-uninit-1", 1, errors.New("err"))
	if err == nil || !strings.Contains(err.Error(), "worker generation token is required and must be strictly positive") {
		t.Fatalf("expected uninitialized generation token error from FailJob, got: %v", err)
	}

	// 5. Heartbeat
	err = runner.Heartbeat(ctx, "job-uninit-1", 1, 50, "running", 60*time.Second)
	if err == nil || !strings.Contains(err.Error(), "worker generation token is required and must be strictly positive") {
		t.Fatalf("expected uninitialized generation token error from Heartbeat, got: %v", err)
	}
}

func TestVerifyWorkerGeneration_SupersededMismatchRejectsMutations(t *testing.T) {
	db := initMockDB()
	mockDrv.reset()
	mockDrv.mu.Lock()
	mockDrv.overrideGenToken = 2 // DB has generation 2
	mockDrv.mu.Unlock()
	defer mockDrv.reset()

	runner := NewRunner(db, "test-worker-stale")
	runner.generationToken = 1 // Worker process is still on generation 1
	ctx := context.Background()

	// 1. StartJob fails with ErrStaleGeneration
	err := runner.StartJob(ctx, "job-stale-1", 1)
	if !errors.Is(err, ErrStaleGeneration) {
		t.Fatalf("expected ErrStaleGeneration from StartJob, got: %v", err)
	}

	// 2. CompleteJob fails with ErrStaleGeneration
	err = runner.CompleteJob(ctx, "job-stale-1", 1, contracts.JobResult{Status: "SUCCEEDED", ManifestSHA256: "manifest"})
	if !errors.Is(err, ErrStaleGeneration) {
		t.Fatalf("expected ErrStaleGeneration from CompleteJob, got: %v", err)
	}

	// 3. FailJob fails with ErrStaleGeneration
	err = runner.FailJob(ctx, "job-stale-1", 1, errors.New("err"))
	if !errors.Is(err, ErrStaleGeneration) {
		t.Fatalf("expected ErrStaleGeneration from FailJob, got: %v", err)
	}

	// 4. Heartbeat fails with ErrStaleGeneration
	err = runner.Heartbeat(ctx, "job-stale-1", 1, 50, "running", 60*time.Second)
	if !errors.Is(err, ErrStaleGeneration) {
		t.Fatalf("expected ErrStaleGeneration from Heartbeat, got: %v", err)
	}
}

func TestVerifyWorkerGeneration_DBErrorPropagates(t *testing.T) {
	db := initMockDB()
	mockDrv.reset()
	mockDrv.mu.Lock()
	mockDrv.queryErr = errors.New("simulated database network failure")
	mockDrv.mu.Unlock()
	defer mockDrv.reset()

	runner := NewRunner(db, "test-worker-dberr")
	runner.generationToken = 1
	ctx := context.Background()

	err := runner.StartJob(ctx, "job-dberr-1", 1)
	if err == nil || !strings.Contains(err.Error(), "simulated database network failure") {
		t.Fatalf("expected database network failure to propagate, got: %v", err)
	}
}

// No legacy implicit Bybit fallback: source-less jobs must never select a market.
func TestExecuteJobRejectsMissingExchangeBeforeCandleRead(t *testing.T) {
	mockDrv.reset()
	db := initMockDB()
	defer db.Close()
	runner := NewRunner(db, "test-worker-source")
	runner.generationToken = 1
	req := &contracts.JobRequest{JobID: "missing-exchange", Kind: contracts.KindBacktest,
		Payload: map[string]interface{}{"symbol": "BTCUSDT"}}
	err := runner.ExecuteJob(context.Background(), req, 1)
	if err == nil || !strings.Contains(err.Error(), "exchange") {
		t.Fatalf("expected explicit exchange error, got: %v", err)
	}
	mockDrv.mu.Lock()
	defer mockDrv.mu.Unlock()
	if mockDrv.lastCandleExchange != "" {
		t.Fatal("source-less job unexpectedly queried candles")
	}
}

func TestBinanceJobLoadsBinanceCandles(t *testing.T) {
	mockDrv.reset()
	db := initMockDB()
	defer db.Close()
	runner := NewRunner(db, "test-worker-binance")
	runner.generationToken = 1
	req := &contracts.JobRequest{JobID: "binance-scope", Kind: contracts.KindBacktest,
		Payload: map[string]interface{}{"exchange": "binance", "symbol": "BTCUSDT"}}
	_ = runner.ExecuteJob(context.Background(), req, 1) // empty mocked dataset can fail backtest
	mockDrv.mu.Lock()
	defer mockDrv.mu.Unlock()
	if mockDrv.lastCandleExchange != "binance" {
		t.Fatalf("expected Binance-scoped DB query, got %q", mockDrv.lastCandleExchange)
	}
}

func TestFailJob_SuccessfulExecution(t *testing.T) {
	mockDrv.reset()
	db := initMockDB()
	defer db.Close()
	runner := NewRunner(db, "test-worker-failjob")
	runner.generationToken = 1
	ctx := context.Background()

	err := runner.FailJob(ctx, "job-fail-123", 1, errors.New("simulated error message"))
	if err != nil {
		t.Fatalf("expected FailJob to succeed, got: %v", err)
	}

	mockDrv.mu.Lock()
	defer mockDrv.mu.Unlock()
	if mockDrv.failedJobID != "job-fail-123" {
		t.Fatalf("expected failedJobID to be job-fail-123, got: %s", mockDrv.failedJobID)
	}
	if !strings.Contains(mockDrv.failedReason, "simulated error message") {
		t.Fatalf("expected failedReason to contain simulated error message, got: %s", mockDrv.failedReason)
	}
}

// A current fencing token is not sufficient after its lease has expired:
// the authoritative database clock must reject stale work before mutation.
func TestLeaseExpiryGuardsAllWorkerStateTransitions(t *testing.T) {
	db := initMockDB()
	defer db.Close()
	mockDrv.reset()
	defer mockDrv.reset()
	r := NewRunner(db, "worker-expired-lease")
	r.generationToken = 1
	ctx := context.Background()
	operations := []struct {
		name string
		run  func() error
	}{
		{"start", func() error { return r.StartJob(ctx, "job", 2) }},
		{"heartbeat", func() error { return r.Heartbeat(ctx, "job", 2, 0, "running", 60*time.Second) }},
		{"complete", func() error {
			return r.CompleteJob(ctx, "job", 2, contracts.JobResult{Metrics: map[string]interface{}{"ok": true}})
		}},
		{"fail", func() error { return r.FailJob(ctx, "job", 2, errors.New("postgres://user:SECRET@host")) }},
	}
	for _, op := range operations {
		t.Run(op.name, func(t *testing.T) {
			mockDrv.reset()
			if err := op.run(); err != nil {
				t.Fatal(err)
			}
			mockDrv.mu.Lock()
			queries := append([]string(nil), mockDrv.executedSQL...)
			failure := mockDrv.failedReason
			mockDrv.mu.Unlock()
			found := false
			for _, query := range queries {
				if strings.Contains(query, "UPDATE research_jobs") &&
					strings.Contains(query, "lease_expires_at > clock_timestamp()") {
					found = true
				}
			}
			if !found {
				t.Fatalf("%s missing database lease-expiry fence in UPDATE: %v", op.name, queries)
			}
			if op.name == "fail" && strings.Contains(failure, "SECRET") {
				t.Fatalf("sensitive failure exposed to API: %q", failure)
			}
		})
	}
	mockDrv.reset()
}

// policyRows models a schema predating historical acquisition; no restricted sources exist.
type policyRows struct{ done bool }

func (r *policyRows) Columns() []string { return []string{"installed"} }
func (r *policyRows) Close() error      { return nil }
func (r *policyRows) Next(dest []driver.Value) error {
	if r.done {
		return io.EOF
	}
	r.done = true
	dest[0] = false
	return nil
}
