'use client';

import { useEffect, useState } from 'react';
import { FileText, Loader2 } from 'lucide-react';
import OptionCard from '@/app/components/common/OptionCard';
import { ErrorAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import type { BillingDetails } from '../billing';

const input = 'w-full px-3.5 py-2.5 text-sm border border-slate-200 rounded-xl focus:outline-none focus:border-brand-accent';
const label = 'block text-sm font-semibold text-slate-700 mb-1.5';

export default function BillingDetailsDrawer({ open, details, onClose, onSaved }: { open: boolean; details: BillingDetails; onClose: () => void; onSaved: (message: string) => Promise<void> | void }) {
  const [form, setForm] = useState(details);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => { if (open) { setForm(details); setError(''); } }, [open, details]);

  const set = (key: keyof BillingDetails, value: string) => setForm((current) => ({ ...current, [key]: value }));

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.put('/billing/details', {
        doc_type: form.doc_type, ruc: form.ruc || null, business_name: form.business_name || null,
        address: form.address || null, dni: form.dni || null, email: form.email || null,
      });
      await onSaved(res.data.message);
    } catch (err) {
      setError(apiError(err, 'No se pudieron guardar los datos.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <FormDrawer isOpen={open} onClose={onClose} title="Boleta o factura" subtitle="Con qué datos te emitimos el comprobante del plan" icon={<FileText size={18} />}>
      <div className="space-y-4" data-billing-details-panel>
        <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Tipo de comprobante">
          {(['boleta', 'factura'] as const).map((type) => (
            <OptionCard key={type} type="button" role="radio" aria-checked={form.doc_type === type} data-doc-type={type} selected={form.doc_type === type} onClick={() => set('doc_type', type)} className="rounded-xl border px-3.5 py-2.5 text-sm font-bold text-brand-ink">
              {type === 'boleta' ? 'Boleta' : 'Factura'}
            </OptionCard>
          ))}
        </div>
        {form.doc_type === 'factura' ? (
          <>
            <div>
              <label className={label} htmlFor="bd-ruc">RUC</label>
              <input id="bd-ruc" inputMode="numeric" maxLength={11} value={form.ruc ?? ''} onChange={(e) => set('ruc', e.target.value.replace(/\D/g, ''))} className={input} placeholder="Ej. 20601234567" />
            </div>
            <div>
              <label className={label} htmlFor="bd-name">Razón social</label>
              <input id="bd-name" maxLength={200} value={form.business_name ?? ''} onChange={(e) => set('business_name', e.target.value)} className={input} placeholder="Ej. Spa Bella Vista S.A.C." />
            </div>
            <div>
              <label className={label} htmlFor="bd-address">Dirección fiscal</label>
              <input id="bd-address" maxLength={255} value={form.address ?? ''} onChange={(e) => set('address', e.target.value)} className={input} placeholder="Ej. Av. Larco 123, Miraflores" />
            </div>
          </>
        ) : (
          <>
            <div>
              <label className={label} htmlFor="bd-name-b">A nombre de</label>
              <input id="bd-name-b" maxLength={200} value={form.business_name ?? ''} onChange={(e) => set('business_name', e.target.value)} className={input} placeholder="Ej. Rosa Quispe" />
            </div>
            <div>
              <label className={label} htmlFor="bd-dni">DNI (opcional)</label>
              <input id="bd-dni" inputMode="numeric" maxLength={8} value={form.dni ?? ''} onChange={(e) => set('dni', e.target.value.replace(/\D/g, ''))} className={input} placeholder="Ej. 45678912" />
            </div>
          </>
        )}
        <div>
          <label className={label} htmlFor="bd-email">Correo para recibir el comprobante (opcional)</label>
          <input id="bd-email" type="email" maxLength={190} value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} className={input} placeholder="Ej. pagos@tuempresa.com" />
        </div>
        <p className="text-xs text-slate-500">Los cambios valen para los próximos pagos del plan. Los comprobantes ya emitidos no cambian.</p>
        <ErrorAlert message={error} />
        <button type="button" data-billing-details-save disabled={busy} onClick={save} className="w-full flex items-center justify-center gap-2 px-5 py-3 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
          {busy && <Loader2 size={15} className="animate-spin" />} Guardar datos
        </button>
      </div>
    </FormDrawer>
  );
}
