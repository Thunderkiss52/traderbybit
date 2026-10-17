import axios from 'axios';

export const api = axios.create({ baseURL: '/api/v1', timeout: 15000 });
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
