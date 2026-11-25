'use client';

import SectionTitle from '@/app/components/common/SectionTitle';
import OptionCard from '@/app/components/common/OptionCard';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useEffect, useState } from 'react';
import { Bot, CheckCircle2, Plug, ShieldCheck } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import IntegrationLogList, { type IntegrationLogEntry } from '@/features/settings/components/IntegrationLogList';
import GatewayWebhookList from '@/features/settings/components/GatewayWebhookList';

interface Payload {
  ai: {
    own: { provider: string; label: string; model: string | null; key_saved: string | null; saved_at: string | null } | null;
    providers: { key: string; label: string; default_model: string }[];
  };
  captcha: {
    on: boolean;
    source: 'platform' | 'env';
    site_key: string | null;
    secret_saved: string | null;
    places: string[];
    all_places: { key: string; label: string }[];
  };
  log: IntegrationLogEntry[];
}

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 space-y-4';
const labelClass = 'block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1.5';
const inputClass = 'w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none font-mono';

export default function PlatformIntegrationsCard() {
  const [data, setData] = useState<Payload | null>(null);
  const [ai, setAi] = useState({ provider: 'anthropic', api_key: '', model: '' });
  const [captcha, setCaptcha] = useState({ site_key: '', secret_key: '', places: [] as string[] });
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const apply = (payload: Payload) => {
    setData(payload);
    setAi({ provider: payload.ai.own?.provider ?? 'anthropic', api_key: '', model: payload.ai.own?.model ?? '' });
    setCaptcha({ site_key: payload.captcha.source === 'platform' ? payload.captcha.site_key ?? '' : '', secret_key: '', places: payload.captcha.source === 'platform' ? payload.captcha.places : payload.captcha.all_places.map((p) => p.key) });
  };

  useEffect(() => {
    api.get('/admin/integrations').then((res) => apply(res.data)).catch((err) => setError(apiError(err, 'No se pudieron cargar las integraciones.')));
  }, []);

  if (!data) return error ? <section className={card}><ErrorAlert message={error} /></section> : null;

  const run = async (key: string, call: () => Promise<{ data: Payload & { ok?: boolean; message?: string } }>, done: (res: { ok?: boolean; message?: string }) => void) => {
    try {
      setBusy(key);
      setError('');
      setNotice('');
      const res = await call();
      if (res.data.ai) apply(res.data);
      done(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy('');
    }
  };

  const chosen = data.ai.providers.find((p) => p.key === ai.provider);
  const togglePlace = (key: string, on: boolean) => setCaptcha((prev) => ({ ...prev, places: on ? [...prev.places, key] : prev.places.filter((p) => p !== key) }));

  return (
    <section className={card} data-card="platform-integrations">
      <div>
        <SectionTitle><span className="text-brand-accent"><Plug size={16} /></span> Integraciones de la plataforma</SectionTitle>
        <p className="text-xs text-slate-500 font-medium mt-1">Servicios que usa la plataforma para todas las empresas. Las claves se guardan cifradas y nunca se muestran completas. Sin claves, cada integración queda apagada.</p>
      </div>

      <div className="rounded-2xl border border-slate-200 p-4 space-y-3" data-platform-ai>
        <span className="flex items-center gap-2 text-sm font-bold text-brand-ink"><Bot size={15} /> Inteligencia artificial del asistente de WhatsApp</span>
        <p className="text-xs text-slate-500">Esta clave la usan las empresas que encienden su asistente y no pegaron una propia. El consumo lo paga la cuenta de esta clave. {data.ai.own ? `Hoy: ${data.ai.own.label}${data.ai.own.saved_at ? `, guardada el ${momentOf(data.ai.own.saved_at)}` : ''}.` : 'Hoy no hay clave: el asistente no responde por su cuenta.'}</p>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2" role="radiogroup" aria-label="Proveedor de inteligencia artificial">
          {data.ai.providers.map((p) => (
            <OptionCard key={p.key} type="button" role="radio" aria-checked={ai.provider === p.key} data-platform-ai-provider={p.key} selected={ai.provider === p.key} onClick={() => setAi({ ...ai, provider: p.key })} className="text-left rounded-xl border px-3.5 py-2.5">
              <span className="block text-sm font-bold text-brand-ink">{p.label}</span>
              <span className="block text-xs text-slate-500">Modelo por defecto: {p.default_model}</span>
            </OptionCard>
          ))}
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className={labelClass} htmlFor="pi-ai-key">Clave (API key)</label>
            <input id="pi-ai-key" type="password" autoComplete="new-password" maxLength={400} value={ai.api_key} onChange={(e) => setAi({ ...ai, api_key: e.target.value.trim() })} placeholder={data.ai.own?.provider === ai.provider ? data.ai.own.key_saved ?? '' : ''} className={inputClass} />
          </div>
          <div>
            <label className={labelClass} htmlFor="pi-ai-model">Modelo (opcional)</label>
            <input id="pi-ai-model" maxLength={80} value={ai.model} onChange={(e) => setAi({ ...ai, model: e.target.value.trim() })} placeholder={chosen?.default_model ?? ''} className={inputClass} />
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <button type="button" data-platform-ai-save disabled={busy !== '' || (!ai.api_key && data.ai.own?.provider !== ai.provider)} onClick={() => run('ai', () => api.put('/admin/integrations/ai', { provider: ai.provider, api_key: ai.api_key || null, model: ai.model || null }), (res) => setNotice(res.message ?? 'Clave guardada.'))} className="px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
            {busy === 'ai' ? 'Probando...' : 'Probar y guardar'}
          </button>
          <button type="button" data-platform-ai-test disabled={busy !== '' || !data.ai.own} onClick={() => run('ai-test', () => api.post('/admin/integrations/ai/test'), (res) => (res.ok ? setNotice(res.message ?? 'Conexión correcta.') : setError(res.message ?? 'Falló la prueba.')))} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50 disabled:opacity-50">
            <CheckCircle2 size={14} /> Probar conexión
          </button>
          {data.ai.own && (
            <button type="button" data-platform-ai-remove disabled={busy !== ''} onClick={() => run('ai-remove', () => api.delete('/admin/integrations/ai'), () => setNotice('Se quitó la clave de la plataforma.'))} className="ml-auto px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50 rounded-xl">
              Quitar la clave
            </button>
          )}
        </div>
      </div>

      <div className="rounded-2xl border border-slate-200 p-4 space-y-3" data-platform-captcha>
        <span className="flex items-center gap-2 text-sm font-bold text-brand-ink"><ShieldCheck size={15} /> Verificación anti-robots (Cloudflare Turnstile)</span>
        <p className="text-xs text-slate-500">Pide una verificación rápida antes de enviar formularios públicos para frenar a los robots. Crea un widget en Cloudflare → Turnstile con el dominio de la plataforma y pega sus dos claves. {data.captcha.on ? 'Hoy está encendida.' : 'Hoy está apagada.'}</p>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className={labelClass} htmlFor="pi-site">Clave del sitio (site key)</label>
            <input id="pi-site" maxLength={120} value={captcha.site_key} onChange={(e) => setCaptcha({ ...captcha, site_key: e.target.value.trim() })} placeholder="0x4AAAAAA..." className={inputClass} />
          </div>
          <div>
            <label className={labelClass} htmlFor="pi-secret">Clave secreta (secret key)</label>
            <input id="pi-secret" type="password" autoComplete="new-password" maxLength={200} value={captcha.secret_key} onChange={(e) => setCaptcha({ ...captcha, secret_key: e.target.value.trim() })} placeholder={data.captcha.source === 'platform' ? data.captcha.secret_saved ?? '' : ''} className={inputClass} />
          </div>
        </div>
        <div className="rounded-xl border border-slate-200 divide-y divide-slate-100">
          {data.captcha.all_places.map((place) => (
            <div key={place.key} className="flex items-center justify-between gap-4 px-4 py-3" data-captcha-place={place.key}>
              <span className="text-sm font-semibold text-slate-800">{place.label}</span>
              <Switch checked={captcha.places.includes(place.key)} onChange={(on) => togglePlace(place.key, on)} label={`Pedir verificación en ${place.label}`} />
            </div>
          ))}
        </div>
        <div className="flex flex-wrap gap-2">
          <button type="button" data-platform-captcha-save disabled={busy !== '' || !captcha.site_key} onClick={() => run('captcha', () => api.put('/admin/integrations/captcha', captcha), () => setNotice('Verificación guardada: Cloudflare reconoce la clave secreta.'))} className="px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
            {busy === 'captcha' ? 'Comprobando...' : 'Probar y guardar'}
          </button>
          {data.captcha.source === 'platform' && (
            <button type="button" data-platform-captcha-remove disabled={busy !== ''} onClick={() => run('captcha-remove', () => api.delete('/admin/integrations/captcha'), () => setNotice('Se quitó la verificación anti-robots.'))} className="ml-auto px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50 rounded-xl">
              Apagar y quitar claves
            </button>
          )}
        </div>
      </div>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
      <IntegrationLogList entries={data.log} showKind />
      <GatewayWebhookList endpoint="/admin/payment-webhooks" canRetry hint="Avisos de Culqi y Mercado Pago sobre los cobros de los planes. Un aviso repetido no cobra dos veces; si uno falla, se reintenta solo y también lo puedes reintentar aquí." />
    </section>
  );
}
