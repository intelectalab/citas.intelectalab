'use client';

import { AlertTriangle } from 'lucide-react';

interface DependentAlertProps {
  name?: string | null;
  alert?: string | null;
  compact?: boolean;
}

export default function DependentAlert({ name, alert, compact = false }: DependentAlertProps) {
  const text = alert?.trim();
  if (!text) return null;

  if (compact) {
    return (
      <span data-dependent-alert className="flex items-start gap-1.5 text-xs font-semibold text-rose-700">
        <AlertTriangle size={12} className="shrink-0 mt-0.5" />
        <span className="break-words">{text}</span>
      </span>
    );
  }

  return (
    <div data-dependent-alert className="flex items-start gap-2.5 p-3 bg-rose-50 border border-rose-200 rounded-xl">
      <AlertTriangle size={16} className="text-rose-600 shrink-0 mt-0.5" />
      <div className="min-w-0">
        <span className="block text-[11px] font-bold text-rose-700 uppercase tracking-wider">{name ? `Alerta de ${name}` : 'Alerta'}</span>
        <p className="text-sm font-semibold text-rose-800 break-words">{text}</p>
      </div>
    </div>
  );
}
