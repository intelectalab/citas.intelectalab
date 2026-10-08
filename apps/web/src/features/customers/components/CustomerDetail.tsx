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
import RecordPanel from '@/features/records/components/RecordPanel';
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
  dependents?: { id: string; name: string; species?: string | null; breed?: string | null }[];
}

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const cardTitle = 'flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider';
const smallLabel = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

const ageFrom = (birthDate?: string | null): string | null => {
  if (!birthDate) return null;
  const [y, m, d] = birthDate.slice(0, 10).split('-').map(Number);
  const now = new Date();
  let age = now.getFullYear() - y;
  if (now.getMonth() + 1 < m || (now.getMonth() + 1 === m && now.getDate() < d)) age--;
  return age >= 0 ? `${age} años` : null;
};

// Página de un cliente: sus datos, sus números y su historia (fichas de
// atención y citas). Se llega desde el ojo o el nombre en la tabla de clientes.
export default function CustomerDetail() {
  const params = useParams<{ id: string }>();
  const { user, hasModule } = useAuth();
  const vocab = useVocabulary();
  const [customer, setCustomer] = useState<CustomerData | null>(null);
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [records, setRecords] = useState<CareRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [editing, setEditing] = useState(false);
  const [recordFor, setRecordFor] = useState<string | null>(null);

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
      .then((res) => { setCustomer(res.data.customer); setError(''); })
      .catch((err) => setError(err?.response?.status === 404 ? `Este ${vocab.client.toLowerCase()} no existe.` : 'No se pudo cargar la información.'))
      .finally(() => setLoading(false));
  }, [params.id, vocab.client]);

  useEffect(() => { loadCustomer(); }, [loadCustomer]);
  useEffect(() => { loadHistory(); }, [loadHistory]);

  const { openDetail, panels } = useAppointmentPanels(loadHistory);

  // Las citas vienen de la más antigua a la más nueva.
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
    <li key={a.id}>
      <button
        type="button"
        data-appointment={a.id}
        onClick={() => openDetail(a.id)}
        className="w-full flex items-center gap-3 px-5 py-3 text-left hover:bg-slate-50 transition-colors"
      >
        <div className="w-28 shrink-0">
          <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">{formatDate(dateOf(a.starts_at), { weekday: 'short', day: '2-digit', month: 'short' })}</span>
          <span className="block text-xs text-slate-500">{dateOf(a.starts_at).slice(0, 4)} · {timeOf(a.starts_at)}</span>
        </div>
        <div className="flex-1 min-w-0">
          <span className="block text-sm text-slate-800 truncate">{a.service?.name}</span>
          <span className="block text-xs text-slate-500 truncate">
            {a.professional?.display_name}{a.dependent ? ` · ${a.dependent.name}` : ''} · {a.code}
          </span>
        </div>
        <span className={`px-2.5 py-1 text-xs rounded-full font-medium whitespace-nowrap ${statusStyle(a.status).badge}`}>{a.status_name}</span>
      </button>
    </li>
  );

  return (
    <div className="space-y-5 pb-8 font-sans">
      {backLink}

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
        {/* Datos del cliente */}
        <section className={`${card} p-6 space-y-5 xl:row-span-2`} data-card="customer">
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
            <button
              type="button"
              data-action="edit-customer"
              title={`Editar ${vocab.client.toLowerCase()}`}
              onClick={() => setEditing(true)}
              className="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors shrink-0"
            >
              <Pencil size={16} />
            </button>
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
              <span className="block text-[11px] font-bold text-amber-700 uppercase tracking-wider">Observaciones</span>
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
        </section>

        {/* Números */}
        <div className={`xl:col-span-2 grid grid-cols-2 gap-4 ${stats.length > 4 ? 'lg:grid-cols-5' : 'lg:grid-cols-4'}`}>
          {stats.map((stat) => (
            <div key={stat.id} data-stat={stat.id} className="bg-white px-4 py-3 rounded-2xl border border-slate-100 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)]">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <span className="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{stat.title}</span>
                  <div className="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900">{stat.value}</div>
                  <span className="block text-xs text-slate-500 mt-0.5 first-letter:uppercase">{stat.note}</span>
                </div>
                <div className={`p-2 rounded-xl bg-slate-50 border border-slate-100 shrink-0 ${stat.color}`}>{stat.icon}</div>
              </div>
            </div>
          ))}
        </div>

        {/* Historia: fichas de atención */}
        {usesRecords && (
          <section className={`${card} xl:col-span-2 overflow-hidden`} data-card="records">
            <div className="px-6 pt-5 pb-3">
              <h2 className={cardTitle}><ClipboardList className="w-4 h-4 text-[#3F768C]" /> Historia de atenciones</h2>
              <p className="text-xs text-slate-500 font-medium mt-1">Las fichas llenadas en cada atención, de la más reciente a la más antigua.</p>
            </div>
            {records.length === 0 ? (
              <p className="px-6 pb-6 text-sm text-slate-400">Aún no tiene fichas de atención.</p>
            ) : (
              <ul className="divide-y divide-slate-100 border-t border-slate-100">
                {records.map((record) => {
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
                })}
              </ul>
            )}
          </section>
        )}

        {/* Citas */}
        {usesAgenda && (
          <section className={`${card} xl:col-span-3 overflow-hidden`} data-card="appointments">
            <div className="px-6 pt-5 pb-3">
              <h2 className={cardTitle}><CalendarDays className="w-4 h-4 text-[#3F768C]" /> Citas</h2>
              <p className="text-xs text-slate-500 font-medium mt-1">Haz clic en una cita para ver su detalle.</p>
            </div>
            {appointments.length === 0 ? (
              <p className="px-6 pb-6 text-sm text-slate-400">Aún no tiene citas.</p>
            ) : (
              <div className="grid grid-cols-1 lg:grid-cols-2 border-t border-slate-100 lg:divide-x divide-slate-100">
                <div data-list="upcoming">
                  <span className={`block px-5 pt-3 ${smallLabel}`}>Próximas ({upcoming.length})</span>
                  {upcoming.length === 0
                    ? <p className="px-5 py-3 text-sm text-slate-400">Sin citas agendadas.</p>
                    : <ul className="divide-y divide-slate-100">{upcoming.map(appointmentRow)}</ul>}
                </div>
                <div data-list="past">
                  <span className={`block px-5 pt-3 ${smallLabel}`}>Anteriores ({past.length})</span>
                  {past.length === 0
                    ? <p className="px-5 py-3 text-sm text-slate-400">Sin citas anteriores.</p>
                    : <ul className="divide-y divide-slate-100 max-h-[28rem] overflow-y-auto">{past.map(appointmentRow)}</ul>}
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
