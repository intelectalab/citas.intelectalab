'use client';

import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, Building, CreditCard, ImagePlus, Loader2, Smartphone, Wallet } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import OptionCard from '@/app/components/common/OptionCard';
import DateField from '@/app/components/common/DateField';
import { ErrorAlert } from '@/app/components/common/Alert';
import { api } from '@/lib/axios';
import { apiError, formatDate } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import { cycleLabel, type Billing, type TransferAccount } from '../billing';

const inputClass = 'w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 bg-white text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none';
const MAX_VOUCHER = 5 * 1024 * 1024;

const longDay = (date: string) => formatDate(date, { day: 'numeric', month: 'long', year: 'numeric' });

const today = () => {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
};

function Row({ label, value }: { label: string; value?: string }) {
  if (!value) return null;
  return (
    <div className="flex items-center justify-between gap-3 py-2 border-b border-slate-100 last:border-0">
      <span className="text-xs text-slate-500">{label}</span>
      <span className="text-sm font-semibold text-slate-800 font-mono select-all text-right">{value}</span>
    </div>
  );
}

export default function PayPlanDrawer({ open, billing, onClose, onCard, onSent }: { open: boolean; billing: Billing; onClose: () => void; onCard: () => void; onSent: (message: string) => void }) {
  const [accounts, setAccounts] = useState<TransferAccount[] | null>(null);
  const [reviewText, setReviewText] = useState('');
  const [chosen, setChosen] = useState<TransferAccount | null>(null);
  const [operation, setOperation] = useState('');
  const [paidOn, setPaidOn] = useState(today());
  const [voucher, setVoucher] = useState<{ data: string; name: string; image: boolean } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!open) return;
    setChosen(null);
    setOperation('');
    setPaidOn(today());
    setVoucher(null);
    setError('');
    if (billing.methods?.transfer) {
      api.get('/billing/transfer')
        .then((res) => { setAccounts(res.data.accounts); setReviewText(res.data.review_text); })
        .catch((err) => setError(apiError(err, 'No se pudieron cargar las cuentas.')));
    } else {
      setAccounts([]);
    }
  }, [open, billing.methods?.transfer]);

  if (!open) return null;

  const plan = billing.pending_plan ?? (billing.plan ? { name: billing.plan.name, price: billing.price, billing_cycle: billing.billing_cycle } : null);
  const amount = plan?.price ?? billing.price;
  const currency = billing.plan?.currency;

  const pick = (file: File | undefined) => {
    setError('');
    if (!file) return;
    if (!/^(image\/(png|jpe?g|webp)|application\/pdf)$/.test(file.type)) { setError('Sube una foto (PNG, JPG o WEBP) o un PDF del comprobante.'); return; }
    if (file.size > MAX_VOUCHER) { setError('El comprobante pesa demasiado: usa uno de menos de 5 MB (una captura basta).'); return; }
    const reader = new FileReader();
    reader.onload = () => setVoucher({ data: String(reader.result), name: file.name, image: file.type.startsWith('image/') });
    reader.readAsDataURL(file);
  };

  const send = async () => {
    if (!chosen || !voucher) return;
    try {
      setBusy(true);
      setError('');
      const res = await api.post('/billing/transfer', { method_id: chosen.id, operation_number: operation.trim(), paid_on: paidOn, voucher: voucher.data });
      onSent(res.data.message);
    } catch (err) {
      setError(apiError(err, 'No se pudo enviar el comprobante.'));
    } finally {
      setBusy(false);
    }
  };

  const icon = (type: TransferAccount['type']) => (type === 'bank' ? <Building size={16} /> : <Smartphone size={16} />);

  return (
    <FormDrawer isOpen onClose={onClose} title="Pagar tu plan" subtitle={plan ? `Plan ${plan.name} · ${money(amount, currency)} ${cycleLabel(plan.billing_cycle)}` : undefined} icon={<Wallet size={18} />}>
      <div className="space-y-4" data-pay-plan>
        {billing.next_period && (
          <p data-pay-period className="text-sm text-brand-ink bg-brand-soft/15 border border-brand-soft/50 rounded-xl px-4 py-2.5">
            Este pago cubre del <span className="font-bold">{longDay(billing.next_period[0])}</span> al <span className="font-bold">{longDay(billing.next_period[1])}</span>.
          </p>
        )}
        {!chosen ? (
          <>
            <p className="text-sm text-slate-600">Elige cómo vas a pagar.</p>
            <div className="space-y-2" role="radiogroup" aria-label="Cómo vas a pagar">
              {billing.methods?.card && (
                <OptionCard type="button" role="radio" aria-checked={false} selected={false} data-pay-method="mercadopago" onClick={onCard} className="w-full text-left rounded-xl border px-4 py-3">
                  <span className="flex items-center gap-2 text-sm font-bold text-brand-ink"><CreditCard size={16} /> Tarjeta u otros medios con Mercado Pago</span>
                  <span className="block text-xs text-slate-500 mt-0.5">Pagas en la página de Mercado Pago y se confirma solo.</span>
                </OptionCard>
              )}
              {(accounts ?? []).map((account) => (
                <OptionCard key={account.id} type="button" role="radio" aria-checked={false} selected={false} data-pay-method={account.id} onClick={() => setChosen(account)} className="w-full text-left rounded-xl border px-4 py-3">
                  <span className="flex items-center gap-2 text-sm font-bold text-brand-ink">{icon(account.type)} {account.type === 'bank' ? `Transferencia a ${account.bank}` : account.type === 'yape' ? 'Yape' : 'Plin'}</span>
                  <span className="block text-xs text-slate-500 mt-0.5">{account.type === 'bank' ? `Cuenta en ${account.currency ?? 'PEN'}` : account.phone}. Luego subes la foto del comprobante.</span>
                </OptionCard>
              ))}
              {accounts === null && <p className="text-xs text-slate-400">Cargando...</p>}
            </div>
          </>
        ) : (
          <>
            <button type="button" onClick={() => setChosen(null)} className="flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-brand-ink"><ArrowLeft size={14} /> Elegir otro medio</button>

            <section className="rounded-2xl border border-slate-200 p-4 space-y-2" data-pay-destination>
              <span className="block text-sm font-bold text-brand-ink">1. Paga {money(amount, currency)} a:</span>
              {chosen.type === 'bank' ? (
                <div>
                  <Row label="Banco" value={chosen.bank} />
                  <Row label="Titular" value={chosen.holder} />
                  <Row label={`Cuenta (${chosen.currency ?? 'PEN'})`} value={chosen.account_number} />
                  <Row label="CCI (desde otro banco)" value={chosen.cci} />
                </div>
              ) : (
                <div className="flex items-start gap-4">
                  {chosen.qr && <img src={chosen.qr} alt={`QR de ${chosen.type === 'yape' ? 'Yape' : 'Plin'}`} className="w-32 h-32 object-contain rounded-lg border border-slate-100 bg-white" />}
                  <div className="flex-1">
                    <Row label={chosen.type === 'yape' ? 'Yape al' : 'Plin al'} value={chosen.phone} />
                    <Row label="A nombre de" value={chosen.holder} />
                  </div>
                </div>
              )}
            </section>

            <section className="rounded-2xl border border-slate-200 p-4 space-y-3">
              <span className="block text-sm font-bold text-brand-ink">2. Envíanos tu comprobante</span>
              <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp,application/pdf" className="hidden" data-voucher-file onChange={(e) => { pick(e.target.files?.[0]); e.target.value = ''; }} />
              <button type="button" data-voucher-pick onClick={() => fileRef.current?.click()} className="w-full flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm font-semibold text-slate-600 hover:bg-slate-100">
                {voucher?.image ? <img src={voucher.data} alt="Comprobante" className="max-h-40 rounded-lg" /> : <ImagePlus size={22} className="text-slate-400" />}
                {voucher ? `${voucher.name} · cambiar` : 'Subir foto o captura del comprobante'}
              </button>
              <label className="block space-y-1">
                <span className="block text-xs font-semibold text-slate-600">Número de operación</span>
                <input aria-label="Número de operación" maxLength={60} value={operation} onChange={(e) => setOperation(e.target.value)} placeholder="Aparece en tu comprobante" className={inputClass} />
              </label>
              <label className="block space-y-1">
                <span className="block text-xs font-semibold text-slate-600">Fecha en que pagaste</span>
                <DateField ariaLabel="Fecha en que pagaste" value={paidOn} max={today()} onChange={(value) => setPaidOn(value)} />
              </label>
            </section>

            <ErrorAlert message={error} />
            <button type="button" data-voucher-send disabled={busy || !voucher || !operation.trim() || !paidOn} onClick={send} className="w-full flex items-center justify-center gap-2 px-5 py-3 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
              {busy ? <Loader2 size={15} className="animate-spin" /> : null} Enviar comprobante
            </button>
            {reviewText && <p className="text-xs text-slate-500 text-center">Lo revisaremos en {reviewText} y te avisaremos.</p>}
          </>
        )}
        {!chosen && <ErrorAlert message={error} />}
      </div>
    </FormDrawer>
  );
}
