'use client';

import OptionCard from '@/app/components/common/OptionCard';
import Badge from '@/app/components/common/Badge';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass } from '@/app/components/ui/forms/styles';
import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, Copy, FileText, ImagePlus, MessageCircle, Plug, QrCode, ShieldCheck, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import { PagedList } from '@/app/components/common/Pager';
import { apiError } from '@/features/appointments/appointments';
import { money, momentOf, type PaymentQr } from '@/features/sales/sales';

interface ProviderOption {
  key: string;
  label: string;
  connectable: boolean;
  saved: { public_key: string | null; secret_key: string | null; webhook_secret?: string | null; saved_at: string } | null;
}

interface PaymentEvent {
  id: string;
  provider: string;
  event: string;
  status: 'processed' | 'duplicate' | 'ignored' | 'refunded' | 'error';
  message: string | null;
  description: string | null;
  amount: number | null;
  currency: string | null;
  at: string;
}

interface IntegrationsPayload {
  payments: { active: string; chosen: string; status: string; mode: string | null; account: string | null; currency: string | null; simulation: boolean; webhook_url: string; providers: ProviderOption[]; link_hours: number };
  events: PaymentEvent[];
  qr: Partial<Record<'yape' | 'plin', PaymentQr>>;
}

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4';
const QR_MAX = 700 * 1024;

const EVENT_TONES: Record<PaymentEvent['status'], string> = {
  processed: 'bg-emerald-50 text-emerald-700',
  duplicate: 'bg-slate-100 text-slate-600',
  ignored: 'bg-slate-100 text-slate-600',
  refunded: 'bg-amber-50 text-amber-800',
  error: 'bg-rose-50 text-rose-700',
};

const EVENT_STATUS: Record<PaymentEvent['status'], string> = {
  processed: 'Registrado',
  duplicate: 'Repetido',
  ignored: 'Sin cambios',
  refunded: 'Devuelto',
  error: 'Error',
};

function QrCard({ method, label, qr, canSave, onSaved }: { method: 'yape' | 'plin'; label: string; qr: PaymentQr | undefined; canSave: boolean; onSaved: (qr: IntegrationsPayload['qr']) => void }) {
  const [image, setImage] = useState<string | null>(null);
  const [holder, setHolder] = useState(qr?.holder ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);

  useEffect(() => { setHolder(qr?.holder ?? ''); }, [qr?.holder]);

  const pick = (file: File | undefined) => {
    setError('');
    setNotice('');
    if (!file) return;
    if (!/^image\/(png|jpe?g|webp)$/.test(file.type)) { setError('Elige una imagen PNG, JPG o WEBP.'); return; }
    if (file.size > QR_MAX) { setError('La imagen pesa demasiado: usa una de menos de 700 KB (una captura del QR basta).'); return; }
    const reader = new FileReader();
    reader.onload = () => setImage(String(reader.result));
    reader.readAsDataURL(file);
  };

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.put(`/payment-qr/${method}`, { image: image ?? qr?.image, holder: holder.trim() || null });
      setImage(null);
      setNotice(`QR de ${label} guardado: aparecerá al cobrar con ${label}.`);
      onSaved(res.data.qr);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el QR.'));
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.delete(`/payment-qr/${method}`);
      setNotice(`Se quitó el QR de ${label}.`);
      onSaved(res.data.qr);
    } catch (err) {
      setError(apiError(err, 'No se pudo quitar el QR.'));
    } finally {
      setBusy(false);
    }
  };

  const shown = image ?? qr?.image ?? null;
  const dirty = Boolean(image) || (qr && holder.trim() !== (qr.holder ?? ''));

  return (
    <div className="rounded-xl border border-slate-200 p-4 flex gap-4" data-qr-card={method}>
      <div className="w-28 h-28 shrink-0 rounded-lg border border-dashed border-slate-300 bg-slate-50 flex items-center justify-center overflow-hidden">
        {shown ? <img src={shown} alt={`QR de ${label}`} className="w-full h-full object-contain bg-white" /> : <QrCode size={32} className="text-slate-300" />}
      </div>
      <div className="flex-1 min-w-0 space-y-2">
        <span className="block text-sm font-bold text-brand-ink">{label}</span>
        <input aria-label={`Titular de ${label}`} maxLength={150} placeholder="A nombre de (opcional)" disabled={!canSave} className={inputClass} value={holder} onChange={(e) => { setHolder(e.target.value); setNotice(''); }} />
        <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" data-qr-file={method} onChange={(e) => { pick(e.target.files?.[0]); e.target.value = ''; }} />
        {canSave && (
          <div className="flex flex-wrap gap-2">
            <button type="button" data-qr-pick={method} disabled={busy} onClick={() => fileRef.current?.click()} className="flex items-center gap-1.5 px-3 py-2 text-sm font-semibold border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors">
              <ImagePlus size={14} /> {shown ? 'Cambiar imagen' : 'Subir imagen'}
            </button>
            {shown && dirty && (
              <button type="button" data-qr-save={method} disabled={busy} onClick={save} className="px-3 py-2 text-sm font-bold bg-brand text-white rounded-lg hover:bg-brand-hover transition-colors disabled:opacity-50">
                {busy ? 'Guardando...' : 'Guardar'}
              </button>
            )}
            {qr && !image && (
              <button type="button" data-qr-remove={method} disabled={busy} onClick={remove} className="flex items-center gap-1 px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 rounded-lg transition-colors">
                <Trash2 size={14} /> Quitar
              </button>
            )}
          </div>
        )}
        {error && <p role="alert" className="text-xs text-red-600">{error}</p>}
        {notice && <p className="text-xs text-emerald-700">{notice}</p>}
      </div>
    </div>
  );
}

export default function IntegrationsSettings({ canSave, onDirtyChange }: { canSave: boolean; onDirtyChange?: (dirty: boolean) => void }) {
  const [data, setData] = useState<IntegrationsPayload | null>(null);
  const [chosen, setChosen] = useState('none');
  const [keys, setKeys] = useState({ public_key: '', secret_key: '', webhook_secret: '' });
  const [mode, setMode] = useState<'sandbox' | 'production'>('sandbox');
  const [copied, setCopied] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const apply = (payload: IntegrationsPayload) => {
    setData(payload);
    setChosen(payload.payments.chosen);
    setMode(payload.payments.mode === 'production' ? 'production' : 'sandbox');
    setKeys({ public_key: '', secret_key: '', webhook_secret: '' });
  };

  useEffect(() => {
    api.get('/integrations')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las integraciones.')));
  }, []);

  const savedMode = data?.payments.mode === 'production' ? 'production' : 'sandbox';
  const dirty = Boolean(data) && (chosen !== data?.payments.chosen || Boolean(keys.public_key || keys.secret_key || keys.webhook_secret) || (chosen === 'mercadopago' && data?.payments.chosen === 'mercadopago' && mode !== savedMode));

  useEffect(() => { onDirtyChange?.(dirty); }, [dirty, onDirtyChange]);

  useEffect(() => () => onDirtyChange?.(false), [onDirtyChange]);

  if (!data) {
    return error ? <section className={cardClass}><p className="text-sm text-slate-600">{error}</p></section> : null;
  }

  const provider = data.payments.providers.find((p) => p.key === chosen);
  const isReal = chosen !== 'simulated' && chosen !== 'none';
  const isMercadoPago = chosen === 'mercadopago';
  const options: ProviderOption[] = [{ key: 'none', label: 'Sin pagos en línea', connectable: true, saved: null }, ...data.payments.providers];

  const copyWebhook = async () => {
    try {
      await navigator.clipboard.writeText(data.payments.webhook_url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  };

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const res = await api.put('/integrations/payments', { provider: chosen, ...(isReal ? { ...keys, mode } : {}) });
      apply(res.data);
      setNotice(isMercadoPago
        ? mode === 'production'
          ? 'Mercado Pago conectado: los links de pago, los adelantos y los paquetes en línea ya cobran de verdad.'
          : 'Mercado Pago conectado en modo de pruebas: los cobros usan tus credenciales de prueba y no mueven dinero real.'
        : isReal
          ? `Guardamos las llaves de ${provider?.label}. Todavía no se cobra con ${provider?.label}: aún no está conectado al sistema.`
          : chosen === 'none'
            ? 'Listo: tu empresa no recibe pagos en línea. No se ofrecen links de pago ni adelantos en línea.'
            : 'Listo: los links de pago usan la simulación.');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar la pasarela.'));
    } finally {
      setBusy(false);
    }
  };

  const test = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.post('/integrations/payments/test');
      if (res.data.ok) setNotice(res.data.message);
      else setError(res.data.message);
    } catch (err) {
      setError(apiError(err, 'La pasarela no respondió.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className={cardClass} data-card="integrations">
      <div>
        <SectionTitle>
          <Plug className="w-4 h-4 text-brand-accent" /> Integraciones
        </SectionTitle>
        <p className="text-xs text-slate-500 font-medium mt-1">
          Los servicios externos que usa tu empresa para cobrar y facturar. Las llaves se guardan cifradas y nunca se muestran completas.
        </p>
      </div>

      <div className="space-y-3">
        <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">Pasarela de pagos</span>
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-2" role="radiogroup" aria-label="Pasarela de pagos">
          {options.map((option) => {
            const active = chosen === option.key;
            const inUse = data.payments.active === option.key;
            return (
              <OptionCard
                key={option.key}
                type="button"
                role="radio"
                aria-checked={active}
                data-provider={option.key}
                disabled={!canSave}
                onClick={() => { setChosen(option.key); setNotice(''); setError(''); }} selected={active} className="text-left rounded-xl border px-3.5 py-3 transition-colors"
              >
                <span className="block text-sm font-bold text-brand-ink">{option.label}</span>
                <span className={`block text-xs mt-0.5 ${inUse ? 'text-emerald-700 font-semibold' : 'text-slate-500'}`}>
                  {inUse ? (option.key === 'none' ? 'Así está' : 'En uso') : option.key === 'simulated' ? 'Solo para probar' : option.key === 'none' ? 'Solo cobras en la sede' : !option.connectable ? 'Próximamente' : option.saved ? 'Llaves guardadas' : 'Lista para conectar'}
                </span>
              </OptionCard>
            );
          })}
        </div>

        {isMercadoPago ? (
          <div className="rounded-xl border border-slate-200 p-4 space-y-4" data-provider-keys>
            <p className="text-sm text-slate-600">
              Copia los datos de tu aplicación en Mercado Pago (Tus integraciones → tu aplicación → Credenciales). El dinero llega a la cuenta de Mercado Pago de tu empresa.
              {data.payments.account && <span className="block mt-1 font-semibold text-brand-ink" data-mp-account>Cuenta conectada: {data.payments.account}{data.payments.currency ? ` · cobra en ${data.payments.currency}` : ''}</span>}
            </p>
            <div className="space-y-1.5">
              <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">Modo</span>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2" role="radiogroup" aria-label="Modo de cobro">
                {([
                  ['sandbox', 'Pruebas', 'Con credenciales de prueba: no se mueve dinero real.'],
                  ['production', 'Real', 'Con credenciales de producción: se cobra de verdad.'],
                ] as const).map(([key, label, hint]) => (
                  <OptionCard key={key} type="button" role="radio" aria-checked={mode === key} data-mode={key} disabled={!canSave} selected={mode === key} onClick={() => { setMode(key); setNotice(''); }} className="text-left rounded-xl border px-3.5 py-3 transition-colors">
                    <span className="block text-sm font-bold text-brand-ink">{label}</span>
                    <span className="block text-xs text-slate-500 mt-0.5">{hint}</span>
                  </OptionCard>
                ))}
              </div>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <label className="space-y-1">
                <span className="block text-xs font-semibold text-slate-600">Public Key</span>
                <input aria-label="Public Key" maxLength={255} placeholder={provider?.saved?.public_key ?? 'APP_USR-...'} disabled={!canSave} className={`${inputClass} font-mono`} value={keys.public_key} onChange={(e) => setKeys({ ...keys, public_key: e.target.value })} />
              </label>
              <label className="space-y-1">
                <span className="block text-xs font-semibold text-slate-600">Access Token</span>
                <input aria-label="Access Token" type="password" autoComplete="off" maxLength={255} placeholder={provider?.saved?.secret_key ?? 'APP_USR-...'} disabled={!canSave} className={`${inputClass} font-mono`} value={keys.secret_key} onChange={(e) => setKeys({ ...keys, secret_key: e.target.value })} />
              </label>
            </div>
            <div className="rounded-lg bg-slate-50 border border-slate-200 p-3 space-y-2" data-webhook>
              <span className="block text-xs font-bold text-slate-700">Avisos de pago (recomendado)</span>
              <p className="text-xs text-slate-600">Para que el pago se confirme al instante, en tu aplicación de Mercado Pago entra a Webhooks, pega esta dirección, marca el evento <span className="font-semibold">Order (Mercado Pago)</span> y guarda. Luego copia aquí la clave secreta que te muestra. Sin esto, el sistema igual revisa los pagos cada pocos minutos.</p>
              <div className="flex flex-col sm:flex-row gap-2">
                <input readOnly aria-label="Dirección de avisos" value={data.payments.webhook_url} onFocus={(e) => e.target.select()} className={`${inputClass} font-mono text-xs flex-1`} />
                <button type="button" data-webhook-copy onClick={copyWebhook} className="flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-semibold border border-slate-200 bg-white rounded-lg text-slate-700 hover:bg-slate-50 transition-colors">
                  <Copy size={14} /> {copied ? '¡Copiada!' : 'Copiar'}
                </button>
              </div>
              <label className="space-y-1 block">
                <span className="block text-xs font-semibold text-slate-600">Clave secreta de los avisos</span>
                <input aria-label="Clave secreta de los avisos" type="password" autoComplete="off" maxLength={255} placeholder={provider?.saved?.webhook_secret ?? 'Opcional'} disabled={!canSave} className={`${inputClass} font-mono`} value={keys.webhook_secret} onChange={(e) => setKeys({ ...keys, webhook_secret: e.target.value })} />
              </label>
            </div>
            {provider?.saved && <p className="text-xs text-slate-500">Guardadas el {momentOf(provider.saved.saved_at)}. Deja los campos vacíos para no cambiarlos.</p>}
          </div>
        ) : isReal ? (
          <div className="rounded-xl border border-slate-200 p-4 space-y-3" data-provider-keys>
            <p className="text-sm text-slate-600">
              {provider?.label} todavía no está conectado al sistema. Puedes dejar guardadas las llaves que te dio; mientras tanto no se ofrecen pagos en línea con {provider?.label}. Para cobrar en línea hoy, elige Mercado Pago.
            </p>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <input aria-label="Llave pública" maxLength={255} placeholder={provider?.saved?.public_key ?? 'Llave pública'} disabled={!canSave} className={`${inputClass} font-mono`} value={keys.public_key} onChange={(e) => setKeys({ ...keys, public_key: e.target.value })} />
              <input aria-label="Llave secreta" maxLength={255} placeholder={provider?.saved?.secret_key ?? 'Llave secreta'} disabled={!canSave} className={`${inputClass} font-mono`} value={keys.secret_key} onChange={(e) => setKeys({ ...keys, secret_key: e.target.value })} />
            </div>
            {provider?.saved && <p className="text-xs text-slate-500">Guardadas el {momentOf(provider.saved.saved_at)}. Déjalas vacías para no cambiarlas.</p>}
          </div>
        ) : chosen === 'none' ? (
          <p className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2" data-no-online>
            Sin pagos en línea no se crean links de pago, los servicios con adelanto no se reservan desde el portal y los paquetes no se venden en línea. Todo lo demás se cobra en la sede como siempre.
          </p>
        ) : (
          <p className="flex items-start gap-2 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            <ShieldCheck size={15} className="shrink-0 mt-0.5" />
            Con la simulación, los links de pago y los adelantos funcionan de punta a punta, pero no se cobra dinero. Solo existe en las pruebas del sistema: en el servidor no aparece.
          </p>
        )}

        <div className="flex flex-wrap gap-2">
          {canSave && (
            <button type="button" data-save-provider disabled={busy || !dirty} onClick={save} className="px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
              {busy ? 'Guardando...' : isMercadoPago ? 'Guardar y conectar' : 'Guardar pasarela'}
            </button>
          )}
          <button type="button" data-test-provider disabled={busy} onClick={test} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50 transition-colors">
            <CheckCircle2 size={15} /> Probar conexión
          </button>
        </div>
        {notice && <p data-integrations-notice className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">{notice}</p>}
        <ErrorAlert message={error} />
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-4 border-t border-slate-100">
        <div className="flex items-start gap-3 rounded-xl border border-slate-200 p-4">
          <span className="p-2 rounded-lg bg-brand-soft/20 text-brand-ink shrink-0"><FileText size={16} /></span>
          <span>
            <span className="block text-sm font-bold text-brand-ink">Facturación electrónica</span>
            <span className="block text-xs text-slate-500 mt-0.5">Simulación de SUNAT: las boletas y facturas aún no se envían. Lista para conectar un proveedor.</span>
          </span>
        </div>
        <div className="flex items-start gap-3 rounded-xl border border-slate-200 p-4">
          <span className="p-2 rounded-lg bg-brand-soft/20 text-brand-ink shrink-0"><MessageCircle size={16} /></span>
          <span>
            <span className="block text-sm font-bold text-brand-ink">Correo y WhatsApp</span>
            <span className="block text-xs text-slate-500 mt-0.5">El correo de tu empresa se configura en Mensajes → <a href="#correo" data-link="correo" className="font-semibold text-brand-accent hover:underline">Correo de tu empresa</a>. WhatsApp queda listo para conectar cuando elijan un proveedor.</span>
          </span>
        </div>
      </div>

      <div className="space-y-3 pt-4 border-t border-slate-100">
        <div>
          <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">QR de tu negocio</span>
          <p className="text-xs text-slate-500 mt-1">Sube la imagen del QR de tu Yape o Plin: al cobrar con ese medio, el panel lo muestra para que el cliente lo escanee. Luego se anota el número de operación, como siempre.</p>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <QrCard method="yape" label="Yape" qr={data.qr.yape} canSave={canSave} onSaved={(qr) => setData({ ...data, qr })} />
          <QrCard method="plin" label="Plin" qr={data.qr.plin} canSave={canSave} onSaved={(qr) => setData({ ...data, qr })} />
        </div>
      </div>

      <div className="pt-4 border-t border-slate-100 space-y-2" data-payment-events>
        <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">Avisos recibidos de la pasarela</span>
        <div className="rounded-xl border border-slate-200 overflow-hidden flex flex-col">
          <PagedList<PaymentEvent>
            items={data.events}
            empty="Aún no hay avisos. Aparecen cuando un cliente paga o intenta pagar un link."
            renderRow={(event) => (
              <li key={event.id} className="flex items-center gap-3 px-5 py-3">
                <Badge tone={EVENT_TONES[event.status]} bold className="shrink-0">{EVENT_STATUS[event.status]}</Badge>
                <span className="flex-1 min-w-0">
                  <span className="block text-sm font-semibold text-slate-800 truncate">{event.event}{event.description ? ` · ${event.description}` : ''}</span>
                  <span className="block text-xs text-slate-500 truncate">{event.message ?? event.provider} · {momentOf(event.at)}</span>
                </span>
                {event.amount !== null && <span className="text-sm font-bold text-slate-800 tabular-nums">{money(event.amount, event.currency)}</span>}
              </li>
            )}
          />
        </div>
      </div>
    </section>
  );
}
