'use client';

import OptionCard from '@/app/components/common/OptionCard';
import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useState } from 'react';
import { BadgeCheck, Plus, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount, money } from '@/features/sales/sales';
import { currencySymbol } from '@/lib/currency';
import type { DiscountScope, MembershipPeriod, MembershipPlan, MembershipsBoardData } from '../memberships';

interface Props {
  isOpen: boolean;
  onClose: () => void;
  initialData: MembershipPlan | null;
  options: MembershipsBoardData['options'] | null;
  currency?: string;
  onSaved: () => void;
}

interface Row { key: string; serviceId: string; sessions: string }

const sectionClass = 'space-y-3 rounded-2xl border border-slate-200 p-4';
const sectionTitle = 'text-sm font-black text-brand-ink';

const PERIOD_HINTS: Record<MembershipPeriod, string> = {
  monthly: 'Paga cada mes',
  quarterly: 'Paga cada 3 meses',
  semiannual: 'Paga cada 6 meses',
  yearly: 'Paga una vez al año',
};

let counter = 0;
const newRow = (serviceId = '', sessions = '1'): Row => ({ key: `row-${++counter}`, serviceId, sessions });

export default function FormMembershipPlan({ isOpen, onClose, initialData, options, currency, onSaved }: Props) {
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [price, setPrice] = useState('');
  const [period, setPeriod] = useState<MembershipPeriod>('monthly');
  const [discount, setDiscount] = useState('');
  const [scope, setScope] = useState<DiscountScope>('services');
  const [active, setActive] = useState(true);
  const [rows, setRows] = useState<Row[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setName(initialData?.name ?? '');
    setDescription(initialData?.description ?? '');
    setPrice(initialData ? String(initialData.price) : '');
    setPeriod(initialData?.billing_period ?? 'monthly');
    setDiscount(initialData && initialData.discount_percent > 0 ? String(initialData.discount_percent) : '');
    setScope(initialData?.discount_scope ?? 'services');
    setActive(initialData ? initialData.status === 'active' : true);
    setRows((initialData?.items ?? []).map((i) => newRow(i.service_id, String(i.sessions))));
    setError('');
  }, [isOpen, initialData]);

  const services = options?.services ?? [];
  const used = new Set(rows.map((r) => r.serviceId).filter(Boolean));
  const changeRow = (key: string, changes: Partial<Row>) => setRows((current) => current.map((r) => (r.key === key ? { ...r, ...changes } : r)));
  const perLabel = { monthly: 'al mes', quarterly: 'cada 3 meses', semiannual: 'cada 6 meses', yearly: 'al año' }[period];

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const payload = {
      name: name.trim(),
      description: description.trim() || null,
      price: Number(price || 0),
      billing_period: period,
      discount_percent: Number(discount || 0),
      discount_scope: scope,
      status: active ? 'active' : 'inactive',
      items: rows.filter((r) => r.serviceId).map((r) => ({ service_id: r.serviceId, sessions: Number(r.sessions || 0) })),
    };

    try {
      setSaving(true);
      setError('');
      if (initialData) await api.put(`/membership-plans/${initialData.id}`, payload);
      else await api.post('/membership-plans', payload);
      onSaved();
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el plan.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'membership-plan-form';

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar: ${initialData.name}` : 'Nuevo plan de membresía'}
      subtitle={initialData ? (initialData.members ? `${initialData.members} ${initialData.members === 1 ? 'socio vigente' : 'socios vigentes'}: los cambios valen para las próximas ventas, no para las ya vendidas` : 'Aún sin socios vigentes') : 'Un pago periódico que le da beneficios al cliente mientras esté vigente'}
      icon={<BadgeCheck size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4" data-membership-form>
          <section className={sectionClass}>
            <h3 className={sectionTitle}>El plan</h3>
            <div>
              <label className={labelClass} htmlFor="membership-name">Nombre *</label>
              <input id="membership-name" required maxLength={150} placeholder="Ej. Plan Salud Familiar" className={inputClass} value={name} onChange={(e) => setName(e.target.value)} />
            </div>
            <div>
              <label className={labelClass} htmlFor="membership-description">Descripción</label>
              <input id="membership-description" maxLength={1000} placeholder="Ej. Descuento en consultas y una consulta incluida al mes" className={inputClass} value={description} onChange={(e) => setDescription(e.target.value)} />
            </div>
            <div>
              <span className={labelClass}>Cada cuánto paga *</span>
              <div className="grid grid-cols-2 gap-2" data-membership-periods>
                {(options?.periods ?? []).map((p) => (
                  <OptionCard key={p.value} type="button" selected={period === p.value} data-period={p.value} onClick={() => setPeriod(p.value as MembershipPeriod)} className="text-left rounded-xl border px-3 py-2.5 transition-colors">
                    <span className="block text-sm font-semibold text-slate-800">{p.label}</span>
                    <span className="block text-xs text-slate-500">{PERIOD_HINTS[p.value as MembershipPeriod]}</span>
                  </OptionCard>
                ))}
              </div>
            </div>
            <div>
              <label className={labelClass} htmlFor="membership-price">Precio ({currencySymbol()}) *</label>
              <div className="flex items-center gap-2">
                <input id="membership-price" required inputMode="decimal" placeholder="89.00" className={inputClass} value={price} onChange={(e) => setPrice(cleanAmount(e.target.value))} />
                <span className="shrink-0 text-sm text-slate-500">{perLabel}</span>
              </div>
            </div>
          </section>

          <section className={sectionClass}>
            <h3 className={sectionTitle}>Beneficios</h3>
            <p className="text-xs text-slate-500 -mt-1">Dale al menos uno. Se aplican solos al cobrar en el punto de venta mientras la membresía esté vigente.</p>
            <div>
              <label className={labelClass} htmlFor="membership-discount">Descuento permanente (%)</label>
              <input id="membership-discount" inputMode="decimal" placeholder="Ej. 10" className={inputClass} value={discount} onChange={(e) => setDiscount(cleanAmount(e.target.value).slice(0, 6))} />
            </div>
            {Number(discount || 0) > 0 && (
              <div>
                <span className={labelClass}>Dónde se aplica el descuento</span>
                <div className="grid grid-cols-2 gap-2" data-membership-scope>
                  {(options?.scopes ?? []).map((s) => (
                    <OptionCard key={s.value} type="button" selected={scope === s.value} data-scope={s.value} onClick={() => setScope(s.value as DiscountScope)} className="text-left rounded-xl border px-3 py-2.5 text-sm font-semibold text-slate-800 transition-colors">
                      {s.label}
                    </OptionCard>
                  ))}
                </div>
              </div>
            )}

            <div className="space-y-2" data-membership-items>
              <span className={labelClass}>Servicios incluidos {perLabel}</span>
              {rows.map((row) => (
                <div key={row.key} className="flex items-start gap-2" data-membership-row>
                  <div className="flex-1 min-w-0">
                    <Select
                      ariaLabel="Servicio incluido"
                      placeholder="Elige el servicio"
                      value={row.serviceId}
                      clearable={false}
                      onChange={(e) => changeRow(row.key, { serviceId: String(e.target.value) })}
                      options={services.filter((s) => s.value === row.serviceId || !used.has(s.value)).map((s) => ({ value: s.value, label: `${s.label} · ${money(s.price, currency)}` }))}
                    />
                  </div>
                  <div className="w-20 shrink-0">
                    <input aria-label="Sesiones" inputMode="numeric" className={`${inputClass} text-center`} value={row.sessions} onChange={(e) => changeRow(row.key, { sessions: e.target.value.replace(/\D/g, '').slice(0, 2) })} />
                  </div>
                  <button type="button" title="Quitar servicio" onClick={() => setRows((current) => current.filter((r) => r.key !== row.key))} className="mt-1 p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                    <Trash2 size={15} />
                  </button>
                </div>
              ))}
              {rows.length < 20 && (
                <button type="button" data-add-row onClick={() => setRows((current) => [...current, newRow()])} className="flex items-center gap-1.5 text-xs font-bold text-brand-accent hover:text-brand-ink transition-colors">
                  <Plus size={14} /> Agregar servicio incluido
                </button>
              )}
              <p className="text-xs text-slate-500">Las sesiones que no use no pasan al periodo siguiente.</p>
            </div>
          </section>

          <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-xl px-4 py-3">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Se vende</span>
              <span className="block text-xs text-slate-500">Apágalo para dejar de ofrecerlo sin borrarlo. Las membresías vendidas siguen igual.</span>
            </div>
            <Switch checked={active} onChange={setActive} label="Plan a la venta" />
          </div>

          <ErrorAlert message={error} />
        </form>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" onClick={onClose} variant="secondary">Cancelar</Button>
          <Button type="submit" form={formId} data-submit disabled={saving} variant="primary">
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Crear plan'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
