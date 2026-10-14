'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { AlertTriangle, CalendarClock, CalendarPlus, CheckCircle, Edit2, Eye, Hourglass, PhoneCall, Repeat, RotateCcw, XCircle } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { useAppointmentPanels } from '@/features/appointments/components/useAppointmentPanels';
import { apiError, formatDate, formatDateTime, todayISO } from '@/features/appointments/appointments';
import FormRecall from './FormRecall';
import { ORIGIN_LABELS, RECALL_STATUS_STYLES, dueLabel, dueTone, type Recall, type RecallStatus } from '../recalls';
import { ProfessionalLink } from '@/app/components/common/PersonLink';

const VIEWS = [
  { id: 'open', label: 'Por agendar' },
  { id: 'booked', label: 'Con cita' },
  { id: 'closed', label: 'Cerrados' },
];

// Tarjetas que filtran los controles por agendar según su urgencia.
const CARDS: { id: string; title: string; match: (r: Recall) => boolean; icon: React.ReactNode; color: string; active: string; hover: string }[] = [
  { id: 'overdue', title: 'Vencidos', match: (r) => r.days_left < 0, icon: <AlertTriangle size={18} />, color: 'text-rose-700', active: 'border-rose-500 ring-2 ring-rose-500/20', hover: 'hover:border-rose-300' },
  { id: 'week', title: 'Hoy y próximos 7 días', match: (r) => r.days_left >= 0 && r.days_left <= 7, icon: <Hourglass size={18} />, color: 'text-amber-600', active: 'border-amber-500 ring-2 ring-amber-500/20', hover: 'hover:border-amber-300' },
  { id: 'later', title: 'Más adelante', match: (r) => r.days_left > 7, icon: <CalendarClock size={18} />, color: 'text-slate-500', active: 'border-slate-500 ring-2 ring-slate-500/20', hover: 'hover:border-slate-300' },
  { id: 'notified', title: 'Ya avisados', match: (r) => r.status === 'notified', icon: <PhoneCall size={18} />, color: 'text-sky-600', active: 'border-sky-500 ring-2 ring-sky-500/20', hover: 'hover:border-sky-300' },
];

const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors disabled:opacity-50';

// Controles y seguimiento: a quién hay que volver a ver y para qué. Los deja la
// ficha de una atención, el servicio atendido o el personal; desde aquí se
// avisa al cliente y se convierten en cita.
export default function RecallsTable() {
  const { user, can } = useAuth();
  const vocab = useVocabulary();
  const usesDependents = Boolean(user?.tenant?.dependents?.enabled);

  const [recalls, setRecalls] = useState<Recall[]>([]);
  const [services, setServices] = useState<{ id: string; name: string }[]>([]);
  const [professionals, setProfessionals] = useState<{ id: string; display_name: string }[]>([]);
  const [view, setView] = useState('open');
  const [searchTerm, setSearchTerm] = useState('');
  const [query, setQuery] = useState('');
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [filters, setFilters] = useState<Record<string, any>>({ service_id: '', professional_id: '' });
  const [activeCard, setActiveCard] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Recall | null>(null);
  const [discarding, setDiscarding] = useState<Recall | null>(null);
  const [discardReason, setDiscardReason] = useState('');
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    api.get('/recalls', {
      params: {
        view,
        ...(query ? { search: query } : {}),
        ...(filters.service_id ? { service_id: filters.service_id } : {}),
        ...(filters.professional_id ? { professional_id: filters.professional_id } : {}),
      },
    })
      .then((res) => setRecalls(Array.isArray(res.data) ? res.data : []))
      .catch((err) => { console.error('Error al cargar controles:', err); setRecalls([]); });
  }, [view, query, filters.service_id, filters.professional_id]);

  const { openNew, openDetail, panels } = useAppointmentPanels(load);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    api.get('/appointments/context')
      .then((res) => { setServices(res.data.services ?? []); setProfessionals(res.data.professionals ?? []); })
      .catch(() => { setServices([]); setProfessionals([]); });
  }, []);

  const handleSearchChange = (value: string) => {
    setSearchTerm(value);
    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => setQuery(value.trim()), 300);
  };

  const changeStatus = async (recall: Recall, status: RecallStatus, reason?: string) => {
    try {
      setBusyId(recall.id);
      setError('');
      await api.post(`/recalls/${recall.id}/status`, { status, ...(reason ? { reason } : {}) });
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo actualizar el control.'));
    } finally {
      setBusyId(null);
    }
  };

  // Abre "Nueva cita" con el cliente, el servicio y la fecha del control ya puestos.
  const book = async (recall: Recall) => {
    if (!recall.customer) return;

    try {
      setBusyId(recall.id);
      setError('');
      // La ficha completa trae sus mascotas o dependientes, que la cita puede necesitar.
      const res = await api.get(`/customers/${recall.customer.id}`);
      const today = todayISO();

      openNew({
        customer: res.data.customer,
        customer_dependent_id: recall.dependent?.id,
        service_id: recall.service?.id,
        professional_id: recall.professional?.id,
        date: recall.due_date > today ? recall.due_date : today,
        recall_id: recall.id,
        recall_label: recall.label,
      });
    } catch (err) {
      setError(apiError(err, 'No se pudo abrir la cita.'));
    } finally {
      setBusyId(null);
    }
  };

  const confirmDiscard = async () => {
    if (!discarding) return;
    await changeStatus(discarding, 'cancelled', discardReason.trim());
    setDiscarding(null);
  };

  const card = view === 'open' ? CARDS.find((c) => c.id === activeCard) : undefined;
  const rows = card ? recalls.filter(card.match) : recalls;

  const metricCards: MetricCardItem[] = view !== 'open' ? [] : [
    {
      title: 'Por agendar',
      value: recalls.length,
      icon: <Repeat size={18} />,
      iconColorClass: 'text-slate-500',
      borderColorClass: !activeCard ? 'border-slate-400 ring-2 ring-slate-400/20' : 'hover:border-slate-300',
      onClick: () => setActiveCard(''),
    },
    ...CARDS.map((item) => ({
      title: item.title,
      value: recalls.filter(item.match).length,
      icon: item.icon,
      iconColorClass: item.color,
      borderColorClass: activeCard === item.id ? item.active : item.hover,
      onClick: () => setActiveCard((current) => (current === item.id ? '' : item.id)),
    })),
  ];

  const filterConfigs: FilterConfig[] = [
    { id: 'service_id', label: 'Servicio', type: 'select', options: services.map((s) => ({ label: s.name, value: s.id })) },
    ...(professionals.length > 1
      ? [{ id: 'professional_id', label: vocab.pro, type: 'select' as const, options: professionals.map((p) => ({ label: p.display_name, value: p.id })) }]
      : []),
  ];

  const columns: Column<Recall>[] = [
    {
      header: vocab.client,
      cell: (r) => (
        <div key={`customer-${r.id}`}>
          {r.customer ? (
            <Link href={`/customers/${r.customer.id}`} className={`${styles.customerName} hover:text-[#3F768C] transition-colors`}>
              {r.customer.name}
            </Link>
          ) : <span className={styles.customerName}>—</span>}
          <span className="block text-xs text-slate-400">
            {usesDependents && r.dependent ? `${vocab.dependent}: ${r.dependent.name}` : r.customer?.phone ?? r.customer?.code}
          </span>
        </div>
      ),
    },
    {
      header: 'Control',
      cell: (r) => (
        <div key={`label-${r.id}`} className="max-w-xs">
          <span className="block text-sm font-semibold text-slate-800">{r.label}</span>
          <span className="block text-xs text-slate-500">
            {r.service?.name ?? 'Sin servicio definido'}
            {r.professional && <> · <ProfessionalLink id={r.professional.id}>{r.professional.display_name}</ProfessionalLink></>}
          </span>
          {r.notes && <span className="block text-xs text-slate-400 mt-0.5 truncate" title={r.notes}>{r.notes}</span>}
        </div>
      ),
    },
    {
      header: view === 'booked' ? 'Cita' : 'Para cuándo',
      cell: (r) => view === 'booked' && r.appointment ? (
        <div key={`when-${r.id}`}>
          <span className="block text-sm font-semibold text-slate-800">{formatDateTime(r.appointment.starts_at)}</span>
          <span className="block text-xs text-slate-500 font-mono">{r.appointment.code} · {r.appointment.status_name}</span>
        </div>
      ) : (
        <div key={`when-${r.id}`}>
          <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">
            {formatDate(r.due_date, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' })}
          </span>
          {view === 'open' && <span className={`block text-xs font-semibold ${dueTone(r.days_left).text}`} data-due={dueTone(r.days_left).bucket}>{dueLabel(r.days_left)}</span>}
          {view === 'closed' && r.closed_reason && <span className="block text-xs text-slate-500">{r.closed_reason}</span>}
        </div>
      ),
    },
    {
      header: 'Origen',
      cell: (r) => (
        <div key={`origin-${r.id}`}>
          <span className="block text-sm text-slate-700">{ORIGIN_LABELS[r.origin]}</span>
          {r.source_code && <span className="block text-xs text-slate-400 font-mono">Cita {r.source_code}</span>}
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (r) => (
        <span key={`status-${r.id}`} className={`px-2.5 py-1 text-xs rounded-full font-medium whitespace-nowrap ${RECALL_STATUS_STYLES[r.status]}`} data-status={r.status}>
          {r.status_name}
        </span>
      ),
    },
    {
      header: 'Acciones',
      cell: (r) => {
        const busy = busyId === r.id;

        if (view === 'booked') {
          return (
            <button key={`actions-${r.id}`} title="Ver la cita" disabled={!r.appointment} onClick={() => r.appointment && openDetail(r.appointment.id)} className={`${iconButton} hover:text-indigo-600 hover:bg-indigo-50`}>
              <Eye size={16} />
            </button>
          );
        }

        if (view === 'closed') {
          return (
            <button key={`actions-${r.id}`} data-reopen={r.id} title="Volver a dejarlo por agendar" disabled={busy || !can('recalls.edit')} onClick={() => changeStatus(r, 'pending')} className={`${iconButton} hover:text-[#022340] hover:bg-slate-100`}>
              <RotateCcw size={16} />
            </button>
          );
        }

        return (
          <div key={`actions-${r.id}`} className="flex items-center gap-1">
            {can('agenda.create') && (<button
              type="button"
              data-book={r.id}
              disabled={busy}
              onClick={() => book(r)}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-[#022340] text-white rounded-lg hover:bg-[#03315a] transition-colors disabled:opacity-50"
            >
              <CalendarPlus size={14} /> Agendar
            </button>)}
            {can('recalls.edit') && (<button
              data-notify={r.id}
              aria-pressed={r.status === 'notified'}
              title={r.status === 'notified' ? 'Ya se le avisó. Clic para quitar la marca' : 'Marcar que ya se le avisó'}
              disabled={busy}
              onClick={() => changeStatus(r, r.status === 'notified' ? 'pending' : 'notified')}
              className={`${iconButton} ${r.status === 'notified' ? 'text-sky-600 bg-sky-50' : 'hover:text-sky-600 hover:bg-sky-50'}`}
            >
              <PhoneCall size={16} />
            </button>)}
            {can('recalls.edit') && (<button data-done={r.id} title="Ya se atendió sin pasar por aquí" disabled={busy} onClick={() => changeStatus(r, 'done', 'Se dio por realizado sin cita')} className={`${iconButton} hover:text-emerald-600 hover:bg-emerald-50`}>
              <CheckCircle size={16} />
            </button>)}
            {can('recalls.edit') && (<button title="Editar" onClick={() => { setEditing(r); setFormOpen(true); }} className={`${iconButton} hover:text-[#022340] hover:bg-slate-100`}>
              <Edit2 size={16} />
            </button>)}
            {can('recalls.edit') && (<button data-discard={r.id} title="Descartar" onClick={() => { setDiscardReason(''); setDiscarding(r); }} className={`${iconButton} hover:text-red-600 hover:bg-red-50`}>
              <XCircle size={16} />
            </button>)}
          </div>
        );
      },
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}

      <div className="mb-6">
        <DataTable<Recall>
          title="Controles y seguimiento"
          primaryActionLabel="Nuevo control"
          onPrimaryAction={can('recalls.create') ? () => { setEditing(null); setFormOpen(true); } : undefined}
          tabs={VIEWS.map((v) => ({ id: v.id, label: v.label, count: v.id === view ? recalls.length : undefined }))}
          activeTab={view}
          onTabChange={(id) => { setView(id); setActiveCard(''); }}
          searchTerm={searchTerm}
          onSearchChange={handleSearchChange}
          filterConfigs={filterConfigs}
          filterValues={filters}
          onFilterChange={(id, value) => setFilters((current) => ({ ...current, [id]: value }))}
          columns={columns}
          dateFilters={[
            { id: 'due_date', label: 'Para cuándo', future: true, value: (r) => r.due_date },
          ]}
          sortOptions={[
            { id: 'due_date', label: 'Para cuándo', type: 'date', value: (r) => r.due_date },
            { id: 'customer', label: vocab.client, value: (r) => r.customer?.name },
            { id: 'label', label: 'Control', value: (r) => r.label },
            { id: 'service', label: 'Servicio', value: (r) => r.service?.name },
            { id: 'professional', label: vocab.pro, value: (r) => r.professional?.display_name },
            { id: 'status', label: 'Estado', value: (r) => r.status_name },
          ]}
          data={rows}
          getItemKey={(r) => r.id}
          entityLabel="controles"
          metricCards={metricCards}
        />
      </div>

      <FormRecall
        isOpen={formOpen}
        onClose={() => setFormOpen(false)}
        initialData={editing}
        services={services}
        professionals={professionals}
        onSaved={load}
      />

      {panels}

      <AppModal
        isOpen={Boolean(discarding)}
        variant="danger"
        title="Descartar control"
        message={
          <div className="space-y-3">
            <p>
              <strong>{discarding?.label}</strong> de <strong>{discarding?.customer?.name}</strong> saldrá de la lista de pendientes. Queda en "Cerrados" y se puede reabrir.
            </p>
            <input
              type="text"
              maxLength={255}
              aria-label="Motivo para descartar"
              placeholder="Motivo (opcional). Ej. Ya no lo necesita"
              value={discardReason}
              onChange={(e) => setDiscardReason(e.target.value)}
              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white"
            />
          </div>
        }
        confirmText="Sí, descartar"
        cancelText="No, mantenerlo"
        isLoading={Boolean(discarding) && busyId === discarding?.id}
        onConfirm={confirmDiscard}
        onClose={() => setDiscarding(null)}
      />
    </div>
  );
}
