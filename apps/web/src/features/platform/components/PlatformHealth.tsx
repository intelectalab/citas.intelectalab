'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { AlertTriangle, HeartPulse, ShieldAlert, ShieldCheck, ShieldQuestion, TrendingUp } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import { ErrorAlert } from '@/app/components/common/Alert';
import { DataTable, type Column, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import { apiError } from '@/features/appointments/appointments';

interface Row {
  id: string;
  name: string;
  plan: string | null;
  status: string;
  status_label: string;
  score: number;
  level: 'high' | 'medium' | 'low';
  signals: { key: string; label: string }[];
  alert: boolean;
  trial_days_left: number | null;
  days_away: number | null;
  last_login_at: string | null;
  appointments: number;
  customers: number;
  failed: number;
  whatsapp_percent?: number | null;
  upsell?: boolean;
  top?: { key: string; label: string; used: number; limit: number | null; percent: number | null } | null;
}

interface Payload {
  summary: { high: number; medium: number; low: number; alerts: number };
  alerts: Row[];
  upsell?: Row[];
  whatsapp_alert?: number;
  rows: Row[];
  signals: { key: string; label: string }[];
  idle_days: number;
  trial_days: number;
  low_use: number;
  login_ready: boolean;
}

const LEVEL = {
  high: { label: 'Riesgo alto', tone: 'bg-rose-50 text-rose-700' },
  medium: { label: 'Riesgo medio', tone: 'bg-amber-50 text-amber-800' },
  low: { label: 'Sana', tone: 'bg-emerald-50 text-emerald-700' },
};

const SIGNAL_TONE: Record<string, string> = {
  overdue: 'bg-rose-50 text-rose-700',
  leaving: 'bg-rose-50 text-rose-700',
  trial_soon: 'bg-amber-50 text-amber-800',
  idle: 'bg-amber-50 text-amber-800',
  no_data: 'bg-slate-100 text-slate-700',
  low_use: 'bg-slate-100 text-slate-700',
  failed: 'bg-slate-100 text-slate-700',
  near: 'bg-sky-50 text-sky-700',
  whatsapp: 'bg-sky-50 text-sky-700',
};

export default function PlatformHealth() {
  const [data, setData] = useState<Payload | null>(null);
  const [error, setError] = useState('');
  const [tab, setTab] = useState('all');
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ signal: '' });

  useEffect(() => {
    api.get<Payload>('/admin/health').then((res) => setData(res.data)).catch((err) => setError(apiError(err, 'No se pudo cargar la salud de las empresas.')));
  }, []);

  const rows = data?.rows ?? [];
  const term = search.trim().toLowerCase();
  const shown = rows
    .filter((r) => tab === 'all' || r.level === tab)
    .filter((r) => !filters.signal || r.signals.some((s) => s.key === filters.signal))
    .filter((r) => !term || `${r.name} ${r.plan ?? ''}`.toLowerCase().includes(term));

  const card = (level: keyof typeof LEVEL, icon: React.ReactNode, color: string): MetricCardItem => ({
    title: LEVEL[level].label,
    value: data?.summary[level] ?? 0,
    icon,
    iconColorClass: color,
    onClick: () => setTab(tab === level ? 'all' : level),
    borderColorClass: tab === level ? 'border-brand ring-2 ring-brand/10 shadow-sm' : '',
  });

  const columns: Column<Row>[] = [
    { header: 'Empresa', cell: (r) => <Link href={`/admin/tenants/${r.id}`} className="font-semibold text-brand-ink hover:text-brand-accent hover:underline" data-health-row={r.id}>{r.name}</Link> },
    { header: 'Plan', cell: (r) => <span>{r.plan ?? '—'}<span className="block text-xs text-slate-500">{r.status_label}{r.trial_days_left !== null ? ` · vence en ${r.trial_days_left} ${r.trial_days_left === 1 ? 'día' : 'días'}` : ''}</span></span> },
    { header: 'Riesgo', cell: (r) => <span className="flex items-center gap-2"><Badge tone={LEVEL[r.level].tone} bold nowrap>{LEVEL[r.level].label}</Badge><span className="text-xs text-slate-500">{r.score} pts</span></span> },
    { header: 'Por qué', cell: (r) => r.signals.length ? <span className="flex flex-wrap gap-1">{r.signals.map((s) => <Badge key={s.key} tone={SIGNAL_TONE[s.key] ?? 'bg-slate-100 text-slate-700'} nowrap>{s.label}</Badge>)}</span> : <span className="text-slate-400">Nada que revisar</span> },
    { header: 'Último ingreso', cell: (r) => (data?.login_ready ? (r.days_away === null ? 'Nunca' : r.days_away === 0 ? 'Hoy' : `Hace ${r.days_away} ${r.days_away === 1 ? 'día' : 'días'}`) : '—') },
    { header: 'Citas (30 días)', cell: (r) => r.appointments },
  ];

  return (
    <div className="space-y-5 pb-10 font-sans" data-platform-health>
      <div>
        <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><HeartPulse className="w-6 h-6 text-brand-accent" /> Salud de las empresas</h1>
        <p className="text-sm text-slate-500 font-medium">
          Qué empresas podrían irse o necesitan ayuda. Cada señal suma puntos: pago vencido, prueba por vencer ({data?.trial_days ?? 3} días o menos), sin ingresar en {data?.idle_days ?? 7} días, sin datos cargados, menos de {data?.low_use ?? 5} citas en 30 días, mensajes que fallaron o baja pedida. Ordena por riesgo, de mayor a menor.
        </p>
      </div>

      <ErrorAlert message={error} />

      {data && data.alerts.length > 0 && (
        <section className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 space-y-1" data-health-alerts>
          <p className="flex items-center gap-2 text-sm font-bold text-amber-900"><AlertTriangle size={16} /> Pruebas por vencer sin datos cargados</p>
          <p className="text-sm text-amber-900">
            {data.alerts.map((r, i) => (
              <span key={r.id}>{i > 0 ? ', ' : ''}<Link href={`/admin/tenants/${r.id}`} className="font-semibold underline">{r.name}</Link> ({r.trial_days_left === 0 ? 'vence hoy' : `${r.trial_days_left} ${r.trial_days_left === 1 ? 'día' : 'días'}`})</span>
            ))}
            . Aún no registran clientes ni citas: conviene escribirles para ayudarles a empezar.
          </p>
        </section>
      )}

      {data && (data.upsell ?? []).length > 0 && (
        <section className="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 space-y-2" data-health-upsell>
          <p className="flex items-center gap-2 text-sm font-bold text-sky-900"><TrendingUp size={16} /> Para vender más</p>
          <p className="text-xs text-sky-900">Empresas al día que ya usan casi todo su plan (80 % de un tope o más de {data.whatsapp_alert ?? 85} % de WhatsApp). Es buen momento para ofrecerles un plan mayor o un complemento.</p>
          <ul className="flex flex-wrap gap-2">
            {(data.upsell ?? []).map((r) => (
              <li key={r.id}>
                <Link href={`/admin/tenants/${r.id}`} className="inline-flex flex-wrap items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white border border-sky-200 text-xs font-semibold text-brand-ink hover:border-brand-accent">
                  {r.name}
                  <span className="text-slate-500 font-normal">{[r.top?.percent ? `${r.top.label} ${r.top.percent} %` : null, r.whatsapp_percent && r.whatsapp_percent >= (data.whatsapp_alert ?? 85) ? `WhatsApp ${r.whatsapp_percent} %` : null].filter(Boolean).join(' · ')}</span>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}

      <DataTable<Row>
        title="Empresas por riesgo"
        metricCards={[
          card('high', <ShieldAlert size={18} />, 'text-rose-600'),
          card('medium', <ShieldQuestion size={18} />, 'text-amber-600'),
          card('low', <ShieldCheck size={18} />, 'text-emerald-600'),
        ]}
        searchTerm={search}
        onSearchChange={setSearch}
        filterConfigs={[{ id: 'signal', label: 'Señal', type: 'select', options: (data?.signals ?? []).map((s) => ({ value: s.key, label: s.label })) }]}
        filterValues={filters}
        onFilterChange={(id, value) => setFilters({ ...filters, [id]: value ?? '' })}
        onClearFilters={() => setFilters({ signal: '' })}
        sortOptions={[
          { id: 'score', label: 'Riesgo', type: 'number', value: (r) => r.score },
          { id: 'away', label: 'Días sin ingresar', type: 'number', value: (r) => r.days_away ?? 9999 },
          { id: 'appointments', label: 'Citas', type: 'number', value: (r) => r.appointments },
          { id: 'name', label: 'Nombre', value: (r) => r.name },
        ]}
        columns={columns}
        data={shown}
        getItemKey={(r) => r.id}
        entityLabel={shown.length === 1 ? 'empresa' : 'empresas'}
        exportTitle="Salud de las empresas"
        exportScreen="admin-health"
        exportColumns={[
          { header: 'Empresa', value: (r) => r.name },
          { header: 'Plan', value: (r) => r.plan ?? '' },
          { header: 'Estado', value: (r) => r.status_label },
          { header: 'Puntos de riesgo', type: 'number', value: (r) => r.score },
          { header: 'Señales', value: (r) => r.signals.map((s) => s.label).join(', ') },
          { header: 'Días sin ingresar', value: (r) => (r.days_away ?? '') as any },
          { header: 'Citas en 30 días', type: 'number', value: (r) => r.appointments },
        ]}
      />
    </div>
  );
}
