import axios from 'axios';

export const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api',
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

const NO_IDEMPOTENCY = /^\/?(login|logout|password|webhooks)(\/|$)/;

export function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  return 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 12);
}

api.interceptors.request.use((config) => {
  if (typeof window !== 'undefined') {
    const token = typeof window !== 'undefined' ? localStorage.getItem('auth_token') : null;
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
  }
  if ((config.method ?? 'get').toLowerCase() === 'post' && !NO_IDEMPOTENCY.test(config.url ?? '') && !config.headers['Idempotency-Key']) {
    config.headers['Idempotency-Key'] = newIdempotencyKey();
  }
  return config;
}, (error) => {
  return Promise.reject(error);
});

api.interceptors.response.use((response) => response, (error) => {
  if (axios.isAxiosError(error) && error.response?.data?.debug) {
    console.error('Detalle del error del servidor:', error.response.data.debug);
  }
  if (axios.isAxiosError(error) && error.response?.status === 402 && error.response.data?.code === 'PLAN_LOCKED' && typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('plan-locked', { detail: error.response.data.message }));
  }
  if (axios.isAxiosError(error) && error.response?.status === 401 && error.response.data?.code === 'SESSION_EXPIRED' && typeof window !== 'undefined' && !localStorage.getItem('platform_auth_token')) {
    const { reason, minutes } = error.response.data;
    localStorage.setItem('logout_reason', reason === 'idle' && minutes ? `idle:${minutes}` : 'expired');
    localStorage.removeItem('auth_token');
    localStorage.removeItem('user');
    localStorage.removeItem('last_activity');
    if (window.location.pathname !== '/login') {
      window.location.href = '/login';
    }
  }
  if (axios.isAxiosError(error) && !error.response && !axios.isCancel(error)) {
    error.message = error.code === 'ECONNABORTED'
      ? 'El servidor tardó demasiado en responder. Inténtalo de nuevo en unos segundos.'
      : 'No hay conexión con el sistema. Revisa tu internet e inténtalo de nuevo.';
  }
  return Promise.reject(error);
});