import axios from 'axios';

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
