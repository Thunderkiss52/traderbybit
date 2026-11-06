export type Side = 'BUY' | 'SELL' | 'SKIP';

export interface Signal {
  id: string;
  symbol: string;
  side: Side;
  quality_class?: string;
  score: number;
  entry_low?: string;
  entry_high?: string;
  stop_loss?: string;
  take_profit_1?: string;
  take_profit_2?: string;
  expires_at: string;
  reasons: string[];
  risks: string[];
  decision_time: string;
}

export interface Envelope<T> {
  schema_version?: string;
  research_mode?: boolean;
  data: T;
  summary?: any;
  total?: number;
  message?: string;
  [key: string]: any;
}

export interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

export type ExchangeLifecycleStatus =
  | 'OFFLINE_DATA_SOURCE'
  | 'CONNECTED_PUBLIC_DATA'
  | 'RESEARCH_ENABLED'
  | 'PAPER_ELIGIBLE'
  | 'ACTIVE_PAPER';

export interface ExchangeCapabilities {
  has_candles: boolean;
  has_funding: boolean;
  has_open_interest: boolean;
  has_live_adapter: boolean;
  adapter_class?: string | null;
  supported_timeframes: string[];
  price_precision: number;
  qty_precision: number;
  min_qty: string;
  min_notional: string;
}

export interface ExchangeCostModel {
  version: string;
  market: string;
  fee_tier: string;
  maker_rate: string;
  taker_rate: string;
  slippage_rate: string;
  [key: string]: any;
}

export interface ExchangeEligibility {
  eligible: boolean;
  lifecycle_status: ExchangeLifecycleStatus;
  has_live_adapter: boolean;
  has_candles: boolean;
  candle_count: number;
  distinct_symbols: number;
  timeframes_complete: boolean;
  supported_timeframes: string[];
  cost_model_valid: boolean;
  zero_unresolved_conflicts: boolean;
  unresolved_conflicts_count: number;
  execution_assumptions_valid: boolean;
  reasons_missing: string[];
}

export interface Exchange {
  id: string;
  name: string;
  market_type: string;
  description?: string | null;
  lifecycle_status: ExchangeLifecycleStatus;
  lifecycle_display?: string;
  capabilities: ExchangeCapabilities;
  cost_model: ExchangeCostModel;
  paper_execution_assumptions?: Record<string, any>;
  paper_eligible: boolean;
  paper_approved: boolean;
  paper_approved_at?: string | null;
  has_live_adapter?: boolean;
  candle_count?: number;
  distinct_symbols?: number;
  earliest_candle?: string | null;
  latest_candle?: string | null;
  eligibility?: ExchangeEligibility;
  metadata?: Record<string, any>;
  created_at?: string;
  updated_at?: string;
}

export interface ExchangeSeriesCoverage {
  symbol: string;
  interval: string;
  count: number;
  earliest?: string | null;
  latest?: string | null;
  span_hours: number;
  expected_bars: number;
  completeness_percent: number;
  lag_seconds?: number | null;
  is_fresh: boolean;
}

export interface ExchangeCoverageReport {
  exchange_id: string;
  name: string;
  lifecycle_status: ExchangeLifecycleStatus;
  total_candles: number;
  unique_symbols_count: number;
  series_count: number;
  series: ExchangeSeriesCoverage[];
  unresolved_conflicts: number;
  dataset_pins_count: number;
  funding_events_count: number;
  open_interest_events_count: number;
  diagnostics: {
    has_live_adapter: boolean;
    adapter_status: string;
    public_data_fresh: boolean;
    paper_approved: boolean;
    paper_approved_at?: string | null;
  };
  generated_at: string;
}

export interface HistoricalCandleUploadResult {
  exchange_id: string;
  symbol: string;
  interval: string;
  total_rows_processed: number;
  imported_bars: number;
  duplicates_skipped: number;
  conflicts_recorded: number;
  first_bar_open_time?: string | null;
  last_bar_open_time?: string | null;
  checksum: string;
}

export type ExchangeStrategyCompatibilityStatus =
  | 'PAPER_ELIGIBLE'
  | 'SHADOW_ELIGIBLE'
  | 'RESEARCH_ELIGIBLE'
  | 'INSUFFICIENT_DATA'
  | 'INCOMPATIBLE';

export interface ExchangeStrategyCompatibilityRecord {
  exchange_id: string;
  strategy_id: string;
  strategy_version: string;
  status: ExchangeStrategyCompatibilityStatus;
  research_eligible: boolean;
  shadow_eligible: boolean;
  paper_eligible: boolean;
  checks: {
    market_type_compatible: boolean;
    instruments_compatible: boolean;
    timeframes_compatible: boolean;
    history_compatible: boolean;
    features_compatible: boolean;
    execution_model_compatible: boolean;
  };
  diagnostics?: {
    missing_requirements?: string[];
    checks?: Record<string, boolean>;
  };
  dataset_coverage?: {
    total_candles?: number;
    distinct_symbols?: number;
    series_count?: number;
    has_funding?: boolean;
    has_open_interest?: boolean;
  };
  evaluated_at?: string;
}

export interface CompatibilityMatrixResponse {
  matrix: Record<string, Record<string, ExchangeStrategyCompatibilityRecord>>;
  total_evaluations: number;
  updated_at: string;
}

export type MarketType = 'spot' | 'futures' | 'perpetual' | 'options';

export type CapabilityStatus =
  | 'SUPPORTED'
  | 'PARTIALLY_SUPPORTED'
  | 'UNSUPPORTED'
  | 'TEMPORARILY_UNAVAILABLE'
  | 'NOT_ASSESSED';

export interface TradingMethodParameter {
  name: string;
  type: string;
  default: any;
  min?: number;
  max?: number;
  step?: number;
  options?: string[];
  description: string;
}

export interface TradingMethod {
  id: string;
  name: string;
  description: string;
  category: string;
  category_display: string;
  type: string;
  type_display: string;
  parameters: TradingMethodParameter[];
  required_input_data: string[];
  required_indicators: string[];
  supported_market_types: MarketType[];
  status: string;
  status_display: string;
  blocker_reason?: string | null;
  is_executable: boolean;
  research_eligible: boolean;
  shadow_eligible: boolean;
  paper_eligible: boolean;
  timeframe_requirements?: string[];
  compatible_categories?: string[];
  composability_rules?: Record<string, any>;
}

export interface ExchangeMarketCapabilities {
  exchange_id: string;
  name: string;
  lifecycle_status: string;
  market_types: (MarketType | string)[];
  capabilities: Record<string, Record<string, CapabilityStatus | string>>;
  has_live_adapter: boolean;
  adapter_class?: string | null;
}

export interface DiscoveredInstrument {
  symbol: string;
  base_asset?: string;
  quote_asset?: string;
  market_type?: string;
  price_precision?: number;
  qty_precision?: number;
  min_qty?: string;
  min_notional?: string;
  status?: string;
  [key: string]: any;
}


