package jobrunner

import (
	"context"
	"database/sql"
	"database/sql/driver"
	"io"
	"strings"
	"sync"
	"testing"

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
	mu           sync.Mutex
	failedJobID  string
	failedReason string
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
	if strings.Contains(s.query, "RETURNING generation_token") {
		return &mockRows{singleVal: int64(1)}, nil
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
