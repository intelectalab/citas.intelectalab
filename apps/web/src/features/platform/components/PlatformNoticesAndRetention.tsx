'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Loader2, MessageCircle, PlugZap, ShieldAlert, Trash2 } from 'lucide-react';
import OptionCard from '@/app/components/common/OptionCard';
import SectionTitle from '@/app/components/common/SectionTitle';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError, formatDate } from '@/features/appointments/appointments';

interface WhatsAppConfig {
  enabled: boolean;
  saved: boolean;
  provider: string | null;
  phone_number_id: string | null;
  language: string;
  templates: Record<string, string | null>;
  active: boolean;
  token_saved: string | null;
  providers: { key: string; label: string }[];
  kinds: { key: string; label: string }[];
}

interface Retention {
  enabled: boolean;
  days: number;
  warn_days: number[];
  ready: boolean;
  upcoming: { tenant: { id: string; name: string }; canceled: string; purge_on: string }[];
}

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 space-y-4';
const longDate = (date: string) => formatDate(date, { day: 'numeric', month: 'long', year: 'numeric' });

function WhatsAppCard() {
  const [config, setConfig] = useState<WhatsAppConfig | null>(null);
  const [form, setForm] = useState({ provider: 'cloud', token: '', phone_number_id: '', language: 'es', templates: {} as Record<string, string>, active: true });
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState('');

  const fill = (data: WhatsAppConfig) => {
    setConfig(data);
    setForm({ provider: data.provider ?? 'cloud', token: '', phone_number_id: data.phone_number_id ?? '', language: data.language || 'es', templates: Object.fromEntries(Object.entries(data.templates ?? {}).map(([k, v]) => [k, v ?? ''])), active: data.active });
  };

  useEffect(() => {
    api.get('/admin/billing/whatsapp').then((res) => fill(res.data)).catch((err) => setError(apiError(err, 'No se pudo cargar el WhatsApp de la plataforma.')));
  }, []);

  const run = async (key: string, call: () => Promise<{ data: Record<string, unknown> }>, done: (data: Record<string, unknown>) => void) => {
    try {
      setBusy(key);
      setError('');
      setNotice('');
      const res = await call();
      done(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo completar.'));
    } finally {
      setBusy('');
    }
  };

  const save = () => run('save', () => api.put('/admin/billing/whatsapp', { ...form, token: form.token || null }), (data) => {
    fill(data as unknown as WhatsAppConfig);
    setNotice((data as unknown as WhatsAppConfig).enabled ? 'Listo: los avisos de plan vencido también saldrán por WhatsApp a los administradores de cada empresa.' : 'Guardado. Los avisos por WhatsApp quedan apagados.');
  });

  return (
    <section className={card} data-platform-whatsapp>
      <SectionTitle><MessageCircle size={16} className="text-brand-accent" /> Avisos del plan por WhatsApp</SectionTitle>
      <p className="text-sm text-slate-500">Cuando un plan vence, queda en solo lectura, se cancela o sus datos se van a borrar, además del correo y del aviso en el sistema le escribimos por WhatsApp al dueño y a los administradores de la empresa (al celular de su cuenta). Sin datos guardados no sale ningún WhatsApp.</p>
      {config && (
        <p className={`text-xs rounded-lg px-3 py-2 border ${config.enabled ? 'text-emerald-800 bg-emerald-50 border-emerald-200' : 'text-slate-600 bg-slate-50 border-slate-200'}`} data-whatsapp-state>
          {config.enabled ? 'Encendido: los avisos salen por WhatsApp.' : 'Apagado: los avisos salen solo por correo y en el sistema.'}
        </p>
      )}
      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
      {config && (
        <div className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-2" role="radiogroup" aria-label="Proveedor de WhatsApp">
            {config.providers.map((item) => (
              <OptionCard key={item.key} type="button" role="radio" aria-checked={form.provider === item.key} data-whatsapp-provider={item.key} selected={form.provider === item.key} onClick={() => setForm({ ...form, provider: item.key })} className="rounded-xl border px-3.5 py-2.5 text-sm font-bold text-brand-ink text-left">
                {item.label}
              </OptionCard>
            ))}
          </div>
          {form.provider !== 'simulated' && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div><label className={labelClass} htmlFor="pw-token">Token de acceso</label><input id="pw-token" type="password" autoComplete="off" value={form.token} onChange={(e) => setForm({ ...form, token: e.target.value })} className={inputClass} placeholder={config.token_saved && config.provider === form.provider ? `Ya guardado (${config.token_saved})` : ''} /></div>
              {form.provider === 'cloud' && <div><label className={labelClass} htmlFor="pw-phone">Identificador del número (Phone number ID)</label><input id="pw-phone" inputMode="numeric" value={form.phone_number_id} onChange={(e) => setForm({ ...form, phone_number_id: e.target.value.replace(/\D/g, '') })} className={inputClass} /></div>}
            </div>
          )}
          <div className="space-y-2">
            <span className="block text-xs text-slate-500">Plantillas aprobadas en Meta (opcional). Fuera de las 24 horas WhatsApp solo deja enviar plantillas: escribe su nombre tal como está en Meta. Sin plantilla se envía un texto.</span>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              {config.kinds.map((kind) => (
                <div key={kind.key}><label className={labelClass} htmlFor={`pw-t-${kind.key}`}>{kind.label}</label><input id={`pw-t-${kind.key}`} value={form.templates[kind.key] ?? ''} onChange={(e) => setForm({ ...form, templates: { ...form.templates, [kind.key]: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '') } })} className={inputClass} placeholder="Ej. plan_vencido" /></div>
              ))}
            </div>
          </div>
          <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3">
            <span className="text-sm font-semibold text-slate-700">Enviar los avisos por WhatsApp</span>
            <Switch checked={form.active} onChange={(on) => setForm({ ...form, active: on })} label="Enviar los avisos por WhatsApp" />
          </div>
          <div className="flex flex-wrap gap-2">
            <button type="button" data-whatsapp-save disabled={busy !== ''} onClick={save} className="inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
              {busy === 'save' && <Loader2 size={15} className="animate-spin" />} Guardar
            </button>
            {config.saved && (
              <>
                <button type="button" data-whatsapp-test disabled={busy !== ''} onClick={() => run('test', () => api.post('/admin/billing/whatsapp/test'), (data) => (data.ok ? setNotice(String(data.message)) : setError(String(data.message))))} className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-slate-700 border border-slate-200 rounded-xl hover:bg-slate-50">
                  {busy === 'test' ? <Loader2 size={15} className="animate-spin" /> : <PlugZap size={15} />} Probar conexión
                </button>
                <button type="button" data-whatsapp-remove disabled={busy !== ''} onClick={() => run('remove', () => api.delete('/admin/billing/whatsapp'), (data) => { fill(data as unknown as WhatsAppConfig); setNotice('Quitamos los datos de WhatsApp: los avisos salen solo por correo y en el sistema.'); })} className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-rose-700 border border-rose-200 rounded-xl hover:bg-rose-50">
                  <Trash2 size={15} /> Quitar
                </button>
              </>
            )}
          </div>
        </div>
      )}
    </section>
  );
}

function RetentionCard() {
  const [data, setData] = useState<Retention | null>(null);
  const [form, setForm] = useState({ enabled: false, days: '90', first: '15', last: '7' });
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);

  const fill = (value: Retention) => {
    setData(value);
    const [first, last] = [Math.max(...value.warn_days), Math.min(...value.warn_days)];
    setForm({ enabled: value.enabled, days: String(value.days), first: String(first), last: String(last) });
  };

  useEffect(() => {
    api.get('/admin/billing/retention').then((res) => fill(res.data)).catch((err) => setError(apiError(err, 'No se pudo cargar el borrado de datos.')));
  }, []);

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const warn = [...new Set([Number(form.first), Number(form.last)].filter((d) => d > 0))];
      const res = await api.put('/admin/billing/retention', { enabled: form.enabled, days: Number(form.days), warn_days: warn });
      fill(res.data);
      setNotice(res.data.enabled ? `Listo: a los ${res.data.days} días de la baja se borrarán los datos personales, con avisos antes.` : 'Listo: los datos de las empresas dadas de baja se conservan sin plazo.');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className={card} data-platform-retention>
      <SectionTitle><ShieldAlert size={16} className="text-brand-accent" /> Borrado de datos de empresas dadas de baja</SectionTitle>
      <p className="text-sm text-slate-500">Si lo enciendes, cuando una empresa lleva el plazo elegido con su plan cancelado (porque pidió la baja o no pagó), se borran los datos personales de sus clientes y de su equipo. Se guardan los montos, las ventas y los comprobantes, que la ley obliga a conservar. Antes se le avisa dos veces por correo, en el sistema y por WhatsApp, y en el último aviso se le prepara el archivo con todos sus datos. Si vuelve a pagar antes, no se borra nada.</p>
      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
      {data && !data.ready && <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Falta actualizar la base de datos: ejecuta php artisan migrate.</p>}
      {data && (
        <div className="space-y-4">
          <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3">
            <span className="text-sm font-semibold text-slate-700">Borrar los datos pasado el plazo</span>
            <Switch checked={form.enabled} onChange={(on) => setForm({ ...form, enabled: on })} label="Borrar los datos pasado el plazo" />
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div><label className={labelClass} htmlFor="rt-days">Días después de la baja</label><input id="rt-days" inputMode="numeric" value={form.days} onChange={(e) => setForm({ ...form, days: e.target.value.replace(/\D/g, '') })} className={inputClass} /></div>
            <div><label className={labelClass} htmlFor="rt-first">Primer aviso (días antes)</label><input id="rt-first" inputMode="numeric" value={form.first} onChange={(e) => setForm({ ...form, first: e.target.value.replace(/\D/g, '') })} className={inputClass} /></div>
            <div><label className={labelClass} htmlFor="rt-last">Último aviso (días antes)</label><input id="rt-last" inputMode="numeric" value={form.last} onChange={(e) => setForm({ ...form, last: e.target.value.replace(/\D/g, '') })} className={inputClass} /></div>
          </div>
          <button type="button" data-retention-save disabled={busy} onClick={save} className="inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
            {busy && <Loader2 size={15} className="animate-spin" />} Guardar
          </button>
          {data.upcoming.length > 0 && (
            <div className="space-y-1.5" data-retention-upcoming>
              <span className="block text-xs font-bold text-slate-600">Empresas dadas de baja{data.enabled ? ' y cuándo se borrarían sus datos' : ''}</span>
              <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200">
                {data.upcoming.slice(0, 6).map((row) => (
                  <li key={row.tenant.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm">
                    <Link href={`/admin/tenants/${row.tenant.id}`} className="font-semibold text-slate-800 hover:text-brand-accent">{row.tenant.name}</Link>
                    <span className="text-xs text-slate-500">De baja desde el {longDate(row.canceled)}{data.enabled ? ` · se borra el ${longDate(row.purge_on)}` : ''}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </section>
  );
}

export default function PlatformNoticesAndRetention() {
  return (
    <div className="space-y-6">
      <WhatsAppCard />
      <RetentionCard />
    </div>
  );
}
