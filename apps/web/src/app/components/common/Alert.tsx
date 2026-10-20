import type { ReactNode } from 'react';

export function ErrorAlert({ message }: { message: ReactNode }) {
  if (!message) return null;

  return <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{message}</p>;
}

export function SuccessAlert({ message }: { message: ReactNode }) {
  if (!message) return null;

  return <p data-notice className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">{message}</p>;
}
