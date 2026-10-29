'use client';

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import {
  ArrowLeft, Building2, CalendarDays, Check, Clock, Contact, Cpu, HardDrive, Headphones, Infinity as InfinityIcon,
  Layers, Loader2, Minus, Pencil, Store, Ticket, Users,
} from 'lucide-react';
import { api } from '@/lib/axios';
import SectionTitle from '@/app/components/common/SectionTitle';
import { CORE_MODULE_LABELS, MODULE_GROUPS, MODULE_LABELS } from '@/lib/modules';
import { screensByModule } from '@/app/components/layout/menu';
import { makeVocabulary } from '@/hooks/useVocabulary';
import { money } from '@/features/sales/sales';
import { TENANT_STATUS } from '@/features/tenants/tenantStatus';
import FormSaaSPlan from './FormSaaSPlan';

interface PlanTenant {
  id: string;
  name: string;
  status: string;
  subscription_status: string;
  billing_cycle: string;
}

interface PlanDetailData {
  id: string;
  name: string;
  slug: string;
  code_internal?: string | null;
  description?: string | null;
  price_monthly: number;
  price_yearly: number;
  currency?: string | null;
  limits?: Record<string, number | undefined>;
  trial_config?: { has_trial?: boolean; trial_days?: number } | null;
  consumption?: { storage_gb?: number; api_requests_monthly?: number } | null;
  modules?: Record<string, unknown>;
  support?: { level?: string; tickets_monthly?: number } | null;
  status: string;
  presentation_order?: number | null;
  tenants: PlanTenant[];
}

const LIMITS: { key: string; aliases: string[]; label: string; icon: React.ReactNode; tint: string; iconColor: string }[] = [
  { key: 'users', aliases: ['usuarios'], label: 'Usuarios', icon: <Users size={18} />, tint: 'bg-sky-50/70 border-sky-100', iconColor: 'text-sky-600 bg-sky-100' },
  { key: 'professionals', aliases: [], label: 'Profesionales', icon: <Contact size={18} />, tint: 'bg-violet-50/70 border-violet-100', iconColor: 'text-violet-600 bg-violet-100' },
  { key: 'branches', aliases: ['sucursales', 'sedes'], label: 'Sedes', icon: <Store size={18} />, tint: 'bg-amber-50/70 border-amber-100', iconColor: 'text-amber-600 bg-amber-100' },
  { key: 'appointments_month', aliases: [], label: 'Citas al mes', icon: <CalendarDays size={18} />, tint: 'bg-emerald-50/70 border-emerald-100', iconColor: 'text-emerald-600 bg-emerald-100' },
];

const SUBSCRIPTION_LABELS: Record<string, string> = { active: 'Activa', trialing: 'En prueba' };

const ghostButton =
  'px-4 py-2 border border-slate-200 bg-white text-slate-700 text-xs font-bold rounded-xl hover:bg-slate-50 transition-colors disabled:opacity-50 cursor-pointer';

function CardTitle({ icon, title, aside }: { icon: React.ReactNode; title: string; aside?: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 mb-4">
      <SectionTitle>
        <span className="text-brand-accent">{icon}</span> {title}
      </SectionTitle>
      {aside}
    </div>
  );
}

const limitText = (value: number | undefined) =>
  value === undefined || value === null ? '—' : value === -1 ? null : value.toLocaleString('es-PE');

export default function PlanDetail() {
  const params = useParams<{ id: string }>();
  const [plan, setPlan] = useState<PlanDetailData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [editing, setEditing] = useState(false);
  const [notice, setNotice] = useState('');
  const screens = useMemo(() => screensByModule(makeVocabulary({})), []);

  const load = useCallback(() => {
    api.get<PlanDetailData>(`/saas-plans/${params.id}`)
      .then((res) => { setPlan(res.data); setError(''); })
      .catch((err) => setError(err.response?.status === 404 ? 'Este plan no existe.' : 'No se pudo cargar el plan.'))
      .finally(() => setLoading(false));
  }, [params.id]);

  useEffect(() => { load(); }, [load]);

  if (loading) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center">
        <Loader2 className="w-8 h-8 text-brand-accent animate-spin" />
      </div>
    );
  }

  if (!plan) {
    return (
      <div className="max-w-2xl mx-auto pt-10 text-center space-y-3">
        <p className="text-sm text-slate-500 font-medium">{error}</p>
        <Link href="/admin/plans" className="inline-block text-xs font-bold text-brand-accent hover:text-brand-ink">Volver a Planes SaaS</Link>
      </div>
    );
  }

  const currency = plan.currency || 'PEN';
  const active = plan.status === 'active';
  const included = new Set([
    ...Object.keys(CORE_MODULE_LABELS),
    ...Object.values(plan.modules ?? {}).flat().filter((key): key is string => typeof key === 'string'),
  ]);
  const extraCount = MODULE_GROUPS.flatMap((group) => group.items).filter((item) => included.has(item.key)).length;
  const limitOf = (key: string, aliases: string[]) => [key, ...aliases].map((k) => plan.limits?.[k]).find((v) => v !== undefined && v !== null);

  const save = async (data: unknown) => {
    await api.put(`/saas-plans/${plan.id}`, data);
    setEditing(false);
    setNotice('Listo: el plan quedó actualizado.');
    load();
  };

  const moduleRow = (key: string, label: string, hint?: string) => {
    const on = included.has(key);
    const list = screens[key] ?? [];
    return (
      <li key={key} className="py-2.5 flex items-start gap-3" data-plan-module={key} data-included={on ? 'true' : 'false'}>
        <span className={`mt-0.5 w-5 h-5 rounded-full flex items-center justify-center shrink-0 ${on ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-400'}`}>
          {on ? <Check size={12} strokeWidth={3} /> : <Minus size={12} strokeWidth={3} />}
        </span>
        <span className="min-w-0 space-y-1">
          <span className={`block text-sm ${on ? 'font-semibold text-slate-800' : 'text-slate-400'}`} title={hint}>
            {label}{!on && <span className="ml-1.5 text-[11px] font-medium">· no incluido</span>}
          </span>
          {list.length > 0 && (
            <span className="flex flex-wrap gap-1" data-module-screens>
              {list.map((screen) => (
                <span key={screen.name} className={`px-2 py-0.5 text-[11px] rounded-md border ${on ? 'bg-white border-slate-200 text-slate-600' : 'bg-slate-50 border-slate-100 text-slate-400'}`}>
                  {screen.name}{screen.needs ? ` (con ${MODULE_LABELS[screen.needs] ?? screen.needs})` : ''}
                </span>
              ))}
            </span>
          )}
        </span>
      </li>
    );
  };

  return (
    <div className="space-y-5 pb-10 font-sans text-[#1F2937]">
      <nav className="flex items-center gap-2 text-sm">
        <Link
          href="/admin/plans"
          className="inline-flex items-center gap-2 pl-2.5 pr-3.5 py-2 rounded-xl bg-white border border-slate-200 font-bold text-brand-ink hover:bg-slate-50 hover:border-slate-300 shadow-xs transition-colors"
        >
          <ArrowLeft size={16} /> Planes SaaS
        </Link>
        <span className="text-slate-300">/</span>
        <span className="font-semibold text-slate-500 truncate">{plan.name}</span>
      </nav>

      {(notice || error) && (
        <div className={`p-3.5 rounded-2xl border text-sm font-medium ${error ? 'bg-red-50 border-red-200 text-red-600' : 'bg-emerald-50 border-emerald-200 text-emerald-700'}`}>
          {error || notice}
        </div>
      )}

      <div className="grid grid-cols-1 xl:grid-cols-12 gap-5">
        <section className="xl:col-span-8 bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden flex flex-col" data-plan-header>
          <div className="h-2 bg-brand" />
          <div className="p-6 flex flex-wrap items-start justify-between gap-4 flex-1">
            <div className="flex items-start gap-4 min-w-0">
              <span className="p-4 rounded-2xl shrink-0 bg-brand-soft/20 text-brand-ink">
                <Ticket className="w-8 h-8" />
              </span>
              <div className="min-w-0 space-y-1.5">
                <div className="flex flex-wrap items-center gap-2">
                  <h1 className="text-2xl font-black text-brand-ink tracking-tight">{plan.name}</h1>
                  <span className={`px-2.5 py-0.5 text-xs rounded-full font-semibold ${active ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'}`}>
                    {active ? 'Activo' : 'Inactivo'}
                  </span>
                  {plan.code_internal && <span className="px-2 py-0.5 text-[11px] font-mono font-semibold bg-slate-100 text-slate-600 rounded">{plan.code_internal}</span>}
                </div>
                <p className="text-xs text-slate-400 font-mono">slug: {plan.slug}</p>
                {plan.description && <p className="text-sm text-slate-600 max-w-2xl">{plan.description}</p>}
              </div>
            </div>
            <button type="button" data-action="edit-plan" onClick={() => setEditing(true)} className={`${ghostButton} flex items-center gap-1.5`}>
              <Pencil size={14} /> Editar plan
            </button>
          </div>

          <div className="grid grid-cols-2 md:grid-cols-4 divide-x divide-slate-100 border-t border-slate-100 bg-slate-50/60">
            {[
              ['Precio mensual', money(Number(plan.price_monthly), currency)],
              ['Precio anual', money(Number(plan.price_yearly), currency)],
              ['Prueba gratis', plan.trial_config?.has_trial ? `${plan.trial_config.trial_days ?? 0} días` : 'No tiene'],
              ['Orden en la portada', plan.presentation_order ? `#${plan.presentation_order}` : '—'],
            ].map(([label, value]) => (
              <div key={label} className="px-5 py-3.5">
                <span className="block text-[11px] font-bold uppercase tracking-wider text-slate-400">{label}</span>
                <span className="block text-base font-black text-brand-ink">{value}</span>
              </div>
            ))}
          </div>
        </section>

        <section className="xl:col-span-4 bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6" data-plan-tenants>
          <CardTitle icon={<Building2 size={16} />} title="Empresas con este plan" aside={<span className="text-2xl font-black text-brand-ink">{plan.tenants.length}</span>} />
          {plan.tenants.length === 0 ? (
            <p className="text-sm text-slate-500">Ninguna empresa usa este plan todavía.</p>
          ) : (
            <ul className="divide-y divide-slate-100 max-h-72 overflow-y-auto -mx-2 px-2">
              {plan.tenants.map((tenant) => {
                const status = TENANT_STATUS[tenant.status] ?? TENANT_STATUS.inactive;
                return (
                  <li key={tenant.id} className="py-2 flex items-center justify-between gap-3">
                    <Link href={`/admin/tenants/${tenant.id}`} className="text-sm font-semibold text-slate-800 hover:text-brand-accent hover:underline truncate">{tenant.name}</Link>
                    <span className="flex items-center gap-1.5 shrink-0">
                      {tenant.subscription_status === 'trialing' && <span className="px-2 py-0.5 text-[11px] rounded-full font-semibold bg-sky-50 text-sky-700">{SUBSCRIPTION_LABELS.trialing}</span>}
                      <span className={`px-2 py-0.5 text-[11px] rounded-full font-semibold ${status.className}`}>{status.label}</span>
                    </span>
                  </li>
                );
              })}
            </ul>
          )}
        </section>

        <section className="xl:col-span-12 grid grid-cols-2 lg:grid-cols-4 gap-4" data-plan-limits>
          {LIMITS.map((limit) => {
            const value = limitOf(limit.key, limit.aliases);
            const text = limitText(value);
            return (
              <div key={limit.key} className={`rounded-3xl border p-5 space-y-2 ${limit.tint}`}>
                <span className={`inline-flex p-2 rounded-xl ${limit.iconColor}`}>{limit.icon}</span>
                <span className="block text-[11px] font-bold uppercase tracking-wider text-slate-500">{limit.label}</span>
                <span className="flex items-center gap-1.5 text-2xl font-black text-brand-ink leading-tight">
                  {text === null ? <><InfinityIcon size={22} /> <span className="text-sm font-semibold text-slate-500">Sin límite</span></> : text}
                </span>
              </div>
            );
          })}
        </section>

        <section className="xl:col-span-8 bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6" data-plan-modules>
          <CardTitle icon={<Layers size={16} />} title="Módulos y pantallas" aside={<span className="text-xs font-semibold text-slate-500">{extraCount} módulos además de los básicos</span>} />
          <p className="text-xs text-slate-500 -mt-2 mb-4">Debajo de cada módulo están las pantallas que abre en el menú de la empresa. El rubro puede apagar alguno, por ejemplo Inventario en un consultorio.</p>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-x-8 gap-y-4">
            <div>
              <span className="block text-xs font-bold text-brand-ink mb-1">Básicos (todos los planes)</span>
              <ul className="divide-y divide-slate-100">
                {Object.entries(CORE_MODULE_LABELS).map(([key, label]) => moduleRow(key, label))}
              </ul>
            </div>
            {MODULE_GROUPS.map((group) => (
              <div key={group.key} data-plan-group={group.key}>
                <span className="block text-xs font-bold text-brand-ink mb-1">{group.title}</span>
                <ul className="divide-y divide-slate-100">
                  {group.items.map((item) => moduleRow(item.key, item.label, item.hint))}
                </ul>
              </div>
            ))}
          </div>
        </section>

        <div className="xl:col-span-4 space-y-5">
          <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6" data-plan-consumption>
            <CardTitle icon={<HardDrive size={16} />} title="Consumo" />
            <dl className="space-y-3 text-sm">
              <div className="flex items-center justify-between gap-3">
                <dt className="flex items-center gap-2 text-slate-500"><HardDrive size={15} className="text-slate-400" /> Almacenamiento</dt>
                <dd className="font-bold text-slate-800">{plan.consumption?.storage_gb ?? 0} GB</dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="flex items-center gap-2 text-slate-500"><Cpu size={15} className="text-slate-400" /> Peticiones a la API al mes</dt>
                <dd className="font-bold text-slate-800">{(plan.consumption?.api_requests_monthly ?? 0).toLocaleString('es-PE')}</dd>
              </div>
            </dl>
          </section>

          <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6" data-plan-support>
            <CardTitle icon={<Headphones size={16} />} title="Soporte" />
            <dl className="space-y-3 text-sm">
              <div className="flex items-center justify-between gap-3">
                <dt className="text-slate-500">Nivel de atención</dt>
                <dd className="font-bold text-slate-800">{plan.support?.level || 'Estándar'}</dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="text-slate-500">Consultas al mes</dt>
                <dd className="font-bold text-slate-800">{plan.support?.tickets_monthly === -1 ? 'Sin límite' : (plan.support?.tickets_monthly ?? 0)}</dd>
              </div>
            </dl>
          </section>

          {plan.trial_config?.has_trial && (
            <p className="flex items-center gap-2 text-xs text-slate-500 px-1"><Clock size={14} className="text-slate-400" /> Las empresas nuevas lo prueban gratis {plan.trial_config.trial_days ?? 0} días.</p>
          )}
        </div>
      </div>

      <FormSaaSPlan
        isOpen={editing}
        onClose={() => setEditing(false)}
        onSubmit={async (data) => {
          try {
            await save(data);
          } catch (err) {
            const errors = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
            setError(errors?.slug ? 'Ya existe un plan con ese identificador (slug). Cambia el nombre del plan.' : 'No se pudo guardar el plan.');
            setEditing(false);
          }
        }}
        initialData={plan as never}
      />
    </div>
  );
}
