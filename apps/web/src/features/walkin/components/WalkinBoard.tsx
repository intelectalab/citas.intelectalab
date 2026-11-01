'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Edit2, Eye, Hourglass, MonitorSmartphone, Play, RotateCcw, Timer, UserRound, XCircle } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { CustomerLink, ProfessionalLink } from '@/app/components/common/PersonLink';
import { DataTable, type Column, type ExportColumn, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { formatMoney } from '@/lib/currency';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { useAppointmentPanels } from '@/features/appointments/components/useAppointmentPanels';
import { apiError } from '@/features/appointments/appointments';
import { LONG_WAIT, WALKIN_STATUS_STYLES, formatWaitLabel, type WalkinContext, type WalkinEntry, type WalkinSummary } from '../walkin';
import FormWalkin from './FormWalkin';
import ServeWalkin from './ServeWalkin';

const TODAY_TABS = [
  { id: 'waiting', label: 'En espera' },
  { id: 'serving', label: 'En atención' },
  { id: 'done', label: 'Atendidos' },
  { id: 'left', label: 'Se retiraron' },
] as const;

const CARDS: { id: string; title: string; match: (e: WalkinEntry) => boolean; icon: React.ReactNode; color: string; active: string; hover: string }[] = [
  { id: 'specific', title: 'Esperan a alguien en particular', match: (e) => Boolean(e.professional_id), icon: <UserRound size={18} />, color: 'text-violet-600', active: 'border-violet-500 ring-2 ring-violet-500/20', hover: 'hover:border-violet-300' },
  { id: 'long', title: `Llevan más de ${LONG_WAIT} min`, match: (e) => (e.elapsed_minutes ?? 0) > LONG_WAIT, icon: <Timer size={18} />, color: 'text-rose-700', active: 'border-rose-500 ring-2 ring-rose-500/20', hover: 'hover:border-rose-300' },
  { id: 'kiosk', title: 'Se anotaron en el kiosko', match: (e) => e.source === 'kiosk', icon: <MonitorSmartphone size={18} />, color: 'text-sky-600', active: 'border-sky-500 ring-2 ring-sky-500/20', hover: 'hover:border-sky-300' },
];

const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors disabled:opacity-50';

const EMPTY_SUMMARY: WalkinSummary = { waiting: 0, serving: 0, done: 0, left: 0, avg_wait_minutes: null };

export default function WalkinBoard() {
  const { can } = useAuth();
  const vocab = useVocabulary();

  const [entries, setEntries] = useState<WalkinEntry[]>([]);
  const [summary, setSummary] = useState<WalkinSummary>(EMPTY_SUMMARY);
  const [context, setContext] = useState<WalkinContext | null>(null);
  const [view, setView] = useState('waiting');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, any>>({ service_id: '', professional_id: '', branch_id: '' });
  const [activeCard, setActiveCard] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<WalkinEntry | null>(null);
  const [serving, setServing] = useState<WalkinEntry | null>(null);
  const [removing, setRemoving] = useState<WalkinEntry | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const history = view === 'history';

  const load = useCallback(() => {
    api.get('/walkins', { params: history ? { view: 'history' } : {} })
      .then((res) => {
        setEntries(res.data.entries ?? []);
        setSummary(res.data.summary ?? EMPTY_SUMMARY);
        setContext(res.data.context ?? null);
      })
      .catch((err) => { console.error('Error al cargar la cola sin cita:', err); setEntries([]); });
  }, [history]);

  const { openDetail, panels, isOpen: appointmentOpen } = useAppointmentPanels(load);

  useEffect(() => {
    load();
    if (history) return;
    const timer = setInterval(load, 30000);
    return () => clearInterval(timer);
  }, [load, history]);

  const changeStatus = async (entry: WalkinEntry, status: 'left' | 'waiting') => {
    try {
      setBusyId(entry.id);
      setError('');
      await api.post(`/walkins/${entry.id}/status`, { status });
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo actualizar el turno.'));
    } finally {
      setBusyId(null);
      setRemoving(null);
    }
  };

  const term = searchTerm.trim().toLowerCase();
  const inView = entries.filter((e) => history || e.status === view);
  const card = view === 'waiting' ? CARDS.find((c) => c.id === activeCard) : undefined;
  const rows = useMemo(() => inView.filter((e) => {
    if (card && !card.match(e)) return false;
    if (filters.service_id && e.service_id !== filters.service_id) return false;
    if (filters.professional_id && e.professional_id !== filters.professional_id) return false;
    if (filters.branch_id && e.branch_id !== filters.branch_id) return false;
    if (!term) return true;
    return [e.ticket, e.customer?.full_name, e.customer?.phone, e.service?.name, e.professional?.display_name, e.notes, e.appointment?.code]
      .some((value) => value?.toLowerCase().includes(term));
  }), [inView, card, filters, term]);

  const manyBranches = (context?.branches.length ?? 0) > 1;
  const tabs = [
    ...TODAY_TABS.map((t) => ({ id: t.id, label: t.label, count: summary[t.id] })),
    { id: 'history', label: 'Días anteriores', count: history ? entries.length : undefined },
  ];

  const metricCards: MetricCardItem[] = view !== 'waiting' ? [] : [
    {
      title: 'En espera',
      value: inView.length,
      icon: <Hourglass size={18} />,
      iconColorClass: 'text-slate-500',
      borderColorClass: !activeCard ? 'border-slate-400 ring-2 ring-slate-400/20' : 'hover:border-slate-300',
      onClick: () => setActiveCard(''),
    },
    ...CARDS.map((item) => ({
      title: item.title,
      value: inView.filter(item.match).length,
      icon: item.icon,
      iconColorClass: item.color,
      borderColorClass: activeCard === item.id ? item.active : item.hover,
      onClick: () => setActiveCard((current) => (current === item.id ? '' : item.id)),
    })),
  ];

  const filterConfigs: FilterConfig[] = [
    { id: 'service_id', label: 'Servicio', type: 'select', options: (context?.services ?? []).map((s) => ({ label: s.name, value: s.id })) },
    ...((context?.professionals.length ?? 0) > 1
      ? [{ id: 'professional_id', label: vocab.pro, type: 'select' as const, options: context!.professionals.map((p) => ({ label: p.display_name, value: p.id })) }]
      : []),
    ...(manyBranches
      ? [{ id: 'branch_id', label: 'Sede', type: 'select' as const, options: context!.branches.map((b) => ({ label: b.name, value: b.id })) }]
      : []),
  ];

  const waitedLabel = (e: WalkinEntry) => (e.waited_minutes === null ? '' : `Esperó ${e.waited_minutes} min`);

  const columns: Column<WalkinEntry>[] = [
    {
      header: 'Turno',
      cell: (e) => (
        <div key={`ticket-${e.id}`}>
          <span className="block text-sm font-bold font-mono text-brand-ink" data-ticket={e.ticket}>{e.ticket}</span>
          <span className="block text-xs text-slate-500 whitespace-nowrap">
            {history ? `${new Date(e.arrived_at).toLocaleDateString('es-PE', { day: 'numeric', month: 'short' })} · ` : ''}Llegó {e.arrived_time} · {e.source_label}
          </span>
        </div>
      ),
    },
    {
      header: vocab.client,
      cell: (e) => (
        <div key={`customer-${e.id}`}>
          <CustomerLink id={e.customer?.id} className="text-sm font-semibold text-slate-800">{e.customer?.full_name ?? '—'}</CustomerLink>
          <span className="block text-xs text-slate-400">
            {e.dependent ? `${vocab.dependent}: ${e.dependent.name}` : e.customer?.phone}
            {e.customer && e.customer.no_show_count > 0 && (
              <span className="ml-1.5 font-semibold text-rose-600">· faltó {e.customer.no_show_count} {e.customer.no_show_count === 1 ? 'vez' : 'veces'}</span>
            )}
          </span>
        </div>
      ),
    },
    {
      header: 'Qué pide',
      cell: (e) => (
        <div key={`what-${e.id}`} className="max-w-xs">
          <span className="block text-sm font-semibold text-slate-800">{e.service?.name ?? '—'}</span>
          <span className="block text-xs text-slate-500">
            {e.professional
              ? <>Con <ProfessionalLink id={e.professional.id}>{e.professional.display_name}</ProfessionalLink></>
              : 'Con quien se libere primero'}
            {manyBranches && e.branch ? ` · ${e.branch.name}` : ''}
          </span>
          {e.notes && <span className="block text-xs text-slate-400 mt-0.5 truncate" title={e.notes}>{e.notes}</span>}
        </div>
      ),
    },
    {
      header: 'Espera',
      cell: (e) => (
        <div key={`wait-${e.id}`} className="whitespace-nowrap">
          {e.status === 'waiting' ? (
            <>
              <span className={`block text-sm font-semibold ${(e.elapsed_minutes ?? 0) > LONG_WAIT ? 'text-rose-700' : 'text-slate-800'}`}>Lleva {e.elapsed_minutes ?? 0} min</span>
              <span className="block text-xs text-slate-500" data-estimate={e.wait_minutes ?? ''}>
                {e.wait_minutes === null
                  ? 'Sin espacio hoy'
                  : `Pasa ${e.wait_minutes <= 0 ? 'ahora' : `${formatWaitLabel(e.wait_minutes)} (${e.estimated_at})`}${!e.professional && e.suggested_professional ? ` con ${e.suggested_professional.display_name}` : ''}`}
              </span>
            </>
          ) : (
            <>
              <span className="block text-sm text-slate-700">{waitedLabel(e) || '—'}</span>
              <span className="block text-xs text-slate-500">
                {e.status === 'serving' && `Pasó a las ${e.served_time}`}
                {e.status === 'done' && `Terminó a las ${e.done_time}`}
                {e.status === 'left' && `Se fue a las ${e.left_time}`}
              </span>
            </>
          )}
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (e) => (
        <div key={`status-${e.id}`}>
          <Badge tone={WALKIN_STATUS_STYLES[e.status]} nowrap data-status={e.status}>{e.status_label}</Badge>
          {e.appointment && <span className="block text-xs text-slate-500 mt-1 font-mono">{e.appointment.code} · {e.appointment.status_name}</span>}
        </div>
      ),
    },
    {
      header: 'Acciones',
      cell: (e) => {
        const busy = busyId === e.id;

        if (e.appointment_id) {
          return (
            <button key={`actions-${e.id}`} title="Ver la cita" onClick={() => openDetail(e.appointment_id!)} className={`${iconButton} hover:text-indigo-600 hover:bg-indigo-50`}>
              <Eye size={16} />
            </button>
          );
        }

        if (e.status === 'left') {
          return !history && can('walkin.edit') ? (
            <button key={`actions-${e.id}`} data-reopen={e.id} title="Volver a ponerlo en espera" disabled={busy} onClick={() => changeStatus(e, 'waiting')} className={`${iconButton} hover:text-brand-ink hover:bg-slate-100`}>
              <RotateCcw size={16} />
            </button>
          ) : <span key={`actions-${e.id}`} className="text-sm text-slate-300">—</span>;
        }

        return (
          <div key={`actions-${e.id}`} className="flex items-center gap-1">
            {can('walkin.edit') && can('agenda.create') && (
              <button
                type="button"
                data-serve={e.id}
                disabled={busy}
                onClick={() => setServing(e)}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-brand text-white rounded-lg hover:bg-brand-hover transition-colors disabled:opacity-50 whitespace-nowrap"
              >
                <Play size={14} /> Atender
              </button>
            )}
            {can('walkin.edit') && (
              <button title="Editar" data-edit={e.id} onClick={() => { setEditing(e); setFormOpen(true); }} className={`${iconButton} hover:text-brand-ink hover:bg-slate-100`}>
                <Edit2 size={16} />
              </button>
            )}
            {can('walkin.edit') && (
              <button title="Se retiró" data-remove={e.id} onClick={() => setRemoving(e)} className={`${iconButton} hover:text-red-600 hover:bg-red-50`}>
                <XCircle size={16} />
              </button>
            )}
          </div>
        );
      },
    },
  ];

  const exportColumns: ExportColumn<WalkinEntry>[] = [
    { header: 'Turno', value: (e) => e.ticket },
    { header: 'Llegó', type: 'datetime', value: (e) => e.arrived_at },
    { header: 'Se anotó en', value: (e) => e.source_label },
    { header: vocab.client, value: (e) => e.customer?.full_name ?? '' },
    { header: 'Teléfono', value: (e) => e.customer?.phone ?? '' },
    ...(context?.dependents.enabled ? [{ header: vocab.dependent, value: (e: WalkinEntry) => e.dependent?.name ?? '' }] : []),
    { header: 'Servicio', value: (e) => e.service?.name ?? '' },
    { header: 'Precio', value: (e) => (e.service ? formatMoney(e.service.price) : '') },
    { header: vocab.pro, value: (e) => e.professional?.display_name ?? 'Quien se libere primero' },
    ...(manyBranches ? [{ header: 'Sede', value: (e: WalkinEntry) => e.branch?.name ?? '' }] : []),
    { header: 'Minutos de espera', type: 'number', value: (e) => e.waited_minutes ?? e.elapsed_minutes },
    { header: 'Estado', value: (e) => e.status_label },
    { header: 'Cita', value: (e) => e.appointment?.code ?? '' },
    { header: 'Notas', value: (e) => e.notes ?? '' },
  ];

  return (
    <div className="space-y-6 mb-8">
      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="mb-6">
        <DataTable<WalkinEntry>
          title="Cola sin cita"
          primaryActionLabel="Anotar en la cola"
          onPrimaryAction={can('walkin.create') ? () => { setEditing(null); setFormOpen(true); } : undefined}
          tabs={tabs}
          activeTab={view}
          onTabChange={(id) => { setView(id); setActiveCard(''); setNotice(''); }}
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          filterConfigs={filterConfigs}
          filterValues={filters}
          onFilterChange={(id, value) => setFilters((current) => ({ ...current, [id]: value }))}
          dateFilters={history ? [{ id: 'arrived_at', label: 'Llegó el', value: (e) => e.arrived_at }] : undefined}
          sortOptions={[
            { id: 'arrived_at', label: 'Hora de llegada', type: 'date', value: (e) => e.arrived_at },
            { id: 'wait', label: 'Minutos de espera', type: 'number', value: (e) => e.waited_minutes ?? e.elapsed_minutes },
            { id: 'customer', label: vocab.client, value: (e) => e.customer?.full_name },
            { id: 'service', label: 'Servicio', value: (e) => e.service?.name },
            { id: 'professional', label: vocab.pro, value: (e) => e.professional?.display_name },
          ]}
          columns={columns}
          data={rows}
          getItemKey={(e) => e.id}
          entityLabel="turnos"
          metricCards={metricCards}
          exportTitle={history ? 'Cola sin cita - días anteriores' : 'Cola sin cita - hoy'}
          exportScreen="walkin"
          exportColumns={exportColumns}
        />
      </div>

      {context && (
        <FormWalkin
          isOpen={formOpen}
          onClose={() => setFormOpen(false)}
          initialData={editing}
          context={context}
          onSaved={(message) => { setNotice(message); load(); }}
        />
      )}

      <ServeWalkin
        entry={appointmentOpen ? null : serving}
        onClose={() => setServing(null)}
        onServed={(result) => {
          setServing(null);
          setNotice(result.message);
          load();
          if (result.pending) openDetail(result.appointmentId);
        }}
      />

      {panels}

      <AppModal
        isOpen={Boolean(removing)}
        variant="danger"
        title="Se retiró de la cola"
        message={`${removing?.customer?.full_name ?? ''} (${removing?.ticket ?? ''}) sale de la cola de hoy. Queda en "Se retiraron" y, si vuelve hoy, se le puede volver a poner en espera.`}
        confirmText="Sí, se retiró"
        cancelText="No, sigue esperando"
        isLoading={Boolean(removing) && busyId === removing?.id}
        onConfirm={() => removing && changeStatus(removing, 'left')}
        onClose={() => setRemoving(null)}
      />
    </div>
  );
}
