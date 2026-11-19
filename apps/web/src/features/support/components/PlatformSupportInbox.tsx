'use client';

import Link from 'next/link';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { AlertTriangle, Inbox, LifeBuoy, UserCheck } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert } from '@/app/components/common/Alert';
import { DataTable, type Column, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import { useAuth } from '@/hooks/useAuth';
import { apiError } from '@/features/appointments/appointments';
import { PRIORITY_TONE, STATUS_TONE, dateTime, type Option, type Ticket } from '../support';

interface Payload {
  ready: boolean;
  message?: string;
  me?: string;
  tickets: Ticket[];
  statuses: Option[];
  priorities: Option[];
  topics: Option[];
  agents: Option[];
  settings?: { consent_required: boolean };
}

const TABS = [
  { id: 'pending', label: 'Por atender' },
  { id: 'mine', label: 'Asignados a mí' },
  { id: 'waiting', label: 'Esperando a la empresa' },
  { id: 'resolved', label: 'Resueltos' },
  { id: 'all', label: 'Todos' },
];

export default function PlatformSupportInbox() {
  const { user } = useAuth();
  const [data, setData] = useState<Payload | null>(null);
  const [tab, setTab] = useState('pending');
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ priority: '', topic: '' });
  const [onlyLate, setOnlyLate] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    api.get<Payload>('/admin/support').then((res) => { setData(res.data); setError(''); }).catch((err) => setError(apiError(err, 'No se pudo cargar la bandeja.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const tickets = useMemo(() => data?.tickets ?? [], [data]);
  const me = data?.me;
  const inTab = (t: Ticket, id: string) => {
    if (id === 'pending') return t.status === 'open' || t.status === 'in_progress';
    if (id === 'mine') return t.assigned_to === me && t.status !== 'resolved';
    if (id === 'waiting') return t.status === 'waiting';
    if (id === 'resolved') return t.status === 'resolved';
    return true;
  };
  const late = tickets.filter((t) => t.overdue).length;
  const term = search.trim().toLowerCase();
  const shown = tickets
    .filter((t) => inTab(t, tab))
    .filter((t) => !onlyLate || t.overdue)
    .filter((t) => !filters.priority || t.priority === filters.priority)
    .filter((t) => !filters.topic || t.topic === filters.topic)
    .filter((t) => !term || `${t.code} ${t.subject} ${t.tenant?.name ?? ''}`.toLowerCase().includes(term));

  const saveConsent = async (value: boolean) => {
    try {
      const res = await api.put('/admin/support-settings', { consent_required: value });
      setData((current) => (current ? { ...current, settings: res.data.settings } : current));
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    }
  };

  const metricCards: MetricCardItem[] = [
    { title: 'Vencidos', value: late, icon: <AlertTriangle size={18} />, iconColorClass: 'text-rose-600', onClick: () => setOnlyLate(!onlyLate), borderColorClass: onlyLate ? 'border-rose-500 ring-2 ring-rose-100 shadow-sm' : 'hover:border-rose-300' },
    { title: 'Por atender', value: tickets.filter((t) => inTab(t, 'pending')).length, icon: <Inbox size={18} />, iconColorClass: 'text-sky-600', onClick: () => setTab('pending') },
    { title: 'Asignados a mí', value: tickets.filter((t) => inTab(t, 'mine')).length, icon: <UserCheck size={18} />, iconColorClass: 'text-violet-600', onClick: () => setTab('mine') },
  ];

  const columns: Column<Ticket>[] = [
    { header: 'Pedido', cell: (t) => <Link href={`/admin/support/${t.id}`} data-ticket-link={t.code} className="block"><span className="block font-bold text-brand-accent hover:text-brand-ink">{t.code}</span><span className="block text-sm text-slate-700">{t.subject}</span></Link> },
    { header: 'Empresa', cell: (t) => t.tenant ? <Link href={`/admin/tenants/${t.tenant.id}`} className="font-semibold text-brand-ink hover:text-brand-accent hover:underline">{t.tenant.name}</Link> : '—' },
    { header: 'Prioridad', cell: (t) => <Badge tone={PRIORITY_TONE[t.priority]} bold nowrap>{t.priority_label}</Badge> },
    { header: 'Responder hasta', cell: (t) => <span className={t.overdue ? 'text-rose-700 font-bold' : ''}>{['open', 'in_progress'].includes(t.status) ? `${dateTime(t.due_at)}${t.overdue ? ' · vencido' : ''}` : '—'}</span> },
    { header: 'Asignado', cell: (t) => t.assigned_name ?? <span className="text-slate-400">Sin asignar</span> },
    { header: 'Estado', cell: (t) => <Badge tone={STATUS_TONE[t.status]} bold nowrap>{t.status_label}</Badge> },
  ];

  return (
    <div className="space-y-5 pb-10 font-sans" data-platform-support>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><LifeBuoy className="w-6 h-6 text-brand-accent" /> Soporte</h1>
          <p className="text-sm text-slate-500 font-medium">Pedidos de ayuda de las empresas. El plazo para responder depende del plan: 24 h en Individual y Esencial, 8 h en Profesional y 2 h en Empresarial; los urgentes, en la cuarta parte. Los vencidos salen primero.</p>
        </div>
        {user?.is_saas_admin && data?.settings && (
          <div className="flex items-center gap-3 bg-white border border-slate-200 rounded-2xl px-4 py-3" data-support-consent>
            <div>
              <p className="text-sm font-bold text-brand-ink">Entrar solo con permiso</p>
              <p className="text-xs text-slate-500">Soporte entra a una empresa solo si ella lo autorizó en su pedido.</p>
            </div>
            <Switch label="Entrar solo con permiso" checked={data.settings.consent_required} onChange={saveConsent} />
          </div>
        )}
      </div>

      <ErrorAlert message={error || (data && !data.ready ? data.message ?? '' : '')} />

      <DataTable<Ticket>
        title="Bandeja de soporte"
        metricCards={metricCards}
        tabs={TABS.map((t) => ({ ...t, count: tickets.filter((x) => inTab(x, t.id)).length }))}
        activeTab={tab}
        onTabChange={setTab}
        searchTerm={search}
        onSearchChange={setSearch}
        filterConfigs={[
          { id: 'priority', label: 'Prioridad', type: 'select', options: data?.priorities ?? [] },
          { id: 'topic', label: 'Tema', type: 'select', options: data?.topics ?? [] },
        ]}
        filterValues={filters}
        onFilterChange={(id, value) => setFilters({ ...filters, [id]: value ?? '' })}
        onClearFilters={() => { setFilters({ priority: '', topic: '' }); setOnlyLate(false); }}
        sortOptions={[
          { id: 'due', label: 'Plazo', type: 'date', value: (t) => t.due_at },
          { id: 'last', label: 'Última novedad', type: 'date', value: (t) => t.last_message_at },
          { id: 'tenant', label: 'Empresa', value: (t) => t.tenant?.name },
        ]}
        columns={columns}
        data={shown}
        getItemKey={(t) => t.id}
        entityLabel={shown.length === 1 ? 'pedido' : 'pedidos'}
      />
    </div>
  );
}
