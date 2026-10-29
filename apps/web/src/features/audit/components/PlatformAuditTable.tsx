'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { Building2, CalendarClock, Eye, KeyRound, LifeBuoy } from 'lucide-react';
import { ErrorAlert } from '@/app/components/common/Alert';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import { dayOf, toISO, type DateRange } from '@/app/components/ui/table/dateFilters';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import { ALERT_ACTIONS, changeSummary, type AuditRow } from '../audit';
import AuditDetailPanel from './AuditDetailPanel';

const normalize = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const tenantHref = (id: string) => `/admin/tenants/${id}`;

export default function PlatformAuditTable() {
  const [rows, setRows] = useState<AuditRow[] | null>(null);
  const [tenants, setTenants] = useState<{ id: string; name: string }[]>([]);
  const [areas, setAreas] = useState<Record<string, string>>({});
  const [ready, setReady] = useState(true);
  const [truncated, setTruncated] = useState(false);
  const [range, setRange] = useState<DateRange | null>(null);
  const [area, setArea] = useState('all');
  const [card, setCard] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ tenant_id: '', actor: '' });
  const [viewing, setViewing] = useState<AuditRow | null>(null);
  const [error, setError] = useState('');
  const [paramsRead, setParamsRead] = useState(false);
  const request = useRef(0);

  useEffect(() => {
    const tenant = new URLSearchParams(window.location.search).get('tenant');
    if (tenant) setFilters((values) => ({ ...values, tenant_id: tenant }));
    setParamsRead(true);
  }, []);

  const load = useCallback(() => {
    const current = ++request.current;
    api.get('/admin/audit', { params: { ...(range ?? {}), ...(filters.tenant_id ? { tenant_id: filters.tenant_id } : {}) } })
      .then((res) => {
        if (current !== request.current) return;
        setRows(res.data.events);
        setTenants(res.data.tenants);
        setAreas(res.data.areas);
        setReady(res.data.ready);
        setTruncated(res.data.truncated);
        setError('');
      })
      .catch((err) => setError(apiError(err, 'No se pudo cargar la auditoría.')));
  }, [range, filters.tenant_id]);

  useEffect(() => { if (paramsRead) load(); }, [load, paramsRead]);

  const all = rows ?? [];
  const term = normalize(searchTerm.trim());
  const today = toISO(new Date());

  const cards: Record<string, (row: AuditRow) => boolean> = {
    today: (row) => dayOf(row.at) === today,
    support: (row) => row.action === 'impersonation.started' || Boolean(row.impersonator),
    alerts: (row) => ALERT_ACTIONS.includes(row.action),
    companies: (row) => row.area === 'companies' || row.area === 'business_types',
  };

  const visible = all.filter((row) =>
    (area === 'all' || row.area === area)
    && (!card || cards[card](row))
    && (!filters.actor || row.actor.name === filters.actor)
    && (!term || normalize(`${row.label} ${row.actor.name} ${row.tenant?.name ?? ''} ${row.entity_label ?? ''} ${row.impersonator?.name ?? ''} ${changeSummary(row)}`).includes(term)),
  );

  const usedAreas = [...new Set(all.map((row) => row.area))];
  const people = [...new Set(all.map((row) => row.actor.name))].sort((a, b) => a.localeCompare(b));

  const filterConfigs: FilterConfig[] = [
    { id: 'tenant_id', label: 'Empresa', type: 'select', options: tenants.map((t) => ({ value: t.id, label: t.name })) },
    { id: 'actor', label: 'Persona', type: 'select', options: people.map((name) => ({ value: name, label: name })) },
  ];

  const metric = (id: string, title: string, icon: React.ReactNode, color: string): MetricCardItem => ({
    title,
    value: all.filter(cards[id]).length,
    icon,
    iconColorClass: color,
    borderColorClass: card === id ? 'border-brand-accent ring-2 ring-brand-accent/20' : 'hover:border-slate-300',
    onClick: () => setCard(card === id ? '' : id),
  });

  const metricCards: MetricCardItem[] = [
    metric('today', 'Hoy', <CalendarClock size={18} />, 'text-brand-accent'),
    metric('companies', 'Cambios a empresas, planes y rubros', <Building2 size={18} />, 'text-brand-ink'),
    metric('support', 'Sesiones de soporte', <LifeBuoy size={18} />, 'text-amber-600'),
    metric('alerts', 'Ingresos fallidos', <KeyRound size={18} />, 'text-rose-600'),
  ];

  const columns: Column<AuditRow>[] = [
    {
      header: 'Cuándo',
      cell: (row) => (
        <div key={`at-${row.id}`}>
          <span className="block text-sm text-slate-700 whitespace-nowrap">{momentOf(row.at)}</span>
          <span className="block text-xs text-slate-400">{row.device ?? '—'}</span>
        </div>
      ),
    },
    {
      header: 'Quién',
      cell: (row) => (
        <div key={`who-${row.id}`}>
          <span className="block text-sm font-semibold text-slate-800">{row.impersonator ? row.impersonator.name : row.actor.name}</span>
          <span className="block text-xs text-slate-400">{row.impersonator ? `Como soporte, en la cuenta de ${row.actor.name}` : row.area_label}</span>
        </div>
      ),
    },
    {
      header: 'Qué hizo',
      cell: (row) => (
        <div key={`what-${row.id}`} className="max-w-[22rem]">
          <span className={`block text-sm font-semibold ${ALERT_ACTIONS.includes(row.action) ? 'text-rose-700' : 'text-slate-800'}`}>{row.label}</span>
          <span className="block text-xs text-slate-500 truncate">{changeSummary(row) || '—'}</span>
        </div>
      ),
    },
    {
      header: 'Empresa',
      cell: (row) => (row.tenant
        ? <Link key={`tenant-${row.id}`} href={tenantHref(row.tenant.id)} className="text-sm font-semibold text-slate-700 hover:text-brand-accent">{row.tenant.name}</Link>
        : <span key={`tenant-${row.id}`} className="text-sm text-slate-400">{row.entity_label ?? 'Plataforma'}</span>),
    },
    {
      header: 'Acciones',
      cell: (row) => (
        <button key={`view-${row.id}`} type="button" data-audit-view={row.id} onClick={() => setViewing(row)} title="Ver el detalle" aria-label="Ver el detalle" className="p-1.5 text-slate-400 hover:text-brand-accent hover:bg-brand-soft/20 rounded-lg transition-colors">
          <Eye size={16} />
        </button>
      ),
    },
  ];

  const exportColumns: ExportColumn<AuditRow>[] = [
    { header: 'Fecha', type: 'datetime', value: (row) => row.at },
    { header: 'Persona', value: (row) => row.impersonator?.name ?? row.actor.name },
    { header: 'En la cuenta de', value: (row) => (row.impersonator ? row.actor.name : null) },
    { header: 'Área', value: (row) => row.area_label },
    { header: 'Qué hizo', value: (row) => row.label },
    { header: 'Sobre qué', value: (row) => row.entity_label },
    { header: 'Qué cambió', value: (row) => row.changes.map((c) => `${c.field}: ${c.from ?? '—'} → ${c.to ?? '—'}`).join('; ') },
    { header: 'Empresa', value: (row) => row.tenant?.name ?? 'Plataforma' },
    { header: 'Dispositivo', value: (row) => row.device },
  ];

  return (
    <div className="space-y-6 mb-8" data-platform-audit>
      <ErrorAlert message={error} />

      {!ready && (
        <p className="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-sm text-amber-800">
          Falta actualizar la base de datos: hasta entonces se ven las acciones guardadas antes, sin los ingresos ni las sesiones de soporte por persona.
        </p>
      )}

      <DataTable<AuditRow>
        title="Auditoría de la plataforma"
        tabs={[{ id: 'all', label: 'Todo', count: all.length }, ...usedAreas.map((id) => ({ id, label: areas[id] ?? id, count: all.filter((row) => row.area === id).length }))]}
        activeTab={area}
        onTabChange={setArea}
        searchTerm={searchTerm}
        onSearchChange={setSearchTerm}
        filterConfigs={filterConfigs}
        filterValues={filters}
        onFilterChange={(id, value) => setFilters((values) => ({ ...values, [id]: value ?? '' }))}
        dateFilters={[{ id: 'at', label: 'Fecha', value: (row) => row.at, onRange: setRange }]}
        sortOptions={[
          { id: 'at', label: 'Fecha', type: 'date', value: (row) => row.at },
          { id: 'actor', label: 'Persona', value: (row) => row.actor.name },
          { id: 'tenant', label: 'Empresa', value: (row) => row.tenant?.name },
        ]}
        columns={columns}
        data={visible}
        exportTitle="Auditoría de la plataforma"
        exportScreen="admin-audit"
        exportColumns={exportColumns}
        getItemKey={(row) => row.id}
        entityLabel={visible.length === 1 ? 'acción' : 'acciones'}
        metricCards={metricCards}
      />

      {rows && (
        <p className="text-xs text-slate-500 px-1">
          {range ? 'Se muestran las acciones del periodo elegido.' : 'Se muestran los últimos 30 días; elige otra fecha para ver más.'}
          {truncated ? ' Hay más acciones de las que caben: acorta el periodo o filtra por empresa.' : ''}
        </p>
      )}

      <AuditDetailPanel row={viewing} onClose={() => setViewing(null)} tenantHref={tenantHref} />
    </div>
  );
}
