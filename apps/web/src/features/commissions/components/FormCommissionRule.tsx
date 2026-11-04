'use client';

import { useEffect, useState } from 'react';
import { Percent } from 'lucide-react';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { currencySymbol } from '@/lib/currency';
import { useVocabulary } from '@/hooks/useVocabulary';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount } from '@/features/sales/sales';
import type { CommissionRule, RulesPayload, RuleType } from '../commissions';

interface Props {
  isOpen: boolean;
  type: RuleType;
  rule: CommissionRule | null;
  options: RulesPayload | null;
  onClose: () => void;
  onSaved: (message: string) => void;
  onDelete: (rule: CommissionRule) => void;
  onExisting: (id: string) => void;
}

const empty = { professionalId: '', serviceId: '', productId: '', percent: '', fixed: '', active: true };

export default function FormCommissionRule({ isOpen, type, rule, options, onClose, onSaved, onDelete, onExisting }: Props) {
  const vocab = useVocabulary();
  const pro = vocab.pro.toLowerCase();
  const [form, setForm] = useState(empty);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [existing, setExisting] = useState<string | null>(null);

  useEffect(() => {
    if (!isOpen) return;
    setError('');
    setExisting(null);
    setForm(rule ? {
      professionalId: rule.professional_id ?? '',
      serviceId: rule.service_id ?? '',
      productId: rule.product_id ?? '',
      percent: rule.percent ? String(rule.percent) : '',
      fixed: rule.fixed ? String(rule.fixed) : '',
      active: rule.active,
    } : empty);
  }, [isOpen, rule]);

  const set = (changes: Partial<typeof empty>) => { setExisting(null); setError(''); setForm((current) => ({ ...current, ...changes })); };
  const rules = options?.rules ?? [];

  const taken = (serviceId: string, professionalId: string) => rules.some((r) => r.id !== rule?.id && r.service_id === serviceId && (r.professional_id ?? '') === professionalId);
  const takenProduct = (productId: string) => rules.some((r) => r.id !== rule?.id && r.type === 'product' && (r.product_id ?? '') === productId);

  const serviceOptions = (options?.services ?? [])
    .filter((s) => type === 'service' ? !taken(s.id, '') || s.id === form.serviceId : !form.professionalId || !taken(s.id, form.professionalId) || s.id === form.serviceId)
    .map((s) => ({ value: s.id, label: s.name }));

  const productOptions = [
    ...(!takenProduct('') || form.productId === '' ? [{ value: '', label: 'Todos los productos' }] : []),
    ...(options?.product_list ?? []).filter((p) => !takenProduct(p.id) || p.id === form.productId).map((p) => ({ value: p.id, label: p.name })),
  ];

  const titles: Record<RuleType, { title: string; subtitle: string }> = {
    service: { title: rule ? `Comisión de ${rule.service}` : 'Servicio con otra comisión', subtitle: `Vale para todos en este servicio y reemplaza el porcentaje de cada ${pro}.` },
    exception: { title: rule ? `Excepción de ${rule.professional}` : 'Excepción de una persona', subtitle: `Solo para esa persona en ese servicio. Reemplaza todo lo demás.` },
    product: { title: rule ? `Comisión de ${rule.product ?? 'todos los productos'}` : 'Comisión de productos', subtitle: 'Lo que gana quien vende el producto.' },
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSaving(true);
      setError('');
      const payload = {
        type,
        professional_id: type === 'exception' ? form.professionalId || null : null,
        service_id: type === 'product' ? null : form.serviceId || null,
        product_id: type === 'product' ? form.productId || null : null,
        percent: Number(form.percent || 0),
        fixed: Number(form.fixed || 0),
        active: form.active,
      };
      if (rule) await api.put(`/commission-rules/${rule.id}`, payload);
      else await api.post('/commission-rules', payload);
      onSaved(rule ? 'Comisión guardada.' : 'Comisión agregada.');
      onClose();
    } catch (err) {
      const data = (err as { response?: { data?: { code?: string; existing_id?: string } } }).response?.data;
      if (data?.code === 'COMMISSION_RULE_EXISTS' && data.existing_id) setExisting(data.existing_id);
      setError(apiError(err, 'No se pudo guardar la comisión.'));
    } finally {
      setSaving(false);
    }
  };

  const percent = Number(form.percent || 0);
  const fixed = Number(form.fixed || 0);
  const example = percent || fixed
    ? `Por una atención de ${currencySymbol()} 100 ${options?.base === 'gross' ? '' : 'sin IGV '}gana ${currencySymbol()} ${(100 * percent / 100 + fixed).toFixed(2)}.`
    : '';
  const formId = 'commission-rule-form';

  return (
    <FormDrawer isOpen={isOpen} onClose={onClose} title={titles[type].title} subtitle={titles[type].subtitle} icon={<Percent size={20} />}>
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={submit} className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
          {type === 'exception' && (
            <div>
              <span className={labelClass}>{vocab.pro} *</span>
              <Select
                ariaLabel={vocab.pro}
                placeholder={`Elige al ${pro}`}
                clearable={false}
                disabled={Boolean(rule)}
                value={form.professionalId}
                onChange={(e) => set({ professionalId: String(e.target.value) })}
                options={(options?.professionals ?? []).map((p) => ({ value: p.id, label: p.name }))}
              />
            </div>
          )}

          {type !== 'product' ? (
            <div>
              <span className={labelClass}>Servicio *</span>
              <Select
                ariaLabel="Servicio"
                placeholder="Elige el servicio"
                clearable={false}
                disabled={Boolean(rule)}
                value={form.serviceId}
                onChange={(e) => set({ serviceId: String(e.target.value) })}
                options={serviceOptions}
              />
              {!rule && <p className="text-xs text-slate-500 mt-1.5">{type === 'service' ? 'Solo aparecen los servicios que todavía no tienen su comisión.' : 'Solo aparecen los servicios que esa persona todavía no tiene como excepción.'}</p>}
            </div>
          ) : (
            <div>
              <span className={labelClass}>Producto</span>
              <Select ariaLabel="Producto" clearable={false} disabled={Boolean(rule)} value={form.productId} onChange={(e) => set({ productId: String(e.target.value) })} options={productOptions} />
              <p className="text-xs text-slate-500 mt-1.5">&quot;Todos los productos&quot; vale para los que no tienen su propia comisión.</p>
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={labelClass} htmlFor="rule-percent">Porcentaje (%)</label>
              <input id="rule-percent" inputMode="decimal" placeholder="Ej. 30" className={inputClass} value={form.percent} onChange={(e) => { const v = cleanAmount(e.target.value); set({ percent: Number(v) > 100 ? '100' : v }); }} />
            </div>
            <div>
              <label className={labelClass} htmlFor="rule-fixed">Monto fijo ({currencySymbol()})</label>
              <input id="rule-fixed" inputMode="decimal" placeholder="Ej. 10.00" className={inputClass} value={form.fixed} onChange={(e) => set({ fixed: cleanAmount(e.target.value) })} />
            </div>
          </div>
          <p className="text-xs text-slate-500 -mt-2">
            Usa uno de los dos o ambos. El monto fijo es por cada {type === 'product' ? 'unidad vendida' : 'atención'}. {example}
          </p>

          <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-xl px-4 py-3">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Activa</span>
              <span className="block text-xs text-slate-500">Apagada, no se usa y vale lo que sigue en la lista.</span>
            </div>
            <Switch checked={form.active} onChange={(active) => set({ active })} label="Comisión activa" />
          </div>

          <ErrorAlert message={error} />
          {existing && (
            <button type="button" data-open-existing onClick={() => onExisting(existing)} className="text-sm font-semibold text-brand-accent hover:text-brand-ink">
              Abrir la que ya existe
            </button>
          )}
        </form>

        <div className="flex items-center justify-between gap-3 pt-4 border-t border-slate-100">
          {rule ? <button type="button" data-delete-rule onClick={() => onDelete(rule)} className="text-sm font-semibold text-rose-700 hover:text-rose-800">Quitar</button> : <span />}
          <div className="flex items-center gap-3">
            <Button type="button" onClick={onClose} variant="secondary">Cancelar</Button>
            <Button type="submit" form={formId} data-submit disabled={saving} variant="primary">{saving ? 'Guardando...' : rule ? 'Guardar cambios' : 'Agregar'}</Button>
          </div>
        </div>
      </div>
    </FormDrawer>
  );
}
