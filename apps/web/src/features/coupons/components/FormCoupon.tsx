'use client';

import OptionCard from '@/app/components/common/OptionCard';
import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useState } from 'react';
import { TicketPercent } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import DateField from '@/app/components/common/DateField';
import Switch from '@/app/components/common/Switch';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount, type Coupon } from '@/features/sales/sales';
import { currencySymbol } from '@/lib/currency';

interface FormCouponProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: Coupon | null;
  onSaved: () => void;
}


const empty = { code: '', description: '', type: 'percent' as Coupon['discount_type'], value: '', min: '', uses: '', startsOn: '', endsOn: '', active: true };

const TYPES: { id: Coupon['discount_type']; title: string; hint: string }[] = [
  { id: 'percent', title: 'Un porcentaje', hint: 'Ej. 10 % de lo que se cobra' },
  { id: 'amount', title: 'Un monto fijo', hint: 'Ej. 20 menos' },
];

export default function FormCoupon({ isOpen, onClose, initialData, onSaved }: FormCouponProps) {
  const [form, setForm] = useState(empty);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;

    setError('');
    setForm(initialData ? {
      code: initialData.code,
      description: initialData.description ?? '',
      type: initialData.discount_type,
      value: String(initialData.discount_value),
      min: initialData.min_purchase ? String(initialData.min_purchase) : '',
      uses: initialData.max_uses ? String(initialData.max_uses) : '',
      startsOn: initialData.starts_on ?? '',
      endsOn: initialData.ends_on ?? '',
      active: initialData.status === 'active',
    } : empty);
  }, [isOpen, initialData]);

  const set = (changes: Partial<typeof empty>) => setForm((current) => ({ ...current, ...changes }));

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const payload = {
      code: form.code.trim(),
      description: form.description.trim() || null,
      discount_type: form.type,
      discount_value: Number(form.value || 0),
      min_purchase: Number(form.min || 0),
      max_uses: form.uses ? Number(form.uses) : null,
      starts_on: form.startsOn || null,
      ends_on: form.endsOn || null,
      status: form.active ? 'active' : 'inactive',
    };

    try {
      setSaving(true);
      setError('');
      if (initialData) {
        await api.put(`/coupons/${initialData.id}`, payload);
      } else {
        await api.post('/coupons', payload);
      }
      onSaved();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el cupón.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'coupon-form';

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar: ${initialData.code}` : 'Nuevo cupón'}
      subtitle={initialData ? `Usado ${initialData.used_count} ${initialData.used_count === 1 ? 'vez' : 'veces'}` : 'Un código que el cliente dice al pagar para recibir un descuento'}
      icon={<TicketPercent size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          <div>
            <label className={labelClass} htmlFor="coupon-code">Código *</label>
            <input
              id="coupon-code"
              required
              maxLength={40}
              placeholder="Ej. BIENVENIDA10"
              className={`${inputClass} uppercase placeholder:normal-case font-semibold tracking-wide`}
              value={form.code}
              onChange={(e) => set({ code: e.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '') })}
            />
            <p className="text-xs text-slate-500 mt-1.5">Letras, números y guiones, sin espacios. Es lo que el cliente dice o muestra al pagar.</p>
          </div>

          <div>
            <label className={labelClass} htmlFor="coupon-description">Para qué es</label>
            <input id="coupon-description" maxLength={255} placeholder="Ej. Bienvenida: 10 % en la primera visita" className={inputClass} value={form.description} onChange={(e) => set({ description: e.target.value })} />
          </div>

          <div>
            <span className={labelClass}>¿Qué descuenta?</span>
            <div className="grid grid-cols-2 gap-3" role="radiogroup" aria-label="Tipo de descuento">
              {TYPES.map((option) => {
                const chosen = form.type === option.id;
                return (
                  <OptionCard
                    key={option.id}
                    type="button"
                    role="radio"
                    aria-checked={chosen}
                    data-coupon-type={option.id}
                    onClick={() => set({ type: option.id })} selected={chosen} className="text-left rounded-xl border px-4 py-3 transition-colors"
                  >
                    <span className="block text-sm font-bold text-[#022340]">{option.title}</span>
                    <span className="block text-xs text-slate-500 mt-0.5">{option.hint}</span>
                  </OptionCard>
                );
              })}
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={labelClass} htmlFor="coupon-value">{form.type === 'percent' ? 'Porcentaje (%) *' : `Monto (${currencySymbol()}) *`}</label>
              <input id="coupon-value" inputMode="decimal" required placeholder={form.type === 'percent' ? '10' : '20.00'} className={inputClass} value={form.value} onChange={(e) => set({ value: cleanAmount(e.target.value) })} />
            </div>
            <div>
              <label className={labelClass} htmlFor="coupon-min">Compra mínima ({currencySymbol()})</label>
              <input id="coupon-min" inputMode="decimal" placeholder="Sin mínimo" className={inputClass} value={form.min} onChange={(e) => set({ min: cleanAmount(e.target.value) })} />
            </div>
          </div>

          <div>
            <span className={labelClass}>Vigencia</span>
            <div className="grid grid-cols-2 gap-3">
              <DateField ariaLabel="Desde" placeholder="Desde hoy" value={form.startsOn} onChange={(value) => set({ startsOn: value, endsOn: form.endsOn && value && form.endsOn < value ? value : form.endsOn })} />
              <DateField ariaLabel="Hasta" placeholder="Sin fecha final" align="right" min={form.startsOn || undefined} value={form.endsOn} onChange={(value) => set({ endsOn: value })} />
            </div>
            <p className="text-xs text-slate-500 mt-1.5">Vale desde el inicio del primer día hasta el final del último. Sin fechas, vale siempre.</p>
          </div>

          <div>
            <label className={labelClass} htmlFor="coupon-uses">Cuántas veces se puede usar</label>
            <input id="coupon-uses" inputMode="numeric" placeholder="Sin límite" className={inputClass} value={form.uses} onChange={(e) => set({ uses: e.target.value.replace(/\D/g, '').slice(0, 7) })} />
            <p className="text-xs text-slate-500 mt-1.5">En total, entre todos los clientes. Al llegar a ese número el cupón queda agotado.</p>
          </div>

          <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-xl px-4 py-3">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Activo</span>
              <span className="block text-xs text-slate-500">Apágalo para que deje de aceptarse sin borrarlo.</span>
            </div>
            <Switch checked={form.active} onChange={(active) => set({ active })} label="Cupón activo" />
          </div>

          <ErrorAlert message={error} />
        </form>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" onClick={onClose} variant="secondary">
            Cancelar
          </Button>
          <Button type="submit" form={formId} data-submit disabled={saving} variant="primary">
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Crear cupón'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
