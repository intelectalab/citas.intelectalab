'use client';

import Badge from '@/app/components/common/Badge';
import SectionTitle from '@/app/components/common/SectionTitle';
import BackLink from '@/app/components/common/BackLink';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useParams } from 'next/navigation';
import Link from 'next/link';
import { CalendarPlus, ClipboardPlus, FileText, ShoppingCart } from 'lucide-react';
import NewRecordDrawer from '@/features/records/components/NewRecordDrawer';
import { AlertTriangle, Building2, CalendarCheck, CalendarClock, CalendarDays, CalendarX2, ClipboardList, EyeOff, Hash, IdCard, Loader2, Mail, MapPin, PawPrint, Pencil, Phone, Stethoscope, Timer, User, Users } from 'lucide-react';
import { formatPhone } from '@/lib/phone';
import CustomerProfilePanel from './CustomerProfilePanel';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import CustomerRecordActions from './CustomerRecordActions';
import { useVocabulary } from '@/hooks/useVocabulary';
import FormCustomer from './FormCustomer';
import CustomerPortalAccess, { type PortalAccess } from './CustomerPortalAccess';
import CustomerStage from './CustomerStage';
import { blockLabel, missesLabel, type OnlineBookingState } from '../onlineBooking';
import RecordPanel from '@/features/records/components/RecordPanel';
import CustomerPackages from '@/features/packages/components/CustomerPackages';
import CustomerMessages from '@/features/messages/components/CustomerMessages';
import type { Consents } from '@/features/messages/messages';
import { ProfessionalLink } from '@/app/components/common/PersonLink';
import { PagedList } from '@/app/components/common/Pager';
import { useAppointmentPanels } from '@/features/appointments/components/useAppointmentPanels';
import { RELEASED, dateOf, formatDate, formatMinutes, statusStyle, timeOf, todayISO, type Appointment } from '@/features/appointments/appointments';
import { RECORD_STATUS, type CareRecord } from '@/features/records/records';
import DependentAlert from './DependentAlert';
import type { CustomerDependent } from './CustomerDependents';

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
  dependents?: { id: string; name: string; species?: string | null; breed?: string | null; alert?: string | null }[];
  alert?: string | null;
  home_branch_id?: string | null;
  preferred_professional_id?: string | null;
  home_branch?: { id: string; name: string } | null;
  preferred_professional?: { id: string; display_name: string } | null;
}

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
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
  const [consents, setConsents] = useState<Consents | null>(null);
  const [forgiving, setForgiving] = useState(false);
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [records, setRecords] = useState<CareRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [editing, setEditing] = useState(false);
  const [recordFor, setRecordFor] = useState<string | null>(null);
  const [sheetFor, setSheetFor] = useState<string | null>(null);
  const [newSheet, setNewSheet] = useState(false);
  const [recordDependent, setRecordDependent] = useState('');
  const [dependentCards, setDependentCards] = useState<CustomerDependent[]>([]);
  const [focus, setFocus] = useState('');
  const [stageKey, setStageKey] = useState(0);
  const [consentKey, setConsentKey] = useState(0);

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
      .then((res) => { setCustomer(res.data.customer); setCanForgive(Boolean(res.data.can_forgive_booking)); setConsents(res.data.consents ?? null); setError(''); })
      .catch((err) => setError(err?.response?.status === 404 ? `${vocab.pick('client', 'Este', 'Esta')} ${vocab.client.toLowerCase()} no existe.` : 'No se pudo cargar la información.'))
      .finally(() => setLoading(false));
  }, [params.id, vocab.client]);

  useEffect(() => { loadCustomer(); }, [loadCustomer]);

  useEffect(() => {
    if (!usesDependents) return;
    api.get(`/customers/${params.id}/dependents`)
      .then((res) => setDependentCards(Array.isArray(res.data) ? res.data : []))
      .catch(() => setDependentCards([]));
  }, [params.id, usesDependents, customer?.dependents?.length]);
  useEffect(() => { loadHistory(); }, [loadHistory]);

  const { openNew, openDetail, panels } = useAppointmentPanels(loadHistory);

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
        <Loader2 className="w-8 h-8 text-brand-accent animate-spin" />
      </div>
    );
  }

  const backLink = (
    <BackLink href="/customers">{vocab.clients}</BackLink>
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
  const picked = { id: customer.id, code: customer.code, first_name: customer.first_name, last_name: customer.last_name, phone: customer.phone ?? null, document_number: customer.document_number ?? null };
  const canSchedule = usesAgenda && can('agenda.create');
  const canQuote = hasModule('quotes') && can('quotes.create');
  const canSell = hasModule('pos') && can('sales.create');
  const sell = () => {
    try {
      const raw = sessionStorage.getItem('pos-cart');
      const current = raw ? JSON.parse(raw) : null;
      if (!current || ((current.items?.length ?? 0) === 0 && !current.saleId)) {
        const owner = String(JSON.parse(localStorage.getItem('user') ?? 'null')?.id ?? '');
        sessionStorage.setItem('pos-cart', JSON.stringify({ customer: picked, items: [], owner }));
      }
    } catch {}
    window.location.href = '/pos';
  };
  const recordDependents = Array.from(new Map(records.filter((r) => r.dependent).map((r) => [r.dependent!.id, r.dependent!])).values());
  const shownRecords = recordDependent ? records.filter((r) => r.dependent?.id === recordDependent) : records;
  const openRecord = (record: CareRecord) => { if (record.appointment_id) setRecordFor(record.appointment_id); else setSheetFor(record.id); };
  const age = ageFrom(customer.birth_date);
  const sex = customer.biological_sex === 'M' ? 'Masculino' : customer.biological_sex === 'F' ? 'Femenino' : null;

  const facts: [React.ReactNode, string, string | null | undefined][] = [
    [<Hash key="i" size={14} />, 'Código', customer.code],
    [<IdCard key="i" size={14} />, customer.type_document?.code ?? customer.type_document?.name ?? 'Documento', customer.document_number],
    [<Phone key="i" size={14} />, 'Celular', formatPhone(customer.phone)],
    [<Mail key="i" size={14} />, 'Correo', customer.email],
    [<User key="i" size={14} />, 'Edad y sexo', [age, sex].filter(Boolean).join(' · ') || null],
    [<MapPin key="i" size={14} />, 'Dirección', [customer.address, customer.district, customer.department].filter(Boolean).join(', ') || null],
    [<Users key="i" size={14} />, 'Contacto de emergencia', [customer.emergency_contact_name, formatPhone(customer.emergency_contact_phone)].filter(Boolean).join(' · ') || null],
    [<Building2 key="i" size={14} />, 'Sede habitual', customer.home_branch?.name],
    [<Stethoscope key="i" size={14} />, vocab.pro + ' preferido', customer.preferred_professional?.display_name],
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
              <span className="w-12 h-12 rounded-2xl bg-brand-soft/20 text-brand-ink flex items-center justify-center shrink-0"><User size={22} /></span>
              <div className="min-w-0">
                <h1 className="text-xl font-black text-brand-ink tracking-tight truncate">{fullName}</h1>
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

          {(canSchedule || canQuote || canSell) && (
            <div className="flex flex-wrap items-center gap-1.5" data-customer-actions>
              {canSchedule && (
                <button type="button" data-action="new-appointment" title="Nueva cita" onClick={() => openNew({ customer: picked })} className="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold bg-brand text-white rounded-lg hover:bg-brand-hover transition-colors">
                  <CalendarPlus size={14} /> <span>Nueva cita</span>
                </button>
              )}
              {canQuote && (
                <Link href={`/quotes?nuevo=1&cliente=${customer.id}`} data-action="new-quote" title="Nuevo presupuesto" className="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-brand-ink bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">
                  <FileText size={14} /> <span>Presupuesto</span>
                </Link>
              )}
              {canSell && (
                <button type="button" data-action="sell" title="Vender" onClick={sell} className="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-brand-ink bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">
                  <ShoppingCart size={14} /> <span>Vender</span>
                </button>
              )}
            </div>
          )}

          {customer.alert && (
            <div data-customer-alert className="flex items-start gap-2.5 p-3 bg-rose-50 border border-rose-200 rounded-xl">
              <AlertTriangle size={16} className="text-rose-600 shrink-0 mt-0.5" />
              <div className="min-w-0">
                <span className="block text-[11px] font-bold text-rose-700 uppercase tracking-wider">Alerta</span>
                <p className="text-sm font-semibold text-rose-800 break-words">{customer.alert}</p>
              </div>
            </div>
          )}

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
                <div className="space-y-2" data-dependent-cards>
                  {(dependentCards.length > 0 ? dependentCards : customer.dependents!).map((dependent) => {
                    const card = dependent as CustomerDependent;
                    const isPet = user?.tenant?.dependents?.kind === 'pet';
                    const age = ageFrom(card.birth_date ?? null);
                    const sexLabel = card.biological_sex === 'M' ? (isPet ? 'Macho' : 'Masculino') : card.biological_sex === 'F' ? (isPet ? 'Hembra' : 'Femenino') : null;
                    const details = isPet
                      ? [card.species, card.breed, sexLabel, age, card.weight_kg ? `${card.weight_kg} kg` : null, card.color, card.sterilized === true ? 'Esterilizado' : card.sterilized === false ? 'Sin esterilizar' : null]
                      : [card.relationship, card.document_number, sexLabel, age];
                    const stats = card.stats;
                    return (
                      <div key={card.id} data-dependent-card={card.id} className="rounded-xl border border-amber-100 bg-amber-50/60 px-3 py-2.5 space-y-1">
                        <span className="block text-sm font-bold text-amber-900">{card.name}</span>
                        {details.filter(Boolean).length > 0 && <span className="block text-xs text-amber-800">{details.filter(Boolean).join(' · ')}</span>}
                        {card.microchip && <span className="block text-[11px] text-amber-700">Microchip {card.microchip}</span>}
                        {stats && (
                          <span className="block text-[11px] text-slate-600" data-dependent-stats>
                            {stats.appointments} {stats.appointments === 1 ? 'cita' : 'citas'}
                            {stats.last_visit ? ` · Última atención: ${formatDate(dateOf(stats.last_visit), { day: 'numeric', month: 'short', year: 'numeric' })}` : ''}
                            {stats.next_appointment ? ` · Próxima cita: ${formatDate(dateOf(stats.next_appointment), { day: 'numeric', month: 'short' })}` : ''}
                            {stats.next_control ? ` · Próximo control: ${stats.next_control.label} (${formatDate(stats.next_control.due_date, { day: 'numeric', month: 'short', year: 'numeric' })})` : ''}
                          </span>
                        )}
                      </div>
                    );
                  })}
                </div>
              )}
              {customer.dependents?.filter((dependent) => dependent.alert?.trim()).map((dependent) => (
                <DependentAlert key={`alert-${dependent.id}`} name={dependent.name} alert={dependent.alert} />
              ))}
            </div>
          )}

          <CustomerStage customerId={customer.id} refreshKey={stageKey} onChanged={loadCustomer} />

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
                  className="text-xs font-bold text-brand-ink bg-white border border-slate-200 rounded-lg px-3 py-1.5 hover:bg-slate-50 transition-colors disabled:opacity-50"
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
                focus === stat.id ? 'border-brand-accent ring-2 ring-brand-accent/20' : 'border-slate-100 enabled:hover:border-slate-200'
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

        <CustomerProfilePanel
          customerId={customer.id}
          customerName={fullName}
          refreshKey={consentKey}
          appointments={appointments}
          records={records}
          onOpenAppointment={openDetail}
          onOpenRecord={openRecord}
          onBookRecall={canSchedule ? (recall) => openNew({ customer: picked, service_id: recall.service_id ?? undefined, customer_dependent_id: recall.dependent_id ?? undefined, recall_id: recall.id, recall_label: recall.label }) : undefined}
          onNewQuote={canQuote ? () => { window.location.href = `/quotes?nuevo=1&cliente=${customer.id}`; } : undefined}
        />

        {hasModule('packages') && can('packages.view') && <CustomerPackages customerId={customer.id} />}

        {(can('messages.view') || can('customers.edit')) && (
          <CustomerMessages customer={customer} consents={consents} canView={can('messages.view')} canEditConsents={can('customers.edit')} onConsentsChanged={() => setConsentKey((key) => key + 1)} />
        )}

        {usesRecords && (
          <section className={`${card} overflow-hidden scroll-mt-4`} data-card="records">
            <div className="px-6 pt-5 pb-3">
              <SectionTitle><ClipboardList className="w-4 h-4 text-brand-accent" /> Historia de atenciones</SectionTitle>
              <p className="text-xs text-slate-500 font-medium mt-1">Las fichas llenadas en cada atención, de la más reciente a la más antigua.</p>
              {(can('records.edit') || recordDependents.length > 0) && (
                <div className="flex flex-wrap items-center gap-2 mt-3">
                  {recordDependents.length > 0 && (
                    <div className="flex flex-wrap gap-1.5" role="group" aria-label={`Filtrar por ${vocab.dependent.toLowerCase()}`} data-record-dependent-filter>
                      {[{ id: '', name: 'Todas' }, ...recordDependents].map((d) => (
                        <button key={d.id || 'all'} type="button" aria-pressed={recordDependent === d.id} onClick={() => setRecordDependent(d.id)} className={`px-2.5 py-1 text-xs font-semibold rounded-lg border transition-colors ${recordDependent === d.id ? 'bg-brand text-white border-brand' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>{d.name}</button>
                      ))}
                    </div>
                  )}
                  {can('records.edit') && (
                    <button type="button" data-action="new-record-sheet" onClick={() => setNewSheet(true)} className="ml-auto inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-brand-ink bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">
                      <ClipboardPlus size={14} className="text-brand-accent" /> Nueva ficha sin cita
                    </button>
                  )}
                </div>
              )}
            </div>
            {records.length === 0 ? (
              <p className="px-6 pb-6 text-sm text-slate-400">Aún no tiene fichas de atención.</p>
            ) : (
              <div className="border-t border-slate-100 flex flex-col">
                <PagedList items={shownRecords} empty="Ninguna ficha para este filtro." renderRow={(record) => {
                  const date = record.appointment?.starts_at ?? record.created_at;
                  return (
                    <li key={record.id}>
                      <button
                        type="button"
                        data-record={record.id}
                        onClick={() => openRecord(record)}
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
                        <Badge tone={RECORD_STATUS[record.status]?.badge ?? ''} nowrap>
                          {RECORD_STATUS[record.status]?.label ?? record.status}
                        </Badge>
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
              <SectionTitle><CalendarDays className="w-4 h-4 text-brand-accent" /> Citas</SectionTitle>
              <p className="text-xs text-slate-500 font-medium mt-1">Haz clic en una cita para ver su detalle. Las tarjetas de arriba filtran esta lista.</p>
            </div>
            {appointments.length === 0 ? (
              <p className="px-6 pb-6 text-sm text-slate-400">Aún no tiene citas.</p>
            ) : focus && FOCUS_TITLES[focus] ? (
              <div className="border-t border-slate-100 flex flex-col" data-list="focus">
                <div className="flex items-center justify-between gap-3 px-5 pt-3">
                  <span className={smallLabel}>{FOCUS_TITLES[focus]} ({focused.length})</span>
                  <button type="button" data-action="clear-focus" onClick={() => setFocus('')} className="text-xs font-bold text-brand-accent hover:text-brand-ink transition-colors">
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

      {can('customers.delete') && <CustomerRecordActions customer={customer} onChanged={loadCustomer} />}

      <FormCustomer
        isOpen={editing}
        initialData={customer}
        onClose={() => { setEditing(false); loadCustomer(); setStageKey((key) => key + 1); }}
        onSubmitSuccess={loadCustomer}
      />

      <RecordPanel appointmentId={recordFor} onClose={() => setRecordFor(null)} onChanged={loadHistory} />

      <RecordPanel appointmentId={null} recordId={sheetFor} onClose={() => { setSheetFor(null); loadHistory(); }} onChanged={loadHistory} />

      <NewRecordDrawer
        isOpen={newSheet}
        customerId={customer.id}
        customerName={fullName}
        dependents={usesDependents ? (customer.dependents ?? []).map((d) => ({ id: d.id, name: d.name })) : []}
        dependentWord={vocab.dependent}
        onClose={() => setNewSheet(false)}
        onCreated={(id) => { setSheetFor(id); loadHistory(); }}
      />

      {panels}
    </div>
  );
}
