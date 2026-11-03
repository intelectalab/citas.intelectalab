'use client';

import SectionTitle from '@/app/components/common/SectionTitle';
import OptionCard from '@/app/components/common/OptionCard';
import Switch from '@/app/components/common/Switch';
import Select from '@/app/components/common/Select';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useEffect, useRef, useState } from 'react';
import { ImagePlus, Plus, Trash2, Wallet } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import type { TransferAccount } from '@/features/billing/billing';

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 space-y-4';
const labelClass = 'block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-1.5';
const inputClass = 'w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none';
const QR_MAX = 700 * 1024;

const TYPES: [TransferAccount['type'], string][] = [['bank', 'Cuenta bancaria'], ['yape', 'Yape'], ['plin', 'Plin']];

function AccountEditor({ account, onChange, onRemove }: { account: TransferAccount; onChange: (next: TransferAccount) => void; onRemove: () => void }) {
  const fileRef = useRef<HTMLInputElement>(null);
  const [error, setError] = useState('');
  const set = (key: keyof TransferAccount, value: string) => onChange({ ...account, [key]: value });

  const pickQr = (file: File | undefined) => {
    setError('');
    if (!file) return;
    if (!/^image\/(png|jpe?g|webp)$/.test(file.type) || file.size > QR_MAX) { setError('El QR debe ser una imagen PNG, JPG o WEBP de menos de 700 KB.'); return; }
    const reader = new FileReader();
    reader.onload = () => onChange({ ...account, qr: String(reader.result) });
    reader.readAsDataURL(file);
  };

  return (
    <div className="rounded-2xl border border-slate-200 p-4 space-y-3" data-transfer-account={account.type}>
      <div className="flex items-center justify-between gap-2">
        <div className="grid grid-cols-3 gap-2 flex-1" role="radiogroup" aria-label="Tipo de cuenta">
          {TYPES.map(([type, label]) => (
            <OptionCard key={type} type="button" role="radio" aria-checked={account.type === type} selected={account.type === type} onClick={() => onChange({ id: account.id, type, holder: account.holder })} className="rounded-xl border px-3 py-2 text-xs font-bold text-brand-ink">
              {label}
            </OptionCard>
          ))}
        </div>
        <button type="button" aria-label="Quitar esta cuenta" onClick={onRemove} className="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg"><Trash2 size={16} /></button>
      </div>
      {account.type === 'bank' ? (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <input aria-label="Banco" maxLength={60} value={account.bank ?? ''} onChange={(e) => set('bank', e.target.value)} placeholder="Banco (por ejemplo, BCP)" className={inputClass} />
          <input aria-label="Titular" maxLength={120} value={account.holder ?? ''} onChange={(e) => set('holder', e.target.value)} placeholder="Titular de la cuenta" className={inputClass} />
          <input aria-label="Número de cuenta" maxLength={40} value={account.account_number ?? ''} onChange={(e) => set('account_number', e.target.value)} placeholder="Número de cuenta" className={`${inputClass} font-mono`} />
          <input aria-label="CCI" maxLength={40} value={account.cci ?? ''} onChange={(e) => set('cci', e.target.value)} placeholder="CCI (opcional)" className={`${inputClass} font-mono`} />
          <Select ariaLabel="Moneda de la cuenta" clearable={false} value={account.currency ?? 'PEN'} onChange={(e) => set('currency', String(e.target.value))} options={[{ value: 'PEN', label: 'Soles (PEN)' }, { value: 'USD', label: 'Dólares (USD)' }]} />
        </div>
      ) : (
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="flex-1 space-y-3">
            <input aria-label="Número de celular" maxLength={20} inputMode="tel" value={account.phone ?? ''} onChange={(e) => set('phone', e.target.value)} placeholder="Número de celular" className={`${inputClass} font-mono`} />
            <input aria-label="A nombre de" maxLength={120} value={account.holder ?? ''} onChange={(e) => set('holder', e.target.value)} placeholder="A nombre de (opcional)" className={inputClass} />
          </div>
          <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={(e) => { pickQr(e.target.files?.[0]); e.target.value = ''; }} />
          <button type="button" onClick={() => fileRef.current?.click()} className="w-28 h-28 shrink-0 rounded-lg border border-dashed border-slate-300 bg-slate-50 flex flex-col items-center justify-center gap-1 text-[11px] font-semibold text-slate-500 overflow-hidden">
            {account.qr ? <img src={account.qr} alt="QR" className="w-full h-full object-contain bg-white" /> : <><ImagePlus size={20} /> Subir QR</>}
          </button>
        </div>
      )}
      {error && <p role="alert" className="text-xs text-red-600">{error}</p>}
    </div>
  );
}

export default function PlatformPayMethods() {
  const [mercadopago, setMercadopago] = useState(true);
  const [transfer, setTransfer] = useState(false);
  const [reviewText, setReviewText] = useState('hasta 1 día hábil');
  const [accounts, setAccounts] = useState<TransferAccount[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const apply = (data: any) => {
    setMercadopago(Boolean(data.methods?.mercadopago ?? true));
    setTransfer(Boolean(data.methods?.transfer?.enabled));
    setReviewText(data.methods?.transfer?.review_text ?? 'hasta 1 día hábil');
    setAccounts(data.methods?.transfer?.accounts ?? []);
    setLoaded(true);
  };

  useEffect(() => {
    api.get('/admin/billing')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los medios de pago.')));
  }, []);

  if (!loaded) {
    return error ? <section className={card}><ErrorAlert message={error} /></section> : null;
  }

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const res = await api.put('/admin/billing/methods', { mercadopago, transfer, review_text: reviewText, accounts });
      apply(res.data);
      setNotice('Medios de pago guardados.');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className={card} data-card="platform-pay-methods">
      <div>
        <SectionTitle><span className="text-brand-accent"><Wallet size={16} /></span> Medios para pagar el plan</SectionTitle>
        <p className="text-xs text-slate-500 font-medium mt-1">Con qué pueden pagar las empresas su plan. Puedes dejar uno solo o los dos; la empresa elige al pagar.</p>
      </div>

      <div className="rounded-2xl border border-slate-200 divide-y divide-slate-100">
        <div className="flex items-center justify-between gap-4 px-4 py-3.5">
          <span>
            <span className="block text-sm font-semibold text-slate-800">Mercado Pago</span>
            <span className="block text-xs text-slate-500 mt-0.5">Tarjeta autorizada que se cobra sola, o pago en línea. Necesita la cuenta conectada abajo.</span>
          </span>
          <Switch checked={mercadopago} onChange={(on) => { setNotice(''); setMercadopago(on); }} label="Aceptar Mercado Pago" />
        </div>
        <div className="flex items-center justify-between gap-4 px-4 py-3.5">
          <span>
            <span className="block text-sm font-semibold text-slate-800">Transferencia, Yape o Plin</span>
            <span className="block text-xs text-slate-500 mt-0.5">La empresa paga desde su banco o app y sube la foto del comprobante; tú lo apruebas en Cobros.</span>
          </span>
          <Switch checked={transfer} onChange={(on) => { setNotice(''); setTransfer(on); }} label="Aceptar transferencia" />
        </div>
      </div>

      {transfer && (
        <div className="space-y-3" data-transfer-config>
          <span className={labelClass}>Cuentas y números donde pagar</span>
          {accounts.map((account, index) => (
            <AccountEditor
              key={account.id}
              account={account}
              onChange={(next) => setAccounts(accounts.map((a, i) => (i === index ? next : a)))}
              onRemove={() => setAccounts(accounts.filter((_, i) => i !== index))}
            />
          ))}
          <button type="button" data-add-account onClick={() => setAccounts([...accounts, { id: Math.random().toString(36).slice(2, 12), type: 'bank', currency: 'PEN' }])} className="flex items-center gap-1.5 px-4 py-2 text-sm font-semibold border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50">
            <Plus size={15} /> Agregar cuenta o número
          </button>
          <div className="max-w-sm">
            <label className={labelClass} htmlFor="review-text">Cuánto tardas en revisar</label>
            <input id="review-text" maxLength={60} value={reviewText} onChange={(e) => setReviewText(e.target.value)} className={inputClass} />
            <p className="text-xs text-slate-400 mt-1.5">La empresa lo lee así: &quot;Lo revisaremos en {reviewText || '…'}&quot;.</p>
          </div>
        </div>
      )}

      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
      <button type="button" data-pay-methods-save disabled={busy} onClick={save} className="px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
        {busy ? 'Guardando...' : 'Guardar medios de pago'}
      </button>
    </section>
  );
}
