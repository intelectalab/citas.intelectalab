'use client';

import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, FileText, ImagePlus, MessageCircle, Plug, QrCode, ShieldCheck, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import { PagedList } from '@/app/components/common/Pager';
import { apiError } from '@/features/appointments/appointments';
import { money, momentOf, type PaymentQr } from '@/features/sales/sales';

interface ProviderOption {
  key: string;
  label: string;
  connectable: boolean;
  saved: { public_key: string | null; secret_key: string | null; saved_at: string } | null;
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
  payments: { active: string; chosen: string; status: string; providers: ProviderOption[]; link_hours: number };
  events: PaymentEvent[];
  qr: Partial<Record<'yape' | 'plin', PaymentQr>>;
}

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4';
const inputClass = 'w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#022340]/20 focus:border-[#022340] text-slate-800 bg-white';
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
  ignored: 'Sin link',
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
        <span className="block text-sm font-bold text-[#022340]">{label}</span>
        <input aria-label={`Titular de ${label}`} maxLength={150} placeholder="A nombre de (opcional)" disabled={!canSave} className={inputClass} value={holder} onChange={(e) => { setHolder(e.target.value); setNotice(''); }} />
        <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" data-qr-file={method} onChange={(e) => { pick(e.target.files?.[0]); e.target.value = ''; }} />
        {canSave && (
          <div className="flex flex-wrap gap-2">
            <button type="button" data-qr-pick={method} disabled={busy} onClick={() => fileRef.current?.click()} className="flex items-center gap-1.5 px-3 py-2 text-sm font-semibold border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors">
              <ImagePlus size={14} /> {shown ? 'Cambiar imagen' : 'Subir imagen'}
            </button>
            {shown && dirty && (
              <button type="button" data-qr-save={method} disabled={busy} onClick={save} className="px-3 py-2 text-sm font-bold bg-[#022340] text-white rounded-lg hover:bg-[#03315a] transition-colors disabled:opacity-50">
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

export default function IntegrationsSettings({ canSave }: { canSave: boolean }) {
  const [data, setData] = useState<IntegrationsPayload | null>(null);
  const [chosen, setChosen] = useState('simulated');
  const [keys, setKeys] = useState({ public_key: '', secret_key: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const apply = (payload: IntegrationsPayload) => {
    setData(payload);
    setChosen(payload.payments.chosen);
    setKeys({ public_key: '', secret_key: '' });
  };

  useEffect(() => {
    api.get('/integrations')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las integraciones.')));
  }, []);

  if (!data) {
    return error ? <section className={cardClass}><p className="text-sm text-slate-600">{error}</p></section> : null;
  }

  const provider = data.payments.providers.find((p) => p.key === chosen);
  const isReal = chosen !== 'simulated';

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const res = await api.put('/integrations/payments', { provider: chosen, ...(isReal ? keys : {}) });
      apply(res.data);
      setNotice(isReal
        ? `Guardamos las llaves de ${provider?.label}. Todavía no se cobra con ${provider?.label}: falta conectar este proveedor con su documentación y una cuenta de pruebas. Mientras tanto, los links usan la simulación.`
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
      setNotice(res.data.message);
    } catch (err) {
      setError(apiError(err, 'La pasarela no respondió.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className={cardClass} data-card="integrations">
      <div>
        <h2 className="flex items-center gap-2 text-sm font-extrabold text-[#022340] uppercase tracking-wider">
          <Plug className="w-4 h-4 text-[#3F768C]" /> Integraciones
        </h2>
        <p className="text-xs text-slate-500 font-medium mt-1">
          Los servicios externos que usa tu empresa para cobrar y facturar. Las llaves se guardan cifradas y nunca se muestran completas.
        </p>
      </div>

      <div className="space-y-3">
        <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">Pasarela de pagos</span>
        <div className="grid grid-cols-1 md:grid-cols-5 gap-2" role="radiogroup" aria-label="Pasarela de pagos">
          {data.payments.providers.map((option) => {
            const active = chosen === option.key;
            const inUse = data.payments.active === option.key;
            return (
              <button
                key={option.key}
                type="button"
                role="radio"
                aria-checked={active}
                data-provider={option.key}
                disabled={!canSave}
                onClick={() => { setChosen(option.key); setNotice(''); setError(''); }}
                className={`text-left rounded-xl border px-3.5 py-3 transition-colors ${active ? 'border-[#022340] bg-[#84D0D9]/15 ring-2 ring-[#022340]/10' : 'border-slate-200 hover:bg-slate-50'}`}
              >
                <span className="block text-sm font-bold text-[#022340]">{option.label}</span>
                <span className={`block text-xs mt-0.5 ${inUse ? 'text-emerald-700 font-semibold' : 'text-slate-500'}`}>
                  {inUse ? 'En uso' : option.key === 'simulated' ? 'Para probar' : option.saved ? 'Llaves guardadas' : 'Lista para conectar'}
                </span>
              </button>
            );
          })}
        </div>

        {isReal ? (
          <div className="rounded-xl border border-slate-200 p-4 space-y-3" data-provider-keys>
            <p className="text-sm text-slate-600">
              Para cobrar de verdad con {provider?.label} hace falta su documentación y una cuenta de pruebas, para conectarlo y comprobar que el dinero llega. Puedes dejar guardadas las llaves que te dio {provider?.label}; mientras no esté conectado, los links siguen usando la simulación.
            </p>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <input aria-label="Llave pública" maxLength={255} placeholder={provider?.saved?.public_key ?? 'Llave pública'} disabled={!canSave} className={`${inputClass} font-mono`} value={keys.public_key} onChange={(e) => setKeys({ ...keys, public_key: e.target.value })} />
              <input aria-label="Llave secreta" maxLength={255} placeholder={provider?.saved?.secret_key ?? 'Llave secreta'} disabled={!canSave} className={`${inputClass} font-mono`} value={keys.secret_key} onChange={(e) => setKeys({ ...keys, secret_key: e.target.value })} />
            </div>
            {provider?.saved && <p className="text-xs text-slate-500">Guardadas el {momentOf(provider.saved.saved_at)}. Déjalas vacías para no cambiarlas.</p>}
          </div>
        ) : (
          <p className="flex items-start gap-2 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            <ShieldCheck size={15} className="shrink-0 mt-0.5" />
            Con la simulación, los links de pago y los adelantos funcionan de punta a punta, pero no se cobra dinero: la página de pago trae botones para simular que el cliente pagó o que su tarjeta fue rechazada.
          </p>
        )}

        <div className="flex flex-wrap gap-2">
          {canSave && (
            <button type="button" data-save-provider disabled={busy || (chosen === data.payments.chosen && !isReal)} onClick={save} className="px-4 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50">
              {busy ? 'Guardando...' : 'Guardar pasarela'}
            </button>
          )}
          <button type="button" data-test-provider disabled={busy} onClick={test} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50 transition-colors">
            <CheckCircle2 size={15} /> Probar conexión
          </button>
        </div>
        {notice && <p data-integrations-notice className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">{notice}</p>}
        {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-4 border-t border-slate-100">
        <div className="flex items-start gap-3 rounded-xl border border-slate-200 p-4">
          <span className="p-2 rounded-lg bg-[#84D0D9]/20 text-[#022340] shrink-0"><FileText size={16} /></span>
          <span>
            <span className="block text-sm font-bold text-[#022340]">Facturación electrónica</span>
            <span className="block text-xs text-slate-500 mt-0.5">Simulación de SUNAT: las boletas y facturas aún no se envían. Lista para conectar un proveedor.</span>
          </span>
        </div>
        <div className="flex items-start gap-3 rounded-xl border border-slate-200 p-4">
          <span className="p-2 rounded-lg bg-[#84D0D9]/20 text-[#022340] shrink-0"><MessageCircle size={16} /></span>
          <span>
            <span className="block text-sm font-bold text-[#022340]">WhatsApp y correo</span>
            <span className="block text-xs text-slate-500 mt-0.5">Llega con Marketing. Por ahora los links de pago se copian y se envían a mano.</span>
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
                <span className={`px-2.5 py-1 text-xs rounded-full font-semibold shrink-0 ${EVENT_TONES[event.status]}`}>{EVENT_STATUS[event.status]}</span>
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
