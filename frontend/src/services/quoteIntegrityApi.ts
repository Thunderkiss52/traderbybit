import axios from 'axios';

export interface QimStatus {
  feature_enabled: boolean;
  measurement_mode: boolean;
  measurement_cap_reached: boolean;
  level_1_status: string;
  level_2_status: string;
  clock_sync_state: string;
  rules_version: string;
  cross_source_reason: string;
  disclaimers: string[];
  timestamp: string;
}

export interface QimSummary {
  from: string;
  to: string;
  total_comparisons: number;
  matched_comparisons: number;
  channel_inconsistencies: number;
  duplicate_counts: {
    exact: number;
    conflicting: number;
    identity_config: number;
  };
  gap_events_count: number;
  stale_observations_count: number;
  incidents_count: {
    total: number;
    new: number;
    investigating: number;
    explained: number;
    unresolved: number;
    closed: number;
  };
  cross_source_status: string;
  calculated_at: string;
}

export interface QimComparison {
  id: number;
  comparison_id: string;
  version: number;
  provider: string;
  category: string;
  symbol: string;
  interval: string;
  candle_open_time: string;
  candle_close_time: string;
  classification: string;
  price_diff_abs: string | null;
  price_diff_rel: string | null;
  volume_diff_abs: string | null;
  volume_diff_rel: string | null;
  timestamp_skew_ms: number | null;
  is_comparable: boolean;
  explanation: string | null;
  compared_at: string;
  rest_observation?: {
    id: number;
    open: string;
    high: string;
    low: string;
    close: string;
    volume: string;
    request_sent_at?: string;
    response_received_at?: string;
    payload_sha256: string;
  } | null;
  ws_observation?: {
    id: number;
    open: string;
    high: string;
    low: string;
    close: string;
    volume: string;
    provider_event_time?: string;
    local_received_at?: string;
    payload_sha256: string;
  } | null;
}

export interface QimIncidentEvent {
  id: number;
  previous_status: string | null;
  new_status: string;
  actor: string;
  explanation: string;
  evidence_references?: Record<string, any> | null;
  rule_version: string;
  created_at: string;
}

export interface QimIncident {
  id: number;
  incident_key: string;
  status: string;
  severity: string;
  priority: string;
  title: string;
  description: string | null;
  provider: string;
  category: string | null;
  symbol: string | null;
  first_detected_at: string;
  last_detected_at: string;
  occurrence_count: number;
  resolution_notes: string | null;
  events?: QimIncidentEvent[];
  created_at: string;
  updated_at: string;
}

export interface QimAlert {
  id: number;
  alert_key: string;
  rule_version: string;
  rule_key: string;
  incident_group: string;
  window_start: string;
  window_end: string;
  severity: string;
  symbol: string | null;
  title: string;
  payload: Record<string, any>;
  created_at: string;
}

export interface QimEvidenceFile {
  id: number;
  file_uuid: string;
  original_filename: string;
  mime_type: string;
  file_size_bytes: number;
  sha256_hash: string;
  provenance_class: string;
  declared_source: string;
  declared_timezone: string;
  missing_metadata: string[];
  investigation_status: string;
  uploaded_by: string;
  uploaded_at: string;
  disclaimers: string[];
}

const client = axios.create({ baseURL: '/api/v1', timeout: 15000 });

export async function fetchQimStatus(): Promise<QimStatus> {
  const resp = await client.get('/quote-integrity/status');
  return resp.data.data;
}

export async function fetchQimSummary(from?: string, to?: string): Promise<QimSummary> {
  const resp = await client.get('/quote-integrity/summary', { params: { from, to } });
  return resp.data.data;
}

export async function fetchQimComparisons(params?: Record<string, any>): Promise<{ data: QimComparison[]; meta?: any }> {
  const resp = await client.get('/quote-integrity/comparisons', { params });
  return resp.data;
}

export async function fetchQimIncidents(params?: Record<string, any>): Promise<{ data: QimIncident[]; meta?: any }> {
  const resp = await client.get('/quote-integrity/incidents', { params });
  return resp.data;
}

export async function fetchQimIncidentDetail(id: string | number): Promise<QimIncident> {
  const resp = await client.get(`/quote-integrity/incidents/${id}`);
  return resp.data.data;
}

export async function transitionQimIncident(
  id: string | number,
  status: string,
  explanation: string,
  token: string,
  evidenceReferences?: Record<string, any>
): Promise<QimIncident> {
  const resp = await client.post(
    `/quote-integrity/incidents/${id}/transitions`,
    { status, explanation, evidence_references: evidenceReferences },
    { headers: { 'X-Research-Token': token } }
  );
  return resp.data.data;
}

export async function fetchQimAlerts(params?: Record<string, any>): Promise<{ data: QimAlert[]; meta?: any }> {
  const resp = await client.get('/quote-integrity/alerts', { params });
  return resp.data;
}

export async function uploadQimEvidence(formData: FormData, token: string): Promise<QimEvidenceFile> {
  const resp = await client.post('/quote-integrity/evidence', formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
      'X-Research-Token': token,
    },
  });
  return resp.data.data;
}
