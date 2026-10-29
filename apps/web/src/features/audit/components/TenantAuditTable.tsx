'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { CalendarClock, Coins, Eye, KeyRound, Trash2 } from 'lucide-react';
import { ErrorAlert } from '@/app/components/common/Alert';
import { CustomerLink, UserLink } from '@/app/components/common/PersonLink';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import { dayOf, toISO, type DateRange } from '@/app/components/ui/table/dateFilters';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import { ACTOR_TYPES, ALERT_ACTIONS, changeSummary, isDeletion, type AuditRow } from '../audit';
import AuditDetailPanel from './AuditDetailPanel';

const normalize = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const SCOPE_NOTES: Record<string, string> = {
  branch: 'Ves solo lo que pasó en tu sede.',
  own: 'Ves solo lo que hiciste tú.',
};

export default function TenantAuditTable() {
  const [rows, setRows] = useState<AuditRow[] | null>(null);
  const [areas, setAreas] = useState<Record<string, string>>({});
  const [ready, setReady] = useState(true);
  const [scope, setScope] = useState('tenant');
  const [truncated, setTruncated] = useState(false);
  const [range, setRange] = useState<DateRange | null>(null);
  const [area, setArea] = useState('all');
  const [card, setCard] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ actor: '', branch_id: '' });
  const [viewing, setViewing] = useState<AuditRow | null>(null);
  const [error, setError] = useState('');
  const request = useRef(0);
  const [actorId, setActorId] = useState('');
  const [paramsRead, setParamsRead] = useState(false);

  useEffect(() => {
    setActorId(new URLSearchParams(window.location.search).get('actor_id') ?? '');
    setParamsRead(true);
  }, []);

  const load = useCallback(() => {
    const current = ++request.current;
    api.get('/audit', { params: { ...(range ?? {}), ...(actorId ? { actor_id: actorId } : {}) } })
      .then((res) => {
        if (current !== request.current) return;
        setRows(res.data.events);
        setAreas(res.data.areas);
        setReady(res.data.ready);
        setScope(res.data.scope);
        setTruncated(res.data.truncated);
        setError('');
      })
      .catch((err) => setError(apiError(err, 'No se pudo cargar la auditoría.')));
  }, [range, actorId]);

  useEffect(() => { if (paramsRead) load(); }, [load, paramsRead]);

  const all = rows ?? [];
  const term = normalize(searchTerm.trim());
  const today = toISO(new Date());

  const cards: Record<string, (row: AuditRow) => boolean> = {
    today: (row) => dayOf(row.at) === today,
    money: (row) => row.area === 'money',
    deletions: isDeletion,
    alerts: (row) => ALERT_ACTIONS.includes(row.action),
  };

  const whoName = (row: AuditRow) => row.impersonator?.name ?? row.actor.name;

  const visible = all.filter((row) =>
    (area === 'all' || row.area === area)
    && (!card || cards[card](row))
    && (!filters.actor || whoName(row) === filters.actor)
    && (!filters.branch_id || row.branch?.id === filters.branch_id)
    && (!term || normalize(`${row.label} ${row.actor.name} ${row.impersonator?.name ?? ''} ${row.entity_label ?? ''} ${row.branch?.name ?? ''} ${changeSummary(row)}`).includes(term)),
  );

  const usedAreas = [...new Set(all.map((row) => row.area))];
  const people = [...new Set(all.map(whoName))].sort((a, b) => a.localeCompare(b));
  const branches = [...new Map(all.filter((row) => row.branch).map((row) => [row.branch!.id, row.branch!.name])).entries()];

  const filterConfigs: FilterConfig[] = [
    { id: 'actor', label: 'Persona', type: 'select', options: people.map((name) => ({ value: name, label: name })) },
    ...(branches.length > 1 ? [{ id: 'branch_id', label: 'Sede', type: 'select' as const, options: branches.map(([value, label]) => ({ value, label })) }] : []),
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
    metric('money', 'Dinero', <Coins size={18} />, 'text-emerald-600'),
    metric('deletions', 'Eliminaciones y anulaciones', <Trash2 size={18} />, 'text-amber-600'),
    metric('alerts', 'Ingresos fallidos', <KeyRound size={18} />, 'text-rose-600'),
  ];

  const who = (row: AuditRow) => {
    if (row.impersonator) {
      return (
        <>
          <span className="block text-sm font-semibold text-slate-800">{row.impersonator.name}</span>
          <span className="block text-xs text-amber-700">Soporte de la plataforma, en la cuenta de {row.actor.name}</span>
        </>
      );
    }

    const link = row.actor.id && row.actor.type === 'staff'
      ? <UserLink id={row.actor.id} className="text-sm font-semibold text-slate-800">{row.actor.name}</UserLink>
      : row.actor.id && row.actor.type === 'customer'
        ? <CustomerLink id={row.actor.id} className="text-sm font-semibold text-slate-800">{row.actor.name}</CustomerLink>
        : <span className="text-sm font-semibold text-slate-800">{row.actor.name}</span>;

    return (
      <>
        <span className="block">{link}</span>
        <span className="block text-xs text-slate-400">{row.actor.type === 'staff' ? row.area_label : ACTOR_TYPES[row.actor.type]}</span>
      </>
    );
  };

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
    { header: 'Quién', cell: (row) => <div key={`who-${row.id}`}>{who(row)}</div> },
    {
      header: 'Qué hizo',
      cell: (row) => (
        <div key={`what-${row.id}`} className="max-w-[24rem]">
          <span className={`block text-sm font-semibold ${ALERT_ACTIONS.includes(row.action) ? 'text-rose-700' : isDeletion(row) ? 'text-amber-800' : 'text-slate-800'}`}>{row.label}</span>
          <span className="block text-xs text-slate-500 truncate">{[row.entity_label, changeSummary(row) !== row.entity_label ? changeSummary(row) : ''].filter(Boolean).join(' · ') || '—'}</span>
        </div>
      ),
    },
    { header: 'Sede', cell: (row) => <span key={`branch-${row.id}`} className="text-sm text-slate-600">{row.branch?.name ?? '—'}</span> },
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
    { header: 'Persona', value: whoName },
    { header: 'En la cuenta de', value: (row) => (row.impersonator ? row.actor.name : null) },
    { header: 'Área', value: (row) => row.area_label },
    { header: 'Qué hizo', value: (row) => row.label },
    { header: 'Sobre qué', value: (row) => row.entity_label },
    { header: 'Qué cambió', value: (row) => row.changes.map((c) => `${c.field}: ${c.from ?? '—'} → ${c.to ?? '—'}`).join('; ') },
    { header: 'Sede', value: (row) => row.branch?.name },
    { header: 'Dispositivo', value: (row) => row.device },
  ];

  return (
    <div className="space-y-6 mb-8" data-tenant-audit-table>
      <ErrorAlert message={error} />

      {!ready && (
        <p className="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-sm text-amber-800">
          Falta actualizar la base de datos: hasta entonces solo se ven algunas acciones, sin la sede ni quién del soporte las hizo.
        </p>
      )}

      {actorId && rows && (
        <p className="flex flex-wrap items-center gap-2 p-3.5 rounded-2xl bg-brand-soft/15 border border-brand-soft/40 text-sm text-slate-700" data-audit-actor-note>
          Ves solo las acciones de <span className="font-semibold">{rows[0]?.actor.name ?? 'esta persona'}</span>.
          <button type="button" onClick={() => { setActorId(''); window.history.replaceState(null, '', '/audit'); }} className="font-bold text-brand-accent hover:text-brand-ink">Ver las de todos</button>
        </p>
      )}

      <DataTable<AuditRow>
        title="Auditoría"
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
          { id: 'actor', label: 'Persona', value: whoName },
          { id: 'branch', label: 'Sede', value: (row) => row.branch?.name },
        ]}
        columns={columns}
        data={visible}
        exportTitle="Auditoría"
        exportScreen="audit"
        exportColumns={exportColumns}
        getItemKey={(row) => row.id}
        entityLabel={visible.length === 1 ? 'acción' : 'acciones'}
        metricCards={metricCards}
      />

      {rows && (
        <p className="text-xs text-slate-500 px-1">
          {SCOPE_NOTES[scope] ? `${SCOPE_NOTES[scope]} ` : ''}
          {range ? 'Se muestran las acciones del periodo elegido.' : 'Se muestran los últimos 30 días; elige otra fecha para ver más.'}
          {truncated ? ' Hay más acciones de las que caben: acorta el periodo.' : ''}
        </p>
      )}

      <AuditDetailPanel row={viewing} onClose={() => setViewing(null)} />
    </div>
  );
}
