import axios from 'axios';
import type { Exchange, ExchangeCoverageReport, HistoricalCandleUploadResult } from '../types';

export const api = axios.create({ baseURL: '/api/v1', timeout: 15000 });
api.interceptors.request.use(config => {
  try {
    const saved = JSON.parse(localStorage.getItem('ui-settings') || '{}');
    const exchange = saved.exchange || 'bybit';
    config.headers['X-Exchange'] = exchange;
  } catch {
    // ignore
  }
  return config;
});
api.interceptors.response.use(response => response, error => {
  const payload = error.response?.data?.error;
  const message = payload?.message || error.response?.data?.message || 'API unavailable';
  const requestId = payload?.request_id || error.response?.headers?.['x-request-id'];
  return Promise.reject(new Error(requestId ? `${message} Request ID: ${requestId}` : message));
});

export async function get<T>(url: string, params?: object, signal?: AbortSignal): Promise<T> {
  return (await api.get(url, { params, signal })).data.data;
}

export async function startBacktest(payload: object) {
  return api.post('/backtests', payload);
}

export async function getResearchJobs(params?: object) {
  return api.get('/research/jobs', { params });
}

export async function submitResearchJob(payload: object) {
  return api.post('/research/jobs', payload);
}

export async function cancelResearchJob(id: string, reason?: string) {
  return api.post(`/research/jobs/${id}/cancel`, { reason });
}

export async function getResearchCapabilities() {
  return api.get('/research/capabilities');
}

// Universal Exchange Onboarding & Management APIs
export async function fetchExchanges(): Promise<Exchange[]> {
  return (await api.get('/exchanges')).data.data;
}

export async function registerExchange(payload: object): Promise<{ data: Exchange; message: string }> {
  return (await api.post('/exchanges', payload)).data;
}

export async function fetchExchangeDetail(id: string): Promise<Exchange> {
  return (await api.get(`/exchanges/${id}`)).data.data;
}

export async function fetchExchangeCoverage(id: string): Promise<ExchangeCoverageReport> {
  return (await api.get(`/exchanges/${id}/coverage`)).data.data;
}

export async function uploadExchangeCandles(
  id: string,
  payload: FormData | object
): Promise<{ data: HistoricalCandleUploadResult; message: string }> {
  const headers = payload instanceof FormData ? { 'Content-Type': 'multipart/form-data' } : {};
  return (await api.post(`/exchanges/${id}/upload-candles`, payload, { headers })).data;
}

export async function updateExchangeLifecycle(
  id: string,
  status: string
): Promise<{ data: Exchange; message: string }> {
  return (await api.post(`/exchanges/${id}/lifecycle`, { status })).data;
}

export async function approveExchangePaper(
  id: string,
  approved: boolean,
  reference?: string
): Promise<{ data: Exchange; message: string }> {
  return (await api.post(`/exchanges/${id}/approve-paper`, { approved, reference })).data;
}

export async function syncExchangeTopology(
  id: string,
  apply: boolean = false
): Promise<{ data: any; message: string }> {
  return (await api.post(`/exchanges/${id}/sync-topology`, { apply })).data;
}
