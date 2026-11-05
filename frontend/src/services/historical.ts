import { api } from './api';
export interface Page<T> { data: T[]; last_page: number; total: number }
export interface PlanInput { provider: string; market: string; symbols: string[]; intervals: string[]; from: string; to: string; funding: boolean; mark_prices: boolean; intended_use: string }
export interface License { version: string; attribution: string; license: string; terms: string; permitted_uses: string[]; exports_enabled: boolean }
export interface Plan { input: PlanInput; from: string; to_exclusive: string; estimate: Record<string, number | boolean>; license: License }
export interface Quality { download_complete: boolean; candle_complete: boolean; funding_complete: boolean; research_ready: boolean; full_fidelity_ready: boolean; validated_at: string; series: Record<string, unknown>[]; funding: Record<string, unknown>[]; errors: string[]; status: string }
export interface BatchSummary { id: string; status: string; created_at: string; intended_use: string }
export interface Batch extends BatchSummary { plan: Plan; license: License; acknowledged_at: string | null; quality: Quality | null; counts: { status: string; files: number; imported_rows: string; duplicate_rows: string; conflict_rows: string }[]; worker: { filename: string; lease_until: string } | null; updated_at: string }
export interface Entry { id: string; filename: string; family: string; status: string; verification_status: string; imported_rows: number; duplicate_rows: number; conflict_rows: number; error_code: string | null; sha256: string | null; attempts: number; quality: unknown; diagnostics: unknown }
export interface Strategy { strategy_id: string; version: string; definition_id: number | null; kind: string }
export interface Run { id: string; status: string; strategy_id: string; strategy_version: string; dataset_pin: string; error_code: string | null; result?: Record<string, unknown> | null; skips?: Record<string, number>; lease_until?: string | null }
export function historyClient(token: () => string) {
  return {
    async get<T>(path: string, params?: object, signal?: AbortSignal): Promise<T> {
      return (await api.get(`/historical/${path}`, { params, signal, headers: { 'X-Research-Token': token() } })).data.data;
    },
    async post<T>(path: string, body: object = {}, key?: string): Promise<T> {
      return (await api.post(`/historical/${path}`, body, { timeout: 120000, headers: { 'X-Research-Token': token(), ...(key ? { 'Idempotency-Key': key } : {}) } })).data.data;
    }
  };
}
