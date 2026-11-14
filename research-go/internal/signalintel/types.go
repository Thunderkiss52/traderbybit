// Package signalintel evaluates research-only fixed-horizon markouts and scoped evidence.
// It never submits an exchange order or writes PAPER accounting state.
package signalintel

const (
	LabelPolicy           = "HYPOTHETICAL_HORIZON_MARKOUT_V1"
	QualityPolicy         = "SIGNAL_QUALITY_HOEFFDING_V2"
	MinuteMS        int64 = 60000
	MaxTimestampMS  int64 = 9007199250000000
	MaxCandles            = 512
	MaxObservations       = 4096
)

type Request struct {
	Operation  string             `json:"operation"`
	Evaluation *EvaluationRequest `json:"evaluation,omitempty"`
	Cohort     *CohortRequest     `json:"cohort,omitempty"`
}

type Response struct {
	SchemaVersion int                 `json:"schema_version"`
	Evaluation    *EvaluationResponse `json:"evaluation,omitempty"`
	Cohort        *CohortResponse     `json:"cohort,omitempty"`
}

type Signal struct {
	ID                     string `json:"id"`
	Exchange               string `json:"exchange"`
	Category               string `json:"category"`
	Symbol                 string `json:"symbol"`
	StrategyID             string `json:"strategy_id"`
	StrategyVersion        string `json:"strategy_version"`
	SourceTimeframeMinutes int    `json:"source_timeframe_minutes"`
	DecisionMS             int64  `json:"decision_ms"`
	AvailableMS            int64  `json:"available_ms"`
	FeatureAvailableMS     int64  `json:"feature_available_ms"`
	Direction              string `json:"direction"`
	ReferencePrice         string `json:"reference_price"`
	Regime                 string `json:"regime"`
}

type Instrument struct {
	Exchange     string `json:"exchange"`
	Category     string `json:"category"`
	Symbol       string `json:"symbol"`
	AvailableMS  int64  `json:"available_ms"`
	TickSize     string `json:"tick_size"`
	QuantityStep string `json:"quantity_step"`
	MinQuantity  string `json:"min_quantity"`
	MaxQuantity  string `json:"max_quantity"`
	MinNotional  string `json:"min_notional"`
}

type Candle struct {
	Exchange string `json:"exchange"`
	Category string `json:"category"`
	Symbol   string `json:"symbol"`
	OpenMS   int64  `json:"open_ms"`
	CloseMS  int64  `json:"close_ms"`
	Closed   bool   `json:"closed"`
	Open     string `json:"open"`
	High     string `json:"high"`
	Low      string `json:"low"`
	Close    string `json:"close"`
}

type FundingEvent struct {
	Exchange  string `json:"exchange"`
	Category  string `json:"category"`
	Symbol    string `json:"symbol"`
	AtMS      int64  `json:"at_ms"`
	Rate      string `json:"rate"`
	MarkPrice string `json:"mark_price"`
}

type Costs struct {
	SourceID               string         `json:"source_id"`
	TakerRate              string         `json:"taker_rate"`
	SlippageRate           string         `json:"slippage_rate"`
	FundingComplete        bool           `json:"funding_complete"`
	FundingScheduleID      string         `json:"funding_schedule_id"`
	FundingIntervalMinutes int            `json:"funding_interval_minutes"`
	FundingOffsetMinutes   int            `json:"funding_offset_minutes"`
	FundingCoverageFromMS  int64          `json:"funding_coverage_from_ms"`
	FundingCoverageToMS    int64          `json:"funding_coverage_to_ms"`
	FundingEvents          []FundingEvent `json:"funding_events"`
}

type Provenance struct {
	DatasetID         string `json:"dataset_id"`
	DatasetHash       string `json:"dataset_hash"`
	ConfigurationHash string `json:"configuration_hash"`
	CodeRevision      string `json:"code_revision"`
}

type EvaluationRequest struct {
	Signal          Signal     `json:"signal"`
	AsOfMS          int64      `json:"as_of_ms"`
	HorizonsMinutes []int      `json:"horizons_minutes"`
	Notional        string     `json:"notional"`
	HorizonAnchor   string     `json:"horizon_anchor"`
	Instrument      Instrument `json:"instrument"`
	Costs           Costs      `json:"costs"`
	Provenance      Provenance `json:"provenance"`
	Candles         []Candle   `json:"candles"`
}

type Scope struct {
	Exchange               string `json:"exchange"`
	Category               string `json:"category"`
	Symbol                 string `json:"symbol"`
	StrategyID             string `json:"strategy_id"`
	StrategyVersion        string `json:"strategy_version"`
	SourceTimeframeMinutes int    `json:"source_timeframe_minutes"`
	HorizonMinutes         int    `json:"horizon_minutes"`
	Direction              string `json:"direction"`
	Regime                 string `json:"regime"`
	ConfigurationHash      string `json:"configuration_hash"`
	CodeRevision           string `json:"code_revision"`
	LabelPolicyVersion     string `json:"label_policy_version"`
	DataSource             string `json:"data_source"`
	HorizonAnchor          string `json:"horizon_anchor"`
	ExecutionHash          string `json:"execution_hash"`
}

// Nil metrics distinguish unavailable evidence from an observed monetary zero.
type Outcome struct {
	Scope              Scope   `json:"scope"`
	SignalID           string  `json:"signal_id"`
	InputHash          string  `json:"input_hash"`
	Status             string  `json:"status"`
	Reason             string  `json:"reason"`
	Source             string  `json:"source"`
	ExecutionModel     string  `json:"execution_model"`
	HorizonAnchor      string  `json:"horizon_anchor"`
	EntryMS            int64   `json:"entry_ms"`
	EndMS              int64   `json:"end_ms"`
	EntryPrice         *string `json:"entry_price"`
	ExitPrice          *string `json:"exit_price"`
	Quantity           *string `json:"quantity"`
	ExecutedNotional   *string `json:"executed_notional"`
	GrossPnL           *string `json:"gross_pnl"`
	Fees               *string `json:"fees"`
	Funding            *string `json:"funding"`
	NetPnL             *string `json:"net_pnl"`
	PriceReturn        *string `json:"price_return"`
	NetReturn          *string `json:"net_return"`
	MFERatio           *string `json:"mfe_ratio"`
	MAERatio           *string `json:"mae_ratio"`
	DirectionalCorrect *bool   `json:"directional_correct"`
	NetProfitable      *bool   `json:"net_profitable"`
}

type EvaluationResponse struct {
	PolicyVersion  string     `json:"policy_version"`
	Provenance     Provenance `json:"provenance"`
	Outcomes       []Outcome  `json:"outcomes"`
	ResearchOnly   bool       `json:"research_only"`
	EntryAuthority bool       `json:"entry_authority"`
}

type QualitySettings struct {
	Version               string  `json:"version"`
	MinIndependentSamples int     `json:"min_independent_samples"`
	MinDistinctDays       int     `json:"min_distinct_days"`
	Alpha                 float64 `json:"alpha"`
	MultipleTests         int     `json:"multiple_tests"`
	EmbargoMS             int64   `json:"embargo_ms"`
	ReturnLowerBound      string  `json:"return_lower_bound"`
	ReturnUpperBound      string  `json:"return_upper_bound"`
	NeutralBand           string  `json:"neutral_band"`
}

type Observation struct {
	ID                 string  `json:"id"`
	InputHash          string  `json:"input_hash"`
	Scope              Scope   `json:"scope"`
	Status             string  `json:"status"`
	AvailableMS        int64   `json:"available_ms"`
	EndMS              int64   `json:"end_ms"`
	NetReturn          string  `json:"net_return"`
	DirectionalCorrect *bool   `json:"directional_correct,omitempty"`
	MFERatio           *string `json:"mfe_ratio,omitempty"`
	MAERatio           *string `json:"mae_ratio,omitempty"`
}

// A baseline must precede every current observation and share the exact scope/policy.
type Baseline struct {
	Scope              Scope   `json:"scope"`
	PolicyVersion      string  `json:"policy_version"`
	PolicyHash         string  `json:"policy_hash"`
	InputHash          string  `json:"input_hash"`
	Classification     string  `json:"classification"`
	IndependentSamples int     `json:"independent_samples"`
	DistinctDays       int     `json:"distinct_days"`
	EndMS              int64   `json:"end_ms"`
	LowerBound         float64 `json:"lower_bound"`
}

type CohortRequest struct {
	Scope        Scope           `json:"scope"`
	AsOfMS       int64           `json:"as_of_ms"`
	Policy       QualitySettings `json:"policy"`
	Observations []Observation   `json:"observations"`
	Baseline     *Baseline       `json:"baseline,omitempty"`
	Provenance   *Provenance     `json:"provenance,omitempty"`
}

type CohortResponse struct {
	DescriptiveMetrics     DescriptiveMetrics     `json:"descriptive_metrics"`
	Scope                  Scope                  `json:"scope"`
	PolicyVersion          string                 `json:"policy_version"`
	PolicyHash             string                 `json:"policy_hash"`
	InputHash              string                 `json:"input_hash"`
	Classification         string                 `json:"classification"`
	Reason                 string                 `json:"reason"`
	TotalObservations      int                    `json:"total_observations"`
	IndependentSamples     int                    `json:"independent_samples"`
	PurgedObservations     int                    `json:"purged_observations"`
	DistinctDays           int                    `json:"distinct_days"`
	MeanNetReturn          *float64               `json:"mean_net_return"`
	LowerBound             *float64               `json:"lower_bound"`
	UpperBound             *float64               `json:"upper_bound"`
	StandardError          *float64               `json:"standard_error"`
	WinRate                *float64               `json:"win_rate"`
	ProfitFactor           *float64               `json:"profit_factor"`
	AdjustedAlpha          float64                `json:"adjusted_alpha"`
	UncertaintyMethod      string                 `json:"uncertainty_method"`
	MetricUnit             string                 `json:"metric_unit"`
	StatisticalAssumptions StatisticalAssumptions `json:"statistical_assumptions"`
	ResearchOnly           bool                   `json:"research_only"`
	EntryAuthority         bool                   `json:"entry_authority"`
}

// DescriptiveMetrics summarizes observed normalized returns, never currency PnL
// or independent statistical evidence. Unsupported predictions remain unavailable.
type DescriptiveMetrics struct {
	Version                  string            `json:"version"`
	Status                   string            `json:"status"`
	MetricUnit               string            `json:"metric_unit"`
	Population               string            `json:"population"`
	ObservedCount            int               `json:"observed_count"`
	Winners                  int               `json:"winners"`
	Losers                   int               `json:"losers"`
	ZeroReturns              int               `json:"zero_returns"`
	MeanWinner               *string           `json:"mean_winner"`
	MeanLoser                *string           `json:"mean_loser"`
	NetExpectancy            *string           `json:"net_expectancy"`
	NetReturnPerSignal       *string           `json:"net_return_per_signal"`
	ProfitFactor             *string           `json:"profit_factor"`
	Quantiles                ReturnQuantiles   `json:"quantiles"`
	Distribution             []ReturnBin       `json:"distribution"`
	Precision                *float64          `json:"precision"`
	FalsePositiveRate        *float64          `json:"false_positive_rate"`
	DirectionalAccuracy      *float64          `json:"directional_accuracy"`
	DirectionalObservedCount int               `json:"directional_observed_count"`
	MFEObservedCount         int               `json:"mfe_observed_count"`
	MAEObservedCount         int               `json:"mae_observed_count"`
	MeanMFERatio             *string           `json:"mean_mfe_ratio"`
	MeanMAERatio             *string           `json:"mean_mae_ratio"`
	BrierScore               *float64          `json:"brier_score"`
	LogLoss                  *float64          `json:"log_loss"`
	ExpectedCalibrationError *float64          `json:"expected_calibration_error"`
	UnavailableReasons       map[string]string `json:"unavailable_reasons"`
}

type ReturnQuantiles struct {
	Method string  `json:"method"`
	Min    *string `json:"min"`
	P05    *string `json:"p05"`
	P25    *string `json:"p25"`
	P50    *string `json:"p50"`
	P75    *string `json:"p75"`
	P95    *string `json:"p95"`
	Max    *string `json:"max"`
}

// A null bound is unbounded; interval inclusion is explicit, including the zero bin.
type ReturnBin struct {
	LowerBound   *string `json:"lower_bound"`
	UpperBound   *string `json:"upper_bound"`
	IncludeLower bool    `json:"include_lower"`
	IncludeUpper bool    `json:"include_upper"`
	Count        int     `json:"count"`
}

// Confidence statements are conditional on assumptions that interval purging
// cannot establish from the observed return series alone.
type StatisticalAssumptions struct {
	ConfidenceInterpretation       string   `json:"confidence_interpretation"`
	Required                       []string `json:"required"`
	SampleCountMeaning             string   `json:"sample_count_meaning"`
	IndependenceVerified           bool     `json:"independence_verified"`
	PurgingEstablishesIndependence bool     `json:"purging_establishes_independence"`
	PreregistrationVerified        bool     `json:"preregistration_verified"`
}
