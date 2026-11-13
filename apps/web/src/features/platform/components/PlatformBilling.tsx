'use client';

import SectionTitle from '@/app/components/common/SectionTitle';
import OptionCard from '@/app/components/common/OptionCard';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useEffect, useState } from 'react';
import { CalendarClock, CheckCircle2, Copy, CreditCard } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';

type ProviderKey = 'culqi' | 'mercadopago';

interface ProviderData {
  key: ProviderKey;
  label: string;
  connected: boolean;
  mode: 'sandbox' | 'production' | null;
  account: string | null;
  currency: string | null;
  saved: { public_key: string | null; secret_key: string | null; webhook_secret: string | null; saved_at: string } | null;
  webhook_url: string;
}

interface Dunning {
  retry_days: number[];
  remind_days: number[];
  readonly_day: number;
  cancel_day: number;
}

interface PlatformBillingData {
  providers: ProviderData[];
  primary: ProviderKey;
  active: ProviderKey | null;
  connected: boolean;
  simulation: boolean;
  dunning: Dunning;
  dunning_ready: boolean;
  summary: { cards: number; failed: number; online: number; overdue: number };
}

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 space-y-4';
const labelClass = 'block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1.5';
const inputClass = 'w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none';

const KEY_TEXT: Record<ProviderKey, { public: string; secret: string; placeholderPublic: string; placeholderSecret: string; where: string }> = {
  culqi: {
    public: 'Llave pública',
    secret: 'Llave secreta',
    placeholderPublic: 'pk_test_... o pk_live_...',
    placeholderSecret: 'sk_test_... o sk_live_...',
    where: 'Están en el panel de Culqi, en Desarrollo → API Keys. Con las llaves de prueba (pk_test, sk_test) no se mueve dinero; con las de producción (pk_live, sk_live) sí.',
  },
  mercadopago: {
    public: 'Public Key',
    secret: 'Access Token',
    placeholderPublic: 'APP_USR-...',
    placeholderSecret: 'APP_USR-...',
    where: 'Están en tu aplicación de Mercado Pago, en Credenciales.',
  },
};

const daysText = (days: number[]) => days.join(', ');
const parseDays = (text: string) => Array.from(new Set(text.split(/[\s,;]+/).filter(Boolean).map(Number).filter((n) => Number.isInteger(n) && n >= 0 && n <= 60))).sort((a, b) => a - b);

export default function PlatformBilling() {
  const [data, setData] = useState<PlatformBillingData | null>(null);
  const [tab, setTab] = useState<ProviderKey>('culqi');
  const [keys, setKeys] = useState({ public_key: '', secret_key: '', webhook_secret: '' });
  const [mode, setMode] = useState<'sandbox' | 'production'>('sandbox');
  const [dunning, setDunning] = useState({ retry: '', remind: '', readonly: 15, cancel: 45 });
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [copied, setCopied] = useState(false);

  const apply = (payload: PlatformBillingData, keepTab = true) => {
    setData(payload);
    const current = payload.providers.find((p) => p.key === (keepTab ? tab : payload.primary));
    setMode(current?.mode === 'production' ? 'production' : 'sandbox');
    setKeys({ public_key: '', secret_key: '', webhook_secret: '' });
    setDunning({ retry: daysText(payload.dunning.retry_days), remind: daysText(payload.dunning.remind_days), readonly: payload.dunning.readonly_day, cancel: payload.dunning.cancel_day });
  };

  useEffect(() => {
    api.get('/admin/billing')
      .then((res) => { setTab(res.data.primary); apply(res.data, false); })
      .catch((err) => setError(apiError(err, 'No se pudo cargar el cobro de la plataforma.')));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (!data) {
    return error ? <section className={card}><ErrorAlert message={error} /></section> : null;
  }

  const provider = data.providers.find((p) => p.key === tab) ?? data.providers[0];
  const text = KEY_TEXT[provider.key];
  const other = data.providers.find((p) => p.key !== data.primary);

  const run = async (action: string, request: () => Promise<{ data: PlatformBillingData }>, success: (payload: PlatformBillingData) => string) => {
    try {
      setBusy(action);
      setError('');
      setNotice('');
      const res = await request();
      apply(res.data);
      setNotice(success(res.data));
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy('');
    }
  };

  const saveProvider = (connect: boolean) => run(
    connect ? 'save' : 'off',
    () => api.put('/admin/billing', { provider: provider.key, connect, mode, ...keys }),
    (payload) => {
      const saved = payload.providers.find((p) => p.key === provider.key);
      if (!connect) return `Listo: ${provider.label} quedó desconectado.`;
      return saved?.mode === 'production'
        ? `${provider.label} quedó conectado. Los planes se cobran con la cuenta de la plataforma.`
        : `${provider.label} quedó conectado en modo de pruebas: lo que se pague no llega a la cuenta.`;
    },
  );

  const choosePrimary = (key: ProviderKey) => run(
    'primary',
    () => api.put('/admin/billing', { primary: key }),
    () => `Listo: ${key === 'culqi' ? 'Culqi' : 'Mercado Pago'} es la pasarela principal.`,
  );

  const saveDunning = () => run(
    'dunning',
    () => api.put('/admin/billing/dunning', { retry_days: parseDays(dunning.retry), remind_days: parseDays(dunning.remind), readonly_day: dunning.readonly, cancel_day: dunning.cancel }),
    () => 'Listo: guardamos los días de cobro, avisos, solo lectura y cancelación.',
  );

  const test = async () => {
    try {
      setBusy('test');
      setError('');
      setNotice('');
      const res = await api.post('/admin/billing/test', { provider: provider.key });
      if (res.data.ok) setNotice(res.data.message);
      else setError(res.data.message);
    } catch (err) {
      setError(apiError(err, `${provider.label} no respondió.`));
    } finally {
      setBusy('');
    }
  };

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(provider.webhook_url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  };

  const activeLabel = data.active ? data.providers.find((p) => p.key === data.active)?.label : null;

  return (
    <section className={card} data-card="platform-billing">
      <div>
        <SectionTitle><span className="text-brand-accent"><CreditCard size={16} /></span> Cobro de los planes</SectionTitle>
        <p className="text-xs text-slate-500 font-medium mt-1">
          Las cuentas de la plataforma con las que las empresas guardan su tarjeta y pagan su plan. Son distintas de las de cada empresa, que cobran a sus propios clientes.
        </p>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        {([
          ['Se cobra con', activeLabel ? `${activeLabel}${data.active !== data.primary ? ' (respaldo)' : ''}` : data.simulation ? 'Simulación (solo pruebas)' : 'Sin conectar'],
          ['Tarjetas guardadas', String(data.summary.cards)],
          ['Pagos vencidos', String(data.summary.overdue)],
        ] as [string, string][]).map(([label, value]) => (
          <div key={label} className="rounded-2xl border border-slate-200 px-4 py-3">
            <span className={labelClass}>{label}</span>
            <span className="block text-sm font-bold text-brand-ink" data-billing-fact={label}>{value}</span>
          </div>
        ))}
      </div>

      <div className="space-y-2">
        <span className={labelClass}>Pasarela principal</span>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2" role="radiogroup" aria-label="Pasarela principal">
          {data.providers.map((p) => (
            <OptionCard key={p.key} type="button" role="radio" aria-checked={data.primary === p.key} data-primary={p.key} disabled={busy !== ''} selected={data.primary === p.key} onClick={() => data.primary !== p.key && choosePrimary(p.key)} className="text-left rounded-xl border px-3.5 py-3 transition-colors">
              <span className="block text-sm font-bold text-brand-ink">{p.label}</span>
              <span className={`block text-xs mt-0.5 ${p.connected ? 'text-emerald-700 font-semibold' : 'text-slate-500'}`}>{p.connected ? `Conectada${p.mode === 'production' ? ' · producción' : ' · pruebas'}` : 'Sin conectar'}</span>
            </OptionCard>
          ))}
        </div>
        <p className="text-xs text-slate-500">
          Las empresas pagan con la principal. Si no está conectada, o si no responde al pagar, se usa {other?.label ?? 'la otra'} como respaldo. Las tarjetas ya guardadas siguen cobrándose con la pasarela donde se guardaron.
        </p>
      </div>

      <div className="rounded-2xl border border-slate-200 p-4 space-y-4" data-provider-panel={provider.key}>
        <div className="flex gap-2" role="tablist" aria-label="Cuenta a configurar">
          {data.providers.map((p) => (
            <button key={p.key} type="button" role="tab" aria-selected={tab === p.key} data-provider-tab={p.key} onClick={() => { setTab(p.key); setMode(p.mode === 'production' ? 'production' : 'sandbox'); setKeys({ public_key: '', secret_key: '', webhook_secret: '' }); setNotice(''); setError(''); }} className={`px-3.5 py-2 text-sm font-bold rounded-xl transition-colors ${tab === p.key ? 'bg-brand-soft/30 text-brand-ink' : 'text-slate-500 hover:bg-slate-50'}`}>
              Cuenta de {p.label}
            </button>
          ))}
        </div>

        <p className="text-xs text-slate-600">{text.where}{provider.account ? <span className="block mt-1 font-semibold text-brand-ink">Cuenta: {provider.account}{provider.currency ? ` · cobra en ${provider.currency}` : ''}</span> : null}</p>

        {provider.key === 'mercadopago' && (
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
        )}

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className={labelClass} htmlFor={`${provider.key}-public`}>{text.public}</label>
            <input id={`${provider.key}-public`} maxLength={255} placeholder={provider.saved?.public_key ?? text.placeholderPublic} value={keys.public_key} onChange={(e) => setKeys({ ...keys, public_key: e.target.value })} className={`${inputClass} font-mono`} />
          </div>
          <div>
            <label className={labelClass} htmlFor={`${provider.key}-secret`}>{text.secret}</label>
            <input id={`${provider.key}-secret`} type="password" autoComplete="off" maxLength={255} placeholder={provider.saved?.secret_key ?? text.placeholderSecret} value={keys.secret_key} onChange={(e) => setKeys({ ...keys, secret_key: e.target.value })} className={`${inputClass} font-mono`} />
          </div>
        </div>

        <div className="rounded-2xl bg-slate-50 border border-slate-200 p-4 space-y-3">
          <span className="block text-xs font-bold text-slate-700">Avisos de {provider.label}</span>
          <p className="text-xs text-slate-600">
            {provider.key === 'culqi'
              ? 'En el panel de Culqi, entra a Desarrollo → Eventos (webhooks), pega esta dirección y marca los eventos de cargos. Cada aviso se confirma volviendo a preguntar a Culqi con la llave secreta, así que no hace falta una clave aparte.'
              : 'En la aplicación de Mercado Pago, entra a Webhooks, pega esta dirección y marca solo los eventos Order (Mercado Pago) y Planes y suscripciones. Copia aquí la firma secreta. Sin avisos, el sistema igual revisa los cobros cada 15 minutos.'}
          </p>
          <div className="flex flex-col sm:flex-row gap-2">
            <input readOnly aria-label={`Dirección de avisos de ${provider.label}`} value={provider.webhook_url} onFocus={(e) => e.target.select()} className={`${inputClass} font-mono text-xs flex-1`} />
            <button type="button" data-platform-webhook-copy onClick={copy} className="flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-semibold border border-slate-200 bg-white rounded-xl text-slate-700 hover:bg-slate-50 transition-colors">
              <Copy size={14} /> {copied ? '¡Copiada!' : 'Copiar'}
            </button>
          </div>
          {provider.key === 'mercadopago' && (
            <div>
              <label className={labelClass} htmlFor="mp-secret">Clave secreta de los avisos</label>
              <input id="mp-secret" type="password" autoComplete="off" maxLength={255} placeholder={provider.saved?.webhook_secret ?? 'Opcional'} value={keys.webhook_secret} onChange={(e) => setKeys({ ...keys, webhook_secret: e.target.value })} className={`${inputClass} font-mono`} />
            </div>
          )}
        </div>

        {provider.saved && <p className="text-xs text-slate-500">Guardadas el {momentOf(provider.saved.saved_at)}. Deja los campos vacíos para no cambiarlas.</p>}

        <div className="flex flex-wrap gap-2">
          <button type="button" data-platform-billing-save disabled={busy !== '' || (!provider.connected && !(keys.public_key && keys.secret_key)) || (provider.connected && !keys.public_key && !keys.secret_key && !keys.webhook_secret && (provider.key !== 'mercadopago' || mode === (provider.mode ?? 'sandbox')))} onClick={() => saveProvider(true)} className="px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
            {busy === 'save' ? 'Guardando...' : provider.connected ? 'Guardar cambios' : `Conectar ${provider.label}`}
          </button>
          <button type="button" data-platform-billing-test disabled={busy !== ''} onClick={test} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50 transition-colors">
            <CheckCircle2 size={15} /> Probar conexión
          </button>
          {provider.connected && (
            <button type="button" data-platform-billing-off disabled={busy !== ''} onClick={() => saveProvider(false)} className="ml-auto px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50 rounded-xl transition-colors">
              Desconectar
            </button>
          )}
        </div>
      </div>

      <div className="rounded-2xl border border-slate-200 p-4 space-y-4" data-dunning-settings>
        <div>
          <span className="flex items-center gap-2 text-sm font-bold text-brand-ink"><CalendarClock size={15} className="text-brand-accent" /> Si una empresa no paga</span>
          <p className="text-xs text-slate-500 mt-1">Días contados desde que vence el plan. Los cobros se hacen con la tarjeta guardada en Culqi; las tarjetas de Mercado Pago las reintenta Mercado Pago. Los avisos van por correo y dentro del sistema.</p>
        </div>
        {!data.dunning_ready && <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Falta actualizar la base de datos para la mora: ejecuta php artisan migrate.</p>}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className={labelClass} htmlFor="retry-days">Volver a cobrar los días</label>
            <input id="retry-days" value={dunning.retry} onChange={(e) => setDunning({ ...dunning, retry: e.target.value })} placeholder="0, 1, 3, 7" className={inputClass} />
            <p className="text-xs text-slate-400 mt-1">El 0 es el mismo día en que vence.</p>
          </div>
          <div>
            <label className={labelClass} htmlFor="remind-days">Avisar los días</label>
            <input id="remind-days" value={dunning.remind} onChange={(e) => setDunning({ ...dunning, remind: e.target.value })} placeholder="1, 3, 7, 12" className={inputClass} />
          </div>
          <div>
            <label className={labelClass} htmlFor="readonly-day">Queda en solo lectura el día</label>
            <input id="readonly-day" type="number" min={1} max={60} value={dunning.readonly} onChange={(e) => setDunning({ ...dunning, readonly: Number(e.target.value) })} className={inputClass} />
            <p className="text-xs text-slate-400 mt-1">Puede ver, exportar y pagar, pero no agendar ni vender.</p>
          </div>
          <div>
            <label className={labelClass} htmlFor="cancel-day">Se cancela el plan el día</label>
            <input id="cancel-day" type="number" min={2} max={180} value={dunning.cancel} onChange={(e) => setDunning({ ...dunning, cancel: Number(e.target.value) })} className={inputClass} />
            <p className="text-xs text-slate-400 mt-1">Sus datos se conservan; si paga, vuelve a tener todo.</p>
          </div>
        </div>
        <button type="button" data-dunning-save disabled={busy !== '' || !data.dunning_ready} onClick={saveDunning} className="px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
          {busy === 'dunning' ? 'Guardando...' : 'Guardar días'}
        </button>
      </div>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
    </section>
  );
}
