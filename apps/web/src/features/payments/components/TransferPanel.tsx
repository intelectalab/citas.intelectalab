'use client';

import { useState } from 'react';
import { Copy, FileUp, Landmark, Loader2, QrCode, Send } from 'lucide-react';
import { api } from '@/lib/axios';
import DateField from '@/app/components/common/DateField';
import { addDays, apiError, todayISO } from '@/features/appointments/appointments';

export type TransferMethod = {
  id: string;
  method: 'transfer' | 'yape' | 'plin';
  bank?: string;
  holder?: string;
  account_number?: string;
  cci?: string;
  phone?: string;
  qr?: string;
};

export type TransferOptions = { methods: TransferMethod[]; review_text: string; hold_hours: number | null };

const MAX_BYTES = 5 * 1024 * 1024;
const NAMES = { transfer: 'Transferencia', yape: 'Yape', plin: 'Plin' } as const;

const title = (m: TransferMethod) => (m.method === 'transfer' ? m.bank ?? 'Banco' : NAMES[m.method]);

function CopyRow({ label, value }: { label: string; value?: string }) {
  const [copied, setCopied] = useState(false);
  if (!value) return null;
  return (
    <div className="flex items-center justify-between gap-2 text-sm">
      <span className="text-slate-500">{label}</span>
      <button type="button" onClick={() => { navigator.clipboard?.writeText(value).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1500); }).catch(() => {}); }} className="inline-flex items-center gap-1.5 font-semibold text-slate-800 tabular-nums hover:text-brand-accent">
        {value} <Copy size={13} className="text-slate-400" /> {copied && <span className="text-[11px] text-emerald-600">Copiado</span>}
      </button>
    </div>
  );
}

export default function TransferPanel({ token, options, accent, amountLabel, onSent }: { token: string; options: TransferOptions; accent: string; amountLabel: string; onSent: (link: unknown, message: string) => void }) {
  const [methodId, setMethodId] = useState(options.methods[0]?.id ?? '');
  const [operation, setOperation] = useState('');
  const [paidOn, setPaidOn] = useState(todayISO());
  const [file, setFile] = useState<{ name: string; data: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const method = options.methods.find((m) => m.id === methodId);

  const pick = (input: HTMLInputElement) => {
    const chosen = input.files?.[0];
    input.value = '';
    if (!chosen) return;
    if (!/^(image\/(png|jpe?g|webp)|application\/pdf)$/.test(chosen.type)) { setError('Sube una foto (PNG, JPG o WEBP) o un PDF del comprobante.'); return; }
    if (chosen.size > MAX_BYTES) { setError('El comprobante pesa demasiado: usa uno de menos de 5 MB (una captura basta).'); return; }
    const reader = new FileReader();
    reader.onload = () => { setFile({ name: chosen.name, data: String(reader.result) }); setError(''); };
    reader.readAsDataURL(chosen);
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) { setError('Sube la foto o el PDF del comprobante.'); return; }
    try {
      setBusy(true);
      setError('');
      const res = await api.post(`/pay/${token}/voucher`, { method_id: methodId, operation_number: operation.trim(), paid_on: paidOn, voucher: file.data });
      onSent(res.data.link, res.data.message);
    } catch (err) {
      setError(apiError(err, 'No se pudo enviar el comprobante. Inténtalo de nuevo.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="space-y-4" data-pay-transfer>
      <p className="text-sm text-slate-600">Paga <b>{amountLabel}</b> a una de estas cuentas y luego sube tu comprobante. La empresa lo revisa {options.review_text}.</p>

      <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="A dónde pagas">
        {options.methods.map((m) => (
          <button key={m.id} type="button" role="radio" aria-checked={m.id === methodId} data-transfer-method={m.id} onClick={() => setMethodId(m.id)} className={`inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-xl border transition-colors ${m.id === methodId ? 'text-white border-transparent' : 'text-slate-700 border-slate-200 bg-white hover:bg-slate-50'}`} style={m.id === methodId ? { background: accent } : undefined}>
            {m.method === 'transfer' ? <Landmark size={15} /> : <QrCode size={15} />} {title(m)}
          </button>
        ))}
      </div>

      {method && (
        <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4 space-y-2" data-transfer-details>
          {method.method === 'transfer' ? (
            <>
              <CopyRow label="Titular" value={method.holder} />
              <CopyRow label="Cuenta" value={method.account_number} />
              <CopyRow label="CCI" value={method.cci} />
            </>
          ) : (
            <>
              {method.qr && <img src={method.qr} alt={`QR de ${NAMES[method.method]}`} className="w-44 h-44 mx-auto object-contain rounded-xl bg-white border border-slate-200" />}
              <CopyRow label="Celular" value={method.phone} />
              {method.holder && <CopyRow label="A nombre de" value={method.holder} />}
            </>
          )}
        </div>
      )}

      {options.hold_hours && (
        <p className="text-xs text-slate-500">Al enviar el comprobante tu horario queda apartado hasta {options.hold_hours} {options.hold_hours === 1 ? 'hora' : 'horas'} mientras la empresa lo revisa.</p>
      )}

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div className="space-y-1.5">
          <label htmlFor="transfer-operation" className="block text-[11px] font-bold text-slate-500 uppercase tracking-wider">N.º de operación</label>
          <input id="transfer-operation" required minLength={3} maxLength={60} value={operation} onChange={(e) => setOperation(e.target.value)} className="w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand/20 focus:border-brand" />
        </div>
        <div className="space-y-1.5">
          <label htmlFor="transfer-date" className="block text-[11px] font-bold text-slate-500 uppercase tracking-wider">Día del pago</label>
          <DateField id="transfer-date" value={paidOn} onChange={setPaidOn} min={addDays(todayISO(), -15)} max={todayISO()} required rounded="xl" />
        </div>
      </div>

      <label className="flex items-center gap-3 px-4 py-3 rounded-xl border border-dashed border-slate-300 bg-white cursor-pointer hover:bg-slate-50" data-transfer-file>
        <FileUp size={18} className="text-slate-400 shrink-0" />
        <span className="text-sm text-slate-600 truncate">{file ? file.name : 'Sube la foto o el PDF del comprobante (hasta 5 MB)'}</span>
        <input type="file" accept="image/png,image/jpeg,image/webp,application/pdf" className="hidden" onChange={(e) => pick(e.target)} />
      </label>

      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-3">{error}</p>}

      <button type="submit" data-transfer-send disabled={busy} className="w-full flex items-center justify-center gap-2 px-5 py-3 text-sm font-bold text-white rounded-xl shadow-sm transition-colors disabled:opacity-50" style={{ background: accent }}>
        {busy ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />} Enviar comprobante
      </button>
    </form>
  );
}
