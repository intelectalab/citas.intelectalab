'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/hooks/useAuth';
import PlanSelection from '@/features/subscriptions/PlanSelection';
import {
  Plus,
  Users,
  Building2,
  FileText,
  ShoppingCart,
  Package,
  Ticket,
  Clock,
  ArrowRight,
  ChevronRight,
  Zap,
  Layers,
  ArrowUp,
  AlertCircle,
  Calendar as CalendarIcon,
  CheckCircle2,
  ListTodo,
  Rss,
  CalendarDays,
  ExternalLink,
  Check,
  Loader2,
  AlertTriangle
} from 'lucide-react';

export default function HomePage() {
  const { user, loading } = useAuth();
  const [taskFilter, setTaskFilter] = useState<'pending' | 'completed'>('pending');

  if (loading) {
    return (
      <div className="min-h-[60vh] flex flex-col items-center justify-center gap-3">
        <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
        <p className="text-sm font-medium text-slate-500">Cargando información del sistema...</p>
      </div>
    );
  }

  const currentUser = user as any;
  const hasActiveSubscription =
    currentUser?.tenant?.subscription?.status === 'active' ||
    Boolean(currentUser?.has_active_subscription) ||
    Boolean(currentUser?.plan_slug) ||
    Boolean(currentUser?.is_saas_admin) ||
    Boolean(currentUser?.is_saas_support);

  // Vista de selección de plan si la cuenta está inactiva
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
  const capitalizedDate = formattedDate.charAt(0).toUpperCase() + formattedDate.slice(1);

  return (
    <div className="max-w-7xl mx-auto space-y-8 pb-10 font-sans text-[#1F2937]">
      <div className="flex flex-col md:flex-row md:items-end justify-between gap-4 pt-2 pb-2 border-b border-slate-200/60">
        <div className="space-y-1">
          <div className="flex items-center gap-2 text-xs font-semibold text-[#3F768C] uppercase tracking-wider">
            <CalendarIcon className="w-3.5 h-3.5" />
            <span>{formattedDate}</span>
          </div>
          <h1 className="text-3xl md:text-4xl font-black text-[#022340] tracking-tight">
            Buenas noches, {currentUser?.first_name?.trim() ? `${currentUser.first_name} ${currentUser.last_name}` : 'Usuario'}
          </h1>
          <p className="text-slate-500 text-sm font-medium">
            Resumen operativo general e indicadores clave de tu sistema ERP.
          </p>
          {currentUser?.tenant?.code && (
            <p className="text-xs text-slate-500 font-medium">
              Código de empresa para el registro de tus {currentUser.tenant.vocabulary?.clients?.toLowerCase() ?? 'clientes'}:{' '}
              <span className="font-mono font-bold text-[#022340] tracking-widest">{currentUser.tenant.code}</span>
            </p>
          )}
        </div>

        <div className="flex items-center gap-3">
          <Link
            href="/orders/new"
            className="bg-[#022340] hover:bg-[#012840] text-white font-bold px-5 py-2.5 rounded-tl-xl rounded-br-xl rounded-tr-sm rounded-bl-sm flex items-center gap-2 text-sm shadow-sm transition-all hover:shadow-md active:scale-95"
          >
            <Plus className="w-4 h-4 text-[#84D0D9] stroke-[2.5]" />
            Nuevo pedido
          </Link>

          <Link
            href="/orders"
            className="bg-white hover:bg-slate-50 text-[#022340] font-semibold px-4 py-2.5 rounded-tl-xl rounded-br-xl rounded-tr-sm rounded-bl-sm text-sm transition-all border border-slate-200 flex items-center gap-2 shadow-2xs"
          >
            Ver pedidos
            <ArrowRight className="w-4 h-4 text-[#3F768C]" />
          </Link>
        </div>
      </div>

      <div className="bg-white rounded-tr-[2rem] rounded-bl-[2rem] rounded-tl-md rounded-br-md border border-slate-200/80 shadow-xs p-6">
        <div className="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
          <div className="flex items-center gap-2">
            <div className="p-1.5 bg-[#84D0D9]/20 text-[#022340] rounded-lg">
              <CalendarDays className="w-4 h-4" />
            </div>
            <h2 className="text-xs font-bold text-[#022340] uppercase tracking-wider">
              Reuniones de hoy
            </h2>
          </div>
          <span className="text-[11px] text-slate-400 font-medium">Sincronizado con Google Calendar / Outlook</span>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-center">
          <div className="lg:col-span-7 space-y-3">
            <div className="flex items-center gap-3 p-3 bg-slate-50/80 rounded-xl border border-slate-100">
              <div className="text-xs font-bold text-slate-400 w-12 text-right">09:00</div>
              <div className="w-1 h-8 bg-[#3F768C] rounded-full" />
              <div className="flex-1">
                <p className="text-xs font-bold text-[#022340]">Reunión de la empresa del segundo trimestre</p>
                <p className="text-[11px] text-slate-500">Revisión trimestral de negocios • Día 1</p>
              </div>
            </div>

            <div className="flex items-center gap-3 p-3 bg-slate-50/80 rounded-xl border border-slate-100">
              <div className="text-xs font-bold text-slate-400 w-12 text-right">10:00</div>
              <div className="w-1 h-8 bg-[#84D0D9] rounded-full" />
              <div className="flex-1">
                <p className="text-xs font-bold text-[#022340]">Demostración de producto de CoreERP</p>
                <p className="text-[11px] text-slate-500">Demo con cliente Tech Solutions S.A.C.</p>
              </div>
            </div>
          </div>

          <div className="lg:col-span-5 bg-slate-50 p-5 rounded-2xl border border-slate-200/60 flex flex-col justify-between space-y-4">
            <div>
              <h3 className="text-sm font-bold text-[#022340]">Prepárate para tus reuniones más importantes</h3>
              <p className="text-xs text-slate-500 mt-1">
                Conecta tu calendario para destacar las reuniones que te ayudan a cerrar negocios.
              </p>
            </div>
            <button
              type="button"
              className="self-start inline-flex items-center gap-2 px-4 py-2 bg-[#022340] hover:bg-[#012840] text-white text-xs font-bold rounded-xl transition-all shadow-xs cursor-pointer"
            >
              <CalendarIcon className="w-3.5 h-3.5 text-[#84D0D9]" />
              Conecta tu calendario
            </button>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        <div className="lg:col-span-4 bg-gradient-to-br from-[#022340] to-[#012840] text-white rounded-tl-[2rem] rounded-br-[2rem] rounded-tr-md rounded-bl-md p-7 flex flex-col justify-between shadow-lg relative overflow-hidden">
          <div className="absolute right-0 top-0 w-32 h-32 bg-[#84D0D9]/10 rounded-full blur-2xl pointer-events-none" />

          <div className="relative z-10">
            <div className="flex items-center justify-between border-b border-white/10 pb-4">
              <span className="text-xs font-bold text-[#84D0D9] uppercase tracking-wider flex items-center gap-2">
                <ShoppingCart className="w-4 h-4" /> Pedidos Hoy
              </span>
              <span className="px-2.5 py-0.5 rounded-full bg-[#84D0D9]/20 text-[#84D0D9] border border-[#84D0D9]/30 text-[11px] font-bold flex items-center gap-1">
                <ArrowUp className="w-3 h-3" /> +12%
              </span>
            </div>

            <div className="mt-6 space-y-1">
              <div className="text-5xl font-black tracking-tight text-white">18</div>
              <p className="text-xs text-slate-300 font-medium">Órdenes procesadas en las últimas 24h</p>
            </div>
          </div>

          <div className="relative z-10 mt-8 pt-4 border-t border-white/10 flex items-center justify-between text-xs text-slate-300">
            <span>Meta diaria: 20</span>
            <span className="font-bold text-[#84D0D9]">90% alcanzado</span>
          </div>
        </div>

        <div className="lg:col-span-8 bg-white rounded-tr-[2rem] rounded-bl-[2rem] rounded-tl-md rounded-bl-md border border-slate-200/80 shadow-xs p-6 flex flex-col justify-between">
          <div className="flex items-center justify-between pb-4 border-b border-slate-100">
            <h2 className="text-xs font-bold text-[#022340] uppercase tracking-wider flex items-center gap-2">
              <Layers className="w-4 h-4 text-[#3F768C]" /> Resumen de Métricas Operativas
            </h2>
            <span className="text-xs text-slate-400 font-medium">Actualizado en tiempo real</span>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-6 my-4 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
            <div className="pt-4 sm:pt-0 sm:pr-4 space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-slate-600">Cotizaciones</span>
                <div className="p-2 bg-[#84D0D9]/15 text-[#022340] rounded-xl border border-[#84D0D9]/30">
                  <FileText className="w-4 h-4" />
                </div>
              </div>
              <div>
                <span className="text-3xl font-extrabold text-[#022340] tracking-tight">7</span>
                <div className="mt-2 inline-flex items-center gap-1.5 text-[#022340] bg-slate-100 px-2.5 py-1 rounded-lg text-[11px] font-semibold">
                  <Clock className="w-3 h-3 text-[#3F768C]" /> 3 por vencer
                </div>
              </div>
            </div>

            <div className="pt-4 sm:pt-0 sm:px-4 space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-slate-600">Nuevos Clientes</span>
                <div className="p-2 bg-[#84D0D9]/15 text-[#022340] rounded-xl border border-[#84D0D9]/30">
                  <Users className="w-4 h-4" />
                </div>
              </div>
              <div>
                <span className="text-3xl font-extrabold text-[#022340] tracking-tight">24</span>
                <div className="mt-2 inline-flex items-center gap-1.5 text-slate-700 bg-slate-100 px-2.5 py-1 rounded-lg text-[11px] font-semibold">
                  <Users className="w-3 h-3 text-slate-500" /> +5 este mes
                </div>
              </div>
            </div>

            <div className="pt-4 sm:pt-0 sm:pl-4 space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-slate-600">Tickets Soporte</span>
                <div className="p-2 bg-slate-100 text-slate-600 rounded-xl">
                  <Ticket className="w-4 h-4" />
                </div>
              </div>
              <div>
                <span className="text-3xl font-extrabold text-[#022340] tracking-tight">4</span>
                <div className="mt-2 inline-flex items-center gap-1.5 text-slate-700 bg-slate-100 px-2.5 py-1 rounded-lg text-[11px] font-semibold">
                  <AlertCircle className="w-3 h-3 text-amber-500" /> 1 pendiente
                </div>
              </div>
            </div>
          </div>

          <div className="pt-3 border-t border-slate-100 text-[11px] text-slate-400 flex justify-end">
            <span>Sincronización activa con módulos ERP</span>
          </div>
        </div>
      </div>

      <div className="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-xs space-y-4">
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
          {[
            { label: `Nuevo ${currentUser?.tenant?.vocabulary?.client ?? 'Cliente'}`, icon: Users, href: '/customers' },
            { label: 'Registrar Empresa', icon: Building2, href: '/companies/new' },
            { label: 'Añadir Producto', icon: Package, href: '/products/new' },
            { label: 'Crear Ticket', icon: Ticket, href: '/tickets/new' },
          ].map((item, idx) => {
            const Icon = item.icon;
            return (
              <Link
                key={idx}
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

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-tr-[2rem] rounded-bl-[2rem] rounded-tl-md rounded-br-md border border-slate-200/80 shadow-xs p-6 flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between pb-4 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <ListTodo className="w-4 h-4 text-[#3F768C]" />
                <h3 className="font-bold text-[#022340] text-sm">Tareas Pendientes</h3>
              </div>

              <div className="flex items-center bg-slate-100 p-0.5 rounded-lg border border-slate-200/60 text-xs font-semibold">
                <button
                  type="button"
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
                  onClick={() => setTaskFilter('completed')}
                  className={`px-3 py-1 rounded-md transition-all cursor-pointer ${
                    taskFilter === 'completed'
                      ? 'bg-white text-[#022340] shadow-2xs'
                      : 'text-slate-500 hover:text-slate-700'
                  }`}
                >
                  Completado
                </button>
              </div>
            </div>

            <div className="mt-4">
              {taskFilter === 'pending' ? (
                <div className="space-y-2.5">
                  <div className="p-3 bg-slate-50 rounded-xl border border-slate-200/60 flex items-center justify-between hover:border-[#84D0D9]/60 transition-colors">
                    <div className="flex items-center gap-3">
                      <div className="w-5 h-5 rounded-full border-2 border-slate-300 flex items-center justify-center cursor-pointer hover:border-[#3F768C] transition-colors">
                        <Check className="w-3 h-3 text-transparent hover:text-slate-300" />
                      </div>
                      <div>
                        <p className="text-xs font-bold text-slate-800">Llamar a Geraldine (Tech Solutions)</p>
                        <p className="text-[11px] text-slate-400">Seguimiento sobre cotización COT-2026-012</p>
                      </div>
                    </div>
                    <span className="text-[10px] font-semibold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-md border border-amber-200">
                      Hoy 17:00
                    </span>
                  </div>

                  <div className="p-3 bg-slate-50 rounded-xl border border-slate-200/60 flex items-center justify-between hover:border-[#84D0D9]/60 transition-colors">
                    <div className="flex items-center gap-3">
                      <div className="w-5 h-5 rounded-full border-2 border-slate-300 flex items-center justify-center cursor-pointer hover:border-[#3F768C] transition-colors">
                        <Check className="w-3 h-3 text-transparent hover:text-slate-300" />
                      </div>
                      <div>
                        <p className="text-xs font-bold text-slate-800">Enviar contrato firmado a Comercial Johnson</p>
                        <p className="text-[11px] text-slate-400">Módulo de ventas</p>
                      </div>
                    </div>
                    <span className="text-[10px] font-semibold text-slate-500 bg-slate-200/60 px-2 py-0.5 rounded-md">
                      Mañana
                    </span>
                  </div>
                </div>
              ) : (
                <div className="p-6 text-center text-xs text-slate-400">
                  <CheckCircle2 className="w-8 h-8 text-emerald-500/40 mx-auto mb-2" />
                  No hay tareas completadas recientemente.
                </div>
              )}
            </div>
          </div>

          <div className="pt-4 mt-4 border-t border-slate-100 flex items-center justify-between">
            <button
              type="button"
              className="inline-flex items-center gap-1.5 text-xs font-bold text-[#022340] hover:text-[#3F768C] transition-colors cursor-pointer"
            >
              <Plus className="w-3.5 h-3.5 text-[#3F768C]" /> Nueva tarea
            </button>
            <span className="text-[11px] text-slate-400">2 pendientes</span>
          </div>
        </div>

        <div className="bg-white rounded-tr-[2rem] rounded-bl-[2rem] rounded-tl-md rounded-br-md border border-slate-200/80 shadow-xs p-6 flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between pb-4 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <Rss className="w-4 h-4 text-[#3F768C]" />
                <h3 className="font-bold text-[#022340] text-sm">Feed de Actividades</h3>
              </div>
              <Link href="/activities" className="text-xs font-bold text-[#3F768C] hover:text-[#022340] flex items-center gap-1">
                Ver todo <ExternalLink className="w-3 h-3" />
              </Link>
            </div>

            <p className="text-xs text-slate-500 mt-3 mb-4">
              Haz un seguimiento de la interacción de tus contactos. Verás cuándo abren correos o hacen clic en enlaces.
            </p>

            <div className="space-y-3">
              <div className="p-3 bg-slate-50 rounded-xl border border-slate-200/60 flex items-start gap-3">
                <div className="w-2 h-2 rounded-full bg-[#84D0D9] mt-1.5 shrink-0" />
                <div className="text-xs">
                  <p className="text-slate-800">
                    <span className="font-bold">HubSpot</span> abrió el correo comercial enviado.
                  </p>
                  <span className="text-[10px] text-slate-400">Hace 10 min • 10 de jul. de 2026 23:38</span>
                </div>
              </div>

              <div className="p-3 bg-slate-50 rounded-xl border border-slate-200/60 flex items-start gap-3">
                <div className="w-2 h-2 rounded-full bg-[#3F768C] mt-1.5 shrink-0" />
                <div className="text-xs">
                  <p className="text-slate-800">
                    <span className="font-bold">Geraldine Ignacio</span> creó la orden <span className="font-mono text-[#022340]">PED-2026-004</span>.
                  </p>
                  <span className="text-[10px] text-slate-400">Hace 2 horas • 7 de jul. de 2026 23:49</span>
                </div>
              </div>
            </div>
          </div>

          <div className="pt-3 border-t border-slate-100 text-[11px] text-slate-400 text-right mt-4">
            Actualización automática del CRM
          </div>
        </div>
      </div>
    </div>
  );
}