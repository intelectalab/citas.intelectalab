'use client';

import { useMemo, useState } from 'react';
import { ChevronDown, SlidersHorizontal, X } from 'lucide-react';
import Select from '@/app/components/common/Select';
import { Pager, usePaged } from '@/app/components/common/Pager';
import DateFilter from '@/app/components/ui/table/DateFilter';
import { dateRangeOf, inDateRange, type DateFilterValue } from '@/app/components/ui/table/dateFilters';
import PortalAppointmentCard from './PortalAppointmentCard';
import type { PortalAppointment, PortalPolicies } from '../portal';

interface CardActions {
  busy: boolean;
  onConfirm: (appointment: PortalAppointment) => void;
  onReschedule: (appointment: PortalAppointment) => void;
  onCancel: (appointment: PortalAppointment) => void;
  onMiss?: 'notify' | 'notify_release' | 'release';
}

interface PortalAppointmentsProps {
  upcoming: PortalAppointment[];
  past: PortalAppointment[];
  policies?: PortalPolicies;
  proWord: string;
  actions: CardActions;
}

const chip = 'flex items-center gap-1 bg-white pl-3 pr-1.5 py-1 rounded-lg border border-slate-300 shadow-xs';
const chipLabel = 'text-xs leading-5 text-slate-500';
const sectionTitle = 'text-sm font-extrabold text-brand-ink uppercase tracking-wider';

const optionsOf = (appointments: PortalAppointment[], pick: (a: PortalAppointment) => { id: string; label: string } | null) => {
  const seen = new Map<string, string>();
  appointments.forEach((a) => {
    const option = pick(a);
    if (option && !seen.has(option.id)) seen.set(option.id, option.label);
  });
  return [...seen.entries()].map(([value, label]) => ({ value, label })).sort((a, b) => a.label.localeCompare(b.label, 'es'));
};

function CardList({ items, empty, actions }: { items: PortalAppointment[]; empty: string; actions?: CardActions }) {
  const { rows, page, pages, setPage } = usePaged(items);

  if (items.length === 0) return <p className="text-sm text-slate-500">{empty}</p>;

  return (
    <>
      {rows.map((appointment) => <PortalAppointmentCard key={appointment.id} appointment={appointment} {...(actions ?? {})} />)}
      {pages > 1 && <Pager page={page} pages={pages} onChange={setPage} />}
    </>
  );
}

export default function PortalAppointments({ upcoming, past, policies, proWord, actions }: PortalAppointmentsProps) {
  const [status, setStatus] = useState('');
  const [serviceId, setServiceId] = useState('');
  const [professionalId, setProfessionalId] = useState('');
  const [branchId, setBranchId] = useState('');
  const [date, setDate] = useState<DateFilterValue | null>(null);
  const [open, setOpen] = useState(false);

  const all = useMemo(() => [...upcoming, ...past], [upcoming, past]);
  const statuses = useMemo(() => optionsOf(all, (a) => ({ id: a.status, label: a.status_name })), [all]);
  const services = useMemo(() => optionsOf(all, (a) => (a.service ? { id: a.service.id, label: a.service.name } : null)), [all]);
  const professionals = useMemo(() => optionsOf(all, (a) => (a.professional ? { id: a.professional.id, label: a.professional.display_name } : null)), [all]);
  const branches = useMemo(() => optionsOf(all, (a) => (a.branch ? { id: a.branch.id, label: a.branch.name } : null)), [all]);

  const range = dateRangeOf(date);
  const matches = (a: PortalAppointment) =>
    (!status || a.status === status) &&
    (!serviceId || a.service?.id === serviceId) &&
    (!professionalId || a.professional?.id === professionalId) &&
    (!branchId || a.branch?.id === branchId) &&
    (!range || inDateRange(range, a.starts_at));

  const shownUpcoming = upcoming.filter(matches);
  const shownPast = past.filter(matches);
  const active = [status, serviceId, professionalId, branchId, date].filter(Boolean).length;
  const filterKey = `${status}|${serviceId}|${professionalId}|${branchId}|${JSON.stringify(date)}`;

  const clear = () => { setStatus(''); setServiceId(''); setProfessionalId(''); setBranchId(''); setDate(null); };

  const lists: { label: string; value: string; set: (value: string) => void; options: { value: string; label: string }[] }[] = [
    { label: 'Estado', value: status, set: setStatus, options: statuses },
    { label: 'Servicio', value: serviceId, set: setServiceId, options: services },
    { label: proWord, value: professionalId, set: setProfessionalId, options: professionals },
    { label: 'Sede', value: branchId, set: setBranchId, options: branches },
  ];

  return (
    <>
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-xl font-black text-brand-ink tracking-tight">Mis citas</h1>
        {all.length > 0 && (
          <button
            type="button"
            data-toggle="filters"
            aria-expanded={open}
            onClick={() => setOpen(!open)}
            className={`inline-flex items-center gap-2 px-3.5 py-2 text-sm font-bold rounded-xl border transition-colors ${
              open ? 'bg-brand border-brand text-white' : 'bg-white border-slate-300 text-brand-ink hover:border-brand-accent'
            }`}
          >
            <SlidersHorizontal size={15} className={open ? 'text-brand-soft' : 'text-brand-accent'} />
            Filtrar
            {active > 0 && (
              <span data-filter-count className={`min-w-[18px] h-[18px] px-1 flex items-center justify-center text-[11px] font-bold rounded-full ${open ? 'bg-brand-soft text-brand-ink' : 'bg-brand-accent text-white'}`}>
                {active}
              </span>
            )}
            <ChevronDown size={15} className={`transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
          </button>
        )}
      </div>

      {all.length > 0 && open && (
        <div className="flex flex-wrap items-center gap-2 px-3.5 py-2.5 bg-slate-100 border border-slate-200 rounded-xl" data-portal-filters>

          {lists.filter((list) => list.options.length > 1).map((list) => (
            <div key={list.label} className={chip}>
              <span className={chipLabel}>{list.label}:</span>
              <Select
                variant="chip"
                ariaLabel={list.label}
                placeholder={list.label === 'Sede' ? 'Todas' : 'Todos'}
                value={list.value}
                onChange={(e) => list.set(String(e.target.value))}
                options={list.options}
              />
            </div>
          ))}

          <div className={chip}>
            <span className={chipLabel}>Fecha:</span>
            <DateFilter label="Fecha" value={date} onChange={setDate} future />
          </div>

          {active > 0 && (
            <button type="button" data-clear-filters onClick={clear} className="ml-auto inline-flex items-center gap-1 text-xs font-bold text-brand-accent hover:text-brand-ink transition-colors">
              <X size={13} /> Limpiar filtros
            </button>
          )}
        </div>
      )}

      <section className="space-y-3" data-list="upcoming">
        <h2 className={sectionTitle}>Próximas ({shownUpcoming.length})</h2>
        <CardList
          key={filterKey}
          items={shownUpcoming}
          actions={actions}
          empty={active ? 'Ninguna cita próxima coincide con el filtro.' : 'No tienes citas próximas.'}
        />
      </section>

      {policies?.booking_enabled && policies.cancel_allowed && shownUpcoming.length > 0 && (
        <p className="text-xs text-slate-500">
          Puedes cambiar o cancelar una cita hasta {policies.cancel_window_hours} horas antes. Después, comunícate con la sede.
        </p>
      )}

      <section className="space-y-3 pt-2" data-list="past">
        <h2 className={sectionTitle}>Anteriores ({shownPast.length})</h2>
        <CardList
          key={filterKey}
          items={shownPast}
          empty={active ? 'Ninguna cita anterior coincide con el filtro.' : 'Aquí verás tus citas pasadas.'}
        />
      </section>
    </>
  );
}
