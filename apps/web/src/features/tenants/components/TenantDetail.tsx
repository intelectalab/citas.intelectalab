'use client';

import React, { useEffect, useState } from 'react';
import Select from '@/app/components/common/Select';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import {
  ArrowLeft, Ban, Building2, Calendar, CalendarCheck, Clock, CreditCard, Hash, Layers, Loader2, LogIn,
  Mail, MapPin, MessageCircle, Puzzle, Receipt, RefreshCw, RotateCcw, Settings2, ShieldAlert, Sparkles,
  Stethoscope, Store, Type, UserRound, Users
} from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { usePlatform } from '@/hooks/usePlatform';
import Switch from '@/app/components/common/Switch';
import { CORE_MODULE_LABELS, MODULE_GROUPS, MODULE_LABELS } from '@/lib/modules';
import { SUBSCRIPTION_STATUS, TENANT_STATUS } from '../tenantStatus';

interface TenantModule {
  key: string;
  name: string;
  is_core: boolean;
  in_plan: boolean;
  enabled: boolean;
  // Por qué la empresa no lo tiene: sin suscripción, plan, rubro, plataforma o la propia empresa.
  off_reason: 'no_subscription' | 'plan' | 'business_type' | 'platform' | 'tenant' | null;
  platform_off: boolean;
}

interface TenantDetailData {
  id: string;
  name: string;
  slug: string;
  code: string;
  timezone: string;
  status: string;
  suspension_reason: string | null;
  created_at: string;
  business_type: { id: string; slug: string; name: string; short_name: string; color: string | null } | null;
  business_profile_outdated?: string[];
  company: Record<string, string | null> | null;
  subscription: {
    id: string;
    status: string;
    is_current: boolean;
    billing_cycle: string;
    starts_at: string | null;
    ends_at: string | null;
    plan: { id: string; name: string; price_monthly: number; price_yearly: number; currency: string; is_public_catalog: boolean } | null;
  } | null;
  invoices: Array<{
    id: string; amount: number; currency: string; status: string | null; status_slug: string | null;
    invoice_date: string | null; period_start: string | null; period_end: string | null; reference: string | null;
  }>;
  modules: TenantModule[];
  usage: Array<{ key: string; used: number; limit: number | null }>;
  users: Array<{ id: string; first_name: string; last_name: string; email: string; status: string; is_owner: boolean; role: string | null }>;
  customer_accounts: number;
  can_impersonate: boolean;
  settings?: {
    vocabulary: Record<string, string>;
    vocabulary_overrides: Record<string, string>;
    modules_off: string[];
    rules_overrides: Record<string, string | number | boolean>;
    dependents: { enabled: boolean; kind: string | null; required: boolean };
    allow_customer_signup: boolean;
    brand_color: string | null;
  };
}

const OFF_REASONS: Record<string, string> = {
  no_subscription: 'Sin suscripción vigente',
  plan: 'Fuera de su plan',
  business_type: 'Lo apaga su rubro',
  platform: 'Apagado por la plataforma',
  tenant: 'Apagado por la empresa',
};

// Cada medida de uso con su nombre, ícono y color propio para la tarjeta.
const USAGE: Record<string, { label: string; icon: React.ReactNode; tint: string; iconColor: string }> = {
  users: { label: 'Usuarios', icon: <UserRound size={18} />, tint: 'bg-sky-50 border-sky-100', iconColor: 'text-sky-600 bg-sky-100' },
  branches: { label: 'Sedes', icon: <Store size={18} />, tint: 'bg-violet-50 border-violet-100', iconColor: 'text-violet-600 bg-violet-100' },
  professionals: { label: 'Profesionales', icon: <Stethoscope size={18} />, tint: 'bg-teal-50 border-teal-100', iconColor: 'text-teal-600 bg-teal-100' },
  appointments_month: { label: 'Citas este mes', icon: <CalendarCheck size={18} />, tint: 'bg-amber-50 border-amber-100', iconColor: 'text-amber-600 bg-amber-100' },
  whatsapp_month: { label: 'WhatsApp este mes', icon: <MessageCircle size={18} />, tint: 'bg-emerald-50 border-emerald-100', iconColor: 'text-emerald-600 bg-emerald-100' },
};

const formatDate = (value?: string | null) => (value ? new Date(value).toLocaleDateString() : 'Sin fecha');

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6';
const labelClass = 'block text-[11px] uppercase tracking-wider text-slate-400 font-semibold mb-1';
const inputClass =
  'w-full text-sm border border-slate-200 rounded-xl px-3 py-2 bg-white text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340]';
const primaryButton =
  'px-4 py-2 bg-[#022340] text-white text-xs font-bold rounded-xl hover:bg-[#03315a] transition-colors shadow-sm disabled:opacity-50 cursor-pointer';
const ghostButton =
  'px-4 py-2 border border-slate-200 bg-white text-slate-700 text-xs font-bold rounded-xl hover:bg-slate-50 transition-colors disabled:opacity-50 cursor-pointer';

function CardTitle({ icon, title, aside }: { icon: React.ReactNode; title: string; aside?: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 mb-4">
      <h2 className="flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider">
        <span className="text-[#3F768C]">{icon}</span> {title}
      </h2>
      {aside}
    </div>
  );
}

// Uso frente al límite del plan: anillo que se llena y cambia de color al acercarse.
function UsageTile({ item }: { item: { key: string; used: number; limit: number | null } }) {
  const { platform } = usePlatform();
  const warnAt = platform.usage_warning_percent / 100;
  const dangerAt = platform.usage_danger_percent / 100;
  const meta = USAGE[item.key] ?? { label: item.key, icon: <Sparkles size={18} />, tint: 'bg-slate-50 border-slate-100', iconColor: 'text-slate-600 bg-slate-100' };
  const hasLimit = item.limit !== null && item.limit > 0;
  const ratio = hasLimit ? item.used / (item.limit as number) : 0;
  const percent = Math.round(ratio * 100);
  const level = !hasLimit ? 'none' : ratio > 1 ? 'over' : ratio >= dangerAt ? 'high' : ratio >= warnAt ? 'mid' : 'ok';
  const ring = { none: '#CBD5E1', ok: 'var(--accent)', mid: '#F59E0B', high: '#F43F5E', over: '#E11D48' }[level];
  const note = {
    none: 'Sin límite en su plan',
    ok: `Le quedan ${((item.limit ?? 0) - item.used).toLocaleString('es-PE')}`,
    mid: `Le quedan ${((item.limit ?? 0) - item.used).toLocaleString('es-PE')}`,
    high: item.used === item.limit ? 'Llegó al límite' : `Solo le quedan ${((item.limit ?? 0) - item.used).toLocaleString('es-PE')}`,
    over: `Pasó el límite por ${(item.used - (item.limit ?? 0)).toLocaleString('es-PE')}`,
  }[level];

  const radius = 26;
  const circumference = 2 * Math.PI * radius;

  return (
    <div className={`rounded-3xl border p-5 flex items-center justify-between gap-3 ${meta.tint}`}>
      <div className="min-w-0 space-y-2">
        <span className={`inline-flex p-2 rounded-xl ${meta.iconColor}`}>{meta.icon}</span>
        <div>
          <span className="block text-[11px] font-bold uppercase tracking-wider text-slate-500">{meta.label}</span>
          <span className="block text-2xl font-black text-[#022340] leading-tight">
            {item.used.toLocaleString('es-PE')}
            <span className="text-sm font-semibold text-slate-400"> / {hasLimit ? (item.limit as number).toLocaleString('es-PE') : '∞'}</span>
          </span>
          <span className={`block text-[11px] font-semibold ${level === 'over' || level === 'high' ? 'text-rose-600' : level === 'mid' ? 'text-amber-700' : 'text-slate-500'}`}>{note}</span>
        </div>
      </div>
      <svg width="68" height="68" viewBox="0 0 68 68" className="shrink-0" role="img" aria-label={hasLimit ? `${percent}% del límite` : 'Sin límite'}>
        <circle cx="34" cy="34" r={radius} fill="none" stroke="var(--ring-track)" strokeWidth="7" />
        <circle
          cx="34" cy="34" r={radius} fill="none" stroke={ring} strokeWidth="7" strokeLinecap="round"
          strokeDasharray={circumference}
          strokeDashoffset={circumference * (1 - Math.min(1, ratio))}
          transform="rotate(-90 34 34)"
        />
        <text x="34" y="38" textAnchor="middle" className="fill-[#022340] text-[13px] font-extrabold">{hasLimit ? `${percent}%` : '—'}</text>
      </svg>
    </div>
  );
}

// Ficha completa de una empresa para la consola de la plataforma. El soporte
// SaaS la ve y entra al panel; los cambios son del administrador SaaS.
export default function TenantDetail() {
  const params = useParams<{ id: string }>();
  const { user, startImpersonation } = useAuth();
  const canManage = Boolean(user?.is_saas_admin);

  const [detail, setDetail] = useState<TenantDetailData | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ type: 'ok' | 'error'; text: string } | null>(null);

  const [plans, setPlans] = useState<any[]>([]);
  const [businessTypes, setBusinessTypes] = useState<any[]>([]);

  const [suspendReason, setSuspendReason] = useState('');
  const [businessTypeId, setBusinessTypeId] = useState('');
  const [installTemplate, setInstallTemplate] = useState(false);
  const [planId, setPlanId] = useState('');
  const [payment, setPayment] = useState({ billing_cycle: 'monthly', amount: '', reference: '' });
  const [extendDate, setExtendDate] = useState('');
  const [platformOff, setPlatformOff] = useState<string[]>([]);

  const apply = (data: TenantDetailData) => {
    setDetail(data);
    setBusinessTypeId(data.business_type?.id ?? '');
    setPlanId(data.subscription?.plan?.id ?? '');
    setPayment({ billing_cycle: data.subscription?.billing_cycle ?? 'monthly', amount: '', reference: '' });
    setPlatformOff(data.modules.filter((m) => m.platform_off).map((m) => m.key));
  };

  useEffect(() => {
    api.get(`/tenants/${params.id}`)
      .then((res) => apply(res.data))
      .catch((err) => setNotice({ type: 'error', text: err.response?.status === 404 ? 'Esta empresa no existe.' : 'No se pudo cargar la empresa.' }))
      .finally(() => setLoading(false));
  }, [params.id]);

  useEffect(() => {
    if (!canManage) return;
    api.get('/saas-plans').then((res) => setPlans(res.data?.data ?? res.data ?? [])).catch(() => setPlans([]));
    api.get('/admin/business-types').then((res) => setBusinessTypes(res.data ?? [])).catch(() => setBusinessTypes([]));
  }, [canManage]);

  // Ejecuta una acción de la plataforma sobre la empresa y refresca la ficha.
  const run = async (request: () => Promise<any>, okText: string) => {
    try {
      setBusy(true);
      setNotice(null);
      const res = await request();
      apply(res.data);
      setNotice({ type: 'ok', text: okText });
    } catch (err: any) {
      const errors = err.response?.data?.errors;
      const first = errors ? (Object.values(errors)[0] as string[])[0] : null;
      setNotice({ type: 'error', text: first || err.response?.data?.message || 'No se pudo completar la acción.' });
    } finally {
      setBusy(false);
    }
  };

  const handleImpersonate = async () => {
    if (!detail) return;
    try {
      setBusy(true);
      setNotice(null);
      await startImpersonation(detail.id);
    } catch (err: any) {
      setNotice({ type: 'error', text: err.response?.data?.message || 'No se pudo entrar al panel de la empresa.' });
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center">
        <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
      </div>
    );
  }

  if (!detail) {
    return (
      <div className="max-w-2xl mx-auto pt-10 text-center space-y-3">
        <p className="text-sm text-slate-500 font-medium">{notice?.text}</p>
        <Link href="/admin/tenants" className="inline-block text-xs font-bold text-[#3F768C] hover:text-[#022340]">Volver a Tenants</Link>
      </div>
    );
  }

  const status = TENANT_STATUS[detail.status] ?? TENANT_STATUS.inactive;
  const color = detail.settings?.brand_color || detail.business_type?.color || '#3F768C';
  const subscription = detail.subscription;
  const selectedPlan = plans.find((plan) => plan.id === planId);
  const defaultAmount = subscription?.plan
    ? (payment.billing_cycle === 'yearly' ? subscription.plan.price_yearly : subscription.plan.price_monthly)
    : 0;
  const daysLeft = subscription?.ends_at ? Math.ceil((new Date(subscription.ends_at).getTime() - Date.now()) / 86400000) : null;
  const savedPlatformOff = detail.modules.filter((m) => m.platform_off).map((m) => m.key);
  const modulesDirty = [...platformOff].sort().join() !== [...savedPlatformOff].sort().join();
  const enabledCount = detail.modules.filter((m) => m.enabled).length;
  const moduleByKey = Object.fromEntries(detail.modules.map((m) => [m.key, m]));
  const settings = detail.settings;
  const vocabularyOverrides = Object.entries(settings?.vocabulary_overrides ?? {});
  const rulesOverrides = Object.keys(settings?.rules_overrides ?? {}).length;

  const renderModule = (key: string, label: string) => {
    const module = moduleByKey[key];
    if (!module) return null;
    // El interruptor de la plataforma solo tiene sentido si su plan y su rubro le dan el módulo.
    const canToggle = canManage && module.in_plan && !module.is_core && module.off_reason !== 'business_type';
    const isPlatformOff = platformOff.includes(key);

    return (
      <li key={key} className="flex items-center justify-between gap-3 py-1.5">
        <span className="flex items-center gap-2 min-w-0">
          <span className={`w-2 h-2 rounded-full shrink-0 ${module.enabled ? 'bg-emerald-500' : 'bg-slate-300'}`} />
          <span className={`text-sm truncate ${module.enabled ? 'text-slate-800 font-medium' : 'text-slate-400'}`}>{label}</span>
        </span>
        <span className="flex items-center gap-2 shrink-0">
          {!module.enabled && <span className="text-[11px] text-slate-400">{OFF_REASONS[module.off_reason ?? ''] ?? ''}</span>}
          {canToggle && (
            <Switch
              checked={!isPlatformOff}
              onChange={() => setPlatformOff((prev) => (isPlatformOff ? prev.filter((k) => k !== key) : [...prev, key]))}
              label={`${label} para esta empresa`}
            />
          )}
        </span>
      </li>
    );
  };

  return (
    <div className="space-y-5 pb-10 font-sans text-[#1F2937]">
      <nav className="flex items-center gap-2 text-sm">
        <Link
          href="/admin/tenants"
          className="inline-flex items-center gap-2 pl-2.5 pr-3.5 py-2 rounded-xl bg-white border border-slate-200 font-bold text-[#022340] hover:bg-slate-50 hover:border-slate-300 shadow-xs transition-colors"
        >
          <ArrowLeft size={16} /> Tenants
        </Link>
        <span className="text-slate-300">/</span>
        <span className="font-semibold text-slate-500 truncate">{detail.name}</span>
      </nav>

      {notice && (
        <div className={`p-3.5 rounded-2xl border text-sm font-medium ${notice.type === 'ok' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-600'}`}>
          {notice.text}
        </div>
      )}

      <div className="grid grid-cols-1 xl:grid-cols-12 gap-5">
        {/* ============ Empresa ============ */}
        <section className="xl:col-span-8 bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden flex flex-col">
          <div className="h-2" style={{ backgroundColor: color }} />
          <div className="p-6 flex flex-wrap items-start justify-between gap-4 flex-1">
            <div className="flex items-start gap-4 min-w-0">
              <span className="p-4 rounded-2xl shrink-0" style={{ backgroundColor: `${color}1A`, color }}>
                <Building2 className="w-8 h-8" />
              </span>
              <div className="min-w-0 space-y-1.5">
                <div className="flex flex-wrap items-center gap-2">
                  <h1 className="text-2xl font-black text-[#022340] tracking-tight">{detail.name}</h1>
                  <span className={`px-2.5 py-0.5 text-xs rounded-full font-semibold ${status.className}`}>{status.label}</span>
                </div>
                <p className="text-xs text-slate-400 font-mono">slug: {detail.slug}</p>
                {detail.company && (
                  <p className="text-sm text-slate-600">
                    <span className="font-semibold">{detail.company.legal_name || detail.company.trade_name}</span>
                    {detail.company.tax_id ? ` · RUC ${detail.company.tax_id}` : ''}
                  </p>
                )}
                {detail.company && (detail.company.district || detail.company.department || detail.company.phone) && (
                  <p className="flex items-center gap-1.5 text-xs text-slate-500">
                    <MapPin size={13} className="text-slate-400" />
                    {[detail.company.address, detail.company.district, detail.company.department, detail.company.phone].filter(Boolean).join(' · ')}
                  </p>
                )}
              </div>
            </div>
            <button
              type="button"
              onClick={handleImpersonate}
              disabled={busy || !detail.can_impersonate}
              title={!detail.can_impersonate ? 'La empresa no tiene una cuenta de dueño activa' : 'Abre su panel como su administrador. El ingreso queda registrado.'}
              className="px-4 py-2.5 text-sm font-bold bg-amber-400 text-[#0B1B2B] rounded-xl hover:bg-amber-300 shadow-sm transition-colors disabled:opacity-50 flex items-center gap-2 cursor-pointer"
            >
              <LogIn size={16} /> Entrar al panel
            </button>
          </div>

          <div className="grid grid-cols-2 md:grid-cols-4 divide-x divide-slate-100 border-t border-slate-100 bg-slate-50/60">
            {[
              { icon: <Layers size={14} />, label: 'Rubro', value: detail.business_type?.short_name ?? 'Sin rubro' },
              { icon: <Hash size={14} />, label: 'Código de empresa', value: detail.code },
              { icon: <Clock size={14} />, label: 'Zona horaria', value: detail.timezone },
              { icon: <Calendar size={14} />, label: 'Registrada', value: formatDate(detail.created_at) },
            ].map((item) => (
              <div key={item.label} className="px-6 py-3.5">
                <span className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400">{item.icon} {item.label}</span>
                <span className="block text-sm font-bold text-slate-800 mt-0.5">{item.value}</span>
              </div>
            ))}
          </div>
        </section>

        {/* ============ Plan ============ */}
        <section className="xl:col-span-4 rounded-3xl p-6 text-white bg-gradient-to-br from-[#022340] via-[#06375f] to-[#3F768C] shadow-lg flex flex-col justify-between gap-5">
          <div className="flex items-start justify-between gap-3">
            <div>
              <span className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-widest text-[#84D0D9]">
                <CreditCard size={14} /> Plan
              </span>
              <h2 className="text-2xl font-black tracking-tight mt-1">{subscription?.plan?.name ?? 'Sin plan'}</h2>
              {subscription?.plan && !subscription.plan.is_public_catalog && (
                <span className="inline-block mt-1 px-2 py-0.5 rounded-md bg-white/15 text-[11px] font-semibold">A medida</span>
              )}
            </div>
            {subscription && (
              <span className={`px-2.5 py-1 rounded-lg text-[11px] font-bold uppercase ${subscription.is_current ? 'bg-emerald-400 text-[#022340]' : 'bg-rose-500 text-white'}`}>
                {SUBSCRIPTION_STATUS[subscription.status] ?? subscription.status}
                {!subscription.is_current && ['active', 'trialing'].includes(subscription.status) ? ' · vencida' : ''}
              </span>
            )}
          </div>

          {subscription?.plan ? (
            <>
              <div>
                <span className="text-4xl font-black">{subscription.plan.currency || 'PEN'} {subscription.billing_cycle === 'yearly' ? subscription.plan.price_yearly : subscription.plan.price_monthly}</span>
                <span className="text-sm font-medium text-white/70"> / {subscription.billing_cycle === 'yearly' ? 'año' : 'mes'}</span>
              </div>
              <div className="grid grid-cols-2 gap-3 text-xs">
                <div className="rounded-2xl bg-white/10 px-3.5 py-2.5">
                  <span className="block text-white/60 font-semibold">Inició</span>
                  <span className="block font-bold">{formatDate(subscription.starts_at)}</span>
                </div>
                <div className="rounded-2xl bg-white/10 px-3.5 py-2.5">
                  <span className="block text-white/60 font-semibold">Vence</span>
                  <span className="block font-bold">
                    {subscription.ends_at ? formatDate(subscription.ends_at) : 'Sin vencimiento'}
                    {daysLeft !== null && subscription.is_current && (
                      <span className={`ml-1 font-semibold ${daysLeft <= 7 ? 'text-amber-300' : 'text-white/70'}`}>· en {daysLeft} días</span>
                    )}
                  </span>
                </div>
              </div>
            </>
          ) : (
            <p className="text-sm text-white/80">Esta empresa no tiene una suscripción. Sin plan no tiene módulos.</p>
          )}
        </section>

        {/* ============ Uso frente a los límites ============ */}
        <section className="xl:col-span-12 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-5">
          {detail.usage.map((item) => <UsageTile key={item.key} item={item} />)}
        </section>

        {/* ============ Módulos ============ */}
        <section className={`xl:col-span-7 ${card}`}>
          <CardTitle
            icon={<Puzzle size={16} />}
            title="Módulos"
            aside={<span className="px-2.5 py-1 rounded-lg bg-emerald-50 border border-emerald-100 text-xs font-bold text-emerald-700">{enabledCount} de {detail.modules.length} activos</span>}
          />
          <p className="text-xs text-slate-500 mb-4">
            Lo que tiene sale de su plan, menos lo que apaga su rubro, la plataforma y ella misma.
            {canManage ? ' El interruptor decide cuáles de los módulos de su plan tiene encendidos; para darle uno que su plan no incluye, cámbiale el plan.' : ''}
          </p>

          <div className="flex flex-wrap gap-2 mb-4">
            {Object.entries(CORE_MODULE_LABELS).map(([key, label]) => (
              <span key={key} className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-[#022340]/5 text-xs font-semibold text-[#022340]">
                <span className={`w-2 h-2 rounded-full ${moduleByKey[key]?.enabled ? 'bg-emerald-500' : 'bg-slate-300'}`} /> {label} · base
              </span>
            ))}
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
            {MODULE_GROUPS.map((group) => (
              <div key={group.key}>
                <span className="block text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1">{group.title}</span>
                <ul className="divide-y divide-slate-100">
                  {group.items.map((item) => renderModule(item.key, item.label))}
                </ul>
              </div>
            ))}
          </div>

          {canManage && (
            <div className="flex justify-end pt-4 mt-4 border-t border-slate-100">
              <button
                type="button"
                disabled={busy || !modulesDirty}
                onClick={() => run(() => api.put(`/tenants/${detail.id}/modules`, { modules_off: platformOff }), 'Módulos actualizados.')}
                className={primaryButton}
              >
                Guardar módulos
              </button>
            </div>
          )}
        </section>

        {/* ============ Gestión de la suscripción ============ */}
        <section className={`xl:col-span-5 ${card} space-y-5`}>
          <CardTitle icon={<CreditCard size={16} />} title="Suscripción y cobros" />

          {canManage ? (
            <>
              <div className="space-y-2">
                <span className={labelClass}>Cambiar plan</span>
                <div className="flex items-center gap-2">
                  <Select
                    ariaLabel="Plan"
                    clearable={false}
                    placeholder="Selecciona un plan"
                    value={planId}
                    onChange={(e) => setPlanId(String(e.target.value))}
                    options={plans.filter((plan) => plan.status === 'active' || plan.id === subscription?.plan?.id).map((plan) => ({ value: plan.id, label: `${plan.name} · ${plan.currency || 'PEN'} ${plan.price_monthly}/mes${plan.is_public_catalog ? '' : ' (a medida)'}` }))}
                  />
                  <button
                    type="button"
                    disabled={busy || !planId || planId === subscription?.plan?.id}
                    onClick={() => run(() => api.put(`/tenants/${detail.id}/plan`, { plan_id: planId }), `Plan cambiado a ${selectedPlan?.name ?? 'el seleccionado'}.`)}
                    className={`${primaryButton} shrink-0`}
                  >
                    Cambiar
                  </button>
                </div>
                <p className="text-[11px] text-slate-400">Incluye planes a medida. Si su suscripción está vigente conserva sus fechas.</p>
              </div>

              {subscription && (
                <>
                  <div className="space-y-2 pt-4 border-t border-slate-100">
                    <span className={labelClass}>Registrar pago</span>
                    <div className="grid grid-cols-3 gap-2">
                      <Select
                        ariaLabel="Ciclo de pago"
                        clearable={false}
                        value={payment.billing_cycle}
                        onChange={(e) => setPayment({ ...payment, billing_cycle: String(e.target.value) })}
                        options={[{ value: 'monthly', label: 'Mensual' }, { value: 'yearly', label: 'Anual' }]}
                      />
                      <input type="number" min="0" step="0.01" value={payment.amount} onChange={(e) => setPayment({ ...payment, amount: e.target.value })} className={inputClass} placeholder={`Monto (${defaultAmount})`} />
                      <input type="text" maxLength={255} value={payment.reference} onChange={(e) => setPayment({ ...payment, reference: e.target.value })} className={inputClass} placeholder="N.º operación" />
                    </div>
                    <div className="flex items-center justify-between gap-3">
                      <p className="text-[11px] text-slate-400">Deja la suscripción activa y suma un periodo.</p>
                      <button
                        type="button"
                        disabled={busy}
                        onClick={() => run(
                          () => api.post(`/tenants/${detail.id}/subscription/payments`, {
                            billing_cycle: payment.billing_cycle,
                            amount: payment.amount === '' ? null : Number(payment.amount),
                            reference: payment.reference || null,
                          }),
                          'Pago registrado.'
                        )}
                        className={`${primaryButton} shrink-0`}
                      >
                        Registrar pago
                      </button>
                    </div>
                  </div>

                  <div className="space-y-2 pt-4 border-t border-slate-100">
                    <span className={labelClass}>Vencimiento</span>
                    <div className="flex flex-wrap items-center gap-2">
                      <input type="date" value={extendDate} onChange={(e) => setExtendDate(e.target.value)} className={`${inputClass} flex-1 min-w-[9rem]`} />
                      <button
                        type="button"
                        disabled={busy || !extendDate}
                        onClick={() => run(() => api.put(`/tenants/${detail.id}/subscription/extend`, { ends_at: extendDate }), 'Vencimiento actualizado.')}
                        className={`${ghostButton} shrink-0`}
                      >
                        Mover
                      </button>
                      {subscription.status !== 'canceled' && (
                        <button
                          type="button"
                          disabled={busy}
                          onClick={() => run(() => api.post(`/tenants/${detail.id}/subscription/cancel`), 'Suscripción cancelada.')}
                          className="px-4 py-2 border border-rose-200 text-rose-600 text-xs font-bold rounded-xl hover:bg-rose-50 transition-colors disabled:opacity-50 shrink-0 cursor-pointer"
                        >
                          Cancelar suscripción
                        </button>
                      )}
                    </div>
                    <p className="text-[11px] text-slate-400">Mover no registra cobro. Al cancelar, la empresa queda sin módulos de inmediato.</p>
                  </div>
                </>
              )}
            </>
          ) : (
            <p className="text-xs text-slate-500">Los cambios de plan y los cobros los hace el administrador SaaS.</p>
          )}

          <div className="space-y-2 pt-4 border-t border-slate-100">
            <span className={labelClass}>Cobros ({detail.invoices.length})</span>
            {detail.invoices.length > 0 ? (
              <ul className="space-y-2 max-h-56 overflow-y-auto pr-1">
                {detail.invoices.map((invoice) => (
                  <li key={invoice.id} className="flex items-center justify-between gap-3 p-3 bg-slate-50 border border-slate-100 rounded-2xl text-xs">
                    <div className="flex items-center gap-3 min-w-0">
                      <Receipt size={16} className="text-slate-400 shrink-0" />
                      <div className="min-w-0">
                        <p className="font-bold text-slate-900">{invoice.currency} {Number(invoice.amount).toFixed(2)}</p>
                        <p className="text-[11px] text-slate-500 truncate">
                          {formatDate(invoice.invoice_date)}
                          {invoice.period_start && invoice.period_end ? ` · cubre ${formatDate(invoice.period_start)} – ${formatDate(invoice.period_end)}` : ''}
                          {invoice.reference ? ` · ${invoice.reference}` : ''}
                        </p>
                      </div>
                    </div>
                    <span className={`px-2 py-0.5 text-[11px] rounded-md font-semibold shrink-0 ${invoice.status_slug === 'paid' ? 'bg-emerald-50 text-emerald-600' : 'bg-white border border-slate-200 text-slate-700'}`}>
                      {invoice.status ?? 'Sin estado'}
                    </span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-xs text-slate-400 italic">Aún no hay cobros registrados.</p>
            )}
          </div>
        </section>

        {/* ============ Configuración de la empresa ============ */}
        <section className={`xl:col-span-4 ${card}`}>
          <CardTitle icon={<Settings2 size={16} />} title="Su configuración" />
          <p className="text-xs text-slate-500 mb-4">Lo que la empresa ajustó por su cuenta. Solo ella lo cambia, desde su panel.</p>

          <div className="space-y-4">
            <div>
              <span className={labelClass}><Type size={11} className="inline mr-1" />Cómo nombra a su gente</span>
              <div className="flex flex-wrap gap-1.5">
                {['clients', 'pros', ...(settings?.dependents.enabled ? ['dependents'] : [])].map((key) => (
                  <span key={key} className="px-2.5 py-1 rounded-lg bg-slate-100 text-xs font-semibold text-slate-700">{settings?.vocabulary[key]}</span>
                ))}
              </div>
              <p className="text-[11px] text-slate-400 mt-1.5">
                {vocabularyOverrides.length > 0
                  ? `Cambió ${vocabularyOverrides.length} ${vocabularyOverrides.length === 1 ? 'término' : 'términos'} del rubro: ${vocabularyOverrides.map(([, value]) => value).join(', ')}.`
                  : 'Usa los términos de su rubro, sin cambios.'}
              </p>
            </div>

            <div className="pt-4 border-t border-slate-100">
              <span className={labelClass}>Módulos que ella apagó</span>
              {settings && settings.modules_off.length > 0 ? (
                <div className="flex flex-wrap gap-1.5">
                  {settings.modules_off.map((key) => (
                    <span key={key} className="px-2.5 py-1 rounded-lg bg-slate-100 text-xs font-medium text-slate-500 line-through decoration-slate-300">{MODULE_LABELS[key] ?? key}</span>
                  ))}
                </div>
              ) : (
                <p className="text-xs text-slate-500">Ninguno: usa todo lo que su plan y su rubro le dan.</p>
              )}
            </div>

            <div className="grid grid-cols-2 gap-3 pt-4 border-t border-slate-100">
              <div className="rounded-2xl bg-slate-50 border border-slate-100 px-3.5 py-3">
                <span className="block text-xl font-black text-[#022340]">{rulesOverrides}</span>
                <span className="block text-[11px] font-semibold text-slate-500">reglas que cambió</span>
              </div>
              <div className="rounded-2xl bg-slate-50 border border-slate-100 px-3.5 py-3">
                <span className={`block text-sm font-black ${settings?.allow_customer_signup ? 'text-emerald-600' : 'text-slate-500'}`}>{settings?.allow_customer_signup ? 'Abierto' : 'Cerrado'}</span>
                <span className="block text-[11px] font-semibold text-slate-500">registro de clientes con su código</span>
              </div>
            </div>
          </div>
        </section>

        {/* ============ Rubro ============ */}
        <section className={`xl:col-span-4 ${card}`}>
          <CardTitle icon={<Layers size={16} />} title="Rubro" />
          <div className="flex items-center gap-3 mb-4">
            <span className="p-3 rounded-2xl" style={{ backgroundColor: `${detail.business_type?.color ?? '#3F768C'}1A`, color: detail.business_type?.color ?? '#3F768C' }}>
              <Layers size={20} />
            </span>
            <div>
              <span className="block text-base font-black text-[#022340]">{detail.business_type?.name ?? 'Sin rubro'}</span>
              {detail.business_type && canManage && (
                <Link href={`/admin/business-types/${detail.business_type.id}`} className="text-xs font-bold text-[#3F768C] hover:underline">Ver la plantilla del rubro</Link>
              )}
            </div>
          </div>

          {(detail.business_profile_outdated?.length ?? 0) > 0 && (
            <div className="flex items-start gap-2 p-3 mb-4 bg-amber-50 border border-amber-200 rounded-2xl text-xs text-amber-800">
              <RefreshCw size={14} className="shrink-0 mt-0.5" />
              <span>La plantilla de su rubro cambió y esta empresa conserva la versión anterior. Los cambios se aplican desde la plantilla.</span>
            </div>
          )}

          {canManage ? (
            <div className="space-y-2">
              <span className={labelClass}>Cambiar rubro</span>
              <div className="flex items-center gap-2">
                <Select
                  ariaLabel="Rubro"
                  clearable={false}
                  placeholder="Selecciona un rubro"
                  value={businessTypeId}
                  onChange={(e) => setBusinessTypeId(String(e.target.value))}
                  options={businessTypes.map((type) => ({ value: type.id, label: `${type.name}${type.status !== 'active' ? ' (inactivo)' : ''}` }))}
                />
                <button
                  type="button"
                  disabled={busy || !businessTypeId || businessTypeId === detail.business_type?.id}
                  onClick={() => run(
                    () => api.put(`/tenants/${detail.id}/business-type`, { business_type_id: businessTypeId, install_template: installTemplate }),
                    'Rubro actualizado.'
                  )}
                  className={`${primaryButton} shrink-0`}
                >
                  Cambiar
                </button>
              </div>
              <label className="flex items-start gap-2 text-xs text-slate-600 cursor-pointer">
                <input type="checkbox" checked={installTemplate} onChange={(e) => setInstallTemplate(e.target.checked)} className="mt-0.5 rounded border-slate-300 text-[#022340] focus:ring-[#022340]" />
                Copiar el catálogo inicial del rubro nuevo (solo si aún no tiene servicios)
              </label>
              <p className="text-[11px] text-slate-400">Cambian sus nombres, los módulos del rubro y sus reglas. Sus servicios y fichas no se tocan.</p>
            </div>
          ) : (
            <p className="text-xs text-slate-500">El rubro lo cambia el administrador SaaS.</p>
          )}
        </section>

        {/* ============ Acceso ============ */}
        <section className={`xl:col-span-4 rounded-3xl border p-6 ${detail.status === 'active' ? 'bg-white border-slate-200/80 shadow-xs' : 'bg-rose-50 border-rose-200'}`}>
          <CardTitle icon={<ShieldAlert size={16} />} title="Acceso" />
          {detail.status === 'active' ? (
            <>
              <p className="text-xs text-slate-500 mb-3">
                La empresa puede iniciar sesión. Al suspenderla nadie de ella entra y sus sesiones abiertas se cierran; sus datos se conservan.
              </p>
              {canManage && (
                <div className="space-y-2">
                  <input type="text" maxLength={255} value={suspendReason} onChange={(e) => setSuspendReason(e.target.value)} className={inputClass} placeholder="Motivo (opcional). Ej. Falta de pago" />
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => run(() => api.post(`/tenants/${detail.id}/suspend`, { reason: suspendReason || null }), 'Empresa suspendida.')}
                    className="w-full px-4 py-2.5 bg-rose-600 text-white text-xs font-bold rounded-xl hover:bg-rose-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5 cursor-pointer"
                  >
                    <Ban size={14} /> Suspender empresa
                  </button>
                </div>
              )}
            </>
          ) : (
            <>
              <p className="text-sm font-bold text-rose-700 mb-1">La empresa no puede iniciar sesión.</p>
              {detail.suspension_reason && <p className="text-xs text-rose-700 mb-3">Motivo: {detail.suspension_reason}</p>}
              {canManage && (
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => run(() => api.post(`/tenants/${detail.id}/reactivate`), 'Empresa reactivada.')}
                  className={`${primaryButton} w-full flex items-center justify-center gap-1.5 py-2.5`}
                >
                  <RotateCcw size={14} /> Reactivar empresa
                </button>
              )}
            </>
          )}
        </section>

        {/* ============ Personal ============ */}
        <section className={`xl:col-span-12 ${card}`}>
          <CardTitle
            icon={<Users size={16} />}
            title={`Personal (${detail.users.length})`}
            aside={<span className="text-xs font-medium text-slate-400">{detail.customer_accounts} cuentas de clientes en su portal</span>}
          />
          {detail.users.length > 0 ? (
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
              {detail.users.map((member) => {
                const fullName = `${member.first_name || ''} ${member.last_name || ''}`.trim();
                return (
                  <div key={member.id} className="flex items-center justify-between gap-3 p-3.5 bg-slate-50 border border-slate-100 rounded-2xl">
                    <div className="flex items-center gap-3 min-w-0">
                      <div className="w-9 h-9 rounded-full bg-[#022340]/10 text-[#022340] font-bold text-sm flex items-center justify-center shrink-0">
                        {(fullName || 'U').charAt(0).toUpperCase()}
                      </div>
                      <div className="min-w-0">
                        <p className="text-sm font-semibold text-slate-900 truncate">{fullName || 'Sin nombre'}</p>
                        <p className="text-[11px] text-slate-500 flex items-center gap-1 truncate"><Mail size={12} className="shrink-0" /> {member.email}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-1.5 shrink-0">
                      {member.status !== 'active' && <span className="px-2 py-0.5 bg-rose-50 text-rose-600 text-[11px] rounded-md font-medium">Desactivada</span>}
                      {member.role && <span className="px-2 py-0.5 bg-white border border-slate-200 text-slate-700 text-[11px] rounded-md font-medium">{member.role}</span>}
                    </div>
                  </div>
                );
              })}
            </div>
          ) : (
            <p className="text-xs text-slate-500">No hay usuarios vinculados a esta empresa.</p>
          )}
        </section>
      </div>
    </div>
  );
}
