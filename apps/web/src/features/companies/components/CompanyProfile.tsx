'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { Building2, CalendarDays, Check, ChevronRight, Clock, Copy, CreditCard, FileText, KeyRound, MapPin, Pencil, Phone, Settings, Store, Users } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { apiError, formatDate } from '@/features/appointments/appointments';
import FormCompany from './FormCompany';
import { SUBSCRIPTION_STATUS, type CompanyProfileData } from '../companies';

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const cardTitle = 'flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider';
const smallLabel = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

const shortDate = (date: string) => formatDate(date, { day: 'numeric', month: 'long', year: 'numeric' });

// Datos generales de la propia empresa: quién es, cómo ubicarla, su plan y
// cuánto usa de lo que su plan permite. La edita su administrador.
export default function CompanyProfile() {
  const { refetchUser } = useAuth();
  const vocab = useVocabulary();
  const [data, setData] = useState<CompanyProfileData | null>(null);
  const [error, setError] = useState('');
  const [editing, setEditing] = useState(false);
  const [copied, setCopied] = useState(false);

  const load = useCallback(() => {
    api.get('/company')
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los datos de la empresa.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  if (!data) {
    return <p role={error ? 'alert' : undefined} className={`text-sm ${error ? 'text-red-600' : 'text-slate-500'}`}>{error || 'Cargando datos de la empresa...'}</p>;
  }

  const { tenant, company, subscription } = data;
  const place = [company.address, company.district, company.department].filter(Boolean).join(', ');

  const facts: [React.ReactNode, string, string | null][] = [
    [<FileText size={16} key="legal" />, 'Razón social', company.legal_name],
    [<CreditCard size={16} key="tax" />, 'RUC', company.tax_id],
    [<Phone size={16} key="phone" />, 'Teléfono', company.phone],
    [<MapPin size={16} key="address" />, 'Dirección fiscal', place || null],
    [<Clock size={16} key="zone" />, 'Zona horaria', tenant.timezone.replace(/_/g, ' ')],
    [<CalendarDays size={16} key="since" />, 'En la plataforma desde', tenant.member_since ? shortDate(tenant.member_since) : null],
  ];

  const usageLabels: Record<string, { title: string; icon: React.ReactNode; href?: string }> = {
    branches: { title: 'Sucursales activas', icon: <Store size={18} />, href: '/branches' },
    professionals: { title: vocab.pros, icon: <Users size={18} />, href: '/professionals' },
    users: { title: 'Usuarios, sin contar al dueño', icon: <KeyRound size={18} /> },
    appointments_month: { title: 'Citas de este mes', icon: <CalendarDays size={18} />, href: '/appointments' },
  };

  const status = subscription ? SUBSCRIPTION_STATUS[subscription.status] ?? { label: subscription.status, badge: 'bg-slate-100 text-slate-600' } : null;

  const copyCode = async () => {
    let done = false;

    try {
      await navigator.clipboard.writeText(tenant.code);
      done = true;
    } catch {
      // Sin permiso para el portapapeles (o sin https): se copia desde un campo temporal.
      const field = document.createElement('textarea');
      field.value = tenant.code;
      field.style.position = 'fixed';
      field.style.opacity = '0';
      document.body.appendChild(field);
      field.select();
      done = document.execCommand('copy');
      field.remove();
    }

    setCopied(done);
    if (done) setTimeout(() => setCopied(false), 2000);
  };

  return (
    <div className="space-y-5 pb-8 font-sans" data-company>
      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
        {/* Quién es la empresa */}
        <section className={`${card} p-6 space-y-5 xl:row-span-2`} data-card="company">
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-center gap-3 min-w-0">
              <span className="w-12 h-12 rounded-2xl bg-[#84D0D9]/20 text-[#022340] flex items-center justify-center shrink-0"><Building2 size={22} /></span>
              <div className="min-w-0">
                <h1 className="text-xl font-black text-[#022340] tracking-tight" data-company-name>{tenant.name}</h1>
                {tenant.business_type && (
                  <span className="inline-block mt-1 px-2.5 py-0.5 text-xs rounded-full font-medium bg-slate-100 text-slate-600">{tenant.business_type}</span>
                )}
              </div>
            </div>
            {data.can_edit && (
              <button type="button" data-action="edit-company" title="Editar los datos de la empresa" onClick={() => setEditing(true)} className="p-2 text-slate-400 hover:text-[#022340] hover:bg-slate-100 rounded-lg transition-colors shrink-0">
                <Pencil size={16} />
              </button>
            )}
          </div>

          <dl className="space-y-3">
            {facts.map(([icon, label, value]) => (
              <div key={label} className="flex items-start gap-3">
                <span className="text-slate-400 mt-0.5">{icon}</span>
                <div className="min-w-0">
                  <dt className={smallLabel}>{label}</dt>
                  <dd className={`text-sm ${value ? 'font-semibold text-slate-800' : 'text-slate-400'}`}>{value || 'Sin registrar'}</dd>
                </div>
              </div>
            ))}
          </dl>
        </section>

        {/* Uso frente a lo que permite el plan */}
        <div className="xl:col-span-2 grid grid-cols-2 lg:grid-cols-4 gap-4">
          {data.usage.map((item) => {
            const meta = usageLabels[item.key];
            const percent = item.limit ? Math.min(100, Math.round((item.used * 100) / item.limit)) : null;
            const tone = percent === null ? 'bg-[#3F768C]' : percent >= 100 ? 'bg-rose-500' : percent >= 80 ? 'bg-amber-500' : 'bg-emerald-500';
            const body = (
              <>
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <span className="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">{meta?.title ?? item.key}</span>
                    <div className="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900">
                      {item.used.toLocaleString('es-PE')}
                      {item.limit !== null && <span className="text-sm font-semibold text-slate-400"> de {item.limit.toLocaleString('es-PE')}</span>}
                    </div>
                  </div>
                  <span className="text-[#3F768C]">{meta?.icon}</span>
                </div>
                <div className="mt-2 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                  <div className={`h-full rounded-full ${tone}`} style={{ width: `${percent ?? 100}%` }} />
                </div>
                <span className="block mt-1.5 text-xs text-slate-500 truncate">
                  {percent === null ? 'Sin tope en tu plan' : percent >= 100 ? 'Llegaste al tope de tu plan' : `${percent}% de tu plan`}
                </span>
              </>
            );
            const className = 'block bg-white px-4 py-3 rounded-2xl border border-slate-100 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)]';

            return meta?.href
              ? <Link key={item.key} href={meta.href} data-usage={item.key} className={`${className} hover:border-[#84D0D9]/60 transition-colors`}>{body}</Link>
              : <div key={item.key} data-usage={item.key} className={className}>{body}</div>;
          })}
        </div>

        {/* Plan */}
        <section className={`${card} p-6 space-y-4`} data-card="plan">
          <h2 className={cardTitle}><CreditCard size={16} className="text-[#3F768C]" /> Tu plan</h2>
          {subscription ? (
            <>
              <div className="flex items-center justify-between gap-3">
                <span className="text-2xl font-black text-[#022340] tracking-tight">{subscription.plan ?? 'Plan a medida'}</span>
                <span data-status={subscription.status} className={`px-2.5 py-0.5 text-xs rounded-full font-medium ${status!.badge}`}>{status!.label}</span>
              </div>
              <p className="text-sm text-slate-600">
                {subscription.billing_cycle === 'yearly' ? 'Pago anual.' : 'Pago mensual.'}
                {subscription.trial_ends_at && ` La prueba termina el ${shortDate(subscription.trial_ends_at)}.`}
                {subscription.ends_at && ` Vigente hasta el ${shortDate(subscription.ends_at)}.`}
              </p>
            </>
          ) : (
            <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5">Tu empresa no tiene un plan vigente.</p>
          )}
          <p className="text-xs text-slate-500">El plan y el rubro los cambia el equipo de la plataforma.</p>
        </section>

        {/* Código de empresa */}
        <section className={`${card} p-6 space-y-4`} data-card="code">
          <h2 className={cardTitle}><KeyRound size={16} className="text-[#3F768C]" /> Código de empresa</h2>
          <div className="flex items-center justify-between gap-3">
            <span className="font-mono text-2xl font-black text-[#022340] tracking-[0.25em]" data-code>{tenant.code}</span>
            <button type="button" data-action="copy-code" onClick={copyCode} className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors">
              {copied ? <><Check size={13} className="text-emerald-600" /> Copiado</> : <><Copy size={13} /> Copiar</>}
            </button>
          </div>
          <p className="text-xs text-slate-500">
            Tus {vocab.clients.toLowerCase()} lo usan para crear su cuenta y reservar en línea. Tienes {data.totals.customers.toLocaleString('es-PE')} {vocab.clients.toLowerCase()} y {data.totals.services} servicios.
          </p>
        </section>

        {/* A dónde seguir */}
        <section className={`${card} p-6 space-y-3 xl:col-span-3`} data-card="links">
          <h2 className={cardTitle}><Settings size={16} className="text-[#3F768C]" /> Lo demás de tu empresa</h2>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            {[
              { label: 'Sucursales', detail: 'Direcciones, horarios y salas', href: '/branches', icon: Store },
              { label: vocab.pros, detail: 'Tu equipo y sus horarios', href: '/professionals', icon: Users },
              { label: 'Configuración', detail: 'Módulos y nombres que usa tu equipo', href: '/settings', icon: Settings },
            ].map((item) => (
              <Link key={item.href} href={item.href} className="flex items-center justify-between gap-3 p-3.5 bg-slate-50 hover:bg-[#84D0D9]/10 rounded-xl border border-slate-200/60 hover:border-[#84D0D9]/50 transition-colors group">
                <div className="flex items-center gap-3 min-w-0">
                  <span className="p-2 bg-white rounded-lg text-[#3F768C] border border-slate-100"><item.icon size={16} /></span>
                  <div className="min-w-0">
                    <span className="block text-sm font-semibold text-slate-800">{item.label}</span>
                    <span className="block text-xs text-slate-500 truncate">{item.detail}</span>
                  </div>
                </div>
                <ChevronRight size={16} className="text-slate-400 group-hover:text-[#022340] shrink-0" />
              </Link>
            ))}
          </div>
        </section>
      </div>

      <FormCompany
        isOpen={editing}
        onClose={() => setEditing(false)}
        initialData={data}
        onSaved={(saved) => { setData(saved); setEditing(false); refetchUser(); }}
      />
    </div>
  );
}
