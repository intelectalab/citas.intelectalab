'use client';

import SectionTitle from '@/app/components/common/SectionTitle';
import OptionCard from '@/app/components/common/OptionCard';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useEffect, useState } from 'react';
import { CheckCircle2, Copy, CreditCard } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';

interface PlatformBillingData {
  connected: boolean;
  simulation: boolean;
  mode: 'sandbox' | 'production' | null;
  account: string | null;
  currency: string | null;
  saved: { public_key: string | null; secret_key: string | null; webhook_secret: string | null; saved_at: string } | null;
  webhook_url: string;
  grace_days: number;
  summary: { cards: number; failed: number; online: number };
}

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 space-y-4';
const labelClass = 'block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1.5';
const inputClass = 'w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none';

export default function PlatformBilling() {
  const [data, setData] = useState<PlatformBillingData | null>(null);
  const [keys, setKeys] = useState({ public_key: '', secret_key: '', webhook_secret: '' });
  const [mode, setMode] = useState<'sandbox' | 'production'>('sandbox');
  const [grace, setGrace] = useState(7);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [copied, setCopied] = useState(false);

  const apply = (payload: PlatformBillingData) => {
    setData(payload);
    setMode(payload.mode === 'production' ? 'production' : 'sandbox');
    setGrace(payload.grace_days);
    setKeys({ public_key: '', secret_key: '', webhook_secret: '' });
  };

  useEffect(() => {
    api.get('/admin/billing')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar el cobro de la plataforma.')));
  }, []);

  if (!data) {
    return error ? <section className={card}><ErrorAlert message={error} /></section> : null;
  }

  const save = async (connect: boolean) => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const res = await api.put('/admin/billing', { connect, mode, grace_days: grace, ...keys });
      apply(res.data);
      setNotice(!connect
        ? data.connected ? 'Listo: la plataforma ya no cobra los planes en línea.' : 'Cambios guardados.'
        : mode === 'production'
          ? 'Mercado Pago quedó conectado. Los planes de las empresas se pagan con la cuenta de la plataforma.'
          : 'Mercado Pago quedó conectado en modo de pruebas. Usa las tarjetas de prueba: lo que se pague no llega a la cuenta.');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  const test = async () => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const res = await api.post('/admin/billing/test');
      if (res.data.ok) setNotice(res.data.message);
      else setError(res.data.message);
    } catch (err) {
      setError(apiError(err, 'Mercado Pago no respondió.'));
    } finally {
      setBusy(false);
    }
  };

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(data.webhook_url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  };

  return (
    <section className={card} data-card="platform-billing">
      <div>
        <SectionTitle><span className="text-brand-accent"><CreditCard size={16} /></span> Cobro de los planes</SectionTitle>
        <p className="text-xs text-slate-500 font-medium mt-1">
          La cuenta de Mercado Pago de la plataforma: con ella las empresas autorizan su tarjeta al registrarse y pagan su plan. Es distinta de la de cada empresa, que cobra a sus propios clientes.
        </p>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        {([
          ['Estado', data.connected ? `Conectado${data.mode === 'production' ? ' · producción' : ' · pruebas'}` : data.simulation ? 'Simulación (solo pruebas)' : 'Sin conectar'],
          ['Tarjetas autorizadas', String(data.summary.cards)],
          ['Cobros con problema', String(data.summary.failed)],
        ] as [string, string][]).map(([label, value]) => (
          <div key={label} className="rounded-2xl border border-slate-200 px-4 py-3">
            <span className={labelClass}>{label}</span>
            <span className="block text-sm font-bold text-brand-ink" data-billing-fact={label}>{value}</span>
          </div>
        ))}
      </div>
      {data.account && <p className="text-xs text-slate-600">Cuenta: <span className="font-semibold">{data.account}</span>{data.currency ? ` · cobra en ${data.currency}` : ''}</p>}

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-2" role="radiogroup" aria-label="Modo de cobro">
        {([
          ['sandbox', 'Pruebas', 'Credenciales de prueba, para probar con tarjetas de prueba.'],
          ['production', 'Producción', 'Credenciales de producción: lo que paguen las empresas llega a la cuenta.'],
        ] as const).map(([key, label, hint]) => (
          <OptionCard key={key} type="button" role="radio" aria-checked={mode === key} data-mode={key} selected={mode === key} onClick={() => setMode(key)} className="text-left rounded-xl border px-3.5 py-3 transition-colors">
            <span className="block text-sm font-bold text-brand-ink">{label}</span>
            <span className="block text-xs text-slate-500 mt-0.5">{hint}</span>
          </OptionCard>
        ))}
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label className={labelClass} htmlFor="mp-public">Public Key</label>
          <input id="mp-public" maxLength={255} placeholder={data.saved?.public_key ?? 'APP_USR-...'} value={keys.public_key} onChange={(e) => setKeys({ ...keys, public_key: e.target.value })} className={`${inputClass} font-mono`} />
        </div>
        <div>
          <label className={labelClass} htmlFor="mp-token">Access Token</label>
          <input id="mp-token" type="password" autoComplete="off" maxLength={255} placeholder={data.saved?.secret_key ?? 'APP_USR-...'} value={keys.secret_key} onChange={(e) => setKeys({ ...keys, secret_key: e.target.value })} className={`${inputClass} font-mono`} />
        </div>
      </div>

      <div className="rounded-2xl bg-slate-50 border border-slate-200 p-4 space-y-3">
        <span className="block text-xs font-bold text-slate-700">Avisos de Mercado Pago</span>
        <p className="text-xs text-slate-600">En la aplicación de Mercado Pago, entra a Webhooks, pega esta dirección y marca solo los eventos <span className="font-semibold">Order (Mercado Pago)</span> y <span className="font-semibold">Planes y suscripciones</span>. Copia aquí la firma secreta. Mercado Pago admite una sola dirección por aplicación: usa una aplicación para la plataforma y otra para cada empresa. Sin avisos, el sistema igual revisa los cobros cada 15 minutos.</p>
        <div className="flex flex-col sm:flex-row gap-2">
          <input readOnly aria-label="Dirección de avisos de la plataforma" value={data.webhook_url} onFocus={(e) => e.target.select()} className={`${inputClass} font-mono text-xs flex-1`} />
          <button type="button" data-platform-webhook-copy onClick={copy} className="flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-semibold border border-slate-200 bg-white rounded-xl text-slate-700 hover:bg-slate-50 transition-colors">
            <Copy size={14} /> {copied ? '¡Copiada!' : 'Copiar'}
          </button>
        </div>
        <div>
          <label className={labelClass} htmlFor="mp-secret">Clave secreta de los avisos</label>
          <input id="mp-secret" type="password" autoComplete="off" maxLength={255} placeholder={data.saved?.webhook_secret ?? 'Opcional'} value={keys.webhook_secret} onChange={(e) => setKeys({ ...keys, webhook_secret: e.target.value })} className={`${inputClass} font-mono`} />
        </div>
      </div>

      <div className="max-w-xs">
        <label className={labelClass} htmlFor="grace">Días de gracia</label>
        <div className="flex items-stretch border border-slate-200 rounded-xl overflow-hidden bg-white">
          <input id="grace" type="number" min="0" max="30" value={grace} onChange={(e) => setGrace(Number(e.target.value))} className="w-full text-sm px-3.5 py-2.5 text-slate-800 outline-none bg-transparent" />
          <span className="flex items-center px-3 text-xs font-semibold text-slate-500 bg-slate-50 border-l border-slate-200">días</span>
        </div>
        <p className="text-xs text-slate-400 mt-1.5">Después de vencer, la empresa sigue igual estos días. Luego pasa a solo lectura hasta que pague.</p>
      </div>

      {data.saved && <p className="text-xs text-slate-500">Guardadas el {momentOf(data.saved.saved_at)}. Deja los campos vacíos para no cambiarlos.</p>}
      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />

      <div className="flex flex-wrap gap-2">
        <button type="button" data-platform-billing-save disabled={busy} onClick={() => save(data.connected || Boolean(keys.public_key || keys.secret_key))} className="px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
          {busy ? 'Guardando...' : data.connected || keys.secret_key ? 'Guardar y conectar' : 'Guardar cambios'}
        </button>
        <button type="button" data-platform-billing-test disabled={busy} onClick={test} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50 transition-colors">
          <CheckCircle2 size={15} /> Probar conexión
        </button>
        {data.connected && (
          <button type="button" data-platform-billing-off disabled={busy} onClick={() => save(false)} className="ml-auto px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50 rounded-xl transition-colors">
            Desconectar
          </button>
        )}
      </div>
    </section>
  );
}
