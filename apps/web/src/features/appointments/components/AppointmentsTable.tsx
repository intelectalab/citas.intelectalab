'use client';

import Badge from '@/app/components/common/Badge';
import { useCallback, useEffect, useRef, useState } from 'react';
import { CalendarDays, CalendarX2, CheckCircle, Eye, Hourglass, Stethoscope, ThumbsUp } from 'lucide-react';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { useAppointmentPanels } from './useAppointmentPanels';
import { useAutoRefresh } from './useAutoRefresh';
import DayBoard from './DayBoard';
import { RELEASED, addDays, dateOf, formatDate, statusStyle, timeOf, todayISO, type Appointment } from '../appointments';
import { CustomerLink, ProfessionalLink } from '@/app/components/common/PersonLink';
import { formatMoney } from '@/lib/currency';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { apiError } from '../appointments';

const PERIODS: { id: string; label: string; range: () => { from: string; to: string } }[] = [
  { id: 'today', label: 'Hoy', range: () => ({ from: todayISO(), to: todayISO() }) },
  { id: 'week', label: 'Próximos 7 días', range: () => ({ from: todayISO(), to: addDays(todayISO(), 7) }) },
  { id: 'month', label: 'Próximos 30 días', range: () => ({ from: todayISO(), to: addDays(todayISO(), 30) }) },
  { id: 'past', label: 'Últimos 30 días', range: () => ({ from: addDays(todayISO(), -30), to: addDays(todayISO(), -1) }) },
  { id: 'all', label: 'Todas', range: () => ({ from: '', to: '' }) },
];

const CARDS: { id: string; title: string; statuses: string[]; icon: React.ReactNode; color: string; active: string; hover: string }[] = [
  { id: 'pending', title: 'Pendientes', statuses: ['pending'], icon: <Hourglass size={18} />, color: 'text-amber-600', active: 'border-amber-500 ring-2 ring-amber-500/20', hover: 'hover:border-amber-300' },
  { id: 'confirmed', title: 'Confirmadas', statuses: ['confirmed'], icon: <ThumbsUp size={18} />, color: 'text-sky-600', active: 'border-sky-500 ring-2 ring-sky-500/20', hover: 'hover:border-sky-300' },
  { id: 'ongoing', title: 'En curso', statuses: ['checked_in', 'in_progress'], icon: <Stethoscope size={18} />, color: 'text-violet-600', active: 'border-violet-500 ring-2 ring-violet-500/20', hover: 'hover:border-violet-300' },
  { id: 'completed', title: 'Atendidas', statuses: ['completed'], icon: <CheckCircle size={18} />, color: 'text-emerald-600', active: 'border-emerald-500 ring-2 ring-emerald-500/20', hover: 'hover:border-emerald-300' },
  { id: 'released', title: 'Canceladas y ausencias', statuses: RELEASED, icon: <CalendarX2 size={18} />, color: 'text-rose-700', active: 'border-rose-500 ring-2 ring-rose-500/20', hover: 'hover:border-rose-300' },
];

export default function AppointmentsTable() {
  const { user, can } = useAuth();
  const vocab = useVocabulary();
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [professionals, setProfessionals] = useState<{ id: string; display_name: string }[]>([]);
  const [period, setPeriod] = useState('week');
  const [dateRange, setDateRange] = useState<{ from: string; to: string } | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, any>>({ status: '', professional_id: '', source: '', branch_id: '' });
  const [origins, setOrigins] = useState<{ value: string; label: string }[]>([]);
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [view, setView] = useState<'table' | 'board'>('table');
  const [boardVersion, setBoardVersion] = useState(0);
  const [activeCard, setActiveCard] = useState('');
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [query, setQuery] = useState('');
  const [bulk, setBulk] = useState<{ ids: string[]; clear: () => void } | null>(null);
  const [bulkReason, setBulkReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [cut, setCut] = useState(0);

  const usesDependents = Boolean(user?.tenant?.dependents?.enabled);
  const statusOptions = (user?.tenant?.appointment_statuses ?? []).map((s) => ({ label: s.name, value: s.key }));

  const load = useCallback(() => {
    const range = dateRange ?? PERIODS.find((p) => p.id === period)!.range();
    const everything = !dateRange && period === 'all';

    api.get('/appointments', {
      params: {
        ...(everything ? { all: 1 } : range),
        ...(query ? { search: query } : {}),
        ...(filters.professional_id ? { professional_id: filters.professional_id } : {}),
        ...(filters.source ? { source: filters.source } : {}),
        ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
      },
    })
      .then((res) => { setAppointments(Array.isArray(res.data) ? res.data : []); setCut(Number(res.headers?.['x-rows-cut'] ?? 0)); })
      .catch((error) => { console.error('Error al cargar citas:', error); setAppointments([]); setCut(0); });
  }, [period, dateRange, query, filters.professional_id, filters.source, filters.branch_id]);

  const reloadAll = useCallback(() => { load(); setBoardVersion((v) => v + 1); }, [load]);

  const { openNew, openDetail, panels, isOpen } = useAppointmentPanels(reloadAll);

  useEffect(() => { load(); }, [load]);

  useAutoRefresh(load, isOpen || view !== 'table', 30000);

  useEffect(() => {
    api.get('/appointments/context')
      .then((res) => { setProfessionals(res.data.professionals ?? []); setOrigins(res.data.origins ?? []); setBranches(res.data.branches ?? []); })
      .catch(() => setProfessionals([]));
  }, []);

  const handleSearchChange = (value: string) => {
    setSearchTerm(value);
    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => setQuery(value.trim()), 300);
  };

  const handleFilterChange = (filterId: string, value: any) => {
    setFilters((current) => ({ ...current, [filterId]: value }));
    if (filterId === 'status') setActiveCard('');
  };

  const toggleCard = (id: string) => {
    setActiveCard((current) => (current === id ? '' : id));
    setFilters((current) => ({ ...current, status: '' }));
  };

  const card = CARDS.find((c) => c.id === activeCard);
  const rows = appointments.filter((a) =>
    card ? card.statuses.includes(a.status) : filters.status ? a.status === filters.status : true,
  );

  const metricCards: MetricCardItem[] = [
    {
      title: 'Citas del periodo',
      value: appointments.length,
      icon: <CalendarDays size={18} />,
      iconColorClass: 'text-slate-500',
      borderColorClass: !activeCard && !filters.status ? 'border-slate-400 ring-2 ring-slate-400/20' : 'hover:border-slate-300',
      onClick: () => { setActiveCard(''); setFilters((current) => ({ ...current, status: '' })); },
    },
    ...CARDS.map((item) => ({
      title: item.title,
      value: appointments.filter((a) => item.statuses.includes(a.status)).length,
      icon: item.icon,
      iconColorClass: item.color,
      borderColorClass: activeCard === item.id ? item.active : item.hover,
      onClick: () => toggleCard(item.id),
    })),
  ];

  const filterConfigs: FilterConfig[] = [
    { id: 'status', label: 'Fase', type: 'select', options: statusOptions },
    { id: 'professional_id', label: vocab.pro, type: 'select', options: professionals.map((p) => ({ label: p.display_name, value: p.id })) },
    ...(origins.length ? [{ id: 'source', label: 'Origen', type: 'select' as const, options: origins.map((o) => ({ label: o.label, value: o.value })) }] : []),
    ...(branches.length > 1 ? [{ id: 'branch_id', label: 'Sede', type: 'select' as const, options: branches.map((b) => ({ label: b.name, value: b.id })) }] : []),
  ];

  const exportColumns: ExportColumn<Appointment>[] = [
    { header: 'Cita', value: (a) => a.code },
    { header: 'Fecha y hora', type: 'datetime', value: (a) => a.starts_at },
    { header: 'Termina', value: (a) => timeOf(a.ends_at) },
    { header: vocab.client, value: (a) => a.customer?.name },
    { header: 'Teléfono', value: (a) => a.customer?.phone },
    ...(usesDependents ? [{ header: vocab.dependent, value: (a: Appointment) => a.dependent?.name }] : []),
    { header: 'Servicio', value: (a) => a.service?.name },
    { header: 'Duración (min)', type: 'number', value: (a) => a.duration_minutes },
    { header: 'Precio', type: 'money', total: true, value: (a) => a.price },
    { header: vocab.pro, value: (a) => a.professional?.display_name },
    { header: 'Sede', value: (a) => a.branch?.name },
    { header: 'Origen', value: (a) => a.source_label },
    { header: 'Fase', value: (a) => a.status_name },
    { header: 'Sin confirmar a tiempo', value: (a) => (a.confirmation_overdue ? 'Sí' : '') },
  ];

  const columns: Column<Appointment>[] = [
    {
      header: 'Cita',
      cell: (a) => (
        <button
          key={`code-${a.id}`}
          type="button"
          data-open={a.id}
          onClick={() => openDetail(a.id)}
          className="text-sm font-bold font-mono text-brand-ink hover:text-brand-accent transition-colors"
        >
          {a.code}
        </button>
      ),
    },
    {
      header: 'Fecha y hora',
      cell: (a) => (
        <div key={`date-${a.id}`}>
          <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">
            {formatDate(dateOf(a.starts_at), { weekday: 'short', day: '2-digit', month: 'short' })}
          </span>
          <span className="block text-xs text-slate-500">{timeOf(a.starts_at)} a {timeOf(a.ends_at)}</span>
        </div>
      ),
    },
    {
      header: vocab.client,
      cell: (a) => (
        <div key={`customer-${a.id}`}>
          <CustomerLink id={a.customer?.id} className={styles.customerName}>{a.customer?.name ?? '—'}</CustomerLink>
          <span className="block text-xs text-slate-400">
            {usesDependents && a.dependent ? `${vocab.dependent}: ${a.dependent.name}` : a.customer?.phone ?? a.customer?.code}
          </span>
        </div>
      ),
    },
    {
      header: 'Servicio',
      cell: (a) => (
        <div key={`service-${a.id}`}>
          <span className="block text-sm text-slate-700">{a.service?.name ?? '—'}</span>
          <span className="block text-xs text-slate-400">{a.duration_minutes} min · {formatMoney(a.price, a.currency)}</span>
        </div>
      ),
    },
    {
      header: vocab.pro,
      cell: (a) => <ProfessionalLink key={`pro-${a.id}`} id={a.professional?.id} className="text-sm text-slate-700">{a.professional?.display_name ?? '—'}</ProfessionalLink>,
    },
    ...(branches.length > 1 ? [{
      header: 'Sede',
      cell: (a: Appointment) => <span key={`branch-${a.id}`} className="text-sm text-slate-700">{a.branch?.name ?? '—'}</span>,
    }] : []),
    {
      header: 'Origen',
      cell: (a) => <span key={`source-${a.id}`} data-source={a.source} className="text-sm text-slate-700">{a.source_label ?? '—'}</span>,
    },
    {
      header: 'Fase',
      cell: (a) => (
        <div key={`status-${a.id}`}>
          <Badge tone={statusStyle(a.status).badge} nowrap>
            {a.status_name}
          </Badge>
          {a.confirmation_overdue && (
            <span data-overdue className="block mt-1.5 text-[11px] font-semibold text-rose-600 whitespace-nowrap" title="Venció el plazo para confirmar y sigue pendiente">
              Sin confirmar a tiempo
            </span>
          )}
        </div>
      ),
    },
    {
      header: 'Acciones',
      cell: (a) => (
        <button
          key={`actions-${a.id}`}
          onClick={() => openDetail(a.id)}
          title="Ver la cita"
          className="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
        >
          <Eye size={16} />
        </button>
      ),
    },
  ];

  const requireReason = user?.tenant?.rules?.['cancel.require_reason'] !== false;
  const canConfirm = can('agenda.confirm');
  const canCancel = can('agenda.cancel');

  const runBulk = async (ids: string[], status: 'confirmed' | 'cancelled', clear: () => void, reason = '') => {
    setBusy(true);
    setNotice('');
    setError('');
    try {
      const res = await api.post<{ done: number; failed: { id: string; message: string }[] }>('/appointments/bulk-status', { ids, status, ...(reason.trim() ? { reason: reason.trim() } : {}) });
      const { done, failed } = res.data;
      const verb = status === 'confirmed' ? (done === 1 ? 'confirmada' : 'confirmadas') : (done === 1 ? 'cancelada' : 'canceladas');
      if (done > 0) setNotice(`${done} ${done === 1 ? 'cita' : 'citas'} ${verb}.`);
      if (failed.length > 0) {
        const codes = Object.fromEntries(appointments.map((a) => [a.id, a.code]));
        const head = failed.length === 1 ? 'Una cita no se pudo cambiar' : `${failed.length} citas no se pudieron cambiar`;
        setError(`${head}: ${failed.map((f) => `${codes[f.id] ?? 'cita'} (${f.message})`).join('; ')}`);
      }
      clear();
      setBulk(null);
      setBulkReason('');
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudieron cambiar las citas.'));
    } finally {
      setBusy(false);
    }
  };

  const selectionActions = (selected: Appointment[], clear: () => void) => {
    const toConfirm = selected.filter((a) => a.status === 'pending').map((a) => a.id);
    const toCancel = selected.filter((a) => a.status === 'pending' || a.status === 'confirmed').map((a) => a.id);
    return (
      <>
        {canConfirm && toConfirm.length > 0 && (
          <button type="button" data-bulk-confirm disabled={busy} onClick={() => runBulk(toConfirm, 'confirmed', clear)} className="px-3 py-1.5 rounded-lg bg-brand text-white text-xs font-bold hover:bg-brand-hover disabled:opacity-50">
            Confirmar {toConfirm.length}
          </button>
        )}
        {canCancel && toCancel.length > 0 && (
          <button type="button" data-bulk-cancel disabled={busy} onClick={() => { setBulkReason(''); setBulk({ ids: toCancel, clear }); }} className="px-3 py-1.5 rounded-lg border border-rose-200 bg-white text-xs font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-50">
            Cancelar {toCancel.length}
          </button>
        )}
        {(!canConfirm || toConfirm.length === 0) && (!canCancel || toCancel.length === 0) && (
          <span className="text-xs text-slate-500">Las elegidas ya no se pueden confirmar ni cancelar.</span>
        )}
      </>
    );
  };

  return (
    <div className="space-y-6 mb-8">
      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />
      {cut > 0 && (
        <p data-rows-cut className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
          Se muestran las primeras {cut} citas del periodo. Para ver las demás, elige un periodo más corto, un profesional o busca por cliente o código.
        </p>
      )}
      <div className="flex items-center gap-0.5 p-0.5 w-fit bg-white border border-slate-200 rounded-lg" role="radiogroup" aria-label="Cómo ver las citas" data-appointments-view>
        {([['table', 'Tabla'], ['board', 'Tablero del día']] as ['table' | 'board', string][]).map(([value, label]) => (
          <button
            key={value}
            type="button"
            role="radio"
            aria-checked={view === value}
            data-view={value}
            onClick={() => setView(value)}
            className={`px-3 py-1.5 text-sm font-semibold rounded-md transition-colors ${view === value ? 'bg-brand text-white' : 'text-slate-600 hover:bg-slate-50'}`}
          >
            {label}
          </button>
        ))}
      </div>

      {view === 'board' ? (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h1 className="text-2xl font-black text-brand-ink tracking-tight">Tablero del día</h1>
            {can('agenda.create') && (
              <button type="button" onClick={() => openNew()} className="px-4 py-2 text-sm font-bold bg-brand text-white rounded-lg hover:bg-brand-hover shadow-sm transition-colors">Nueva cita</button>
            )}
          </div>
          <DayBoard onOpen={openDetail} paused={isOpen} version={boardVersion} />
        </div>
      ) : (
      <div className="mb-6">
        <DataTable<Appointment>
          title="Citas"
          primaryActionLabel="Nueva cita"
          onPrimaryAction={can('agenda.create') ? () => openNew() : undefined}
          tabs={PERIODS.map((p) => ({ id: p.id, label: p.label, count: p.id === period ? appointments.length : undefined }))}
          activeTab={dateRange ? '' : period}
          onTabChange={(id) => { setPeriod(id); setActiveCard(''); }}
          searchTerm={searchTerm}
          onSearchChange={handleSearchChange}
          filterConfigs={filterConfigs}
          filterValues={filters}
          onFilterChange={handleFilterChange}
          columns={columns}
          dateFilters={[
            { id: 'starts_at', label: 'Fecha de la cita', future: true, value: (a) => a.starts_at, onRange: setDateRange, clearOnTab: true },
          ]}
          sortOptions={[
            { id: 'starts_at', label: 'Fecha y hora', type: 'date', value: (a) => a.starts_at },
            { id: 'code', label: 'Código de cita', value: (a) => a.code },
            { id: 'customer', label: vocab.client, value: (a) => a.customer?.name },
            { id: 'service', label: 'Servicio', value: (a) => a.service?.name },
            { id: 'professional', label: vocab.pro, value: (a) => a.professional?.display_name },
            { id: 'status', label: 'Fase', value: (a) => a.status_name },
            { id: 'price', label: 'Precio', type: 'number', value: (a) => Number(a.price) },
            { id: 'duration', label: 'Duración', type: 'number', value: (a) => a.duration_minutes },
          ]}
          data={rows}
          exportTitle="Citas"
          exportScreen="appointments"
          exportColumns={exportColumns}
          exportFilters={card ? [`Tarjeta: ${card.title}`] : []}
          getItemKey={(a) => a.id}
          entityLabel={rows.length === 1 ? 'cita' : 'citas'}
          metricCards={metricCards}
          selectionActions={canConfirm || canCancel ? selectionActions : undefined}
        />
      </div>
      )}

      {panels}

      <AppModal
        isOpen={Boolean(bulk)}
        variant="danger"
        title={bulk && bulk.ids.length === 1 ? 'Cancelar 1 cita' : `Cancelar ${bulk?.ids.length ?? 0} citas`}
        confirmText="Cancelar citas"
        cancelText="Volver"
        isLoading={busy}
        onClose={() => { if (!busy) setBulk(null); }}
        onConfirm={() => { if (bulk && (!requireReason || bulkReason.trim())) runBulk(bulk.ids, 'cancelled', bulk.clear, bulkReason); }}
        message={(
          <div className="space-y-3 text-left" data-bulk-cancel-form>
            <p className="text-sm text-slate-600">Se cancelan solo las citas elegidas, igual que si las cancelaras una por una. No cuenta como cancelación tardía {vocab.pick('client', 'del', 'de la')} {vocab.client.toLowerCase()}.</p>
            <label className="block text-xs font-semibold uppercase tracking-wider text-rose-700">Motivo de la cancelación{requireReason ? '' : ' (opcional)'}</label>
            <input type="text" maxLength={255} aria-label="Motivo de la cancelación" placeholder="Ej. Cierre del local por feriado" value={bulkReason} onChange={(e) => setBulkReason(e.target.value)} className="w-full text-sm border border-slate-200 rounded-lg px-3 py-2.5 text-slate-800 focus:ring-2 focus:ring-rose-200 focus:border-rose-400 outline-none" />
            {requireReason && !bulkReason.trim() && <p className="text-xs text-slate-500">Escribe el motivo para poder cancelar.</p>}
          </div>
        )}
      />
    </div>
  );
}
