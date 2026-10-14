'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import {
  ArrowLeft, Award, CalendarCheck, CalendarClock, CalendarDays, CalendarOff, CalendarX2, Clock, FileBadge, IdCard,
  Loader2, Mail, Pencil, Phone, Sun, Timer, Wrench,
} from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import FormProfessional from './FormProfessional';
import ProfessionalSchedule from './ProfessionalSchedule';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { PagedList } from '@/app/components/common/Pager';
import { useAppointmentPanels } from '@/features/appointments/components/useAppointmentPanels';
import { RELEASED, addDays, dateOf, formatDate, formatMinutes, statusStyle, timeOf, todayISO, toMinutes, type Appointment } from '@/features/appointments/appointments';
import type { Block } from '@/features/blocks/blocks';

interface ProfessionalData {
  id: string;
  display_name: string;
  license_number?: string | null;
  document_number?: string | null;
  phone?: string | null;
  bio?: string | null;
  created_at?: string;
  type_document?: { name?: string; code?: string } | null;
  status?: { id: string; name: string; slug?: string } | null;
  user?: { id: string; email: string; first_name?: string; last_name?: string } | null;
  specialties?: { id: string; name: string }[];
  services?: { id: string; name: string }[];
}

interface ScheduleData {
  branches: { id: string; name: string }[];
  schedules: { branch_id: string; weekday: number; starts: string; ends: string }[];
}

// Lunes primero; el número es el día de la semana que guarda la API (0 = domingo).
const WEEK: { weekday: number; label: string }[] = [
  { weekday: 1, label: 'Lunes' },
  { weekday: 2, label: 'Martes' },
  { weekday: 3, label: 'Miércoles' },
  { weekday: 4, label: 'Jueves' },
  { weekday: 5, label: 'Viernes' },
  { weekday: 6, label: 'Sábado' },
  { weekday: 0, label: 'Domingo' },
];

// Cuántos días hacia atrás y hacia adelante se traen las citas.
const WINDOW_DAYS = 30;

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const cardTitle = 'flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider';
const smallLabel = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

// Tarjetas de números que funcionan como filtro, y cómo se titula la lista que dejan.
const CLICKABLE = ['today', 'upcoming', 'completed', 'missed'];
const FOCUS_TITLES: Record<string, string> = { today: 'Citas de hoy', upcoming: 'Citas próximas', completed: 'Atenciones', missed: 'Canceladas y ausencias' };

const initials = (name: string) =>
  name.replace(/^(Dra?|Lic|Ps|Tec|Mg|Ing)\.?\s+/i, '').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();

const hoursLabel = (minutes: number) => {
  const hours = minutes / 60;
  return `${Number.isInteger(hours) ? hours : hours.toFixed(1)} h`;
};

// Página de un profesional: sus datos, sus números, su horario de la semana,
// sus ausencias y sus citas. Se llega desde su nombre en cualquier pantalla.
export default function ProfessionalDetail() {
  const params = useParams<{ id: string }>();
  const { user, hasModule, can } = useAuth();
  const vocab = useVocabulary();
  const [professional, setProfessional] = useState<ProfessionalData | null>(null);
  const [schedule, setSchedule] = useState<ScheduleData | null>(null);
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [blocks, setBlocks] = useState<Block[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [editing, setEditing] = useState(false);
  const [scheduleOpen, setScheduleOpen] = useState(false);
  // Tarjeta de números elegida: filtra la lista de citas.
  const [focus, setFocus] = useState('');

  const isAdmin = can('professionals.edit');
  const usesAgenda = hasModule('agenda');
  const usesLicense = Boolean(user?.tenant?.rules?.['professionals.license']);
  const today = todayISO();

  const loadProfessional = useCallback(() => {
    api.get(`/professionals/${params.id}`)
      .then((res) => { setProfessional(res.data); setError(''); })
      .catch((err) => setError(err?.response?.status === 404 ? `Este ${vocab.pro.toLowerCase()} no existe.` : 'No se pudo cargar la información.'))
      .finally(() => setLoading(false));
  }, [params.id, vocab.pro]);

  const loadSchedule = useCallback(() => {
    api.get(`/professionals/${params.id}/schedule`)
      .then((res) => setSchedule({ branches: res.data.branches ?? [], schedules: res.data.schedules ?? [] }))
      .catch(() => setSchedule(null));

    if (usesAgenda) {
      api.get('/availability-blocks', { params: { professional_id: params.id, period: 'current' } })
        .then((res) => setBlocks((Array.isArray(res.data) ? res.data : []).filter((b: Block) => b.professional_id === params.id)))
        .catch(() => setBlocks([]));
    }
  }, [params.id, usesAgenda]);

  const loadAppointments = useCallback(() => {
    if (!usesAgenda) return;

    api.get('/appointments', { params: { professional_id: params.id, from: addDays(todayISO(), -WINDOW_DAYS), to: addDays(todayISO(), WINDOW_DAYS) } })
      .then((res) => setAppointments((Array.isArray(res.data) ? res.data : []).filter((a: Appointment) => a.professional?.id === params.id)))
      .catch(() => setAppointments([]));
  }, [params.id, usesAgenda]);

  useEffect(() => { loadProfessional(); }, [loadProfessional]);
  useEffect(() => { loadSchedule(); }, [loadSchedule]);
  useEffect(() => { loadAppointments(); }, [loadAppointments]);

  const { openDetail, panels } = useAppointmentPanels(loadAppointments);

  // Las citas vienen de la más antigua a la más nueva.
  const upcoming = useMemo(
    () => appointments.filter((a) => dateOf(a.starts_at) >= today && !RELEASED.includes(a.status) && a.status !== 'completed'),
    [appointments, today],
  );
  const past = useMemo(() => appointments.filter((a) => !upcoming.includes(a)).reverse(), [appointments, upcoming]);

  if (loading) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center">
        <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
      </div>
    );
  }

  const backLink = (
    <Link href="/professionals" className="inline-flex items-center gap-2 text-base font-bold text-[#3F768C] hover:text-[#022340] transition-colors">
      <ArrowLeft size={18} /> {vocab.pros}
    </Link>
  );

  if (!professional) {
    return (
      <div className="space-y-5 font-sans">
        {backLink}
        <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>
      </div>
    );
  }

  const inactive = professional.status?.slug === 'inactive' || professional.status?.name?.toLowerCase() === 'inactivo';
  const todayList = appointments.filter((a) => dateOf(a.starts_at) === today && !RELEASED.includes(a.status));
  const completed = appointments.filter((a) => a.status === 'completed');
  const measured = completed.filter((a) => a.timing.measured && a.timing.service_seconds);
  const averageMinutes = measured.length
    ? measured.reduce((sum, a) => sum + (a.timing.service_seconds as number), 0) / measured.length / 60
    : null;
  const released = appointments.filter((a) => RELEASED.includes(a.status));
  const nextOne = upcoming.find((a) => dateOf(a.starts_at) > today) ?? upcoming[0];
  const focused = focus === 'today' ? todayList
    : focus === 'upcoming' ? upcoming
    : focus === 'completed' ? [...completed].reverse()
    : focus === 'missed' ? [...released].reverse()
    : [];

  // Las tarjetas de números filtran la lista de citas de esta misma página.
  const pickStat = (id: string) => {
    const target = id === 'records' ? 'records' : 'appointments';
    if (id !== 'records') setFocus((current) => (current === id ? '' : id));
    setTimeout(() => document.querySelector(`[data-card="${target}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
  };

  const branchName = (id: string) => schedule?.branches.find((b) => b.id === id)?.name ?? '';
  const severalBranches = new Set(schedule?.schedules.map((s) => s.branch_id)).size > 1;
  const weeklyMinutes = (schedule?.schedules ?? []).reduce((sum, s) => sum + toMinutes(s.ends) - toMinutes(s.starts), 0);
  const worksAt = [...new Set((schedule?.schedules ?? []).map((s) => branchName(s.branch_id)).filter(Boolean))];

  const facts: [React.ReactNode, string, string | null | undefined][] = [
    [<Mail key="i" size={14} />, 'Correo de acceso', professional.user?.email],
    [<Phone key="i" size={14} />, 'Celular', professional.phone],
    [<IdCard key="i" size={14} />, professional.type_document?.code ?? professional.type_document?.name ?? 'Documento', professional.document_number],
    ...(usesLicense ? [[<FileBadge key="i" size={14} />, 'Colegiatura', professional.license_number] as [React.ReactNode, string, string | null | undefined]] : []),
    [<Sun key="i" size={14} />, worksAt.length > 1 ? 'Sedes donde atiende' : 'Sede donde atiende', worksAt.join(' · ') || null],
  ];

  const stats: { id: string; title: string; value: string; note: string; icon: React.ReactNode; color: string }[] = [
    { id: 'today', title: 'Citas de hoy', value: String(todayList.length), note: todayList.length ? `${todayList.filter((a) => a.status === 'completed').length} ya atendidas` : 'Sin citas hoy', icon: <CalendarDays size={18} />, color: 'text-[#3F768C]' },
    { id: 'upcoming', title: `Próximos ${WINDOW_DAYS} días`, value: String(upcoming.length), note: nextOne ? `Sigue: ${formatDate(dateOf(nextOne.starts_at), { weekday: 'short', day: 'numeric', month: 'short' })}, ${timeOf(nextOne.starts_at)}` : 'Sin citas agendadas', icon: <CalendarClock size={18} />, color: 'text-sky-600' },
    { id: 'completed', title: `Atenciones, ${WINDOW_DAYS} días`, value: String(completed.length), note: `${new Set(completed.map((a) => a.customer?.id)).size} ${vocab.clients.toLowerCase()} distintos`, icon: <CalendarCheck size={18} />, color: 'text-emerald-600' },
    { id: 'missed', title: 'Canceladas y ausencias', value: String(released.length), note: `${released.filter((a) => a.status === 'no_show').length} sin asistir`, icon: <CalendarX2 size={18} />, color: 'text-rose-700' },
    { id: 'time', title: 'Tiempo promedio', value: formatMinutes(averageMinutes), note: measured.length ? `En ${measured.length} ${measured.length === 1 ? 'atención medida' : 'atenciones medidas'}` : 'Sin atenciones medidas', icon: <Timer size={18} />, color: 'text-teal-600' },
  ];

  const appointmentRow = (a: Appointment) => (
    <li key={a.id} className="flex items-center gap-3 px-5 py-3 hover:bg-slate-50 transition-colors">
      <button type="button" data-appointment={a.id} onClick={() => openDetail(a.id)} className="w-28 shrink-0 text-left" title="Ver la cita">
        <span className="block text-sm font-semibold text-slate-800 first-letter:uppercase">{formatDate(dateOf(a.starts_at), { weekday: 'short', day: '2-digit', month: 'short' })}</span>
        <span className="block text-xs text-slate-500">{timeOf(a.starts_at)} a {timeOf(a.ends_at)}</span>
      </button>
      <div className="flex-1 min-w-0">
        <CustomerLink id={a.customer?.id} className="block text-sm font-semibold text-slate-800 truncate">{a.customer?.name ?? '—'}</CustomerLink>
        <span className="block text-xs text-slate-500 truncate">
          {a.service?.name}{a.dependent ? ` · ${a.dependent.name}` : ''} · {a.code}
        </span>
      </div>
      <button type="button" onClick={() => openDetail(a.id)} className={`px-2.5 py-1 text-xs rounded-full font-medium whitespace-nowrap ${statusStyle(a.status).badge}`}>{a.status_name}</button>
    </li>
  );

  return (
    <div className="space-y-5 pb-8 font-sans">
      {backLink}

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
        {/* Datos del profesional */}
        <section className={`${card} p-6 space-y-5`} data-card="professional">
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-center gap-3 min-w-0">
              <span className="w-12 h-12 rounded-2xl bg-[#022340] text-[#84D0D9] flex items-center justify-center text-base font-black shrink-0">{initials(professional.display_name)}</span>
              <div className="min-w-0">
                <h1 className="text-xl font-black text-[#022340] tracking-tight truncate">{professional.display_name}</h1>
                <p className="text-xs text-slate-500">
                  {vocab.pro}{professional.created_at ? ` desde ${formatDate(professional.created_at.slice(0, 10), { month: 'long', year: 'numeric' })}` : ''}
                </p>
              </div>
            </div>
            {isAdmin && (
              <button
                type="button"
                data-action="edit-professional"
                title={`Editar ${vocab.pro.toLowerCase()}`}
                onClick={() => setEditing(true)}
                className="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors shrink-0"
              >
                <Pencil size={16} />
              </button>
            )}
          </div>

          <span className={`inline-flex px-2.5 py-1 text-xs rounded-full font-medium ${inactive ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600'}`} data-status>
            {professional.status?.name ?? 'Activo'}
          </span>

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

          <div className="pt-4 border-t border-slate-100 space-y-2" data-card="specialties">
            <span className={`flex items-center gap-1.5 ${smallLabel}`}><Award size={13} /> Especialidades</span>
            {(professional.specialties?.length ?? 0) === 0 ? (
              <p className="text-sm text-slate-400">Sin registrar.</p>
            ) : (
              <div className="flex flex-wrap gap-1.5">
                {professional.specialties!.map((specialty) => (
                  <span key={specialty.id} className="px-2.5 py-1 text-xs font-medium bg-[#84D0D9]/20 text-[#022340] rounded-lg">{specialty.name}</span>
                ))}
              </div>
            )}
          </div>

          {(professional.services?.length ?? 0) > 0 && (
            <div className="pt-4 border-t border-slate-100 space-y-2" data-card="services">
              <span className={`flex items-center gap-1.5 ${smallLabel}`}><Wrench size={13} /> Servicios que atiende</span>
              <div className="flex flex-wrap gap-1.5">
                {professional.services!.map((service) => (
                  <span key={service.id} className="px-2.5 py-1 text-xs font-medium bg-slate-100 text-slate-700 rounded-lg">{service.name}</span>
                ))}
              </div>
            </div>
          )}

          {professional.bio && <p className="pt-4 border-t border-slate-100 text-sm text-slate-600 whitespace-pre-line">{professional.bio}</p>}
        </section>

        {/* Números y, pegado debajo, el horario */}
        <div className="xl:col-span-2 space-y-5">
        {usesAgenda && (
          <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
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
        )}

        {/* Horario de la semana y ausencias */}
        <section className={`${card} overflow-hidden`} data-card="schedule">
          <div className="px-6 pt-5 pb-3 flex items-start justify-between gap-3">
            <div>
              <h2 className={cardTitle}><Clock className="w-4 h-4 text-[#3F768C]" /> Horario de la semana</h2>
              <p className="text-xs text-slate-500 font-medium mt-1">
                {weeklyMinutes > 0 ? `${hoursLabel(weeklyMinutes)} de atención a la semana.` : 'Sin horario definido: la agenda no le ofrece citas.'}
              </p>
            </div>
            {isAdmin && (
              <button
                type="button"
                data-action="edit-schedule"
                onClick={() => setScheduleOpen(true)}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-[#3F768C] hover:text-[#022340] hover:bg-slate-100 rounded-lg transition-colors shrink-0"
              >
                <Pencil size={13} /> Horario y ausencias
              </button>
            )}
          </div>

          <ul className="border-t border-slate-100 divide-y divide-slate-100">
            {WEEK.map((day) => {
              const ranges = (schedule?.schedules ?? []).filter((s) => s.weekday === day.weekday);
              const isToday = new Date().getDay() === day.weekday;

              return (
                <li key={day.weekday} data-day={day.weekday} className={`flex items-center gap-4 px-6 py-2.5 ${isToday ? 'bg-[#84D0D9]/10' : ''}`}>
                  <span className={`w-24 shrink-0 text-sm ${isToday ? 'font-bold text-[#022340]' : 'font-medium text-slate-600'}`}>
                    {day.label}{isToday ? ' · hoy' : ''}
                  </span>
                  {ranges.length === 0 ? (
                    <span className="text-sm text-slate-400">No atiende</span>
                  ) : (
                    <div className="flex flex-wrap gap-1.5">
                      {ranges.map((range, index) => (
                        <span key={index} className="px-2.5 py-1 text-xs font-semibold bg-slate-100 text-slate-700 rounded-lg tabular-nums">
                          {range.starts} a {range.ends}
                          {severalBranches && <span className="font-medium text-slate-500"> · {branchName(range.branch_id)}</span>}
                        </span>
                      ))}
                    </div>
                  )}
                </li>
              );
            })}
          </ul>

          {usesAgenda && (
            <div className="px-6 py-4 border-t border-slate-100" data-card="blocks">
              <span className={`flex items-center gap-1.5 ${smallLabel}`}><CalendarOff size={13} /> Ausencias vigentes y próximas</span>
              {blocks.length === 0 ? (
                <p className="text-sm text-slate-400 mt-1.5">Ninguna registrada.</p>
              ) : (
                <ul className="mt-2 space-y-1.5">
                  {blocks.map((block) => (
                    <li key={block.id} className="flex items-center justify-between gap-3 text-sm">
                      <span className="text-slate-700">
                        <span className="font-semibold">{block.kind_label}</span>
                        {block.reason ? ` · ${block.reason}` : ''}
                      </span>
                      <span className="text-xs text-slate-500 whitespace-nowrap first-letter:uppercase">
                        {dateOf(block.starts_at) === dateOf(block.ends_at)
                          ? `${formatDate(dateOf(block.starts_at), { day: 'numeric', month: 'short' })}, ${timeOf(block.starts_at)} a ${timeOf(block.ends_at)}`
                          : `${formatDate(dateOf(block.starts_at), { day: 'numeric', month: 'short' })} al ${formatDate(dateOf(block.ends_at), { day: 'numeric', month: 'short' })}`}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </section>
        </div>

        {/* Citas */}
        {usesAgenda && (
          <section className={`${card} xl:col-span-3 overflow-hidden scroll-mt-4`} data-card="appointments">
            <div className="px-6 pt-5 pb-3">
              <h2 className={cardTitle}><CalendarDays className="w-4 h-4 text-[#3F768C]" /> Citas</h2>
              <p className="text-xs text-slate-500 font-medium mt-1">
                Los últimos y los próximos {WINDOW_DAYS} días. La fecha y la fase abren la cita; el nombre lleva a la página de la persona. Las tarjetas de arriba filtran esta lista.
              </p>
            </div>
            {appointments.length === 0 ? (
              <p className="px-6 pb-6 text-sm text-slate-400">Sin citas en este periodo.</p>
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

      <FormProfessional
        isOpen={editing}
        initialData={professional as never}
        onClose={() => setEditing(false)}
        onSubmitSuccess={loadProfessional}
      />

      <ProfessionalSchedule
        isOpen={scheduleOpen}
        onClose={() => { setScheduleOpen(false); loadSchedule(); }}
        professional={professional}
      />

      {panels}
    </div>
  );
}
