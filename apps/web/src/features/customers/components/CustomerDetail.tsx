'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import {
  ArrowLeft, CalendarCheck, CalendarClock, CalendarDays, CalendarX2, ClipboardList, EyeOff, Hash, IdCard, Loader2,
  Mail, MapPin, PawPrint, Pencil, Phone, Timer, User, Users,
} from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import FormCustomer from './FormCustomer';
import CustomerPortalAccess, { type PortalAccess } from './CustomerPortalAccess';
import { blockLabel, missesLabel, type OnlineBookingState } from '../onlineBooking';
import RecordPanel from '@/features/records/components/RecordPanel';
import { ProfessionalLink } from '@/app/components/common/PersonLink';
import { PagedList } from '@/app/components/common/Pager';
import { useAppointmentPanels } from '@/features/appointments/components/useAppointmentPanels';
import { RELEASED, dateOf, formatDate, formatMinutes, statusStyle, timeOf, todayISO, type Appointment } from '@/features/appointments/appointments';
import { RECORD_STATUS, type CareRecord } from '@/features/records/records';

interface CustomerData {
  id: string;
  code: string;
  first_name: string;
  last_name: string;
  birth_date?: string | null;
  biological_sex?: string | null;
  blood_type?: string | null;
  document_number?: string | null;
  type_document?: { name?: string; code?: string } | null;
  email?: string | null;
  phone?: string | null;
  address?: string | null;
  district?: string | null;
  department?: string | null;
  emergency_contact_name?: string | null;
  emergency_contact_phone?: string | null;
  medical_notes?: string | null;
  created_at?: string;
  portal_access?: PortalAccess;
  online_booking?: OnlineBookingState | null;
  dependents?: { id: string; name: string; species?: string | null; breed?: string | null }[];
}

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const cardTitle = 'flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider';
const smallLabel = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

const CLICKABLE = ['completed', 'upcoming', 'missed', 'records'];
const FOCUS_TITLES: Record<string, string> = { completed: 'Atenciones', upcoming: 'Citas próximas', missed: 'Canceladas y ausencias' };

const ageFrom = (birthDate?: string | null): string | null => {
  if (!birthDate) return null;
  const [y, m, d] = birthDate.slice(0, 10).split('-').map(Number);
  const now = new Date();
  let age = now.getFullYear() - y;
  if (now.getMonth() + 1 < m || (now.getMonth() + 1 === m && now.getDate() < d)) age--;
  return age >= 0 ? `${age} años` : null;
};

export default function CustomerDetail() {
  const params = useParams<{ id: string }>();
  const { user, hasModule, can } = useAuth();
  const vocab = useVocabulary();
  const [customer, setCustomer] = useState<CustomerData | null>(null);
  const [canForgive, setCanForgive] = useState(false);
  const [forgiving, setForgiving] = useState(false);
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [records, setRecords] = useState<CareRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [editing, setEditing] = useState(false);
  const [recordFor, setRecordFor] = useState<string | null>(null);
  const [focus, setFocus] = useState('');

  const usesDependents = Boolean(user?.tenant?.dependents?.enabled);
  const usesAgenda = hasModule('agenda');
  const usesRecords = hasModule('records');

  const loadHistory = useCallback(() => {
    if (usesAgenda) {
      api.get('/appointments', { params: { customer_id: params.id } })
        .then((res) => setAppointments(Array.isArray(res.data) ? res.data : []))
        .catch(() => setAppointments([]));
    }
    if (usesRecords) {
      api.get('/care-records', { params: { customer_id: params.id } })
        .then((res) => setRecords(Array.isArray(res.data) ? res.data : []))
        .catch(() => setRecords([]));
    }
  }, [params.id, usesAgenda, usesRecords]);

  const loadCustomer = useCallback(() => {
    api.get(`/customers/${params.id}`)
      .then((res) => { setCustomer(res.data.customer); setCanForgive(Boolean(res.data.can_forgive_booking)); setError(''); })
      .catch((err) => setError(err?.response?.status === 404 ? `Este ${vocab.client.toLowerCase()} no existe.` : 'No se pudo cargar la información.'))
      .finally(() => setLoading(false));
  }, [params.id, vocab.client]);

  useEffect(() => { loadCustomer(); }, [loadCustomer]);
  useEffect(() => { loadHistory(); }, [loadHistory]);

  const { openDetail, panels } = useAppointmentPanels(loadHistory);

  const today = todayISO();
  const upcoming = useMemo(
    () => appointments.filter((a) => dateOf(a.starts_at) >= today && !RELEASED.includes(a.status) && a.status !== 'completed'),
    [appointments, today],
  );
  const past = useMemo(
    () => appointments.filter((a) => !upcoming.includes(a)).reverse(),
    [appointments, upcoming],
  );

  const completed = appointments.filter((a) => a.status === 'completed');
  const measured = completed.filter((a) => a.timing.measured && a.timing.service_seconds);
  const averageMinutes = measured.length
    ? measured.reduce((sum, a) => sum + (a.timing.service_seconds as number), 0) / measured.length / 60
    : null;
  const lastVisit = completed.length ? completed[completed.length - 1] : null;
  const focused = focus === 'completed' ? [...completed].reverse()
    : focus === 'upcoming' ? upcoming
    : focus === 'missed' ? appointments.filter((a) => RELEASED.includes(a.status)).reverse()
    : [];

  const pickStat = (id: string) => {
    const target = id === 'records' ? 'records' : 'appointments';
    if (id !== 'records') setFocus((current) => (current === id ? '' : id));
    setTimeout(() => document.querySelector(`[data-card="${target}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
  };

  if (loading) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center">
        <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
      </div>
    );
  }

  const backLink = (
    <Link href="/customers" className="inline-flex items-center gap-2 text-base font-bold text-[#3F768C] hover:text-[#022340] transition-colors">
      <ArrowLeft size={18} /> {vocab.clients}
    </Link>
  );

  if (!customer) {
    return (
      <div className="space-y-5 font-sans">
        {backLink}
        <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>
      </div>
    );
  }

  const fullName = `${customer.first_name} ${customer.last_name}`;
  const age = ageFrom(customer.birth_date);
  const sex = customer.biological_sex === 'M' ? 'Masculino' : customer.biological_sex === 'F' ? 'Femenino' : null;

  const facts: [React.ReactNode, string, string | null | undefined][] = [
    [<Hash key="i" size={14} />, 'Código', customer.code],
    [<IdCard key="i" size={14} />, customer.type_document?.code ?? customer.type_document?.name ?? 'Documento', customer.document_number],
    [<Phone key="i" size={14} />, 'Celular', customer.phone],
    [<Mail key="i" size={14} />, 'Correo', customer.email],
    [<User key="i" size={14} />, 'Edad y sexo', [age, sex].filter(Boolean).join(' · ') || null],
    [<MapPin key="i" size={14} />, 'Dirección', [customer.address, customer.district, customer.department].filter(Boolean).join(', ') || null],
    [<Users key="i" size={14} />, 'Contacto de emergencia', [customer.emergency_contact_name, customer.emergency_contact_phone].filter(Boolean).join(' · ') || null],
  ];

  const stats: { id: string; title: string; value: string; note: string; icon: React.ReactNode; color: string }[] = [
    { id: 'completed', title: 'Atenciones', value: String(completed.length), note: lastVisit ? `Última: ${formatDate(dateOf(lastVisit.starts_at), { day: 'numeric', month: 'short', year: 'numeric' })}` : 'Aún ninguna', icon: <CalendarCheck size={18} />, color: 'text-emerald-600' },
    { id: 'upcoming', title: 'Citas próximas', value: String(upcoming.length), note: upcoming[0] ? `${formatDate(dateOf(upcoming[0].starts_at), { weekday: 'short', day: 'numeric', month: 'short' })}, ${timeOf(upcoming[0].starts_at)}` : 'Sin citas agendadas', icon: <CalendarClock size={18} />, color: 'text-sky-600' },
    { id: 'missed', title: 'Canceladas y ausencias', value: String(appointments.filter((a) => RELEASED.includes(a.status)).length), note: `${appointments.filter((a) => a.status === 'no_show').length} sin asistir`, icon: <CalendarX2 size={18} />, color: 'text-rose-700' },
    { id: 'time', title: 'Tiempo promedio', value: formatMinutes(averageMinutes), note: measured.length ? `En ${measured.length} ${measured.length === 1 ? 'atención medida' : 'atenciones medidas'}` : 'Sin atenciones medidas', icon: <Timer size={18} />, color: 'text-teal-600' },
    ...(usesRecords ? [{ id: 'records', title: 'Fichas', value: String(records.length), note: `${records.filter((r) => r.status !== 'draft').length} firmadas`, icon: <ClipboardList size={18} />, color: 'text-violet-600' }] : []),
  ];

  const appointmentRow = (a: Appointment) => (
    <li key={a.id} className="flex items-center gap-3 px-5 py-3 hover:bg-slate-50 transition-colors">
      <button type="button" data-appointment={a.id} onClick={() => openDetail(a.id)} className="flex-1 min-w-0 flex items-center gap-3 text-left">
        <div className="w-28 shrink-0">
          <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">{formatDate(dateOf(a.starts_at), { weekday: 'short', day: '2-digit', month: 'short' })}</span>
          <span className="block text-xs text-slate-500">{dateOf(a.starts_at).slice(0, 4)} · {timeOf(a.starts_at)}</span>
        </div>
        <div className="flex-1 min-w-0">
          <span className="block text-sm text-slate-800 truncate">{a.service?.name}</span>
          <span className="block text-xs text-slate-500 truncate">
            {a.dependent ? `${a.dependent.name} · ` : ''}{a.code}
          </span>
        </div>
      </button>
      <ProfessionalLink id={a.professional?.id} className="hidden sm:block max-w-40 text-xs font-semibold text-slate-600 truncate">{a.professional?.display_name}</ProfessionalLink>
      <button type="button" onClick={() => openDetail(a.id)} className={`px-2.5 py-1 text-xs rounded-full font-medium whitespace-nowrap ${statusStyle(a.status).badge}`}>{a.status_name}</button>
    </li>
  );

  return (
    <div className="space-y-5 pb-8 font-sans">
      {backLink}

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
        <section className={`${card} p-6 space-y-5`} data-card="customer">
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-center gap-3 min-w-0">
              <span className="w-12 h-12 rounded-2xl bg-[#84D0D9]/20 text-[#022340] flex items-center justify-center shrink-0"><User size={22} /></span>
              <div className="min-w-0">
                <h1 className="text-xl font-black text-[#022340] tracking-tight truncate">{fullName}</h1>
                <p className="text-xs text-slate-500">
                  {vocab.client}{customer.created_at ? ` desde ${formatDate(customer.created_at.slice(0, 10), { month: 'long', year: 'numeric' })}` : ''}
                </p>
              </div>
            </div>
            {can('customers.edit') && (<button
              type="button"
              data-action="edit-customer"
              title={`Editar ${vocab.client.toLowerCase()}`}
              onClick={() => setEditing(true)}
              className="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors shrink-0"
            >
              <Pencil size={16} />
            </button>)}
          </div>

          <dl className="space-y-3">
            {facts.filter(([, , value]) => value).map(([icon, label, value]) => (
              <div key={label} className="flex items-start gap-3">
                <span className="mt-0.5 text-slate-400 shrink-0">{icon}</span>
                <div className="min-w-0">
                  <dt className={smallLabel}>{label}</dt>
                  <dd className="text-sm font-semibold text-slate-800 break-words">{value}</dd>
                </div>
              </div>
            ))}
          </dl>

          {customer.medical_notes && (
            <div className="p-3 bg-amber-50 border border-amber-200 rounded-xl">
              <span className="block text-[11px] font-bold text-amber-700 uppercase tracking-wider">
                {user?.tenant?.rules?.['customers.clinical_fields'] ? 'Antecedentes y observaciones' : 'Observaciones'}
              </span>
              <p className="text-sm text-amber-800 mt-0.5 whitespace-pre-line">{customer.medical_notes}</p>
            </div>
          )}

          {usesDependents && (
            <div className="pt-4 border-t border-slate-100 space-y-2" data-card="dependents">
              <span className={`flex items-center gap-1.5 ${smallLabel}`}><PawPrint size={13} /> {vocab.dependents}</span>
              {(customer.dependents?.length ?? 0) === 0 ? (
                <p className="text-sm text-slate-400">Sin registrar.</p>
              ) : (
                <div className="flex flex-wrap gap-1.5">
                  {customer.dependents!.map((dependent) => (
                    <span key={dependent.id} className="px-2.5 py-1 text-xs font-medium bg-amber-50 text-amber-700 rounded-lg border border-amber-100">
                      {dependent.name}{dependent.species ? ` · ${dependent.species}` : ''}
                    </span>
                  ))}
                </div>
              )}
            </div>
          )}

          <CustomerPortalAccess customer={customer} clientWord={vocab.client} onChanged={loadCustomer} />

          {customer.online_booking && (
            <div data-online-booking={customer.online_booking.blocked ? 'blocked' : 'note'} className={`rounded-xl border px-4 py-3 space-y-2 ${customer.online_booking.blocked ? 'bg-rose-50 border-rose-200' : 'bg-amber-50 border-amber-200'}`}>
              <div>
                <span className={`block text-sm font-bold ${customer.online_booking.blocked ? 'text-rose-800' : 'text-amber-800'}`}>{missesLabel(customer.online_booking)}</span>
                <span className={`block text-xs ${customer.online_booking.blocked ? 'text-rose-700' : 'text-amber-700'}`}>
                  {[blockLabel(customer.online_booking), `Última: ${formatDate(customer.online_booking.last_miss_at.slice(0, 10), { day: 'numeric', month: 'short', year: 'numeric' })}`].filter(Boolean).join('. ')}.
                  {customer.online_booking.blocked ? ' El personal sí puede agendarle.' : ''}
                </span>
              </div>
              {canForgive && (
                <button
                  type="button"
                  data-action="forgive-booking"
                  disabled={forgiving}
                  onClick={() => {
                    setForgiving(true);
                    api.post(`/customers/${customer.id}/booking-strikes/forgive`)
                      .then(loadCustomer)
                      .catch(() => setError('No se pudieron perdonar las inasistencias.'))
                      .finally(() => setForgiving(false));
                  }}
                  className="text-xs font-bold text-[#022340] bg-white border border-slate-200 rounded-lg px-3 py-1.5 hover:bg-slate-50 transition-colors disabled:opacity-50"
                >
                  {forgiving ? 'Guardando...' : customer.online_booking.blocked ? 'Perdonar y permitirle reservar' : 'Perdonar inasistencias'}
                </button>
              )}
            </div>
          )}
        </section>

        <div className="xl:col-span-2 space-y-5">
        <div className={`grid grid-cols-2 gap-4 ${stats.length > 4 ? 'lg:grid-cols-5' : 'lg:grid-cols-4'}`}>
          {stats.map((stat) => (
            <button
              key={stat.id}
              type="button"
              data-stat={stat.id}
              disabled={!CLICKABLE.includes(stat.id)}
              aria-pressed={CLICKABLE.includes(stat.id) ? focus === stat.id : undefined}
              title={CLICKABLE.includes(stat.id) ? 'Ver cuáles son' : undefined}
              onClick={() => pickStat(stat.id)}
              className={`bg-white px-4 py-3 rounded-2xl border text-left shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)] transition-all duration-300 enabled:cursor-pointer enabled:hover:-translate-y-1 enabled:hover:shadow-[0_10px_25px_-5px_rgba(0,0,0,0.08)] ${
                focus === stat.id ? 'border-[#3F768C] ring-2 ring-[#3F768C]/20' : 'border-slate-100 enabled:hover:border-slate-200'
              }`}
            >
              <span className="flex items-start justify-between gap-2">
                <span className="block min-w-0">
                  <span className="block text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{stat.title}</span>
                  <span className="block mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900">{stat.value}</span>
                  <span className="block text-xs text-slate-500 mt-0.5 first-letter:uppercase">{stat.note}</span>
                </span>
                <span className={`p-2 rounded-xl bg-slate-50 border border-slate-100 shrink-0 ${stat.color}`}>{stat.icon}</span>
              </span>
            </button>
          ))}
        </div>

        {usesRecords && (
          <section className={`${card} overflow-hidden scroll-mt-4`} data-card="records">
            <div className="px-6 pt-5 pb-3">
              <h2 className={cardTitle}><ClipboardList className="w-4 h-4 text-[#3F768C]" /> Historia de atenciones</h2>
              <p className="text-xs text-slate-500 font-medium mt-1">Las fichas llenadas en cada atención, de la más reciente a la más antigua.</p>
            </div>
            {records.length === 0 ? (
              <p className="px-6 pb-6 text-sm text-slate-400">Aún no tiene fichas de atención.</p>
            ) : (
              <div className="border-t border-slate-100 flex flex-col">
                <PagedList items={records} empty="" renderRow={(record) => {
                  const date = record.appointment?.starts_at ?? record.created_at;
                  return (
                    <li key={record.id}>
                      <button
                        type="button"
                        data-record={record.id}
                        disabled={!record.appointment_id}
                        onClick={() => setRecordFor(record.appointment_id)}
                        className="w-full flex items-center gap-3 px-6 py-3 text-left hover:bg-slate-50 transition-colors"
                      >
                        <div className="w-28 shrink-0">
                          <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">{formatDate(dateOf(date), { weekday: 'short', day: '2-digit', month: 'short' })}</span>
                          <span className="block text-xs text-slate-500">{dateOf(date).slice(0, 4)} · {timeOf(date)}</span>
                        </div>
                        <div className="flex-1 min-w-0">
                          <span className="flex items-center gap-1.5 text-sm font-semibold text-slate-800 truncate">
                            {record.appointment?.service ?? record.template_name}
                            {record.is_private && <span title="Privada: solo la ve quien la escribió"><EyeOff size={13} className="text-violet-600" /></span>}
                          </span>
                          <span className="block text-xs text-slate-500 truncate">
                            {record.template_name} · {record.professional ?? record.author}{record.dependent ? ` · ${record.dependent.name}` : ''}
                          </span>
                        </div>
                        <span className={`px-2.5 py-1 text-xs rounded-full font-medium whitespace-nowrap ${RECORD_STATUS[record.status]?.badge ?? ''}`}>
                          {RECORD_STATUS[record.status]?.label ?? record.status}
                        </span>
                      </button>
                    </li>
                  );
                }} />
              </div>
            )}
          </section>
        )}
        </div>

        {usesAgenda && (
          <section className={`${card} xl:col-span-3 overflow-hidden scroll-mt-4`} data-card="appointments">
            <div className="px-6 pt-5 pb-3">
              <h2 className={cardTitle}><CalendarDays className="w-4 h-4 text-[#3F768C]" /> Citas</h2>
              <p className="text-xs text-slate-500 font-medium mt-1">Haz clic en una cita para ver su detalle. Las tarjetas de arriba filtran esta lista.</p>
            </div>
            {appointments.length === 0 ? (
              <p className="px-6 pb-6 text-sm text-slate-400">Aún no tiene citas.</p>
            ) : focus && FOCUS_TITLES[focus] ? (
              <div className="border-t border-slate-100 flex flex-col" data-list="focus">
                <div className="flex items-center justify-between gap-3 px-5 pt-3">
                  <span className={smallLabel}>{FOCUS_TITLES[focus]} ({focused.length})</span>
                  <button type="button" data-action="clear-focus" onClick={() => setFocus('')} className="text-xs font-bold text-[#3F768C] hover:text-[#022340] transition-colors">
                    Ver todas las citas
                  </button>
                </div>
                <PagedList key={focus} items={focused} renderRow={appointmentRow} empty="Ninguna." />
              </div>
            ) : (
              <div className="grid grid-cols-1 lg:grid-cols-2 border-t border-slate-100 lg:divide-x divide-slate-100">
                <div data-list="upcoming" className="flex flex-col">
                  <span className={`block px-5 pt-3 ${smallLabel}`}>Próximas ({upcoming.length})</span>
                  <PagedList items={upcoming} renderRow={appointmentRow} empty="Sin citas agendadas." />
                </div>
                <div data-list="past" className="flex flex-col">
                  <span className={`block px-5 pt-3 ${smallLabel}`}>Anteriores ({past.length})</span>
                  <PagedList items={past} renderRow={appointmentRow} empty="Sin citas anteriores." />
                </div>
              </div>
            )}
          </section>
        )}
      </div>

      <FormCustomer
        isOpen={editing}
        initialData={customer}
        onClose={() => { setEditing(false); loadCustomer(); }}
        onSubmitSuccess={loadCustomer}
      />

      <RecordPanel appointmentId={recordFor} onClose={() => setRecordFor(null)} onChanged={loadHistory} />

      {panels}
    </div>
  );
}
