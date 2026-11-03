import axios from 'axios';

export const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api',
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

api.interceptors.request.use((config) => {
  if (typeof window !== 'undefined') {
    const token = typeof window !== 'undefined' ? localStorage.getItem('auth_token') : null;
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
  }
  return config;
}, (error) => {
  return Promise.reject(error);
});

api.interceptors.response.use((response) => response, (error) => {
  if (axios.isAxiosError(error) && error.response?.data?.debug) {
    console.error('Detalle del error del servidor:', error.response.data.debug);
  }
  if (axios.isAxiosError(error) && !error.response && !axios.isCancel(error)) {
    error.message = error.code === 'ECONNABORTED'
      ? 'El servidor tardó demasiado en responder. Inténtalo de nuevo en unos segundos.'
      : 'No hay conexión con el sistema. Revisa tu internet e inténtalo de nuevo.';
  }
  return Promise.reject(error);
});