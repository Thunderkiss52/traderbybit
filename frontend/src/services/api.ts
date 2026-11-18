import axios from 'axios';
import type {
  Envelope,
  Exchange,
  ExchangeCoverageReport,
  HistoricalCandleUploadResult,
  ExchangeStrategyCompatibilityRecord,
  CompatibilityMatrixResponse,
  TradingMethod,
} from '../types';

export const api = axios.create({ baseURL: '/api/v1', timeout: 45000 });
api.interceptors.request.use(config => {
  try {
    const saved = JSON.parse(localStorage.getItem('ui-settings') || '{}');
    const exchange = saved.exchange || 'bybit';
    config.headers['X-Exchange'] = exchange;
    const instrument = saved.instrument || 'all';
    config.headers['X-Instrument'] = instrument;
    if (instrument === 'BTC') {
      config.headers['X-Symbol'] = 'BTCUSDT';
    } else if (instrument === 'ETH') {
      config.headers['X-Symbol'] = 'ETHUSDT';
    }
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

export async function onboardExchange(payload: object): Promise<{ data: Exchange; message: string }> {
  return (await api.post('/exchanges/onboard', payload)).data;
}

export async function testExchangeConnectivity(id: string): Promise<{
  data: { exchange_id: string; reachable: boolean; latency_ms: number | null; probe_status: string; error: string | null };
  message: string;
}> {
  return (await api.post(`/exchanges/${id}/connectivity`)).data;
}

export async function deactivateExchange(id: string, reason?: string): Promise<{ data: Exchange; message: string }> {
  return (await api.post(`/exchanges/${id}/deactivate`, { reason })).data;
}

export async function pauseExchange(id: string, reason?: string): Promise<{ data: Exchange; message: string }> {
  return (await api.post(`/exchanges/${id}/pause`, { reason })).data;
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

export async function fetchCompatibilityMatrix(): Promise<CompatibilityMatrixResponse> {
  return (await api.get('/exchanges/matrix')).data.data;
}

export async function fetchExchangeStrategies(id: string): Promise<{
  exchange_id: string;
  strategies_evaluated: number;
  results: ExchangeStrategyCompatibilityRecord[];
}> {
  return (await api.get(`/exchanges/${id}/strategies`)).data.data;
}

export async function reconcileExchangeCompatibility(id: string): Promise<{
  success: boolean;
  message: string;
  data: {
    exchange_id: string;
    strategies_evaluated: number;
    results: ExchangeStrategyCompatibilityRecord[];
  };
}> {
  return (await api.post(`/exchanges/${id}/reconcile`)).data;
}

export async function getStrategyMethods(params?: {
  category?: string;
  type?: string;
  status?: string;
}): Promise<Envelope<{ data: TradingMethod[]; summary: any }>> {
  const res = (await api.get('/strategy-methods', { params })).data;
  if (Array.isArray(res.data) && res.summary) {
    return {
      schema_version: res.schema_version || 'v1',
      research_mode: true,
      data: {
        data: res.data,
        summary: res.summary,
      },
      summary: res.summary,
      total: res.total,
    };
  }
  return res;
}

export async function getStrategyMethod(id: string): Promise<Envelope<TradingMethod>> {
  return (await api.get(`/strategy-methods/${id}`)).data;
}

export async function assignStrategyExchange(
  strategyId: number,
  exchangeId: string,
  marketType?: string
): Promise<Envelope<any>> {
  return (await api.post(`/strategies/${strategyId}/assign-exchange`, {
    exchange_id: exchangeId,
    market_type: marketType || 'perpetual',
  })).data;
}

export async function getExchangeCapabilities(exchangeId: string): Promise<Envelope<any>> {
  return (await api.get(`/exchanges/${exchangeId}/capabilities`)).data;
}

export async function getExchangeInstruments(
  exchangeId: string,
  marketType?: string
): Promise<Envelope<any>> {
  return (await api.get(`/exchanges/${exchangeId}/instruments`, {
    params: marketType ? { market_type: marketType } : undefined,
  })).data;
}

