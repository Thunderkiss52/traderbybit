package jobrunner_test

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"os"
	"testing"
	"time"

	_ "github.com/lib/pq"
	"github.com/traderbybit/research-go/internal/contracts"
	"github.com/traderbybit/research-go/internal/jobrunner"
)

func getTestDB(t *testing.T) *sql.DB {
	host := os.Getenv("DB_HOST")
	if host == "" {
		host = "127.0.0.1"
	}
	port := os.Getenv("DB_PORT")
	if port == "" {
		port = "25432"
	}
	user := os.Getenv("DB_USERNAME")
	if user == "" {
		user = "postgres"
	}
	pass := os.Getenv("DB_PASSWORD")
	if pass == "" {
		pass = "testpass"
	}
	dbname := os.Getenv("DB_DATABASE")
	if dbname == "" {
		dbname = "bybit_test"
	}

	connStr := fmt.Sprintf("host=%s port=%s user=%s password='%s' dbname=%s sslmode=disable",
		host, port, user, pass, dbname)

	db, err := sql.Open("postgres", connStr)
	if err != nil {
		t.Skipf("PostgreSQL not available: %v", err)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	if err := db.PingContext(ctx); err != nil {
		t.Skipf("PostgreSQL ping failed (service not reachable on %s:%s): %v", host, port, err)
	}

	return db
}

func TestWorkerLifecycle_SupersededWorkerRejectedByPostgres(t *testing.T) {
	db := getTestDB(t)
	defer db.Close()

	ctx := context.Background()
	workerID := fmt.Sprintf("test-worker-go-e2e-%d", time.Now().UnixNano())
	defer func() {
		_, _ = db.Exec("DELETE FROM research_workers WHERE worker_id = $1", workerID)
	}()

	// 1. Worker A registers -> receives generation token = 1
	runnerA := jobrunner.NewRunner(db, workerID)
	err := runnerA.RegisterWorker(ctx, "1.0.0", []string{"backtest"}, "localhost", 1001)
	if err != nil {
		t.Fatalf("Worker A RegisterWorker failed: %v", err)
	}
	if runnerA.GenerationToken() != 1 {
		t.Fatalf("expected Worker A generation token 1, got %d", runnerA.GenerationToken())
	}

	// Seed a research job claimed by Worker A
	jobID := fmt.Sprintf("%08x-%04x-%04x-%04x-%012x", 0x12345678, 0x1234, 0x1234, 0x1234, time.Now().UnixNano()%0xffffffffffff)
	defer func() {
		_, _ = db.Exec("DELETE FROM research_jobs WHERE job_id = $1", jobID)
	}()

	_, err = db.Exec(`
		INSERT INTO research_jobs (
			job_id, schema_version, idempotency_key, kind, algorithm_version, code_revision,
			strategy_id, strategy_version, configuration_hash, research_mode, limits, payload,
			created_by, requested_at_utc, status, lease_owner, lease_expires_at, fencing_token
		) VALUES (
			$1, 1, $2, 'BACKTEST', '1.0.0', 'git-e2e',
			'trend_pullback', '1.0.0', 'e2ehash', 'RESEARCH', '{"max_rows": 100}', '{"symbol": "BTCUSDT"}',
			'e2e_test', NOW(), 'CLAIMED', $3, NOW() + INTERVAL '5 minutes', 1
		)
	`, jobID, "idemp-"+jobID, workerID)
	if err != nil {
		t.Fatalf("failed to seed test job: %v", err)
	}

	// 2. Worker B registers with the SAME workerID -> receives generation token = 2
	runnerB := jobrunner.NewRunner(db, workerID)
	err = runnerB.RegisterWorker(ctx, "1.0.0", []string{"backtest"}, "localhost", 1002)
	if err != nil {
		t.Fatalf("Worker B RegisterWorker failed: %v", err)
	}
	if runnerB.GenerationToken() != 2 {
		t.Fatalf("expected Worker B generation token 2, got %d", runnerB.GenerationToken())
	}

	// 3. Worker A (stale generation 1) attempts job mutations:
	// a. StartJob
	err = runnerA.StartJob(ctx, jobID, 1)
	if !errors.Is(err, jobrunner.ErrStaleGeneration) {
		t.Fatalf("expected ErrStaleGeneration from Worker A StartJob, got: %v", err)
	}

	// b. Heartbeat
	err = runnerA.Heartbeat(ctx, jobID, 1, 50, "Running", 60*time.Second)
	if !errors.Is(err, jobrunner.ErrStaleGeneration) {
		t.Fatalf("expected ErrStaleGeneration from Worker A Heartbeat, got: %v", err)
	}

	// c. CompleteJob
	err = runnerA.CompleteJob(ctx, jobID, 1, contracts.JobResult{
		Status:  "SUCCEEDED",
		Metrics: map[string]interface{}{"status": "fake"},
	})
	if !errors.Is(err, jobrunner.ErrStaleGeneration) {
		t.Fatalf("expected ErrStaleGeneration from Worker A CompleteJob, got: %v", err)
	}

	// d. FailJob
	err = runnerA.FailJob(ctx, jobID, 1, errors.New("simulated error"))
	if !errors.Is(err, jobrunner.ErrStaleGeneration) {
		t.Fatalf("expected ErrStaleGeneration from Worker A FailJob, got: %v", err)
	}

	// 4. Assert in PostgreSQL: job state remains untouched (CLAIMED, progress 0, no completed_at, no results)
	var status string
	var completedAt sql.NullTime
	var resultPayload sql.NullString
	err = db.QueryRow("SELECT status, completed_at, result_payload FROM research_jobs WHERE job_id = $1", jobID).Scan(&status, &completedAt, &resultPayload)
	if err != nil {
		t.Fatalf("querying job state failed: %v", err)
	}
	if status != "CLAIMED" {
		t.Fatalf("expected job status to remain CLAIMED, got %s", status)
	}
	if completedAt.Valid {
		t.Fatalf("expected completed_at to be NULL, got %v", completedAt.Time)
	}
	if resultPayload.Valid {
		t.Fatalf("expected result_payload to be NULL, got %s", resultPayload.String)
	}

	// 5. Worker B (active generation 2) CAN mutate and complete the job
	err = runnerB.StartJob(ctx, jobID, 1)
	if err != nil {
		t.Fatalf("Worker B StartJob failed: %v", err)
	}

	err = runnerB.CompleteJob(ctx, jobID, 1, contracts.JobResult{
		Status:       "SUCCEEDED",
		Metrics:      map[string]interface{}{"win_rate": 0.65, "valid": true},
		RuntimeStats: contracts.RuntimeStats{DurationMS: 50, RowsProcessed: 100},
	})
	if err != nil {
		t.Fatalf("Worker B CompleteJob failed: %v", err)
	}

	// 6. Assert in PostgreSQL: job is now SUCCEEDED with valid manifest
	var finalStatus string
	var manifestSHA string
	err = db.QueryRow("SELECT status, result_manifest_sha256 FROM research_jobs WHERE job_id = $1", jobID).Scan(&finalStatus, &manifestSHA)
	if err != nil {
		t.Fatalf("querying final job state failed: %v", err)
	}
	if finalStatus != "SUCCEEDED" {
		t.Fatalf("expected final job status SUCCEEDED, got %s", finalStatus)
	}
	if len(manifestSHA) != 64 {
		t.Fatalf("expected valid 64-char sha256 manifest, got %s", manifestSHA)
	}
}
