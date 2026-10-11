'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { AlertTriangle, CalendarSearch, Edit2, Eye, Hourglass, PhoneCall, RotateCcw, Star, UserRound, XCircle } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { useAppointmentPanels } from '@/features/appointments/components/useAppointmentPanels';
import { apiError, formatDate, formatDateTime, type AppointmentContext } from '@/features/appointments/appointments';
import FormWaitlist from './FormWaitlist';
import { WAITLIST_STATUS_STYLES, dateRangeLabel, timeRangeLabel, waitingLabel, type WaitlistEntry, type WaitlistSlotDay, type WaitlistStatus } from '../waitlist';
import { ProfessionalLink } from '@/app/components/common/PersonLink';

const VIEWS = [
  { id: 'open', label: 'En espera' },
  { id: 'booked', label: 'Con cita' },
  { id: 'closed', label: 'Cerrados' },
];

// Tarjetas que filtran a quienes siguen esperando.
const CARDS: { id: string; title: string; match: (e: WaitlistEntry) => boolean; icon: React.ReactNode; color: string; active: string; hover: string }[] = [
  { id: 'priority', title: 'Prioritarios', match: (e) => e.priority, icon: <Star size={18} />, color: 'text-amber-600', active: 'border-amber-500 ring-2 ring-amber-500/20', hover: 'hover:border-amber-300' },
  { id: 'ending', title: 'Les quedan 3 días o menos', match: (e) => e.days_left !== null && e.days_left <= 3, icon: <AlertTriangle size={18} />, color: 'text-rose-700', active: 'border-rose-500 ring-2 ring-rose-500/20', hover: 'hover:border-rose-300' },
  { id: 'specific', title: 'Esperan a alguien en particular', match: (e) => Boolean(e.professional), icon: <UserRound size={18} />, color: 'text-violet-600', active: 'border-violet-500 ring-2 ring-violet-500/20', hover: 'hover:border-violet-300' },
  { id: 'notified', title: 'Ya avisados', match: (e) => e.status === 'notified', icon: <PhoneCall size={18} />, color: 'text-sky-600', active: 'border-sky-500 ring-2 ring-sky-500/20', hover: 'hover:border-sky-300' },
];

const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors disabled:opacity-50';

// Lista de espera: quién quiere una cita y no encontró horario. Desde aquí se
// anota, se le busca un horario libre que le sirva y se le agenda.
export default function WaitlistTable() {
  const { user } = useAuth();
  const vocab = useVocabulary();
  const usesDependents = Boolean(user?.tenant?.dependents?.enabled);

  const [entries, setEntries] = useState<WaitlistEntry[]>([]);
  const [context, setContext] = useState<AppointmentContext | null>(null);
  const [view, setView] = useState('open');
  const [searchTerm, setSearchTerm] = useState('');
  const [query, setQuery] = useState('');
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [filters, setFilters] = useState<Record<string, any>>({ service_id: '', professional_id: '', branch_id: '' });
  const [activeCard, setActiveCard] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<WaitlistEntry | null>(null);
  const [removing, setRemoving] = useState<WaitlistEntry | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState('');

  // Persona a la que se le está buscando horario, y lo encontrado.
  const [finding, setFinding] = useState<WaitlistEntry | null>(null);
  const [slotDays, setSlotDays] = useState<WaitlistSlotDay[]>([]);
  const [searchedUntil, setSearchedUntil] = useState('');
  const [slotsLoading, setSlotsLoading] = useState(false);
  const [slotsError, setSlotsError] = useState('');

  const load = useCallback(() => {
    api.get('/waitlist', {
      params: {
        view,
        ...(query ? { search: query } : {}),
        ...(filters.service_id ? { service_id: filters.service_id } : {}),
        ...(filters.professional_id ? { professional_id: filters.professional_id } : {}),
        ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
      },
    })
      .then((res) => setEntries(Array.isArray(res.data) ? res.data : []))
      .catch((err) => { console.error('Error al cargar la lista de espera:', err); setEntries([]); });
  }, [view, query, filters.service_id, filters.professional_id, filters.branch_id]);

  const { openNew, openDetail, panels, isOpen: appointmentOpen } = useAppointmentPanels(load);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    api.get('/appointments/context')
      .then((res) => setContext(res.data))
      .catch(() => setContext(null));
  }, []);

  const handleSearchChange = (value: string) => {
    setSearchTerm(value);
    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => setQuery(value.trim()), 300);
  };

  const changeStatus = async (entry: WaitlistEntry, status: WaitlistStatus) => {
    try {
      setBusyId(entry.id);
      setError('');
      await api.post(`/waitlist/${entry.id}/status`, { status });
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo actualizar la lista de espera.'));
    } finally {
      setBusyId(null);
      setRemoving(null);
    }
  };

  const findSlots = (entry: WaitlistEntry) => {
    setFinding(entry);
    setSlotDays([]);
    setSearchedUntil('');
    setSlotsError('');
    setSlotsLoading(true);

    api.get(`/waitlist/${entry.id}/slots`)
      .then((res) => { setSlotDays(res.data.days ?? []); setSearchedUntil(res.data.searched_until ?? ''); })
      .catch((err) => setSlotsError(apiError(err, 'No se pudieron buscar horarios.')))
      .finally(() => setSlotsLoading(false));
  };

  // Abre "Nueva cita" con todo puesto: solo falta confirmar.
  const book = async (entry: WaitlistEntry, date: string, time: string, professionalId: string) => {
    if (!entry.customer) return;

    try {
      setSlotsError('');
      // La ficha completa trae sus mascotas o dependientes, que la cita puede necesitar.
      const res = await api.get(`/customers/${entry.customer.id}`);

      setFinding(null);
      openNew({
        customer: res.data.customer,
        customer_dependent_id: entry.dependent?.id,
        service_id: entry.service?.id,
        branch_id: entry.branch?.id,
        professional_id: professionalId,
        date,
        time,
        waitlist_id: entry.id,
        origin_label: 'Lista de espera',
      });
    } catch (err) {
      setSlotsError(apiError(err, 'No se pudo abrir la cita.'));
    }
  };

  const card = view === 'open' ? CARDS.find((c) => c.id === activeCard) : undefined;
  const rows = card ? entries.filter(card.match) : entries;
  // Puesto en la lista completa, no en la filtrada.
  const position = (entry: WaitlistEntry) => entries.findIndex((e) => e.id === entry.id) + 1;

  const metricCards: MetricCardItem[] = view !== 'open' ? [] : [
    {
      title: 'En espera',
      value: entries.length,
      icon: <Hourglass size={18} />,
      iconColorClass: 'text-slate-500',
      borderColorClass: !activeCard ? 'border-slate-400 ring-2 ring-slate-400/20' : 'hover:border-slate-300',
      onClick: () => setActiveCard(''),
    },
    ...CARDS.map((item) => ({
      title: item.title,
      value: entries.filter(item.match).length,
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
    ...((context?.branches.length ?? 0) > 1
      ? [{ id: 'branch_id', label: 'Sede', type: 'select' as const, options: context!.branches.map((b) => ({ label: b.name, value: b.id })) }]
      : []),
  ];

  const columns: Column<WaitlistEntry>[] = [
    ...(view === 'open' ? [{
      header: 'Puesto',
      cell: (e: WaitlistEntry) => (
        <span key={`pos-${e.id}`} className="inline-flex items-center gap-1.5 text-sm font-bold font-mono text-[#022340]" data-position={position(e)}>
          {position(e)}
          {e.priority && <Star size={14} className="text-amber-500 fill-amber-400" aria-label="Prioritario" />}
        </span>
      ),
    }] : []),
    {
      header: vocab.client,
      cell: (e) => (
        <div key={`customer-${e.id}`}>
          {e.customer ? (
            <Link href={`/customers/${e.customer.id}`} className={`${styles.customerName} hover:text-[#3F768C] transition-colors`}>
              {e.customer.name}
            </Link>
          ) : <span className={styles.customerName}>—</span>}
          <span className="block text-xs text-slate-400">
            {usesDependents && e.dependent ? `${vocab.dependent}: ${e.dependent.name}` : e.customer?.phone ?? e.customer?.code}
          </span>
        </div>
      ),
    },
    {
      header: 'Qué espera',
      cell: (e) => (
        <div key={`what-${e.id}`} className="max-w-xs">
          <span className="block text-sm font-semibold text-slate-800">{e.service?.name ?? '—'}</span>
          <span className="block text-xs text-slate-500">
            {e.professional
              ? <>Con <ProfessionalLink id={e.professional.id}>{e.professional.display_name}</ProfessionalLink></>
              : `Con cualquier ${vocab.pro.toLowerCase()}`}
            {(context?.branches.length ?? 0) > 1 && e.branch ? ` · ${e.branch.name}` : ''}
          </span>
          {e.notes && <span className="block text-xs text-slate-400 mt-0.5 truncate" title={e.notes}>{e.notes}</span>}
        </div>
      ),
    },
    {
      header: view === 'booked' ? 'Cita' : 'Cuándo le sirve',
      cell: (e) => view === 'booked' && e.appointment ? (
        <div key={`when-${e.id}`}>
          <span className="block text-sm font-semibold text-slate-800">{formatDateTime(e.appointment.starts_at)}</span>
          <span className="block text-xs text-slate-500 font-mono">{e.appointment.code} · {e.appointment.status_name}</span>
        </div>
      ) : (
        <div key={`when-${e.id}`}>
          <span className="block text-sm font-semibold text-slate-800">{dateRangeLabel(e)}</span>
          <span className="block text-xs text-slate-500">{timeRangeLabel(e)}</span>
          {view === 'open' && e.days_left !== null && e.days_left <= 3 && (
            <span className="block text-xs font-semibold text-rose-700">{e.days_left === 0 ? 'Hoy es su último día' : `Le quedan ${e.days_left} días`}</span>
          )}
        </div>
      ),
    },
    {
      header: 'Espera',
      cell: (e) => <span key={`since-${e.id}`} className="text-sm text-slate-600 whitespace-nowrap">{waitingLabel(e.days_waiting)}</span>,
    },
    {
      header: 'Estado',
      cell: (e) => (
        <span key={`status-${e.id}`} className={`px-2.5 py-1 text-xs rounded-full font-medium whitespace-nowrap ${WAITLIST_STATUS_STYLES[e.status]}`} data-status={e.status}>
          {e.status_name}
        </span>
      ),
    },
    {
      header: 'Acciones',
      cell: (e) => {
        const busy = busyId === e.id;

        if (view === 'booked') {
          return (
            <button key={`actions-${e.id}`} title="Ver la cita" disabled={!e.appointment} onClick={() => e.appointment && openDetail(e.appointment.id)} className={`${iconButton} hover:text-indigo-600 hover:bg-indigo-50`}>
              <Eye size={16} />
            </button>
          );
        }

        if (view === 'closed') {
          return e.status === 'cancelled' ? (
            <button key={`actions-${e.id}`} data-reopen={e.id} title="Volver a ponerlo en espera" disabled={busy} onClick={() => changeStatus(e, 'waiting')} className={`${iconButton} hover:text-[#022340] hover:bg-slate-100`}>
              <RotateCcw size={16} />
            </button>
          ) : <span key={`actions-${e.id}`} className="text-sm text-slate-300">—</span>;
        }

        return (
          <div key={`actions-${e.id}`} className="flex items-center gap-1">
            <button
              type="button"
              data-find={e.id}
              disabled={busy}
              onClick={() => findSlots(e)}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-[#022340] text-white rounded-lg hover:bg-[#03315a] transition-colors disabled:opacity-50 whitespace-nowrap"
            >
              <CalendarSearch size={14} /> Buscar horario
            </button>
            <button
              data-notify={e.id}
              aria-pressed={e.status === 'notified'}
              title={e.status === 'notified' ? 'Ya se le avisó. Clic para quitar la marca' : 'Marcar que ya se le avisó'}
              disabled={busy}
              onClick={() => changeStatus(e, e.status === 'notified' ? 'waiting' : 'notified')}
              className={`${iconButton} ${e.status === 'notified' ? 'text-sky-600 bg-sky-50' : 'hover:text-sky-600 hover:bg-sky-50'}`}
            >
              <PhoneCall size={16} />
            </button>
            <button title="Editar" onClick={() => { setEditing(e); setFormOpen(true); }} className={`${iconButton} hover:text-[#022340] hover:bg-slate-100`}>
              <Edit2 size={16} />
            </button>
            <button data-remove={e.id} title="Retirar de la lista" onClick={() => setRemoving(e)} className={`${iconButton} hover:text-red-600 hover:bg-red-50`}>
              <XCircle size={16} />
            </button>
          </div>
        );
      },
    },
  ];

  return (
    <div className="space-y-6 mb-8">
      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}

      <div className="mb-6">
        <DataTable<WaitlistEntry>
          title="Lista de espera"
          primaryActionLabel="Anotar en la lista"
          onPrimaryAction={() => { setEditing(null); setFormOpen(true); }}
          tabs={VIEWS.map((v) => ({ id: v.id, label: v.label, count: v.id === view ? entries.length : undefined }))}
          activeTab={view}
          onTabChange={(id) => { setView(id); setActiveCard(''); }}
          searchTerm={searchTerm}
          onSearchChange={handleSearchChange}
          filterConfigs={filterConfigs}
          filterValues={filters}
          onFilterChange={(id, value) => setFilters((current) => ({ ...current, [id]: value }))}
          columns={columns}
          dateFilters={[
            { id: 'created_at', label: 'Anotado el', value: (e) => e.created_at },
            { id: 'date_from', label: 'Le sirve desde', future: true, value: (e) => e.date_from, end: (e) => e.date_to ?? '9999-12-31' },
          ]}
          sortOptions={[
            { id: 'days_waiting', label: 'Días en espera', type: 'number', value: (e) => e.days_waiting },
            { id: 'created_at', label: 'Anotado el', type: 'date', value: (e) => e.created_at },
            { id: 'date_from', label: 'Le sirve desde', type: 'date', value: (e) => e.date_from },
            { id: 'customer', label: vocab.client, value: (e) => e.customer?.name },
            { id: 'service', label: 'Servicio', value: (e) => e.service?.name },
            { id: 'professional', label: vocab.pro, value: (e) => e.professional?.display_name },
          ]}
          data={rows}
          getItemKey={(e) => e.id}
          entityLabel="personas"
          metricCards={metricCards}
        />
      </div>

      <FormWaitlist
        isOpen={formOpen}
        onClose={() => setFormOpen(false)}
        initialData={editing}
        context={context}
        onSaved={load}
      />

      <FormDrawer
        isOpen={Boolean(finding) && !appointmentOpen}
        onClose={() => setFinding(null)}
        title={`Horarios para ${finding?.customer?.name ?? ''}`}
        subtitle={finding ? `${finding.service?.name ?? ''} · ${dateRangeLabel(finding)} · ${timeRangeLabel(finding)}` : ''}
        icon={<CalendarSearch size={20} />}
      >
        {finding && (
          <div className="space-y-5" data-slots-panel>
            {slotsLoading ? (
              <p className="text-sm text-slate-500">Buscando horarios libres...</p>
            ) : slotsError ? (
              <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{slotsError}</p>
            ) : slotDays.length === 0 ? (
              <div className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 space-y-1" data-no-slots>
                <p className="font-semibold text-slate-800">Aún no hay horarios que le sirvan.</p>
                <p>
                  Se revisó hasta el {searchedUntil ? formatDate(searchedUntil, { day: 'numeric', month: 'long' }) : 'final de su rango'}, con lo que pidió:{' '}
                  {finding.professional ? finding.professional.display_name : `cualquier ${vocab.pro.toLowerCase()}`}, {timeRangeLabel(finding).toLowerCase()}.
                  Sigue en la lista; vuelve a buscar cuando se libere una cita.
                </p>
              </div>
            ) : (
              <>
                <p className="text-sm text-slate-600">Elige un horario para abrir la cita con sus datos ya puestos.</p>
                {slotDays.map((day) => (
                  <section key={day.date} className="space-y-2" data-slot-day={day.date}>
                    <h4 className="text-sm font-extrabold text-[#022340] first-letter:uppercase">
                      {formatDate(day.date, { weekday: 'long', day: 'numeric', month: 'long' })}
                    </h4>
                    {day.groups.map((group) => (
                      <div key={group.professional.id}>
                        <span className="block text-xs font-semibold text-slate-600 mb-1.5">{group.professional.display_name}</span>
                        <div className="flex flex-wrap gap-1.5">
                          {group.slots.map((slot) => (
                            <button
                              key={slot}
                              type="button"
                              data-slot={`${day.date}T${slot}`}
                              onClick={() => book(finding, day.date, slot, group.professional.id)}
                              className="px-2.5 py-1.5 text-xs font-semibold rounded-lg border bg-white text-slate-700 border-slate-200 hover:border-[#3F768C] hover:text-[#022340] transition-colors"
                            >
                              {slot}
                            </button>
                          ))}
                        </div>
                      </div>
                    ))}
                  </section>
                ))}
              </>
            )}
          </div>
        )}
      </FormDrawer>

      {panels}

      <AppModal
        isOpen={Boolean(removing)}
        variant="danger"
        title="Retirar de la lista de espera"
        message={`${removing?.customer?.name ?? ''} dejará de esperar ${removing?.service?.name ?? 'su cita'}. Queda en "Cerrados" y se puede volver a poner en espera.`}
        confirmText="Sí, retirar"
        cancelText="No, mantenerlo"
        isLoading={Boolean(removing) && busyId === removing?.id}
        onConfirm={() => removing && changeStatus(removing, 'cancelled')}
        onClose={() => setRemoving(null)}
      />
    </div>
  );
}
