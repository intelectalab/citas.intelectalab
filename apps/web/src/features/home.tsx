'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import PlanSelection from '@/features/subscriptions/PlanSelection';
import { formatDateTime, statusStyle, timeOf } from '@/features/appointments/appointments';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { formatMoney } from '@/lib/currency';
import {
  Plus,
  Users,
  Building2,
  Ticket,
  ArrowRight,
  ChevronRight,
  Zap,
  Layers,
  ArrowUp,
  ArrowDown,
  AlertCircle,
  Calendar as CalendarIcon,
  CheckCircle2,
  ListTodo,
  Rss,
  CalendarDays,
  ExternalLink,
  Loader2,
  AlertTriangle,
  CalendarOff,
  CalendarClock,
  ClipboardList,
  Hourglass,
  ListOrdered,
  Repeat,
  UserCheck,
  UserPlus,
  Wallet,
  Settings,
} from 'lucide-react';

interface DashboardData {
  scope: 'own' | 'company';
  today: {
    total: number;
    last_week: number;
    completed: number;
    value: number;
    waiting: number;
    in_progress: number;
    to_confirm: number;
    released: number;
    occupancy: number | null;
    branches: { name: string | null; count: number }[];
    upcoming: { id: string; starts_at: string; who: string | null; service: string | null; professional: string | null; branch: string | null; status: string; status_name: string }[];
  };
  month: {
    attended: number;
    no_show: number;
    cancelled: number;
    attendance: number | null;
    value: number;
    new_customers: number;
    new_customers_before: number;
    top_services: { name: string; count: number }[];
  };
  pending: { key: string; count: number; href: string }[];
  activity: { at: string; from: string | null; to: string; to_name: string; by: string | null; by_customer: boolean; code: string | null; who: string | null; customer_id?: string | null; service: string | null }[];
}

const money = (value: number) => formatMoney(value, null, 0);

const greeting = () => {
  const hour = new Date().getHours();
  return hour < 12 ? 'Buenos días' : hour < 19 ? 'Buenas tardes' : 'Buenas noches';
};

export default function HomePage() {
  const { user, loading, hasModule } = useAuth();
  const vocab = useVocabulary();
  const [taskFilter, setTaskFilter] = useState<'pending' | 'completed'>('pending');
  const [data, setData] = useState<DashboardData | null>(null);
  const [error, setError] = useState('');

  const currentUser = user as any;
  const hasTenant = Boolean(currentUser?.tenant);
  const isPlatform = Boolean(currentUser?.is_saas_admin) || Boolean(currentUser?.is_saas_support);
  const hasActiveSubscription =
    currentUser?.tenant?.subscription?.status === 'active' ||
    Boolean(currentUser?.has_active_subscription) ||
    Boolean(currentUser?.plan_slug) ||
    isPlatform;
  const showsDashboard = hasTenant && hasActiveSubscription && hasModule('agenda');

  const load = useCallback(() => {
    api.get('/dashboard')
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(err?.response?.data?.message || 'No se pudo cargar el resumen de tu empresa.'));
  }, []);

  useEffect(() => {
    if (loading || !showsDashboard) return;

    load();
    const timer = setInterval(load, 60000);
    return () => clearInterval(timer);
  }, [loading, showsDashboard, load]);

  if (loading) {
    return (
      <div className="min-h-[60vh] flex flex-col items-center justify-center gap-3">
        <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
        <p className="text-sm font-medium text-slate-500">Cargando información del sistema...</p>
      </div>
    );
  }

  if (!hasActiveSubscription) {
    return (
      <div className="max-w-7xl mx-auto space-y-6 pb-10 font-sans text-[#1F2937]">
        <div className="p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl flex items-center gap-3 text-xs font-semibold">
          <AlertTriangle className="w-5 h-5 shrink-0 text-amber-600" />
          <span>Tu empresa no cuenta con un plan o suscripción activa. Selecciona uno para desbloquear tu ERP.</span>
        </div>
        <PlanSelection isEmbedded />
      </div>
    );
  }

  const today = new Date();
  const formattedDate = today.toLocaleDateString('es-ES', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });
  const weekday = today.toLocaleDateString('es-ES', { weekday: 'long' });
  const own = data?.scope === 'own';
  const client = vocab.client.toLowerCase();
  const clients = vocab.clients.toLowerCase();

  const quickActions = hasTenant
    ? [
        { label: 'Nueva cita', icon: CalendarDays, href: '/agenda' },
        { label: `Nuevo ${vocab.client}`, icon: UserPlus, href: '/customers' },
        hasModule('walkin') ? { label: 'Cola sin cita', icon: ListOrdered, href: '/modules/walkin' }
          : hasModule('classes') ? { label: 'Clases', icon: CalendarClock, href: '/modules/classes' }
          : hasModule('records') ? { label: 'Fichas de atención', icon: ClipboardList, href: '/records' }
          : { label: 'Lista de espera', icon: Hourglass, href: '/waitlist' },
        own ? { label: 'Controles y seguimiento', icon: Repeat, href: '/recalls' } : { label: 'Bloqueos y ausencias', icon: CalendarOff, href: '/blocks' },
      ]
    : [
        { label: 'Empresas', icon: Building2, href: '/admin/tenants' },
        { label: 'Planes', icon: Ticket, href: '/admin/plans' },
        { label: 'Plantillas de rubros', icon: Layers, href: '/admin/business-types' },
        { label: 'Configuración', icon: Settings, href: '/admin/configuration' },
      ].filter((item) => currentUser?.is_saas_admin || item.href === '/admin/tenants');

  const pendingLabels: Record<string, { label: string; detail: string }> = {
    to_confirm: { label: 'Citas por confirmar', detail: 'De hoy y de mañana' },
    unclosed: { label: 'Citas pasadas sin cerrar', detail: 'Falta marcar si se atendieron o no asistieron' },
    blocked: { label: 'Citas dentro de un bloqueo o feriado', detail: 'Hay que reprogramarlas o cancelarlas' },
    recalls: { label: 'Controles vencidos', detail: `${vocab.clients} que ya debían volver` },
    waitlist: { label: 'En lista de espera', detail: 'Esperan que se libere un horario' },
    draft_records: { label: 'Fichas sin firmar', detail: 'Borradores por terminar' },
  };
  const pendingItems = (data?.pending ?? []).filter((item) => item.count > 0);
  const clearItems = (data?.pending ?? []).filter((item) => item.count === 0);

  const change = data && data.today.last_week > 0
    ? Math.round(((data.today.total - data.today.last_week) * 100) / data.today.last_week)
    : null;

  const activityText = (event: DashboardData['activity'][number]) => {
    if (event.from === null) return event.by_customer ? 'reservó desde el portal' : 'tiene una cita nueva';
    if (event.from === event.to) return 'cambió de fecha';
    return `pasó a ${event.to_name.toLowerCase()}`;
  };

  return (
    <div className="max-w-7xl mx-auto space-y-8 pb-10 font-sans text-[#1F2937]" data-dashboard={data ? 'ready' : 'loading'}>
      <div className="flex flex-col md:flex-row md:items-end justify-between gap-4 pt-2 pb-2 border-b border-slate-200/60">
        <div className="space-y-1">
          <div className="flex items-center gap-2 text-xs font-semibold text-[#3F768C] uppercase tracking-wider">
            <CalendarIcon className="w-3.5 h-3.5" />
            <span>{formattedDate}</span>
          </div>
          <h1 className="text-3xl md:text-4xl font-black text-[#022340] tracking-tight">
            {greeting()}, {currentUser?.first_name?.trim() ? `${currentUser.first_name} ${currentUser.last_name}` : 'Usuario'}
          </h1>
          <p className="text-slate-500 text-sm font-medium">
            {!hasTenant
              ? 'Consola de la plataforma.'
              : own
                ? 'Tu agenda de hoy y lo que tienes por resolver.'
                : `Lo que pasa hoy en ${currentUser.tenant.name} y lo que hay por resolver.`}
          </p>
          {currentUser?.tenant?.code && !own && (
            <p className="text-xs text-slate-500 font-medium">
              Código de empresa para el registro de tus {clients}:{' '}
              <span className="font-mono font-bold text-[#022340] tracking-widest">{currentUser.tenant.code}</span>
            </p>
          )}
        </div>

        {showsDashboard && (
          <div className="flex items-center gap-3">
            <Link
              href="/agenda"
              className="bg-[#022340] hover:bg-[#012840] text-white font-bold px-5 py-2.5 rounded-tl-xl rounded-br-xl rounded-tr-sm rounded-bl-sm flex items-center gap-2 text-sm shadow-sm transition-all hover:shadow-md active:scale-95"
            >
              <Plus className="w-4 h-4 text-[#84D0D9] stroke-[2.5]" />
              Nueva cita
            </Link>

            <Link
              href="/appointments"
              className="bg-white hover:bg-slate-50 text-[#022340] font-semibold px-4 py-2.5 rounded-tl-xl rounded-br-xl rounded-tr-sm rounded-bl-sm text-sm transition-all border border-slate-200 flex items-center gap-2 shadow-2xs"
            >
              Ver citas
              <ArrowRight className="w-4 h-4 text-[#3F768C]" />
            </Link>
          </div>
        )}
      </div>

      {error && (
        <p role="alert" className="p-4 bg-red-50 border border-red-100 text-red-700 rounded-2xl text-xs font-semibold">{error}</p>
      )}

      {showsDashboard && !data && !error && (
        <div className="min-h-[30vh] flex items-center justify-center gap-3 text-sm font-medium text-slate-500">
          <Loader2 className="w-5 h-5 text-[#3F768C] animate-spin" /> Cargando el resumen de tu empresa...
        </div>
      )}

      {data && (
        <>
          <div className="bg-white rounded-tr-[2rem] rounded-bl-[2rem] rounded-tl-md rounded-br-md border border-slate-200/80 shadow-xs p-6" data-card="upcoming">
            <div className="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
              <div className="flex items-center gap-2">
                <div className="p-1.5 bg-[#84D0D9]/20 text-[#022340] rounded-lg">
                  <CalendarDays className="w-4 h-4" />
                </div>
                <h2 className="text-xs font-bold text-[#022340] uppercase tracking-wider">
                  {own ? 'Tus próximas citas de hoy' : 'Próximas citas de hoy'}
                </h2>
              </div>
              <span className="text-[11px] text-slate-400 font-medium">Se actualiza sola cada minuto</span>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
              <div className="lg:col-span-7 space-y-3">
                {data.today.upcoming.length === 0 && (
                  <div className="p-6 text-center text-xs text-slate-400 bg-slate-50/80 rounded-xl border border-slate-100">
                    <CheckCircle2 className="w-8 h-8 text-emerald-500/40 mx-auto mb-2" />
                    {data.today.total > 0 ? 'No quedan citas por atender hoy.' : 'No hay citas para hoy.'}
                  </div>
                )}

                {data.today.upcoming.map((item) => (
                  <Link key={item.id} href="/agenda" data-upcoming={item.id} className="flex items-center gap-3 p-3 bg-slate-50/80 rounded-xl border border-slate-100 hover:border-[#84D0D9]/60 transition-colors">
                    <div className="text-xs font-bold text-slate-400 w-12 text-right">{timeOf(item.starts_at)}</div>
                    <div className={`w-1 h-8 rounded-full ${statusStyle(item.status).dot}`} />
                    <div className="flex-1 min-w-0">
                      <p className="text-xs font-bold text-[#022340] truncate">{item.who ?? vocab.client}</p>
                      <p className="text-[11px] text-slate-500 truncate">
                        {[item.service, own ? null : item.professional, data.today.branches.length > 1 ? item.branch : null].filter(Boolean).join(' • ')}
                      </p>
                    </div>
                    <span className={`px-2 py-0.5 text-[10px] rounded-md font-semibold shrink-0 ${statusStyle(item.status).badge}`}>{item.status_name}</span>
                  </Link>
                ))}
              </div>

              <div className="lg:col-span-5 bg-slate-50 p-5 rounded-2xl border border-slate-200/60 flex flex-col justify-between space-y-4" data-card="now">
                <div>
                  <h3 className="text-sm font-bold text-[#022340]">Ahora mismo</h3>
                  <div className="grid grid-cols-3 gap-3 mt-3">
                    {[
                      { id: 'waiting', label: 'En espera', value: data.today.waiting },
                      { id: 'in_progress', label: 'En atención', value: data.today.in_progress },
                      { id: 'to_confirm', label: 'Por confirmar', value: data.today.to_confirm },
                    ].map((item) => (
                      <div key={item.id} data-now={item.id} className="bg-white rounded-xl border border-slate-200/60 px-3 py-2.5">
                        <span className="block text-2xl font-extrabold text-[#022340] tracking-tight">{item.value}</span>
                        <span className="block text-[11px] text-slate-500 font-medium">{item.label}</span>
                      </div>
                    ))}
                  </div>

                  {data.today.branches.length > 1 && (
                    <ul className="mt-4 space-y-1.5" data-branches>
                      {data.today.branches.map((branch) => (
                        <li key={branch.name} className="flex items-center justify-between text-xs text-slate-600">
                          <span className="truncate">{branch.name}</span>
                          <span className="font-bold text-[#022340]">{branch.count} {branch.count === 1 ? 'cita' : 'citas'}</span>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
                <Link
                  href="/agenda"
                  className="self-start inline-flex items-center gap-2 px-4 py-2 bg-[#022340] hover:bg-[#012840] text-white text-xs font-bold rounded-xl transition-all shadow-xs cursor-pointer"
                >
                  <CalendarIcon className="w-3.5 h-3.5 text-[#84D0D9]" />
                  Ir a la agenda
                </Link>
              </div>
            </div>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
            <div className="lg:col-span-4 bg-gradient-to-br from-[#022340] to-[#012840] text-white rounded-tl-[2rem] rounded-br-[2rem] rounded-tr-md rounded-bl-md p-7 flex flex-col justify-between shadow-lg relative overflow-hidden" data-card="today">
              <div className="absolute right-0 top-0 w-32 h-32 bg-[#84D0D9]/10 rounded-full blur-2xl pointer-events-none" />

              <div className="relative z-10">
                <div className="flex items-center justify-between border-b border-white/10 pb-4">
                  <span className="text-xs font-bold text-[#84D0D9] uppercase tracking-wider flex items-center gap-2">
                    <CalendarDays className="w-4 h-4" /> Citas de hoy
                  </span>
                  {change !== null && (
                    <span title={`Frente al ${weekday} pasado (${data.today.last_week})`} className="px-2.5 py-0.5 rounded-full bg-[#84D0D9]/20 text-[#84D0D9] border border-[#84D0D9]/30 text-[11px] font-bold flex items-center gap-1">
                      {change >= 0 ? <ArrowUp className="w-3 h-3" /> : <ArrowDown className="w-3 h-3" />} {change >= 0 ? '+' : ''}{change}%
                    </span>
                  )}
                </div>

                <div className="mt-6 space-y-1">
                  <div className="text-5xl font-black tracking-tight text-white" data-today-total>{data.today.total}</div>
                  <p className="text-xs text-slate-300 font-medium">
                    {data.today.completed} atendidas{data.today.released > 0 ? ` · ${data.today.released} canceladas o ausentes` : ''}
                    {change !== null ? ` · el ${weekday} pasado fueron ${data.today.last_week}` : ''}
                  </p>
                </div>
              </div>

              <div className="relative z-10 mt-8 pt-4 border-t border-white/10 flex items-center justify-between text-xs text-slate-300">
                <span>{data.today.occupancy === null ? 'Sin horario hoy' : `Agenda ocupada: ${data.today.occupancy}%`}</span>
                <span className="font-bold text-[#84D0D9]">{money(data.today.value)} atendido</span>
              </div>
            </div>

            <div className="lg:col-span-8 bg-white rounded-tr-[2rem] rounded-bl-[2rem] rounded-tl-md rounded-bl-md border border-slate-200/80 shadow-xs p-6 flex flex-col justify-between" data-card="month">
              <div className="flex items-center justify-between pb-4 border-b border-slate-100">
                <h2 className="text-xs font-bold text-[#022340] uppercase tracking-wider flex items-center gap-2">
                  <Layers className="w-4 h-4 text-[#3F768C]" /> {own ? 'Tus últimos 30 días' : 'Últimos 30 días'}
                </h2>
                <Link href="/attention-times" className="text-xs font-bold text-[#3F768C] hover:text-[#022340] flex items-center gap-1">
                  Tiempos de atención <ExternalLink className="w-3 h-3" />
                </Link>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-6 my-4 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
                <div className="pt-4 sm:pt-0 sm:pr-4 space-y-3" data-metric="attendance">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-bold text-slate-600">Asistencia</span>
                    <div className="p-2 bg-[#84D0D9]/15 text-[#022340] rounded-xl border border-[#84D0D9]/30">
                      <UserCheck className="w-4 h-4" />
                    </div>
                  </div>
                  <div>
                    <span className="text-3xl font-extrabold text-[#022340] tracking-tight">{data.month.attendance === null ? '—' : `${data.month.attendance}%`}</span>
                    <div className="mt-2 inline-flex items-center gap-1.5 text-[#022340] bg-slate-100 px-2.5 py-1 rounded-lg text-[11px] font-semibold">
                      <AlertCircle className="w-3 h-3 text-amber-500" /> {data.month.no_show} no asistieron · {data.month.cancelled} cancelaron
                    </div>
                  </div>
                </div>

                <div className="pt-4 sm:pt-0 sm:px-4 space-y-3" data-metric="new-customers">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-bold text-slate-600">{vocab.clients} nuevos este mes</span>
                    <div className="p-2 bg-[#84D0D9]/15 text-[#022340] rounded-xl border border-[#84D0D9]/30">
                      <Users className="w-4 h-4" />
                    </div>
                  </div>
                  <div>
                    <span className="text-3xl font-extrabold text-[#022340] tracking-tight">{data.month.new_customers}</span>
                    <div className="mt-2 inline-flex items-center gap-1.5 text-slate-700 bg-slate-100 px-2.5 py-1 rounded-lg text-[11px] font-semibold">
                      <Users className="w-3 h-3 text-slate-500" /> {data.month.new_customers_before} el mes pasado
                    </div>
                  </div>
                </div>

                <div className="pt-4 sm:pt-0 sm:pl-4 space-y-3" data-metric="value">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-bold text-slate-600">Valor de lo atendido</span>
                    <div className="p-2 bg-slate-100 text-slate-600 rounded-xl">
                      <Wallet className="w-4 h-4" />
                    </div>
                  </div>
                  <div>
                    <span className="text-3xl font-extrabold text-[#022340] tracking-tight">{money(data.month.value)}</span>
                    <div className="mt-2 inline-flex items-center gap-1.5 text-slate-700 bg-slate-100 px-2.5 py-1 rounded-lg text-[11px] font-semibold">
                      <CheckCircle2 className="w-3 h-3 text-emerald-500" /> {data.month.attended} citas atendidas
                    </div>
                  </div>
                </div>
              </div>

              <div className="pt-3 border-t border-slate-100 text-[11px] text-slate-500 flex flex-wrap items-center justify-between gap-2" data-top-services>
                <span>
                  <span className="font-bold text-[#022340]">Lo más pedido:</span>{' '}
                  {data.month.top_services.length ? data.month.top_services.map((service) => `${service.name} (${service.count})`).join(' · ') : 'aún no hay citas'}
                </span>
                <span className="text-slate-400">El valor suma el precio de las citas atendidas; no es lo cobrado.</span>
              </div>
            </div>
          </div>
        </>
      )}

      <div className="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-xs space-y-4" data-card="quick">
        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
          <div className="flex items-center gap-2">
            <div className="p-1.5 bg-[#84D0D9]/20 text-[#022340] rounded-lg">
              <Zap className="w-4 h-4" />
            </div>
            <span className="text-xs font-bold uppercase tracking-wider text-[#022340]">Acciones Rápidas</span>
          </div>
          <span className="text-[11px] text-slate-400">Atajos frecuentes</span>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          {quickActions.map((item) => {
            const Icon = item.icon;
            return (
              <Link
                key={item.href + item.label}
                href={item.href}
                className="flex items-center justify-between p-3.5 bg-slate-50 hover:bg-[#84D0D9]/10 text-slate-700 hover:text-[#022340] text-xs font-semibold rounded-tl-xl rounded-br-xl rounded-tr-sm rounded-bl-sm border border-slate-200/60 hover:border-[#84D0D9]/50 transition-all duration-200 group"
              >
                <div className="flex items-center gap-3">
                  <div className="p-2 bg-white rounded-lg text-[#3F768C] shadow-2xs group-hover:scale-105 transition-transform border border-slate-100">
                    <Icon className="w-4 h-4" />
                  </div>
                  <span>{item.label}</span>
                </div>
                <ChevronRight className="w-4 h-4 text-slate-400 group-hover:text-[#022340] group-hover:translate-x-1 transition-all" />
              </Link>
            );
          })}
        </div>
      </div>

      {data && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div className="bg-white rounded-tr-[2rem] rounded-bl-[2rem] rounded-tl-md rounded-br-md border border-slate-200/80 shadow-xs p-6 flex flex-col justify-between" data-card="pending">
            <div>
              <div className="flex items-center justify-between pb-4 border-b border-slate-100">
                <div className="flex items-center gap-2">
                  <ListTodo className="w-4 h-4 text-[#3F768C]" />
                  <h3 className="font-bold text-[#022340] text-sm">Por resolver</h3>
                </div>

                <div className="flex items-center bg-slate-100 p-0.5 rounded-lg border border-slate-200/60 text-xs font-semibold">
                  <button
                    type="button"
                    data-filter="pending"
                    onClick={() => setTaskFilter('pending')}
                    className={`px-3 py-1 rounded-md transition-all cursor-pointer ${
                      taskFilter === 'pending'
                        ? 'bg-white text-[#022340] shadow-2xs'
                        : 'text-slate-500 hover:text-slate-700'
                    }`}
                  >
                    Pendientes
                  </button>
                  <button
                    type="button"
                    data-filter="completed"
                    onClick={() => setTaskFilter('completed')}
                    className={`px-3 py-1 rounded-md transition-all cursor-pointer ${
                      taskFilter === 'completed'
                        ? 'bg-white text-[#022340] shadow-2xs'
                        : 'text-slate-500 hover:text-slate-700'
                    }`}
                  >
                    Al día
                  </button>
                </div>
              </div>

              <div className="mt-4 space-y-2.5" data-pending-list={taskFilter}>
                {taskFilter === 'pending' && pendingItems.length === 0 && (
                  <div className="p-6 text-center text-xs text-slate-400">
                    <CheckCircle2 className="w-8 h-8 text-emerald-500/40 mx-auto mb-2" />
                    Todo al día: no hay nada por resolver.
                  </div>
                )}
                {taskFilter === 'completed' && clearItems.length === 0 && (
                  <div className="p-6 text-center text-xs text-slate-400">Todavía hay pendientes en todos los frentes.</div>
                )}

                {(taskFilter === 'pending' ? pendingItems : clearItems).map((item) => (
                  <Link key={item.key} href={item.href} data-pending={item.key} className="p-3 bg-slate-50 rounded-xl border border-slate-200/60 flex items-center justify-between gap-3 hover:border-[#84D0D9]/60 transition-colors">
                    <div className="flex items-center gap-3 min-w-0">
                      {item.count > 0
                        ? <div className="w-5 h-5 rounded-full border-2 border-slate-300 shrink-0" />
                        : <CheckCircle2 className="w-5 h-5 text-emerald-500 shrink-0" />}
                      <div className="min-w-0">
                        <p className="text-xs font-bold text-slate-800">{pendingLabels[item.key]?.label ?? item.key}</p>
                        <p className="text-[11px] text-slate-400 truncate">{pendingLabels[item.key]?.detail}</p>
                      </div>
                    </div>
                    {item.count > 0 ? (
                      <span className="text-[10px] font-semibold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-md border border-amber-200 shrink-0">{item.count}</span>
                    ) : (
                      <span className="text-[10px] font-semibold text-slate-500 bg-slate-200/60 px-2 py-0.5 rounded-md shrink-0">Al día</span>
                    )}
                  </Link>
                ))}
              </div>
            </div>

            <div className="pt-4 mt-4 border-t border-slate-100 flex items-center justify-between">
              <Link href="/agenda" className="inline-flex items-center gap-1.5 text-xs font-bold text-[#022340] hover:text-[#3F768C] transition-colors cursor-pointer">
                <Plus className="w-3.5 h-3.5 text-[#3F768C]" /> Nueva cita
              </Link>
              <span className="text-[11px] text-slate-400">{pendingItems.length} {pendingItems.length === 1 ? 'pendiente' : 'pendientes'}</span>
            </div>
          </div>

          <div className="bg-white rounded-tr-[2rem] rounded-bl-[2rem] rounded-tl-md rounded-br-md border border-slate-200/80 shadow-xs p-6 flex flex-col justify-between" data-card="activity">
            <div>
              <div className="flex items-center justify-between pb-4 border-b border-slate-100">
                <div className="flex items-center gap-2">
                  <Rss className="w-4 h-4 text-[#3F768C]" />
                  <h3 className="font-bold text-[#022340] text-sm">Actividad reciente</h3>
                </div>
                <Link href="/appointments" className="text-xs font-bold text-[#3F768C] hover:text-[#022340] flex items-center gap-1">
                  Ver citas <ExternalLink className="w-3 h-3" />
                </Link>
              </div>

              <p className="text-xs text-slate-500 mt-3 mb-4">
                Los últimos movimientos de {own ? 'tus citas' : 'las citas'}: reservas, confirmaciones, atenciones y cancelaciones.
              </p>

              <div className="space-y-3">
                {data.activity.length === 0 && <p className="p-6 text-center text-xs text-slate-400">Aún no hay movimientos.</p>}

                {data.activity.map((event, index) => (
                  <div key={index} className="p-3 bg-slate-50 rounded-xl border border-slate-200/60 flex items-start gap-3" data-activity={event.to}>
                    <div className={`w-2 h-2 rounded-full mt-1.5 shrink-0 ${statusStyle(event.to).dot}`} />
                    <div className="text-xs min-w-0">
                      <p className="text-slate-800">
                        <CustomerLink id={event.customer_id} className="font-bold">{event.who ?? `Un ${client}`}</CustomerLink> {activityText(event)}
                        {event.code && <> · <span className="font-mono text-[#022340]">{event.code}</span></>}
                      </p>
                      <span className="text-[10px] text-slate-400">
                        {[formatDateTime(event.at), event.service, event.by_customer ? 'desde el portal' : event.by].filter(Boolean).join(' • ')}
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <div className="pt-3 border-t border-slate-100 text-[11px] text-slate-400 text-right mt-4">
              Se actualiza sola cada minuto
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
