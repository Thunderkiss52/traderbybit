package contracts

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"time"
)

// Job kinds
const (
	KindBacktest     = "BACKTEST"
	KindWalkForward  = "WALK_FORWARD"
	KindPatternMine  = "PATTERN_MINE"
	KindReplay       = "REPLAY"
	KindSkipOutcomes = "SKIP_OUTCOMES"
	KindMonteCarlo   = "MONTE_CARLO"
	KindFeatureBuild = "FEATURE_BUILD"
	KindModelTrain   = "MODEL_TRAIN"
)

// Job states
const (
	StatusQueued    = "QUEUED"
	StatusClaimed   = "CLAIMED"
	StatusRunning   = "RUNNING"
	StatusSucceeded = "SUCCEEDED"
	StatusFailed    = "FAILED"
	StatusCancelled = "CANCELLED"
	StatusExpired   = "EXPIRED"
	StatusRetryWait = "RETRY_WAIT"
)

type JobLimits struct {
	MaxRows      int `json:"max_rows"`
	MaxDurationS int `json:"max_duration_s"`
	MaxMemoryMiB int `json:"max_memory_mib"`
}

type JobRequest struct {
	SchemaVersion     int                    `json:"schema_version"`
	JobID             string                 `json:"job_id"`
	IdempotencyKey    string                 `json:"idempotency_key"`
	Kind              string                 `json:"kind"`
	AlgorithmVersion  string                 `json:"algorithm_version"`
	CodeRevision      string                 `json:"code_revision"`
	StrategyID        string                 `json:"strategy_id,omitempty"`
	StrategyVersion   string                 `json:"strategy_version,omitempty"`
	ConfigurationHash string                 `json:"configuration_hash,omitempty"`
	ModelVersion      string                 `json:"model_version,omitempty"`
	DatasetID         string                 `json:"dataset_id,omitempty"`
	DatasetSHA256     string                 `json:"dataset_sha256,omitempty"`
	AsOfUTC           string                 `json:"as_of_utc,omitempty"`
	TrainingCutoffUTC string                 `json:"training_cutoff_utc,omitempty"`
	ResearchMode      string                 `json:"research_mode,omitempty"`
	RandomSeed        int64                  `json:"random_seed"`
	Limits            JobLimits              `json:"limits"`
	Payload           map[string]interface{} `json:"payload,omitempty"`
	CreatedBy         string                 `json:"created_by"`
	RequestedAtUTC    string                 `json:"requested_at_utc"`
}

type RuntimeStats struct {
	DurationMS    int64  `json:"duration_ms"`
	PeakRSSBytes  uint64 `json:"peak_rss_bytes"`
	CPUTimeMS     int64  `json:"cpu_time_ms"`
	RowsProcessed int    `json:"rows_processed"`
}

type JobResult struct {
	JobID          string                 `json:"job_id"`
	Kind           string                 `json:"kind"`
	Status         string                 `json:"status"`
	Metrics        map[string]interface{} `json:"metrics"`
	Artifacts      map[string]interface{} `json:"artifacts,omitempty"`
	RuntimeStats   RuntimeStats           `json:"runtime_stats"`
	ManifestSHA256 string                 `json:"manifest_sha256"`
	CompletedAt    string                 `json:"completed_at"`
}

// ComputeManifestHash calculates the canonical SHA-256 digest of result payload
func ComputeManifestHash(payload interface{}) (string, error) {
	bytes, err := json.Marshal(payload)
	if err != nil {
		return "", err
	}
	hash := sha256.Sum256(bytes)
	return hex.EncodeToString(hash[:]), nil
}

func FormatNowUTC() string {
	return time.Now().UTC().Format(time.RFC3339Nano)
}
